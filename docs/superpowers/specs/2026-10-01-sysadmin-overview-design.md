# SysAdmin Overview: one page that answers four questions

Date: 2026-10-01. Branch: `ManukMinasyan/registered-user-activation-workflow`.

## Problem

The SystemAdmin panel has two dashboards and ten widgets, yet it cannot answer the questions a
founder asks every week:

- **Money.** `AiSpendStatsWidget` prices `ai_credit_transactions.input_tokens`, which hold only
  the uncached remainder. Cache reads and writes, most of a prompt, are never priced. One catalog
  model has no price at all and is costed at zero. Conversation titles and next-step suggestions
  call a model but write no ledger row. Measured on a production snapshot for one month, the
  widget showed about one sixth of the list-price cost. There is no MRR, no view of provider
  bills, and no view of what unused trial credits could still cost.
- **Value.** The activation rate counts one own record, but most workspaces that create one never
  come back. Nothing shows repeat use or cohort retention, which every early-stage metrics source
  ranks first.
- **Sales.** No list says which non-paying workspaces use the product for real.
- **Problems.** Trial farming (signups using the Pro trial's premium models as a free chatbot)
  looks like growth in every signup and activation number, and there is no way to see or stop it.

## Goal

One SystemAdmin page, **Overview**, with four stacked rows. Each row is a plain question with three
or four numbers. Every number states what it counts, turns green, amber or red by a stated rule,
and opens the list behind it. Every list row has at most three buttons. Suspected abuse is left
out of every number except the abuse row.

A product change ships alongside it: during a Pro trial, premium models stay locked until the
workspace adds its own data.

## Decisions

1. **One page replaces two dashboards.** `Overview` becomes the panel's default page. The old
   `Dashboard` and `EngagementDashboard` and their ten widgets are deleted in the last slice, so
   nothing disappears before its replacement ships. Rejected: adding widgets to the existing
   dashboards (the reason they fail today is that nothing on them is a sentence).
2. **Real cost lives on the ledger row.** `ai_credit_transactions` gains `cache_read_tokens`,
   `cache_write_tokens` and `cost_micros` (integer micro-dollars at list price, null when a price
   is missing). The row is written where usage is already in hand. Rejected: pricing
   `agent_conversation_messages.usage` at read time (titles and suggestions have no message
   row, and it puts a pricing formula inside a dashboard).
3. **No cost backfill.** Cost history starts at deploy. For the first partial month the tile reads
   "since <date>". Rejected: matching old ledger rows to assistant messages by conversation and
   time. It is approximate, and the analytics clone already answers historical questions.
4. **Four prices per catalog model.** Catalog entries gain `cache_read_per_mtok` and
   `cache_write_per_mtok` next to the input and output prices. `ManageAiSettings` requires all
   four for every enabled cloud model (zero is allowed). A settings migration fills the two new
   prices on existing entries from each provider's published ratio. Rejected: a private
   provider-to-multiplier map inside the cost code (a second owner of a price fact).
5. **Billed cost comes from provider cost APIs, per provider project.** A daily command reads the
   Anthropic cost report and the OpenAI costs endpoint, filtered to the configured Anthropic
   workspace and OpenAI project, and stores one row per provider per day. Google has no cost API
   without a BigQuery export, so Gemini shows the ledger estimate only. No provider exposes a
   prepaid balance, so "budget left" is a monthly budget the admin types in, minus billed cost.
6. **"End trial now" sets `trial_ends_at` to now.** `BillingStatus` then reads `TrialEnded` and
   `HostedWorkspaceAccess` pauses the workspace at once. The nightly `billing:process-trials` run
   reverts the plan, resets credits and sends the usual email. SystemAdmin may only use
   `App\Models`, `App\Enums` and `App\Rules` (`tests/Arch/ArchTest.php`), so it cannot call an
   `App\Actions` class. The action lives in `Relaticle\SystemAdmin\Actions`. Rejected: a new
   suspension state (a concept the product does not need yet).
7. **The premium lock has one owner: `Relaticle\Chat\Services\ModelAccess`.** Its
   `planFor(Workspace): Plan` returns `Plan::Free` while the workspace's billing status is
   `Trialing` and it holds no own record, otherwise `$workspace->plan`. Its
   `lockReason(Workspace, ModelDescriptor): ?string` returns `trial_setup` or `plan`. Every reader
   of `$workspace->plan` for model access switches to it. Today there are four:
   `AiModelResolver::resolve()`, `AiModelResolver::failoverNext()`, the `ChatController` model
   check, and `_model-state.blade.php`. `auto` then resolves to the first free-tier model for a
   locked trial.
8. **The own-data predicate becomes a scope.** `HasCreator` gains
   `#[Scope] ownData()` (`creation_source` is not `system`). SystemAdmin metrics use it on the five
   CRM models. `WorkspaceActivationFacts` keeps its single-query union for speed.
9. **Weeks are UTC, starting Monday.** Every "this week vs last week" comparison uses them, matching
   the analytics clone.
10. **Exit reasons come from the setup nudge mail, confirmed on a page.** The day-2 nudge mail gets
    four reason links. A link opens a signed page with that reason preselected and one Send button
    (POST). Rejected: recording on the GET, because mail security scanners open links before the
    person does.

## Shared definitions

Each definition has one owner. SystemAdmin owners are readonly classes in
`Relaticle\SystemAdmin\Metrics`.

| Term | Definition | Owner |
|---|---|---|
| Internal workspace | Listed in `system-admin.internal_workspace_ids` | package config |
| Own data | A company, person, opportunity, task or note whose `creation_source` is not `system` | `HasCreator::ownData()` scope |
| Active day | A day on which a user created own data or sent a typed chat message (`AgentConversationMessage::typed()`) | `Metrics\ActivityDays` |
| Abuse suspect | A workspace in `Trialing` with no own data, and either at least half its chat credits spent on models whose `min_plan` is above Free, or an owner timezone in `system-admin.abuse_timezones` | `Metrics\AbuseSuspects` |
| Genuine signup | Verified, not an invited teammate (the existing organic rule: no membership in someone else's workspace within 24 hours), owner of no abuse-suspect workspace, owner of no internal workspace | `Metrics\Signups` |
| First value | A genuine signup who created own data within 7 days of signing up | `Metrics\Signups` |
| Habit | A non-internal workspace with an active day in at least 3 of the last 4 complete weeks | `Metrics\ActivityDays` |
| Cost per credit | `sum(cost_micros) / sum(credits_charged)` over chat rows of the last 30 days that carry a cost | `Metrics\AiCost` |

The organic rule moves from `FunnelWidget::countOrganicSignups()` into `Metrics\Signups` before the
widget is deleted.

Known gap in the abuse rule: once the premium lock ships, a new farmer can no longer spend on
premium models without own data, so the premium arm stops firing for new trials and the rule rests
on the timezone arm. A farmer outside those timezones chatting off-topic on a free model is not
caught, and credit volume cannot separate them: on the production snapshot, genuine owners without
own data and farmers both spent under 20 credits. Detecting off-topic chat is out of scope.

## The page

`Relaticle\SystemAdmin\Filament\Pages\Overview`, four rows, each a lazy Livewire widget whose numbers
are cached for ten minutes per week. A **Refresh** header action clears the cache. Colors use
Filament's success, warning and danger. A number with no data shows the empty-value placeholder
used elsewhere in SystemAdmin, plus a one-line reason ("Add the Anthropic admin key").

### Row 1: Are we making money?

| Tile | Value | Color rule | Click opens |
|---|---|---|---|
| MRR | Monthly amount of active subscriptions (Cashier `active()`, past due included) on non-internal workspaces, yearly prices divided by 12. Amounts come from the Stripe price, fetched once per price id and cached for a day. Delta vs last week | Green when MRR covers this month's AI cost, red otherwise | Paying workspaces: plan, interval, next renewal |
| AI cost this month | `sum(cost_micros)` this calendar month, all ledger types. Delta vs last month. Footnote with the count of unpriced rows when above zero | Same rule as MRR | Workspaces by AI cost this month, with own records created |
| Provider budget left | Per provider: monthly budget minus billed cost this month. The tile shows the total and the lowest provider | Amber under 50%, red under 20%, grey with no budget set | Per provider: budget, billed, our estimate, difference |
| Unused trial credits | `credits_remaining` summed over `Trialing` workspaces, times cost per credit | Red when above the total budget left, otherwise neutral | Trials by credits spent, with own records and suspect flag |

With the Billing feature off (self-hosted), the MRR and trial tiles are hidden.

### Row 2: Is anyone getting value?

| Tile | Value | Color rule | Click opens |
|---|---|---|---|
| Real signups | Genuine signups in the latest complete week at least 7 days old. Delta vs the week before | Neutral, with arrow | Those signups: signup method, workspace created, first value, active days |
| Reached first value | First value share of that same week | Red under 20%, amber under 30%, green from 30% | Same list, filtered |
| Formed a habit | Habit workspaces now. Delta vs one week earlier | Green up, amber flat, red down | Those workspaces |

Below the tiles, a cohort table: the last six signup weeks (genuine signups), size, and the share
with an active day in the signup week and each of the next three weeks. A week that has not
happened yet shows a dot, never 0%.

### Row 3: Who should I talk to next?

A table of up to ten non-paying, non-internal, non-suspect workspaces with own data, not marked
contacted in the last 14 days, ordered by active days in the last 30 days, then own records.

| Column | Content |
|---|---|
| Workspace | Name, linking to the SystemAdmin workspace view |
| Why | Built from signals: own records, active days (30d), teammates, and "uses API", "uses MCP" or "imported" when present |
| Plan | `BillingStatus` label |
| Last active | Latest active day |
| Actions | **Open as user** (`Impersonate::workspaceOwner()`), **Email owner** (`mailto:`), **Contacted** (sets `workspaces.sales_contacted_at`) |

The workspace view gains a **Journey** section: signup date and method (password or the Socialite
provider), wizard answers, the last wizard step reached, first own record date, active days (30d),
typed chat messages, credits used and AI cost this month, billing status, setup exit reason.

### Row 4: What's going wrong?

| Tile | Value | Color rule | Click opens |
|---|---|---|---|
| Trial abuse suspects | Count of abuse suspects | Red when any suspect spent credits in the last 7 days, green at zero | Suspects: owner timezone, credits spent, premium share, typed messages. Action **End trial now** with a confirmation |
| Stuck after setup | Share of genuine owners whose workspace is 3 to 30 days old with no own data and no typed chat in its first 3 days | Red from 60%, amber from 40% | Those owners, with last wizard step and exit reason |
| Left the setup wizard | Share of genuine verified signups of the last 30 days with no workspace, split by password and Socialite provider | Amber from 10% | Counts by last wizard step reached |
| Thumbs down this week | `chat_message_feedback` rated down this week | Green 0, amber 1 to 2, red from 3 | The existing feedback resource, filtered |

## Product changes

### Premium lock during the trial (decision 7)

- `ChatController` returns the existing `model_not_allowed` error. When the reason is
  `trial_setup` the message reads "Add your own records to unlock premium models during your
  trial." and `upgrade_available` is false.
- The model picker shows the same sentence on locked premium models instead of the upgrade hint.
- The lock lifts as soon as the workspace creates its first own record, through any surface.
- `ModelAccess` memoizes the own-record check per request.

### Ledger cost (decisions 2 and 4)

- `CreditService::settleReservation()` and `deduct()` take `cacheReadTokens` and
  `cacheWriteTokens`. `ProcessChatMessage` passes `cacheReadInputTokens` and
  `cacheWriteInputTokens` from the usage it already holds.
- `Relaticle\Chat\Support\TokenCost::micros(CatalogEntry $entry, int $uncachedInput, int $cacheRead, int $cacheWrite, int $output): ?int`
  prices one call. It returns null when any needed price is null.
- `GenerateConversationTitle` and `SuggestNextSteps` write a ledger row of a new type,
  `AiCreditType::Internal`, with zero credits and the call's cost. The balance is untouched.
  Adding the case means sweeping SystemAdmin `match` expressions over `AiCreditType`, because
  PHPStan skips that package.

### Setup exit reason (decision 10)

- `SetupNudgeMail` renders four links: "Just looking around", "Missing something I need", "Too
  hard to get started", "Chose another tool". Labels live in `lang/en/mail.php`.
- A signed route shows the page with the reason preselected, an optional note (max 500 characters)
  and Send. The POST stores `workspaces.setup_exit_reason` (a new backed enum `SetupExitReason`),
  `setup_exit_note` and `setup_exit_reason_at`. A second answer overwrites the first.
- The signed URL is built for the app host, because signatures are host-bound.

### Wizard step (Row 4)

`CreateWorkspace` stores the key of each step the user completes in `users.onboarding_step`
(`workspace`, `attribution`, `use-case`) from the step's `afterValidation` callback. Each step
gets an explicit `->key()`.

## Schema

All additive, `up()` only. No column a queued job reads is dropped, so no Horizon pause is needed.

| Table | Columns |
|---|---|
| `ai_credit_transactions` | `cache_read_tokens` int default 0, `cache_write_tokens` int default 0, `cost_micros` bigint null |
| `ai_provider_costs` (new) | `id`, `provider` string, `date` date, `amount_micros` bigint, `fetched_at` timestamp; unique (`provider`, `date`) |
| `workspaces` | `sales_contacted_at` timestamp null, `setup_exit_reason` string null, `setup_exit_note` string(500) null, `setup_exit_reason_at` timestamp null |
| `users` | `onboarding_step` string null |

Settings: `ChatSettings` gains `provider_monthly_budgets` (map of provider to USD). A settings
migration adds the two cache prices to every stored catalog entry.

Config: `services.anthropic.admin_key`, `services.anthropic.workspace_id`,
`services.openai.admin_key`, `services.openai.project_id`; package config
`system-admin.internal_workspace_ids` and `system-admin.abuse_timezones`, both env-driven lists.

## Provider cost sync

`ai:sync-provider-costs` runs daily at 06:00 UTC from `bootstrap/app.php`. It fetches the last
7 days per configured provider (costs settle late) and upserts on (`provider`, `date`). A provider
with no admin key is skipped with a comment line. An HTTP failure logs a warning and leaves the
stored rows. The budget tile then shows the last fetch date in its footnote. It never throws, so
one provider's outage cannot stop the others.

## Error handling

- Stripe price fetch fails: the MRR tile shows the empty-value placeholder with "Stripe
  unavailable", the rest of the row renders.
- A catalog model without prices: its ledger rows store null cost and the AI cost tile counts them
  in its footnote. `ManageAiSettings` validation stops new ones from being enabled.
- Cost per credit with fewer than 100 priced credits in 30 days: the unused-trial-credits tile
  shows the empty-value placeholder with "Not enough priced usage yet".
- "End trial now" on a workspace that is no longer trialing: the action is hidden. The server
  re-checks and refuses with a notification.

## Slices

Each slice is one PR and leaves the panel working.

1. **Stop the bleed, make cost true.** Premium lock (`ModelAccess`, four call sites, picker and
   error copy), ledger cost columns and `TokenCost`, `Internal` ledger rows for titles and
   suggestions, four catalog prices with validation and the settings migration.
2. **Overview with Money and Problems.** The page as the panel default, rows 1 and 4 (without the
   wizard-step and exit-reason detail), `Metrics\AiCost`, `Metrics\AbuseSuspects`, End trial now,
   provider cost sync, budgets, MRR.
3. **Sales.** Row 3, `sales_contacted_at`, the Journey section.
4. **Value, and the rest of Problems.** Row 2 with the cohort table, `Metrics\Signups` and
   `Metrics\ActivityDays`, wizard step, exit reasons, then delete the old dashboards, their ten
   widgets and those widgets' tests.

## Testing

All through real entry points, per `.ai/rules/relaticle/testing`.

- `tests/Feature/SystemAdmin/OverviewTest.php`: each tile's value, color and click target against
  factory data that includes one of each trap (an invited teammate, an internal workspace, an abuse
  suspect, sample data only, a week not yet complete).
- `tests/Feature/Chat/`: a trialing workspace without own data gets `model_not_allowed` with the
  trial reason for a premium model and resolves `auto` to a free model. The same workspace gets the
  premium model after creating one own record. A paid Pro workspace is unaffected.
- Settlement writes cache tokens and `cost_micros`. A title and a suggestion each write one
  `Internal` row with zero credits. A model with a null price writes a null cost.
- `ManageAiSettingsTest`: enabling a model without all four prices fails validation.
- Provider sync with `Http::fake()`: upserts, skips a provider without a key, survives a 500.
- End trial now: status reads `TrialEnded` immediately and the workspace is paused. The action is
  hidden for a paying workspace.
- Setup exit reason: the GET stores nothing. The POST stores the reason. An unsigned or tampered
  URL gets a 403.
- `CreateWorkspace`: completing each step stores its key.
- Browser: walk the Overview in agent-browser, light and dark, including an empty database. Walk
  the picker lock as a new trial user.

## Out of scope

- Region blocking. Whether to serve regions the AI providers do not support is a legal question.
- Card-required trials, daily credit drip, off-topic chat detection.
- A PMF survey, session recordings, a weekly email digest.
- Revenue beyond MRR (credit-pack cash, runway).
- Updating the analytics toolkit's `metrics.md`, which names `FunnelWidget` as the owner of the
  organic rule. It moves to `Metrics\Signups` in slice 4 and the doc follows.
