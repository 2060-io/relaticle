# SysAdmin Overview: one page that answers four questions

Date: 2026-10-01. Branch: `ManukMinasyan/registered-user-activation-workflow`. Ships as one PR.

Status: the premium lock and true per-call cost (formerly "slice 1") are built and reviewed on
this branch (commits `ec6670659` to `6783e3096`). Everything else below is still to build.

## Problem

The SystemAdmin panel has two dashboards and ten widgets, yet it cannot answer the questions a
founder asks every week:

- **Money.** `AiSpendStatsWidget` prices `ai_credit_transactions.input_tokens`, which hold only
  the uncached remainder. Cache reads and writes, most of a prompt, are never priced. Measured on
  a production snapshot for one month, the widget showed about one sixth of the list-price cost.
  There is no MRR, no view of provider bills, and no view of what unused trial credits could
  still cost.
- **Value.** The activation rate counts one own record, but most workspaces that create one never
  come back. Nothing shows repeat use or cohort retention, which every early-stage metrics source
  ranks first.
- **Sales.** No list says which non-paying workspaces use the product for real.
- **Problems.** Trial farming (signups using the Pro trial's premium models as a free chatbot)
  looks like growth in every signup and activation number, and there is no way to see or stop it.

## Goal

One SystemAdmin page, **Overview**, with four stacked rows. Each row is a plain question with three
or four numbers. Every number states what it counts, turns green, amber or red by a stated rule,
and opens the list behind it. Suspected abuse and internal workspaces are left out of every number
except the abuse row.

Alongside it: premium models stay locked during a Pro trial until the workspace adds its own data
(built), every model call carries its real list-price cost (built, completed here for cancelled
turns), and two measurement hooks record where new users stop (wizard step, setup exit reason).

## Decisions

1. **One page replaces two dashboards.** `Overview` becomes the panel's home page. The old
   `Dashboard` and `EngagementDashboard`, their ten widgets and those widgets' tests are deleted
   in this PR, after `Overview` exists. Rejected: adding widgets to the existing dashboards (the
   reason they fail today is that nothing on them is a sentence).
2. **Real cost lives on the ledger row** (built). `ai_credit_transactions` carries
   `cache_read_tokens`, `cache_write_tokens` and `cost_micros` (integer micro-dollars at list
   price, null when a price is missing), written where usage is already in hand.
3. **No cost backfill.** Cost history starts at deploy. For the first partial month the AI cost
   tile reads "since <date>". The analytics clone answers historical questions.
4. **Four prices per catalog model** (built). Gemini's cheapest model gets a disabled, unpriced
   catalog entry like Anthropic's and OpenAI's, so its title and suggestion calls can be priced
   once an admin enters prices.
5. **Billed cost comes from the provider cost APIs.** A daily command reads the Anthropic cost
   report and the OpenAI costs endpoint, keeps only Relaticle's Anthropic workspace and OpenAI
   project, and stores one row per provider per day. Gemini has no cost API without a BigQuery
   export, so it shows our estimate. The budget tile compares billed cost with a monthly budget
   the admin types in. With no admin key, the tile falls back to our estimate and says so. The
   "AI cost this month" tile stays on our estimate, because only the estimate can say which
   workspace spent it. Rejected: estimate only (misses spend we do not log), manual entry
   (stale), billed cost as the headline (cannot split by workspace).
6. **"End trial now" sets `trial_ends_at` to now.** `BillingStatus` then reads `TrialEnded` and
   `HostedWorkspaceAccess` pauses the workspace at once: the app, chat, API and MCP stop, and the
   paused screen offers the plan choice and checkout. The nightly `billing:process-trials` run
   moves the plan to Free, resets credits and sends the standard "trial ended" email. This is
   the same pay-to-unlock gate every ended trial gets. Rejected: an immediate full downgrade
   (duplicates the nightly logic), locking premium models only (too soft for a flagged
   workspace), blocking the owner's account (they could never reach the pay screen, and a wrong
   flag would lock a genuine buyer out of every workspace).
7. **The premium lock has one owner: `Relaticle\Chat\Services\ModelAccess`** (built).
8. **The own-data predicate becomes a scope.** `HasCreator` gains `#[Scope] ownData()`
   (`creation_source` is not `system`). Metrics use it on the five CRM models.
   `WorkspaceActivationFacts` keeps its single-query union for speed.
9. **Weeks are UTC, starting Monday**, matching the analytics clone.
10. **Exit reasons come from the setup nudge mail, confirmed on a page.** The day-2 nudge mail gets
    four reason links. A link opens a signed page with that reason preselected and one Send button
    (POST). Rejected: recording on the GET, because mail security scanners open links first.
11. **A workspace is internal when its owner's email matches a SysAdmin's email.** No setup:
    on production today this catches all six of the founder's workspaces, including the paying
    one. It matches the owner, never a member, so a customer workspace a SysAdmin joins to help
    is never hidden. Rejected: a per-workspace switch (new test workspaces pollute the numbers
    until flipped), an ID list in `.env` (needs a deploy per change).
12. **MRR is what each customer actually paid.** For each active subscription on a non-internal
    workspace, MRR uses the latest invoice's `total_excluding_tax`, divided by the price's billing
    period in months. Stripe is read once per subscription per day (cached). Rejected: list
    prices (wrong under the 50%-off promo), amounts in config (a second copy of a Stripe fact).
13. **Cancelled turns get a real cost.** When the user presses Stop, the stream still runs to the
    end, so its usage is known. The ledger records the resolved model, its tokens and its cost;
    the user is still charged the one-credit minimum. A turn that dies from a provider error has
    no usage and stays `incomplete` with no cost. Rejected: charging full credits for a stopped
    answer, really stopping generation (a separate streaming change).
14. **Every click opens an existing SysAdmin resource, already filtered.** Filament 5 list pages
    bind filters and sort to the URL (`?filters[...]`, `?sort=`), so tiles link to the Users,
    Workspaces, AI credit balances, Subscriptions and Chat feedback lists with a filter applied.
    Rejected: new drill-down pages (more surface for the same table).
15. **Predicates that span tables are `Scope` classes.** `tests/Arch/ConventionsTest.php` allows a
    public method to take a query builder only on a model, an enum, or a class implementing
    `Illuminate\Database\Eloquent\Scope`. SystemAdmin may not add scopes to `App\Models` that
    read SystemAdmin data, so each shared predicate is a `Scope` class in
    `Relaticle\SystemAdmin\Metrics\Scopes`, applied with `->withGlobalScope(Name::class, new Name)`
    on a query. Tile numbers and filters use the same class, so they cannot disagree.

## Shared definitions

| Term | Definition | Owner |
|---|---|---|
| Internal workspace | Its owner's canonical email equals a `system_administrators.email` (lower-cased) | `Scopes\InternalWorkspace` (and its inverse `Scopes\ExternalWorkspace`) |
| Own data | A company, person, opportunity, task or note whose `creation_source` is not `system` | `HasCreator::ownData()` scope |
| Active day | A day on which a user created own data or sent a typed chat message (`AgentConversationMessage::typed()`) | `Metrics\ActivityDays` |
| Abuse suspect | A `Trialing` workspace with no own data, and either at least half its chat credits spent on models whose `min_plan` is above Free, or an owner timezone in `system-admin.abuse_timezones` | `Scopes\AbuseSuspect` |
| Genuine signup | Verified; not an invited teammate (no membership in someone else's workspace within 24 hours of signing up); owns no abuse-suspect and no internal workspace | `Scopes\GenuineSignup` |
| First value | A genuine signup who created own data within 7 days of signing up | `Scopes\ReachedFirstValue` |
| Habit | A non-internal workspace with an active day in at least 3 of the last 4 complete weeks | `Scopes\FormedHabit` |
| Stuck after setup | A genuine owner's personal workspace, 3 to 30 days old, with no own data and no typed chat message in its first 3 days | `Scopes\StuckAfterSetup` |
| Cost per credit | `sum(cost_micros) / sum(credits_charged)` over chat rows of the last 30 days that carry a cost | `Metrics\AiCost` |

The organic rule moves from `FunnelWidget::countOrganicSignups()` into `Scopes\GenuineSignup`
before the widget is deleted. `system-admin.abuse_timezones` is a package config list
(`packages/SystemAdmin/config/system-admin.php`, merged by the panel provider) of IANA zones,
defaulting to Iran's and Russia's, overridable with `SYSTEM_ADMIN_ABUSE_TIMEZONES`.

Known gap in the abuse rule: with the premium lock live, a new farmer can no longer spend on
premium models without own data, so the premium arm stops firing for new trials and the rule
rests on the timezone arm. A farmer elsewhere chatting off-topic on a free model is not caught,
and credit volume cannot separate them (on the production snapshot both groups spent under 20
credits). Detecting off-topic chat is out of scope.

## The page

`Relaticle\SystemAdmin\Filament\Pages\Overview` extends Filament's dashboard page, is the panel's
home, takes one column, and holds five lazy widgets in this order: `MoneyStats`, `ValueStats`,
`CohortTable`, `SalesLeads`, `ProblemsStats`. The stats widgets are `StatsOverviewWidget`s whose
heading is the row's question; each `Stat` carries its value, a one-line description (delta and
short context), its color, a hover tooltip with the definition, and a `url()` to the filtered
list. Numbers are cached for ten minutes under a shared version key; a **Refresh** header action
bumps the version. A number with no data shows the empty-value placeholder used elsewhere in
SystemAdmin and a one-line reason.

### Row 1: Are we making money? (`MoneyStats`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| MRR | Sum over active subscriptions (Cashier `active()`, past due included) on external workspaces of the latest invoice's `total_excluding_tax`, divided by the billing period in months. Delta vs one week earlier (subscriptions active at that time, same amounts) | Green when MRR covers this month's AI cost, red otherwise | Subscriptions list, filter "Counts toward MRR" |
| AI cost this month | `sum(cost_micros)` this calendar month, all ledger types. Delta vs last month. Footnote with the count of unpriced rows when above zero | Same rule as MRR | Workspaces list sorted by a new "AI cost this month" column |
| Provider budget left | Per provider: monthly budget minus billed cost this month (our estimate when that provider has no admin key). Shows the total and the lowest provider | Amber under 50%, red under 20%, grey with no budget set | AI settings page, new "This month" section: budget, billed, estimate, difference, last fetch |
| Unused trial credits | `credits_remaining` over `Trialing` workspaces, times cost per credit | Red when above the total budget left, otherwise neutral | AI credit balances, filter "Trialing", sorted by credits used |

With the Billing feature off (self-hosted), the MRR and trial tiles are hidden.

### Row 2: Is anyone getting value? (`ValueStats`, `CohortTable`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| Real signups | Genuine signups in the latest complete week at least 7 days old. Delta vs the week before | Neutral, with arrow | Users list, filters "Genuine signup" and that week |
| Reached first value | First-value share of that same week | Red under 20%, amber under 30%, green from 30% | Same list plus filter "Reached first value" |
| Formed a habit | Habit workspaces now. Delta vs one week earlier | Green up, amber flat, red down | Workspaces list, filter "Formed a habit" |

`CohortTable` renders the last six signup weeks (genuine signups): size, then the share with an
active day in the signup week and each of the next three weeks. A week that has not happened yet
shows a dot, never 0%.

### Row 3: Who should I talk to next? (`SalesLeads`)

A `TableWidget` of up to ten external, non-paying, non-suspect workspaces with own data, not
marked contacted in the last 14 days, ordered by active days in the last 30 days, then own
records.

| Column | Content |
|---|---|
| Workspace | Name, linking to the SysAdmin workspace view |
| Why | Own records, active days (30d), teammates, and "uses API", "uses MCP" or "imported" when present |
| Plan | `BillingStatus` label |
| Last active | Latest active day |
| Actions | **Open as user** (`Impersonate::workspaceOwner()`), **Email owner** (`mailto:`), **Contacted** (sets `workspaces.sales_contacted_at`) |

The workspace view gains a **Journey** section: signup date and method (password or the Socialite
provider), wizard answers, last wizard step reached, first own record date, active days (30d),
typed chat messages, credits used and AI cost this month, billing status, setup exit reason, and
"Internal" when it applies.

### Row 4: What's going wrong? (`ProblemsStats`)

| Tile | Value | Color rule | Opens |
|---|---|---|---|
| Trial abuse suspects | Count of abuse suspects | Red when any suspect spent credits in the last 7 days, green at zero | Workspaces list, filter "Abuse suspect"; **End trial now** is a row action, a bulk action and a view-page action there |
| Stuck after setup | Stuck share of genuine owners whose workspace is 3 to 30 days old | Red from 60%, amber from 40% | Workspaces list, filter "Stuck after setup", with the exit-reason column shown |
| Left the setup wizard | Share of genuine verified signups of the last 30 days with no workspace, split by password and Socialite provider | Amber from 10% | Users list, filters "Genuine signup", "No workspace" and "Signed up" covering the last 30 days, with the signup-method and last-wizard-step columns shown |
| Thumbs down this week | `chat_message_feedback` rated down this week | Green 0, amber 1 to 2, red from 3 | Chat feedback list, `?filters[rating][value]=down` |

With the Billing feature off, the abuse tile is hidden.

## Resource additions (the filtered lists)

| Resource | Adds |
|---|---|
| Users | Filters: Genuine signup, Signed up in week (date range), Reached first value, No workspace. Columns: Signup method, Last wizard step |
| Workspaces | Filters: Internal, Abuse suspect, Formed a habit, Stuck after setup. Columns: AI cost this month (sortable subquery), Setup exit reason, Contacted at. Actions: End trial now (row, bulk, view page), Contacted (view page) |
| AI credit balances | Filter: Trialing |
| Subscriptions | Filter: Counts toward MRR (active, external workspace) |
| AI settings page | Budgets per provider; a read-only "This month" section |

## Product changes

### Premium lock during the trial (built)

`ChatController` answers `model_not_allowed` with "Add your own records to unlock premium models
during your trial." for a model the workspace's own plan allows; the picker shows the same
sentence from the same snapshot as the gate; the lock lifts on the first own record.

### Ledger cost (built, plus cancelled turns)

- `CreditService::settleReservation()` records cache tokens and `cost_micros` via
  `Relaticle\Chat\Services\TokenCost::micros(string $model, int $uncachedInput, int $cacheRead, int $cacheWrite, int $output): ?int`.
- Titles and suggestions write `AiCreditType::Internal` rows with zero credits through
  `CreditService::recordInternalUsage()`, naming the requested cheapest model, wrapped in
  `rescue()`.
- New: `CreditService::settleReservedMinimum()` takes an optional model and `TextUsage`. The
  cancel branch of `ProcessChatMessage` passes the resolved model and `$response->usage`; the row
  then names that model and carries its tokens and cost, with `credits_charged` still the reserved
  minimum and `metadata.reason` still `cancelled`. The provider-error branch passes neither and
  stays `incomplete` with a null cost.

### Setup exit reason

- `SetupNudgeMail` renders four links: "Just looking around", "Missing something I need", "Too
  hard to get started", "Chose another tool" (a backed enum `App\Enums\SetupExitReason`, labels
  in `lang/en/mail.php`).
- A signed route on the primary host, mirroring the unsubscribe flow
  (`UnsubscribeController`, `<x-layouts::filament-standalone>`), shows the reason preselected, an
  optional note (max 500 characters) and Send. The POST stores `workspaces.setup_exit_reason`,
  `setup_exit_note` and `setup_exit_reason_at` through an action; a second answer overwrites the
  first. The GET stores nothing.

### Wizard step

Steps 1 and 2 of `CreateWorkspace` record their name (`workspace`, `attribution`) in
`users.onboarding_step` from an `afterValidation` callback, through an action. Finishing step 3
creates the workspace, which is its own record. Existing step keys are not changed (tests depend
on the `onboarding-use-case` prefix).

## Schema

All additive, `up()` only. No column a queued job reads is dropped, so no Horizon pause is needed.

| Table | Columns |
|---|---|
| `ai_credit_transactions` | `cache_read_tokens`, `cache_write_tokens`, `cost_micros` (built) |
| `ai_provider_costs` (new) | `id`, `provider` string(32), `date` date, `amount_micros` bigint, `fetched_at` timestamp; unique (`provider`, `date`) |
| `workspaces` | `sales_contacted_at` timestamp null, `setup_exit_reason` string(32) null, `setup_exit_note` string(500) null, `setup_exit_reason_at` timestamp null |
| `users` | `onboarding_step` string(32) null |

Settings: `ChatSettings` gains `provider_monthly_budgets` (map of provider to whole dollars,
default empty) through a settings migration. Config: `services.anthropic.admin_key`,
`services.anthropic.workspace_id` (null means the default workspace),
`services.openai.admin_key`, `services.openai.project_id`; `ai.providers.gemini.models.text.cheapest`.

## Provider cost sync

`ai:sync-provider-costs` (Chat package) runs daily at 06:00 UTC from `bootstrap/app.php`. For each
provider with an admin key it fetches the last 7 days (costs settle late) and upserts one row per
(`provider`, `date`):

- Anthropic: `GET https://api.anthropic.com/v1/organizations/cost_report` with `x-api-key` and
  `anthropic-version: 2023-06-01`, `starting_at`/`ending_at` (RFC 3339), `bucket_width=1d`,
  `group_by[]=workspace_id`, following `next_page` while `has_more`. Keep results whose
  `workspace_id` equals the configured one (null for the default workspace). `amount` is a decimal
  string in cents: micros = cents x 10,000.
- OpenAI: `GET https://api.openai.com/v1/organization/costs` with `Authorization: Bearer`,
  `start_time`/`end_time` (Unix seconds), `bucket_width=1d`, `project_ids[]` when configured,
  following `next_page`. `amount.value` is in dollars: micros = dollars x 1,000,000.

A provider with no key is skipped with a comment line. An HTTP failure logs a warning, keeps the
stored rows and moves on to the next provider, so one outage never stops the other. The budget
tile's footnote names the last fetch date.

## Error handling

- Stripe unreachable: the MRR tile shows the placeholder with "Stripe unavailable"; the rest of
  the row renders. A subscription with no latest invoice counts as zero.
- A catalog model without prices: its rows store a null cost and the AI cost tile counts them in a
  footnote. `ManageAiSettings` refuses to enable an unpriced model.
- Cost per credit with fewer than 100 priced credits in 30 days: the unused-trial-credits tile
  shows the placeholder with "Not enough priced usage yet".
- "End trial now" on a workspace that is not trialing: the action is hidden, and the action class
  re-checks and refuses.
- An exit-reason link with a bad signature or an unknown reason: 403 or 404.

## Build order (one PR)

1. Complete slice 1: commit the spec alignment, price cancelled turns, Gemini's cheapest model.
2. Shared predicates: `ownData()`, the `Scopes` classes, `Metrics\ActivityDays`, `Metrics\AiCost`.
3. Money row: `Metrics\Revenue` (Stripe), provider cost table, sync command, budgets, the AI
   settings "This month" section, `MoneyStats`, the Overview page as home.
4. Problems row: abuse filter, End trial now, the wizard-step and exit-reason hooks,
   `ProblemsStats`.
5. Sales row: `SalesLeads`, Contacted, the Journey section.
6. Value row: `ValueStats`, `CohortTable`, the Users filters.
7. Delete the old dashboards, their ten widgets and tests; full gates; browser walk.

## Testing

All through real entry points, per `.ai/rules/relaticle/testing`. Never set `chat.models` before a
test's first database touch (it seeds that catalog into the test database).

- `tests/Feature/SystemAdmin/Overview/*Test.php`, one file per row: each tile's value, color and
  link against factory data that includes one of each trap (an invited teammate, an internal
  workspace owned by a SysAdmin's email, an abuse suspect, sample data only, a week not yet
  complete).
- Each new resource filter returns exactly the rows its tile counts (same `Scope` class).
- MRR with Stripe faked through `Stripe\ApiRequestor::setHttpClient()` (the pattern in
  `SubscriptionTransferActionTest`): a yearly and a monthly subscription, a 50%-off invoice, an
  internal workspace excluded, Stripe failing.
- Provider sync with `Http::fake()`: Anthropic pagination and workspace filtering, OpenAI project
  filtering, cents and dollars converted to micros, a provider without a key skipped, a 500 on one
  provider not stopping the other, re-runs upserting.
- Cancelled turn: the row names the resolved model with tokens and cost and one credit charged;
  the provider-error path stays `incomplete` with a null cost.
- End trial now: the workspace reads `TrialEnded` and is paused immediately; hidden and refused
  for a paying workspace.
- Exit reason: the GET stores nothing, the POST stores the reason, a tampered URL gets 403.
- `CreateWorkspace`: finishing steps 1 and 2 stores their names.
- Architecture: the new classes pass `ArchTest` and `ConventionsTest`.
- Browser: walk the Overview in agent-browser, light and dark, with data and with an empty
  database; click every tile through to its filtered list; run End trial now on a test workspace.

## Out of scope

- Fixing the onboarding drop-offs. A separate investigation prompt lives at
  `.context/onboarding-investigation-prompt.md`; this PR only adds the measurement hooks.
- Region blocking (a legal question), card-required trials, daily credit drip, off-topic chat
  detection, really stopping generation on Stop.
- A PMF survey, session recordings, a weekly email digest, revenue beyond MRR, runway.
- Updating the analytics toolkit's `metrics.md`, which names `FunnelWidget` as the owner of the
  organic rule; it follows this PR.
