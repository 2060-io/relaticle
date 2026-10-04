# Architecture

Relaticle is a modular monolith: the CRM core lives in `app/`, and self-contained
subsystems live in `packages/<Name>/` with the `Relaticle\<Name>` namespace
(autoloaded from `packages/<Name>/src` via the root composer.json). Packages have
no composer.json of their own. Service providers are registered in
`bootstrap/providers.php`.

| Location | Owns |
|---|---|
| `app/` | CRM domain: models, actions, Filament app panel, API, MCP server |
| `packages/Chat` | AI assistant (agents, chat tools, credit system, streaming) |
| `packages/SystemAdmin` | Internal admin panel (separate Filament panel) |
| `packages/ImportWizard` | CSV import flows |
| `packages/Documentation` | Public docs pages |
| `packages/OnboardSeed` | Demo/onboarding data seeding |
| `packages/EmailIntegration` | Email + calendar sync (Gmail/Microsoft Graph), sharing, privacy |

Create a new package only for a genuinely separable subsystem with its own panel,
routes, or lifecycle. A new CRM entity is not one of those; it goes in `app/`. Package
anatomy mirrors a Laravel app: `src/`, `config/`, `routes/`, `resources/`,
`database/`.

## Module boundaries (enforced by tests/Arch/ArchTest.php)

- `App` must not depend on `Relaticle\SystemAdmin`; `Relaticle\SystemAdmin` may
  only reach back into `App\Models`, `App\Enums`, `App\Rules`
- `packages/EmailIntegration` owns its controllers, jobs, policies, views, config and
  timeline entries. `App\Http`, `App\Jobs`, `App\Policies`, `App\ActivityLog` and
  `App\Console` must not use it. The app reaches it only from CRM resources, models,
  onboarding and panel wiring, and anything it exposes to them checks the feature flag
- Never use the custom-fields package models directly. Use the `App\Models\CustomField*`
  subclasses (runtime model swapping is configured in `AppServiceProvider`)
- `packages/SystemAdmin` is excluded from PHPStan. When adding or removing enum
  cases, manually sweep SystemAdmin for `match` expressions over that enum (this
  exclusion already caused a production `UnhandledMatchError`)

## Actions (the write path)

Write operations (create, update, delete) that reach the domain from a transport
surface go through action classes in `app/Actions/<Domain>/`. Never inline business
logic in controllers, MCP tools, Livewire components, or Filament resources.
Actions are the single source of truth for business logic and side effects
(notifications, syncs, etc.).

The canonical shape is `final readonly`, with a single `execute()` method and
authorization plus tenant-ownership checks inside the action itself:

```php
final readonly class CreateOpportunity
{
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Opportunity
    {
        abort_unless($user->can('create', Opportunity::class), 403);

        TenantFkValidator::assertOwned($user, $data, [...]);
        // ...
    }
}
```

- Enforced by `EloquentWriteOutsideActionRule` (PHPStan): Eloquent writes in
  controllers, MCP tools, Livewire components, Filament classes, or chat tools
  fail analysis. Pre-existing violations are grandfathered per-file in
  `phpstan.neon`. When you refactor one, remove its ignore entry
- Filament CRUD may use native `CreateAction`/`EditAction` when the operation is a
  plain `Model::create()`/`->update()` with no extra logic. Side effects
  (e.g., notifications) must still be triggered via `->after()` hooks calling the
  appropriate action
- An action exists to keep business logic out of transport surfaces and to share one
  write between callers. A console command is neither: it has no `$user`, so the
  canonical `abort_unless` plus `assertOwned` shape does not apply. Give a command an
  action when a second caller shares the write, otherwise the logic lives in `handle()`
- When reviewing or refactoring code, extract inline business logic into action classes
- Use `App\Data` (spatie/laravel-data) objects for structured payloads where they
  already exist; don't introduce new patterns
- Name domain concepts plainly (`Plan`, not `AiPlan`). Context comes from the
  namespace

## One fact, one owner

A fact more than one surface publishes gets an owner class, and every surface reads it.
The working examples: `CustomFieldFilterSchema` owns filter operators,
`App\Mcp\Schema\CustomFieldSchema` plus `CustomFieldType::inputFormat()` own how a
custom field is described to an agent, `CrmEntity::titleColumn()` owns the name column.
Facts that are a pure function of an enum case (a label, a format, a capability) live on
the enum, never in a private `match` inside a consumer.

The measurement that produced this rule: of four cross-surface axes, the two with an
owner class had zero drift and the two without had three, including a company owner the
REST API silently dropped while MCP and chat both wrote it.

`tests/Feature/CRM/SurfaceParityTest.php` is the gate for the surfaces that still carry
a per-entity copy: API form request, MCP tool, chat tool, MCP schema resource. Adding a
writable field or a relation include to one of them fails that test until the others
follow.

A query predicate over one model's columns has one owner too: a `#[Scope]` on that model,
such as `AgentConversation::ownedBy()` or `AgentConversationMessage::typed()`. Every
reader goes through it. A `DB::table` reader that wants plain rows keeps them with
`Model::query()->ownedBy($user)->toBase()` and never copies the `where` clauses. Readers
that each wrote their own filter drifted: `sentBy()` skipped the typed check and tagged
users `has-ai-usage` who had never typed. Scoped queries take no table alias, because
`whereKey()` and `whereRelation()` qualify columns with the table name, which Postgres
rejects under an alias. `tests/Arch/ConventionsTest.php` fails when a public method
outside a model, enum, or `Scope` class takes a query builder.

## Business language

One word per business concept. The model class owns the word, and code, tests and copy use it.

| Concept | Word | Retired |
|---|---|---|
| The tenant | workspace (`App\Models\Workspace`) | team |
| A user's place in a workspace | member (`App\Models\Membership`, `WorkspaceRole`) | the `editor` role key |
| A business the workspace tracks | company (`App\Models\Company`) | account as the record's name |
| A human the workspace tracks | person, people (`App\Models\People`) | contact as the record's name |
| A sale in progress | opportunity (`App\Models\Opportunity`) | deal as a label or as the record's name |

`team` survives in three places. Jetstream's own contract and event names keep it
(`AddsTeamMembers`, `TeamMemberAdded`). `teammate` is a person, not the tenant. A company's
team in the email composer (`company_team`) means the people at a CRM company. `tenant` is
the custom-fields package's word and stays at that boundary: `TenantContextService`, and the
`team` relation name the package registers. Stripe objects already carry a `team_id` metadata
key, so billing still writes and reads it.

The model is `People` and the singular is person. `contact` survives where it names something
else. The opportunity's `contact` relation carries the "Point of Contact" label. "Contact us"
and the contact form are a verb and a page. On the email privacy page, contacts are addresses
and domains. `deal` stays the plain word in prose ("track a deal through the pipeline") and
never labels the record. Agent-facing text keeps both retired words as synonyms, so a model
maps the user's word to the right tool: tool descriptions, prompts and schema resources.

`tests/Arch/ConventionsTest.php` fails an identifier that uses `team` for the tenant, and any
use of the retired `editor` role key. It fails a bare `Contact` or `Deal` label anywhere in
source. It fails `contacts` and `a contact` in published copy: `lang/`, `resources/views/`,
`resources/js/` and each package's `resources/`. The gate reads no other form of the two
words, so a reviewer does.

`account` names the sign-in and a connected mailbox, never a company. The company's
"Account Owner" field keeps the word, and so does its `account_owner_id` column. No test can
tell these senses apart, so a reviewer reads for a company called an account.

## i18n enforcement

Two custom PHPStan rules (`app/PHPStan/Rules/`) forbid hardcoded user-facing
strings: `HardcodedUserFacingStringRule` (guarded methods like `label()`,
`heading()`, `title()`) and `HardcodedStaticPropertyRule` (guarded static
properties like `$navigationLabel`). Wrap user-facing strings in `__()`.
Some paths are deferred via explicit ignores in `phpstan.neon`. Don't add new
ignores without approval.
