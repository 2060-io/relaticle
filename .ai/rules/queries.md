---
paths:
  - 'app/Queries/**'
  - 'app/Actions/*/List*.php'
  - 'app/Mcp/Tools/BaseListTool.php'
  - 'packages/Chat/src/Tools/BaseReadListTool.php'
---

# Queries: the filter and sort language

One filter language answers a list question the same way on the REST API, MCP and chat.
It lives in `app/Queries`. The design record is
`docs/superpowers/specs/2026-10-02-crm-filter-language-design.md`.

## Where a file goes

The layout follows spatie/laravel-query-builder's own `src/`.

| Kind of class | Folder |
|---|---|
| implements Spatie's `Filter` | `app/Queries/Filters` |
| implements Spatie's `Sort` | `app/Queries/Sorts` |
| a trait those classes share | `app/Queries/Concerns` |
| registry, tree validation, vocabulary | `app/Queries` |
| an enum | `app/Enums` |

`tests/Arch/ArchTest.php` fails a class in `Filters` or `Sorts` without its interface, and a
`Filter` or `Sort` anywhere else in `app/Queries`. `tests/Arch/ConventionsTest.php` fails one
without the `Filter` or `Sort` suffix. The Pest Laravel preset fails an enum outside
`app/Enums`.

The folders name roles, not layers. `EntityFilters` builds the classes in `Filters`, and they
use `Operand` and `FilterErrors` from the root.

## The language imports no transport

`app/Queries` never uses `App\Mcp`, `App\Http`, `App\Filament`, `App\Livewire`, `App\Scribe`
or `Relaticle\Chat`. The surfaces import it. The arch test `the query language uses no
transport` fails the reverse.

A new surface gets its adapter on its own side. A panel filter lives under `app/Filament` and
imports `App\Queries`.

## One owner per fact

- `EntityFilters::definitions()` owns the filter names each entity accepts. A list action
  never registers an `AllowedFilter` of its own.
- `CustomFieldFilterSchema::operatorsForType()` owns the operators per field type. A native
  field takes the operators of the custom field type it maps to.
- `FilterTree` owns the limits: `MAX_CONDITIONS`, `MAX_LOGIC_DEPTH`, `MAX_HOPS`.
- `FilterVocabulary` builds what one workspace can filter on, and MCP and chat render it. The
  API docs render `EntityFilters::grammar()`, which needs no workspace.

Never write a filter name, an operator or a limit by hand in a tool description or a docs
page. `tests/Feature/CRM/SurfaceParityTest.php` fails a surface that drifts: "publishes
exactly the filter names the list action accepts for each entity" and "states every filter
limit from the constants on every surface".

## Adding to it

- **A filter name.** Add one line to `EntityFilters::definitions()`. Every surface picks it up.
- **A kind of condition.** Add a `FilterKind` case and a class in `Filters`. PHPStan fails a
  `match` over `FilterKind` that has no `default` arm and misses the case.
  `FilterDefinition::operand()`, `NativeFilter` and `FilterTree::walk()` branch on the kind
  without that guard, so read them by hand.
- **An operator.** Add it to `operatorsForType()` and compile it in
  `CustomFieldFilter::applyCondition()`. A native field shares the type's operators, so
  compile it in `NativeFilter` too: its `text()` treats every operator but `$contains` as
  equality. `SurfaceParityTest` fails until the MCP description names it ("names every custom
  field filter operator in the mcp list tool description").
- **A filter parameter on a list tool or endpoint.** Do not add one. A list takes filters only
  as the `filter` tree. `FilterTree::rejectUnknownArguments()` rejects a flat parameter, and
  `FilterTree::REPLACED` names the tree form of each retired one. `ListToolFilterTest` is the
  gate ("rejects an argument a list tool does not take instead of listing every record").
- **A caller that is not a list action.** None exists yet. Give it a `#[Scope]` in
  `app/Models/Concerns` that runs `FilterTree::validate()` and applies the registry. Never
  copy `where` clauses into the caller. `tests/Arch/ConventionsTest.php` fails a public
  method outside a model, enum or `Scope` that takes a query builder.

## What the SQL must keep

- Pass the acting `User` in. Never read `auth()` inside `app/Queries`: chat list tools run in
  queued jobs. Every subquery bounds itself to `$user->currentWorkspace`.
  `ListFilterSurfacesTest` is the gate ("never reaches another workspace through a relation
  or a member id on the api and mcp").
- `$not` is a set complement: `NOT EXISTS` over the matching keys. It never compiles as SQL
  `NOT (...)`, which drops a row whose column is null. `$not_in` and `$has_none` follow the
  same rule. `ListFilterTest` is the gate ("returns records with an empty value under $not").
- A scoped query takes no table alias. `whereKey()` and `whereRelation()` qualify columns
  with the table name, which Postgres rejects under an alias.

## Errors

A rejection is a `ValidationException` from `FilterErrors::at()`, keyed by the path of the
node to fix. Its message lives under `validation.filter.*` or `validation.custom_field.*` in
`lang/en/validation.php` and names the fix, because an agent acts on the message it reads.

`FilterTree::validate()` runs before the query builder is built. Several checks exist in both
the pre-pass and the filter classes. Change both, or the same mistake returns two different
errors. `tests/Feature/Api/V1/ListFilterTest.php` pins the keys and messages.
