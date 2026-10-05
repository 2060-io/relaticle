# Queries Namespace Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `sdd-lean` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the filter and sort language out of `app/Support/Filters` and `app/Mcp/Schema` into its own root, `app/Queries`, laid out like spatie/laravel-query-builder's `src/`, with no behavior change.

**Architecture:** Registries, tree validation and vocabulary sit at the root of `app/Queries`. Classes that implement Spatie's `Filter` go in `Filters/`, `Sort` classes in `Sorts/`, shared traits in `Concerns/`. Arch tests pin each folder to its role and forbid the namespace from importing any transport.

**Tech Stack:** PHP 8.5, Laravel 12, spatie/laravel-query-builder 7, Pest 4 with pest-plugin-arch, PHPStan (Larastan), Pint, Rector, Laravel Boost.

**Spec:** No separate spec. The design decisions are recorded below. Task 4 amends the Components section of `docs/superpowers/specs/2026-10-02-crm-filter-language-design.md`. Sources and measurements: `~/.claude/research/laravel-filter-engine-architecture-sources-2026-10.md`.

**Status:** Written 2026-10-05 and revised the same day to run inside PR #906. Re-verified against commit `757c37974`: 15 engine files, the same sibling table, every edit anchor present. Not executed: waiting for Manuk's go-ahead. `.gitignore` ignores `/docs/*`, so Task 4 force-adds this file, as earlier plans were.

**Approval:** The Boost foundation rule forbids a new base folder without approval. Manuk approved this one on 2026-10-05 by choosing "Option 2: own root, Spatie's role folders" and asking for this plan. On the same day Manuk confirmed the `app/Queries` root name and the cache class rename, and asked for the work to land inside PR #906.

## Design decisions

1. **Root name `app/Queries`.** Spatie's own app layout is the precedent. `spatie/mailcoach-ui` keeps `src/Http/App/{Controllers,Queries,Requests}`, with `Queries/UsersQuery.php` importing `Spatie\Mailcoach\Http\App\Queries\Filters\FuzzyFilter`. Mailcoach nests it under `Http` because one transport reads it. Here REST, MCP, chat jobs and later the panel read it, so it sits at the `app` root.
2. **Role folders from the package.** `vendor/spatie/laravel-query-builder/src` keeps `AllowedFilter.php` at the root beside `Filters/`, `Sorts/` and `Concerns/`. This plan mirrors those three.
3. **Two folders are not mirrored.** The package also has `Enums/` and `Exceptions/`. The Pest Laravel preset fails an enum outside `App\Enums` and a `Throwable` outside `App\Exceptions`, so `FilterKind` stays in `app/Enums` and no exception class is added.
4. **Folders name roles, not layers.** `EntityFilters` at the root builds the classes in `Filters/`, and those classes use `Operand` and `FilterErrors` from the root. Spatie's package has the same shape: `AllowedFilter` imports `Filters\FiltersGroup`, which imports `AllowedFilter`. No test claims a direction between the root and `Filters/`.
5. **`CustomFieldFilterSchema` moves whole.** No split and no operator enum here. That is a separate change. `.ai/guidelines/relaticle/architecture.md` names the class without a namespace, so the owner sentence stays true.
6. **The cache key owner is renamed, not split.** `App\Mcp\Schema\McpSchemaCache` becomes `App\Support\CustomFields\CustomFieldSchemaCache`. Both keys describe custom fields, one class keeps owning both, and no method body changes. Splitting the keys between two classes would break the rule in its own docblock: "Nothing else may spell these keys".
7. **The move happens inside PR #906.** `App\Support\Filters` is new in that PR, so `main` never sees the old namespace and no later branch has to rename again. Ten of the files this plan touches are not already in the PR's diff: `McpSchemaCache.php`, `tests/Arch/ConventionsTest.php`, the new `.ai/rules/queries.md`, two rule files, two guideline sources and the three compiled guideline files.
8. **The rules travel with the code.** No guideline describes the filter language today beyond one sentence. Task 4 adds `.ai/rules/queries.md`, which Boost routes to anyone editing `app/Queries`, a list action or a list tool. It states where a file goes, who owns each fact, how to add a filter, an operator or a caller, and which test fails for each rule.
9. **The pull request page ends with one body and one review comment.** The review tooling posts a fresh comment at each new head, so #906 holds two. Task 7 rewrites the body for the final head, folds both reviews into comment `5997703573`, and deletes `5984058674`. It recomputes no verdict, and it marks which commits no full review pass covered.

## Target layout

```
app/Queries/
├── CustomFieldFilterSchema.php    from app/Mcp/Schema
├── EntityFilters.php              from app/Support/Filters
├── FilterDefinition.php           from app/Support/Filters
├── FilterErrors.php               from app/Support/Filters
├── FilterTree.php                 from app/Support/Filters
├── FilterVocabulary.php           from app/Support/Filters
├── Operand.php                    from app/Support/Filters
├── TreeAllowedFilter.php          from app/Support/Filters
├── Concerns/
│   └── AppliesFilterNodes.php     from app/Support/Filters
├── Filters/
│   ├── AssignedToMeFilter.php     from app/Support/Filters
│   ├── CustomFieldFilter.php      from app/Support/Filters
│   ├── LogicFilter.php            from app/Support/Filters
│   ├── NativeFilter.php           from app/Support/Filters
│   ├── RelationFilter.php         from app/Support/Filters
│   └── StaleDaysFilter.php        from app/Support/Filters
└── Sorts/
    └── CustomFieldSort.php        from app/Support/Filters

app/Support/CustomFields/CustomFieldSchemaCache.php   from app/Mcp/Schema/McpSchemaCache.php
tests/Feature/CRM/ListFilterSurfacesTest.php          from tests/Feature/Mcp/Filters
```

The folder rule for any later file: a class that implements Spatie's `Filter` goes in `Filters/`, a `Sort` in `Sorts/`, a trait in `Concerns/`, everything else at the root.

## Global Constraints

- Behavior-neutral. In a moved file only `namespace` lines, `use` lines, a renamed class name and one docblock sentence change. No method body changes.
- Runs on `feat/crm-filter-language`, inside PR #906. Start only when Manuk says go and `git status` is clean: another session has been committing to this branch from this same directory.
- Conductor pushes each commit to PR #906 as it is made. Every commit must be green on its own, so run the task's whole check list before committing.
- Every PHP file starts with `declare(strict_types=1);`. Classes are `final` (`final readonly` where possible). All parameters and returns typed.
- Enums live only in `app/Enums`. Exceptions live only in `app/Exceptions`.
- One negated arch expectation covers one layer. `tests/Arch/ConventionsTest.php` fails the multi-layer form.
- A new arch check is proven by a planted violation before it is trusted.
- No new PHPStan ignore. If an i18n rule fires on a moved class, stop and report.
- No comments narrating the diff. No comments in tests. Docblocks carry types only.
- Never an em-dash (U+2014) in code, copy, commits or docs.
- Guideline sources are `.ai/guidelines/relaticle/*.md`. After an edit run `php artisan boost:update`, then `cp AGENTS.md GEMINI.md`.
- Commits: conventional, lowercase subject under 72 characters, present tense, no AI attribution. Run `git branch --show-current` before each commit.
- Text that leaves the repo (the PR body, a PR comment) is shown as a draft first. It is published only on an explicit "post" given after the draft. No AI attribution and no competitor names in it.
- Tests are scoped. Never run `composer test:pest`, `composer test:pest:full` or `composer test:type-coverage` locally. CI's `Tests` workflow is the suite gate.
- If PHP reports a missing class right after a `git mv` while the file exists, retry with `php -d opcache.enable_cli=0` before debugging. Herd's CLI opcache file cache can hold a stale unit.

## Review Focus

1. **Engine edits another session made after this plan was written.** A person expects every engine file and every reference to move. Task 1 Step 1 stops on a dirty tree, compares the directory to the 15 names, and recomputes the sibling references.
2. **A sibling reference with no `use` line.** Classes in one namespace reference each other without imports, and the split breaks that silently until the class loads. Task 1 Step 5 lists all 27 imports. Task 1 Step 9 and Task 3 Step 8 run PHPStan, which fails on a miss.
3. **The i18n path ignore that `CustomFieldFilterSchema` loses.** `phpstan.neon` ignores `app.i18n.hardcodedUserFacingString` under `app/Mcp/*`. Task 3 Step 8 runs PHPStan on the class at its new path and stops on a hit.
4. **A changed cache key or TTL.** A person expects a custom field edit to keep invalidating sorts and schemas. Task 3 Step 7 proves the rename touched no key literal and no TTL.
5. **A transport reference the arch test cannot see.** A class name inside a string or `resolve()` call passes `toUse`. Task 3 Step 9 greps for it.

---

### Task 1: Move the engine to `app/Queries`

**Files:**
- Move: 15 files from `app/Support/Filters/` (Step 2)
- Modify: `app/Actions/Company/ListCompanies.php`, `app/Actions/Note/ListNotes.php`, `app/Actions/Opportunity/ListOpportunities.php`, `app/Actions/People/ListPeople.php`, `app/Actions/Task/ListTasks.php`, `app/Concerns/PaginatesListQuery.php`, `app/Http/Requests/Api/V1/IndexRequest.php`, `app/Mcp/Schema/CustomFieldFilterSchema.php`, `app/Mcp/Schema/CustomFieldSchema.php`, `app/Mcp/Tools/BaseListTool.php`, `app/Scribe/Strategies/GetFilterBodyFromEntityFilters.php`, `app/Scribe/Strategies/GetFromSpatieQueryBuilder.php`, `app/Support/CustomFields/CustomFieldInput.php`
- Modify: `packages/Chat/src/Agents/CrmAssistant.php`, `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php`, `packages/Chat/src/Tools/BaseReadListTool.php`
- Modify: `tests/Arch/ArchTest.php:144,216`, `tests/Feature/Api/V1/ListFilterTest.php`, `tests/Feature/CRM/SurfaceParityTest.php`, `tests/Feature/Chat/CrmAssistantInstructionsTest.php`, `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php`, `tests/Feature/Documentation/ApiDocumentationGenerationTest.php`, `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`, `tests/Feature/Mcp/Filters/CustomFieldSortTest.php`, `tests/Feature/Mcp/Filters/ListFilterSurfacesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: namespaces `App\Queries`, `App\Queries\Filters`, `App\Queries\Sorts`, `App\Queries\Concerns`. Class names are unchanged.

- [ ] **Step 1: Preflight**

```bash
git branch --show-current
git status --short | grep -v '^!!'
git fetch origin feat/crm-filter-language
git rev-list --left-right --count origin/feat/crm-filter-language...HEAD
ls app/Support/Filters
```

Expected: `feat/crm-filter-language`, then no output from `git status`, then `0	0`, then exactly these 15 names:

```
AppliesFilterNodes.php  AssignedToMeFilter.php  CustomFieldFilter.php  CustomFieldSort.php
EntityFilters.php  FilterDefinition.php  FilterErrors.php  FilterTree.php
FilterVocabulary.php  LogicFilter.php  NativeFilter.php  Operand.php
RelationFilter.php  StaleDaysFilter.php  TreeAllowedFilter.php
```

If `git status` prints a modified file, stop. Another session is editing this branch from this directory, and a move would collide with it. If a name is extra, place it by the folder rule, add it to Steps 2 to 5, and say so in the commit body. If a name is missing, stop and report.

Then recompute which sibling classes each file references, because the engine changed after this plan was written:

```bash
(cd app/Support/Filters && for f in *.php; do self="${f%.php}"; refs=""; for c in AppliesFilterNodes AssignedToMeFilter CustomFieldFilter CustomFieldSort EntityFilters FilterDefinition FilterErrors FilterTree FilterVocabulary LogicFilter NativeFilter Operand RelationFilter StaleDaysFilter TreeAllowedFilter; do [ "$c" = "$self" ] && continue; grep -v "^use \|^namespace " "$f" | grep -qw "$c" && refs="$refs $c"; done; echo "$self ->$refs"; done)
```

Expected, as measured on 2026-10-05:

```
AppliesFilterNodes -> FilterErrors
AssignedToMeFilter -> FilterErrors Operand
CustomFieldFilter -> FilterErrors Operand
CustomFieldSort ->
EntityFilters -> AssignedToMeFilter CustomFieldFilter FilterDefinition FilterTree LogicFilter NativeFilter RelationFilter StaleDaysFilter TreeAllowedFilter
FilterDefinition -> AssignedToMeFilter StaleDaysFilter
FilterErrors ->
FilterTree -> EntityFilters FilterDefinition FilterErrors LogicFilter
FilterVocabulary -> EntityFilters
LogicFilter -> AppliesFilterNodes EntityFilters FilterErrors
NativeFilter -> FilterDefinition FilterErrors Operand
Operand -> FilterErrors
RelationFilter -> AppliesFilterNodes EntityFilters FilterDefinition FilterErrors LogicFilter Operand
StaleDaysFilter -> FilterErrors Operand
TreeAllowedFilter -> FilterErrors
```

If a line differs, adjust the table in Step 5: a file needs a `use` line for every class it references that lands in a different folder.

- [ ] **Step 2: Move the files**

```bash
mkdir -p app/Queries/Filters app/Queries/Sorts app/Queries/Concerns
for f in EntityFilters FilterDefinition FilterErrors FilterTree FilterVocabulary Operand TreeAllowedFilter; do git mv "app/Support/Filters/$f.php" "app/Queries/$f.php"; done
for f in AssignedToMeFilter CustomFieldFilter LogicFilter NativeFilter RelationFilter StaleDaysFilter; do git mv "app/Support/Filters/$f.php" "app/Queries/Filters/$f.php"; done
git mv app/Support/Filters/CustomFieldSort.php app/Queries/Sorts/CustomFieldSort.php
git mv app/Support/Filters/AppliesFilterNodes.php app/Queries/Concerns/AppliesFilterNodes.php
rmdir app/Support/Filters
```

Expected: `rmdir` succeeds, which proves the old folder is empty.

- [ ] **Step 3: Rewrite the namespace declarations**

```bash
perl -pi -e 's/^namespace App\\Support\\Filters;/namespace App\\Queries;/' app/Queries/*.php
perl -pi -e 's/^namespace App\\Support\\Filters;/namespace App\\Queries\\Filters;/' app/Queries/Filters/*.php
perl -pi -e 's/^namespace App\\Support\\Filters;/namespace App\\Queries\\Sorts;/' app/Queries/Sorts/*.php
perl -pi -e 's/^namespace App\\Support\\Filters;/namespace App\\Queries\\Concerns;/' app/Queries/Concerns/*.php
grep -rh "^namespace" app/Queries | sort | uniq -c
```

Expected:

```
   7 namespace App\Queries;
   1 namespace App\Queries\Concerns;
   6 namespace App\Queries\Filters;
   1 namespace App\Queries\Sorts;
```

- [ ] **Step 4: Rewrite every class reference**

```bash
perl -pi -e '
  s/App\\Support\\Filters\\(AssignedToMeFilter|CustomFieldFilter|LogicFilter|NativeFilter|RelationFilter|StaleDaysFilter)\b/App\\Queries\\Filters\\$1/g;
  s/App\\Support\\Filters\\CustomFieldSort\b/App\\Queries\\Sorts\\CustomFieldSort/g;
  s/App\\Support\\Filters\\AppliesFilterNodes\b/App\\Queries\\Concerns\\AppliesFilterNodes/g;
  s/App\\Support\\Filters\\/App\\Queries\\/g;
' $(grep -rl 'App\\Support\\Filters' app packages tests)
grep -rn 'Support\\Filters\|Support/Filters' app packages tests config routes bootstrap lang
```

Expected: the last command prints nothing. A dry run on 2026-10-05, at commit `757c37974`, rewrote 61 lines, including both `TreeAllowedFilter` strings in `tests/Arch/ArchTest.php`.

- [ ] **Step 5: Add the 27 imports the split requires**

Add these lines to each file's existing `use` block. Order does not matter, Pint sorts them in Step 6.

| File | Add |
|---|---|
| `app/Queries/EntityFilters.php` | `use App\Queries\Filters\AssignedToMeFilter;` `use App\Queries\Filters\CustomFieldFilter;` `use App\Queries\Filters\LogicFilter;` `use App\Queries\Filters\NativeFilter;` `use App\Queries\Filters\RelationFilter;` `use App\Queries\Filters\StaleDaysFilter;` |
| `app/Queries/FilterDefinition.php` | `use App\Queries\Filters\AssignedToMeFilter;` `use App\Queries\Filters\StaleDaysFilter;` |
| `app/Queries/FilterTree.php` | `use App\Queries\Filters\LogicFilter;` |
| `app/Queries/Concerns/AppliesFilterNodes.php` | `use App\Queries\FilterErrors;` |
| `app/Queries/Filters/AssignedToMeFilter.php` | `use App\Queries\FilterErrors;` `use App\Queries\Operand;` |
| `app/Queries/Filters/CustomFieldFilter.php` | `use App\Queries\FilterErrors;` `use App\Queries\Operand;` |
| `app/Queries/Filters/LogicFilter.php` | `use App\Queries\Concerns\AppliesFilterNodes;` `use App\Queries\EntityFilters;` `use App\Queries\FilterErrors;` |
| `app/Queries/Filters/NativeFilter.php` | `use App\Queries\FilterDefinition;` `use App\Queries\FilterErrors;` `use App\Queries\Operand;` |
| `app/Queries/Filters/RelationFilter.php` | `use App\Queries\Concerns\AppliesFilterNodes;` `use App\Queries\EntityFilters;` `use App\Queries\FilterDefinition;` `use App\Queries\FilterErrors;` `use App\Queries\Operand;` |
| `app/Queries/Filters/StaleDaysFilter.php` | `use App\Queries\FilterErrors;` `use App\Queries\Operand;` |

`FilterDefinition.php` needs its two imports for the docblock `class-string<StaleDaysFilter|AssignedToMeFilter>` as well as for the code.

- [ ] **Step 6: Format and refresh the autoloader**

```bash
vendor/bin/pint --dirty --format agent
composer dump-autoload
```

Expected: Pint reports the touched files as fixed or passing. An import Pint removes was unused, which is correct.

- [ ] **Step 7: Prove the move changed no logic**

```bash
git add -A app packages tests
git diff --cached -M --stat -- app/Queries app/Support | tail -18
git diff --cached -M -- app/Queries app/Support/Filters | grep '^[+-]' | grep -v '^[+-]\{3\} ' | grep -v '^[+-]\(namespace\|use\) ' | grep -v '^[+-]$'
```

Expected: the stat lists 15 renames. The last command prints nothing, so only `namespace` and `use` lines differ.

- [ ] **Step 8: Run the arch suite**

Run: `composer test:arch`
Expected: PASS. The readonly and inheritance rules pass only because Step 4 rewrote both `TreeAllowedFilter` entries in their ignore lists.

- [ ] **Step 9: Run PHPStan, Rector and the lint check**

```bash
vendor/bin/phpstan analyse
vendor/bin/rector --dry-run
composer test:lint
```

Expected: `[OK] No errors` from PHPStan, no suggested change from Rector, and a passing lint. An unknown-class error names a file that misses an import from Step 5. Add it and re-run. Conductor pushes the commit in Step 11 straight to PR #906, so all three must pass first.

- [ ] **Step 10: Run the scoped tests**

```bash
php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Feature/Mcp/Filters tests/Feature/Mcp/SchemaResourcesTest.php tests/Feature/Chat/ListToolFilterTest.php tests/Feature/Chat/ListDateFilterTest.php tests/Feature/Chat/CrmAssistantInstructionsTest.php tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php tests/Feature/CRM/SurfaceParityTest.php tests/Feature/Documentation/ApiDocumentationGenerationTest.php
```

Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git branch --show-current
git add -A app packages tests
git commit -m "refactor(filters): move the filter language to app/Queries"
```

Expected: the branch is `feat/crm-filter-language`.

---

### Task 2: Pin each folder to its role

**Files:**
- Modify: `tests/Arch/ArchTest.php` (two imports near line 27, four tests after the block that ends at line 323)
- Modify: `tests/Arch/ConventionsTest.php` (the `$suffixes` map near line 931)

**Interfaces:**
- Consumes: the namespaces from Task 1.
- Produces: arch tests named `query filters implement the query builder filter contract`, `query sorts implement the query builder sort contract`, `a query filter lives in the filters folder`, `a query sort lives in the sorts folder`.

- [ ] **Step 1: Add the imports to `tests/Arch/ArchTest.php`**

After `use Relaticle\EmailIntegration\Filament\RelationManagers\BaseMeetingsRelationManager;` add:

```php
use Spatie\QueryBuilder\Filters\Filter;
use Spatie\QueryBuilder\Sorts\Sort;
```

- [ ] **Step 2: Add the four role tests**

Insert after the `arch('email integration owns its controllers, jobs, policies and timeline entries')` block:

```php
arch('query filters implement the query builder filter contract')
    ->expect('App\Queries\Filters')
    ->toImplement(Filter::class);

arch('query sorts implement the query builder sort contract')
    ->expect('App\Queries\Sorts')
    ->toImplement(Sort::class);

arch('a query filter lives in the filters folder')
    ->expect('App\Queries')
    ->not
    ->toImplement(Filter::class)
    ->ignoring('App\Queries\Filters');

arch('a query sort lives in the sorts folder')
    ->expect('App\Queries')
    ->not
    ->toImplement(Sort::class)
    ->ignoring('App\Queries\Sorts');
```

- [ ] **Step 3: Add both folders to the suffix map in `tests/Arch/ConventionsTest.php`**

In the `$suffixes` array of `it('keeps the role suffix on classes whose directory carries one')`, after `'Policies' => 'Policy',` add:

```php
        'Queries/Filters' => 'Filter',
        'Queries/Sorts' => 'Sort',
```

- [ ] **Step 4: Run the arch suite**

Run: `composer test:arch`
Expected: PASS. The folders already comply, so this run proves nothing yet.

- [ ] **Step 5: Plant four violations**

Create `app/Queries/Filters/Planted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries\Filters;

final readonly class Planted {}
```

Create `app/Queries/Sorts/Planted.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries\Sorts;

final readonly class Planted {}
```

Create `app/Queries/PlantedFilter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Model>
 */
final readonly class PlantedFilter implements Filter
{
    public function __invoke(Builder $query, mixed $value, string $property): void {}
}
```

Create `app/Queries/PlantedSort.php`:

```php
<?php

declare(strict_types=1);

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * @implements Sort<Model>
 */
final readonly class PlantedSort implements Sort
{
    public function __invoke(Builder $query, bool $descending, string $property): void {}
}
```

- [ ] **Step 6: Run the arch suite and watch all five checks fail**

Run: `composer test:arch`
Expected: FAIL. These five tests must be among the failures:

- `query filters implement the query builder filter contract` names `App\Queries\Filters\Planted`
- `query sorts implement the query builder sort contract` names `App\Queries\Sorts\Planted`
- `a query filter lives in the filters folder` names `App\Queries\PlantedFilter`
- `a query sort lives in the sorts folder` names `App\Queries\PlantedSort`
- `keeps the role suffix on classes whose directory carries one` names both `Planted.php` files

A test in this list that passes is vacuous. Fix it before continuing.

- [ ] **Step 7: Remove the planted files**

```bash
rm app/Queries/Filters/Planted.php app/Queries/Sorts/Planted.php app/Queries/PlantedFilter.php app/Queries/PlantedSort.php
composer dump-autoload
composer test:arch
git status --short
```

Expected: PASS, and `git status` lists only `tests/Arch/ArchTest.php` and `tests/Arch/ConventionsTest.php`.

- [ ] **Step 8: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Arch/ArchTest.php tests/Arch/ConventionsTest.php
git commit -m "test(arch): pin query filters and sorts to their folders"
```

---

### Task 3: The query language imports no transport

**Files:**
- Modify: `tests/Arch/ArchTest.php` (one test after the four from Task 2)
- Move: `app/Mcp/Schema/CustomFieldFilterSchema.php` to `app/Queries/CustomFieldFilterSchema.php`
- Move and rename: `app/Mcp/Schema/McpSchemaCache.php` to `app/Support/CustomFields/CustomFieldSchemaCache.php`
- Modify: `app/Mcp/Schema/CustomFieldSchema.php`, `app/Mcp/Tools/BaseListTool.php`, `app/Mcp/Resources/CompanySchemaResource.php`, `app/Mcp/Resources/NoteSchemaResource.php`, `app/Mcp/Resources/OpportunitySchemaResource.php`, `app/Mcp/Resources/PeopleSchemaResource.php`, `app/Mcp/Resources/TaskSchemaResource.php`, `app/Providers/AppServiceProvider.php`, `app/Scribe/Strategies/GetFromSpatieQueryBuilder.php`, the five `app/Actions/*/List*.php` files
- Modify: `app/Queries/EntityFilters.php`, `app/Queries/FilterDefinition.php`, `app/Queries/FilterVocabulary.php`, `app/Queries/Operand.php`, `app/Queries/Filters/CustomFieldFilter.php`, `app/Queries/Filters/NativeFilter.php`
- Modify: `packages/Chat/src/Services/Tools/CustomFieldsFilterDescriber.php`, `packages/Chat/src/Tools/BaseReadListTool.php`
- Modify: `tests/Feature/CRM/SurfaceParityTest.php`, `tests/Feature/Chat/CrmAssistantInstructionsTest.php`, `tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php`, `tests/Feature/Documentation/ApiDocumentationGenerationTest.php`, `tests/Feature/Mcp/Filters/CustomFieldFilterTest.php`, `tests/Feature/Mcp/Filters/CustomFieldSortTest.php`, `tests/Feature/Mcp/Filters/ListFilterSurfacesTest.php`, `tests/Feature/Mcp/SchemaResourcesTest.php`

**Interfaces:**
- Consumes: `App\Queries` from Task 1.
- Produces: `App\Queries\CustomFieldFilterSchema` (same public API as today) and `App\Support\CustomFields\CustomFieldSchemaCache` with `TTL`, `entitySchemaKey(int|string $tenantId, string $entityType): string`, `filterSchemaKey(int|string $tenantId, string $entityType): string`, `forget(int|string $tenantId, string $entityType): void`, `forgetTenant(int|string $tenantId): void`.

- [ ] **Step 1: Write the failing test**

Insert into `tests/Arch/ArchTest.php` after `arch('a query sort lives in the sorts folder')`:

```php
arch('the query language uses no transport')
    ->expect('App\Queries')
    ->not
    ->toUse(['App\Mcp', 'App\Http', 'App\Filament', 'App\Livewire', 'App\Scribe', 'Relaticle\Chat']);
```

- [ ] **Step 2: Run it and watch it fail**

Run: `composer test:arch`
Expected: FAIL in `the query language uses no transport`. It names the six files that use `App\Mcp\Schema\CustomFieldFilterSchema`: `EntityFilters`, `FilterDefinition`, `FilterVocabulary`, `Operand`, `Filters\CustomFieldFilter`, `Filters\NativeFilter`.

- [ ] **Step 3: Move `CustomFieldFilterSchema`**

```bash
git mv app/Mcp/Schema/CustomFieldFilterSchema.php app/Queries/CustomFieldFilterSchema.php
perl -pi -e 's/^namespace App\\Mcp\\Schema;/namespace App\\Queries;/' app/Queries/CustomFieldFilterSchema.php
perl -pi -e 's/App\\Mcp\\Schema\\CustomFieldFilterSchema\b/App\\Queries\\CustomFieldFilterSchema/g' $(grep -rl 'App\\Mcp\\Schema\\CustomFieldFilterSchema' app packages tests)
```

- [ ] **Step 4: Move and rename the cache key owner**

```bash
git mv app/Mcp/Schema/McpSchemaCache.php app/Support/CustomFields/CustomFieldSchemaCache.php
perl -pi -e 's/^namespace App\\Mcp\\Schema;/namespace App\\Support\\CustomFields;/' app/Support/CustomFields/CustomFieldSchemaCache.php
perl -pi -e 's/App\\Mcp\\Schema\\McpSchemaCache\b/App\\Support\\CustomFields\\CustomFieldSchemaCache/g; s/\bMcpSchemaCache\b/CustomFieldSchemaCache/g' $(grep -rl 'McpSchemaCache' app packages tests)
```

In `app/Support/CustomFields/CustomFieldSchemaCache.php` replace the docblock sentence `Owns both per-tenant MCP schema cache keys.` with:

```
Owns both per-tenant custom field schema cache keys.
```

- [ ] **Step 5: Import the two former siblings**

Both classes were referenced without a `use` line by files that shared their namespace.

In `app/Mcp/Schema/CustomFieldSchema.php` add:

```php
use App\Queries\CustomFieldFilterSchema;
use App\Support\CustomFields\CustomFieldSchemaCache;
```

In `app/Queries/CustomFieldFilterSchema.php` add:

```php
use App\Support\CustomFields\CustomFieldSchemaCache;
```

- [ ] **Step 6: Format and refresh the autoloader**

```bash
vendor/bin/pint --dirty --format agent
composer dump-autoload
grep -n '^use App\\Queries\\[A-Za-z]*;' app/Queries/*.php
```

Expected: the `grep` prints nothing. A line it prints is an import of a class in the file's own namespace. Delete that line.

- [ ] **Step 7: Prove the rename changed no key and no TTL**

```bash
git add -A app packages tests
git diff --cached -M -- app/Queries/CustomFieldFilterSchema.php app/Support/CustomFields/CustomFieldSchemaCache.php app/Mcp/Schema/CustomFieldFilterSchema.php app/Mcp/Schema/McpSchemaCache.php | grep '^[+-]' | grep -v '^[+-]\{3\} ' | grep -v '^[+-]\(namespace\|use\) ' | grep -v '^[+-]$'
grep -n 'custom_fields_schema_\|custom_fields_filter_schema_\|TTL = 60' app/Support/CustomFields/CustomFieldSchemaCache.php
```

Expected from the first command, and nothing else:

- the class declaration, `McpSchemaCache` to `CustomFieldSchemaCache`
- the docblock sentence from Step 4
- the two lines in `CustomFieldFilterSchema.php` that call `McpSchemaCache::filterSchemaKey` and `McpSchemaCache::TTL`, now on `CustomFieldSchemaCache`

Expected from the second command: three lines, holding `custom_fields_schema_`, `custom_fields_filter_schema_` and `TTL = 60`.

- [ ] **Step 8: Run the arch suite, PHPStan, Rector and the lint check**

```bash
composer test:arch
vendor/bin/phpstan analyse
vendor/bin/rector --dry-run
composer test:lint
```

Expected: all four pass, including `the query language uses no transport`. If PHPStan reports `app.i18n.hardcodedUserFacingString` or `app.i18n.hardcodedStaticProperty` in `app/Queries/CustomFieldFilterSchema.php`, stop and report it. Never add an ignore.

- [ ] **Step 9: Grep for a transport the arch test cannot see**

```bash
grep -rnE 'App\\(Mcp|Http|Filament|Livewire|Scribe)|Relaticle\\Chat' app/Queries
```

Expected: nothing.

- [ ] **Step 10: Run the scoped tests**

```bash
php artisan test --compact tests/Feature/Api/V1/ListFilterTest.php tests/Feature/Mcp/Filters tests/Feature/Mcp/SchemaResourcesTest.php tests/Feature/Mcp/McpReadToolsTest.php tests/Feature/Chat/ListToolFilterTest.php tests/Feature/Chat/CrmAssistantInstructionsTest.php tests/Feature/Chat/CustomFieldsBridge/SchemaDescriberTest.php tests/Feature/CRM/SurfaceParityTest.php tests/Feature/Documentation/ApiDocumentationGenerationTest.php
```

Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git branch --show-current
git add -A app packages tests
git commit -m "refactor(filters): move operator and sort ownership out of mcp"
```

---

### Task 4: Guidelines, rules and the spec

Today no guideline describes the filter language beyond one sentence. This task puts the rules where a later change starts: an always-loaded bullet in `architecture.md`, and a path-scoped rule file that Boost routes to anyone editing `app/Queries`, a list action or a list tool.

**Files:**
- Create: `.ai/rules/queries.md`
- Modify: `.ai/rules/agent-surfaces.md` (front matter)
- Modify: `.ai/rules/index.md` (row 7, and one new row at the end)
- Modify: `.ai/guidelines/relaticle/architecture.md` (the `## Module boundaries` list, the `## One fact, one owner` paragraph)
- Modify: `.ai/guidelines/relaticle/core.md:160-162`
- Regenerate: `CLAUDE.md`, `AGENTS.md`, `GEMINI.md`
- Modify: `docs/superpowers/specs/2026-10-02-crm-filter-language-design.md:215,227`
- Add: `docs/superpowers/plans/2026-10-05-queries-namespace.md` (force-added)

**Interfaces:**
- Consumes: the class paths from Tasks 1 and 3, the test names from Tasks 2 and 3.
- Produces: nothing code depends on.

- [ ] **Step 1: Create `.ai/rules/queries.md`**

````markdown
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
````

- [ ] **Step 2: Prove every artifact the rule file names exists**

A rule that names a missing class or test is decoration, and another session changed this branch after the text above was written.

```bash
for needle in 'function definitions' 'function grammar' 'function operatorsForType' 'function rejectUnknownArguments' 'const array REPLACED' 'MAX_CONDITIONS' 'MAX_LOGIC_DEPTH' 'MAX_HOPS' 'function applyCondition' 'function text' 'function validate' 'function at' 'function operand' 'function walk'; do grep -rqF "$needle" app/Queries || echo "MISSING: $needle"; done
for name in 'publishes exactly the filter names the list action accepts for each entity' 'states every filter limit from the constants on every surface' 'names every custom field filter operator in the mcp list tool description' 'never reaches another workspace through a relation or a member id on the api and mcp' 'returns records with an empty value under $not' 'rejects an argument a list tool does not take instead of listing every record' 'the query language uses no transport' 'keeps reusable query predicates on their model as scopes' 'keeps the role suffix on classes whose directory carries one'; do grep -rqF "$name" tests || echo "MISSING TEST: $name"; done
grep -nF "'\$contains'" app/Queries/Filters/NativeFilter.php
```

Expected: the two loops print nothing. The last command prints the line in `text()` that singles out `$contains`. If anything is missing, correct the sentence in `.ai/rules/queries.md` to match the code, never the code to match the sentence.

- [ ] **Step 3: Route both rule files**

In the front matter of `.ai/rules/agent-surfaces.md`, after the line `  - 'app/Mcp/Schema/**'` add:

```yaml
  - 'app/Queries/**'
```

In `.ai/rules/index.md` replace `app/Mcp/Tools/**, app/Mcp/Schema/**, app/Mcp/Resources/**,` with:

```
app/Mcp/Tools/**, app/Mcp/Schema/**, app/Queries/**, app/Mcp/Resources/**,
```

and add this row after the `.ai/rules/panel-links.md` row:

```
| app/Queries/**, app/Actions/*/List*.php, app/Mcp/Tools/BaseListTool.php, packages/Chat/src/Tools/BaseReadListTool.php | .ai/rules/queries.md |
```

- [ ] **Step 4: Record the boundary in `architecture.md`**

Append this bullet to the list under `## Module boundaries (enforced by tests/Arch/ArchTest.php)`, after the bullet about `packages/SystemAdmin`:

```markdown
- `app/Queries` holds the filter and sort language every list surface shares, laid out
  like spatie/laravel-query-builder's own `src/`. A class that implements Spatie's `Filter`
  lives in `app/Queries/Filters`, and a `Sort` in `app/Queries/Sorts`. Shared traits go in
  `app/Queries/Concerns`, and registries sit at the root. `app/Queries` never uses a
  transport: `App\Mcp`, `App\Http`, `App\Filament`, `App\Livewire`, `App\Scribe` or
  `Relaticle\Chat`. Enums stay in `app/Enums`, because the Pest Laravel preset fails one
  anywhere else. `.ai/rules/queries.md` holds the rules for extending it
```

- [ ] **Step 5: Name the filter registry as an owner in `architecture.md`**

Under `## One fact, one owner` replace:

```markdown
The working examples: `CustomFieldFilterSchema` owns filter operators,
`App\Mcp\Schema\CustomFieldSchema` plus `CustomFieldType::inputFormat()` own how a
```

with:

```markdown
The working examples: `CustomFieldFilterSchema` owns filter operators,
`EntityFilters::definitions()` owns the filter names each entity accepts,
`App\Mcp\Schema\CustomFieldSchema` plus `CustomFieldType::inputFormat()` own how a
```

- [ ] **Step 6: Extend the role suffix sentence in `core.md`**

Replace:

```markdown
- A class is named for its role where its directory carries one: `Command`, `Controller`,
  `Request`, `Resource`, `Mail`, `Observer`, `Policy`, `Tool`. `tests/Arch/ConventionsTest.php`
  fails a class there without the suffix.
```

with:

```markdown
- A class is named for its role where its directory carries one: `Command`, `Controller`,
  `Request`, `Resource`, `Mail`, `Observer`, `Policy`, `Tool`, and `Filter` and `Sort` under
  `app/Queries`. `tests/Arch/ConventionsTest.php` fails a class there without the suffix.
```

- [ ] **Step 7: Recompile the guidelines**

```bash
php artisan boost:update
cp AGENTS.md GEMINI.md
git status --short | grep -v '^!!'
git diff .ai/rules/index.md
```

Expected: `git status` lists `.ai/rules/queries.md` as new and `.ai/rules/agent-surfaces.md`, `.ai/rules/index.md`, the two guideline sources, `CLAUDE.md`, `AGENTS.md` and `GEMINI.md` as modified. The index diff shows only the two rows from Step 3. If Boost rewrote those rows differently, keep Boost's version. If Boost changed any other file, stop and report: a Boost upgrade is riding along.

- [ ] **Step 8: Amend the spec's Components section**

In `docs/superpowers/specs/2026-10-02-crm-filter-language-design.md` replace line 215:

```markdown
All in `app/Support/Filters/`, moved from `app/Mcp/Filters/` because REST, MCP and chat share them:
```

with:

```markdown
All in `app/Queries/`, because REST, MCP and chat share them. Filter classes sit in `app/Queries/Filters/` and the sort in `app/Queries/Sorts/`. They were first built under `app/Support/Filters/`, and `docs/superpowers/plans/2026-10-05-queries-namespace.md` moved them before this shipped:
```

and replace line 227:

```markdown
`App\Mcp\Schema\CustomFieldFilterSchema` stays the owner of operators per type, as the architecture rules name it.
```

with:

```markdown
`App\Queries\CustomFieldFilterSchema` stays the owner of operators per type, as the architecture rules name it.
```

- [ ] **Step 9: Run the arch suite**

Run: `composer test:arch`
Expected: PASS, including `keeps compiled agent guidelines in sync with their .ai sources`.

- [ ] **Step 10: Commit**

```bash
git add .ai CLAUDE.md AGENTS.md GEMINI.md docs/superpowers/specs/2026-10-02-crm-filter-language-design.md
git add -f docs/superpowers/plans/2026-10-05-queries-namespace.md
git commit -m "docs(guidelines): record the queries folder layout and its rules"
```

---

### Task 5: The cross-surface test moves beside its sibling

`ListFilterSurfacesTest.php` makes REST and MCP calls from a folder named `Mcp`. Cross-surface tests live in `tests/Feature/CRM/`, beside `SurfaceParityTest.php`. The other two files in `tests/Feature/Mcp/Filters/` drive MCP tools only and stay.

**Files:**
- Move: `tests/Feature/Mcp/Filters/ListFilterSurfacesTest.php` to `tests/Feature/CRM/ListFilterSurfacesTest.php`
- Modify, only if Step 2 says so: `tests/.pest/shards.json`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing.

- [ ] **Step 1: Move the file**

```bash
git mv tests/Feature/Mcp/Filters/ListFilterSurfacesTest.php tests/Feature/CRM/ListFilterSurfacesTest.php
```

- [ ] **Step 2: Keep the shard key in step**

```bash
grep -c 'ListFilterSurfacesTest' tests/.pest/shards.json
```

Expected: `0`, as on 2026-10-05, and nothing more to do. If it prints `1`, run:

```bash
perl -pi -e 's/Tests\\\\Feature\\\\Mcp\\\\Filters\\\\ListFilterSurfacesTest/Tests\\\\Feature\\\\CRM\\\\ListFilterSurfacesTest/' tests/.pest/shards.json
grep -c 'CRM\\\\ListFilterSurfacesTest' tests/.pest/shards.json
```

Expected: `1`.

- [ ] **Step 3: Run the moved test and the arch suite**

```bash
php artisan test --compact tests/Feature/CRM/ListFilterSurfacesTest.php
composer test:arch
```

Expected: PASS. `TestSuiteIntegrityTest` confirms the file still sits inside a declared suite.

- [ ] **Step 4: Commit**

```bash
git add -A tests
git commit -m "test: move the list filter surface test beside the parity test"
```

---

### Task 6: Confirm the push and watch CI

**Files:** none.

**Interfaces:**
- Consumes: the five commits above.
- Produces: a green `Tests` run on the final head, which Task 7 cites.

- [ ] **Step 1: Confirm the branch is pushed**

```bash
git fetch origin feat/crm-filter-language
git rev-list --left-right --count origin/feat/crm-filter-language...HEAD
```

Expected: `0	0`, because Conductor pushes each commit. If the second number is not `0`, run `git push`.

- [ ] **Step 2: Watch CI as a background task**

```bash
gh run watch --exit-status $(gh run list --branch feat/crm-filter-language --workflow Tests --limit 1 --json databaseId --jq '.[0].databaseId')
```

Expected: exit status 0. Fix what it reports and push again. Never a `sleep` loop.

---

### Task 7: One complete pull request page

The page drifted from the branch. This task rewrites the body for the final head and folds the two review comments into one.

State on 2026-10-05 at `757c37974`:

- **Body.** Its verification cites CI on `5008885f6`. Its deploy notes cite `3.x-dev as 3.12.0`, while `composer.json` holds `^3.13.1` and v3.13.1 is released. Two known limits were changed by later commits. It names none of the 14 commits after `5691ee41a` and not `app/Queries`.
- **Comments.** `5984058674` is the review at `5008885f6` (25,925 characters). `5997703573` is the review at `5691ee41a` (38,171 characters). Both are by ManukMinasyan. Together they hold 64,096 characters, and GitHub caps a comment at 65,536, so the single comment must condense, not concatenate.
- **Not ours.** Review `5405762762` is a Copilot notice that it could not review. It stays.

The kept comment is `5997703573`. The review tooling's bundle in `.context/finalize-pr/906/posted-comment-id.txt` already points at it. `5984058674` is deleted once its content lives in the kept one.

**Files** (all under `.context/pr906/`, which is gitignored):
- Create: `body.before.md`, `comment-5984058674.before.md`, `comment-5997703573.before.md`, `facts.txt`, `body.md`, `review.md`
- Remote: the body of PR #906 (edit), comment `5997703573` (edit), comment `5984058674` (delete)

**Interfaces:**
- Consumes: the green CI run from Task 6, and the gate results of Tasks 1 to 5.
- Produces: one PR body and one review comment.

**Rules for both texts:**
- State only what a saved source or a command in Step 2 shows. Keep each measurement with the commit it was measured on. Never re-date an old result to the new head.
- No verdict is recomputed here. The comment quotes the last computed verdict with its SHA, then says what changed since.
- No AI attribution, no em-dash, no competitor names. One idea per sentence, 25 words at most.
- Keep every image link the merged text still refers to. The images live on the `gh-attach-assets` branch, so deleting a comment does not remove them.

- [ ] **Step 1: Back up the three texts**

```bash
mkdir -p .context/pr906
gh api repos/relaticle/relaticle/pulls/906 --jq .body > .context/pr906/body.before.md
gh api repos/relaticle/relaticle/issues/comments/5984058674 --jq .body > .context/pr906/comment-5984058674.before.md
gh api repos/relaticle/relaticle/issues/comments/5997703573 --jq .body > .context/pr906/comment-5997703573.before.md
gh api --paginate repos/relaticle/relaticle/issues/906/comments --jq '.[] | "\(.id) \(.user.login) \(.body | length)"'
```

Expected: exactly two lines, for `5984058674` and `5997703573`. If a third comment exists, save it the same way and fold it in too. The review tooling posts a fresh comment at each new head, which is how the page got two.

- [ ] **Step 2: Collect the facts the new texts may state**

```bash
{
  echo "head: $(git rev-parse --short=9 HEAD)"
  echo "commits vs main: $(git rev-list --count origin/main..HEAD)"
  echo "diff: $(git diff --shortstat origin/main...HEAD)"
  echo "package: $(grep '"relaticle/custom-fields"' composer.json | tr -s ' ')"
  echo "package PR 250 merged: $(gh api repos/relaticle/custom-fields/pulls/250 --jq .merged)"
  echo "package latest release: $(gh api repos/relaticle/custom-fields/releases/latest --jq .tag_name)"
  echo "checks on head:"
  gh api "repos/relaticle/relaticle/commits/$(git rev-parse HEAD)/check-runs" --jq '.check_runs[] | "  \(.name): \(.conclusion // .status)"' | sort
  echo "commits after the last full review (5691ee41a):"
  git log --reverse --format='  %h %s' 5691ee41a..HEAD
} > .context/pr906/facts.txt
cat .context/pr906/facts.txt
```

Expected: every check reads `success`, apart from `merge`, which reads `skipped` on every PR. If a check is red or still running, stop: Task 6 is not done.

- [ ] **Step 3: Draft the body in `.context/pr906/body.md`**

Copy `body.before.md` and apply exactly these edits. Before keeping a sentence that names a commit, confirm it with `git show --stat <sha>`.

1. Under `## What changes`, append:

```markdown
- **Layout.** The filter and sort language lives in `app/Queries`, laid out like spatie/laravel-query-builder's own `src/`. Arch tests pin `Filters/` and `Sorts/` to their contracts and keep the language free of any transport import. `.ai/rules/queries.md` records how to extend it.
```

2. Under `## Behavior changes`, append:

```markdown
- **An unknown key in a `query` body** returns a 422 that lists the accepted keys. A misspelled `filter` used to return every record.
- **`page` sent beside `cursor`** returns a 422 that names both keys, on `GET` and on a `query` body. It used to return the first cursor page.
- **Operands are trimmed, and a blank operand is a 422, on every surface.** A padded value used to match on the API only, and a blank `$contains` returned every record on MCP and chat.
- **A custom field sort lists records without a value last,** in both directions.
- **`links.next` and `links.prev` keep the query string.** Following one used to drop the filter, the sort and the page size.
- **Chat reads a bare date on a date-time field as the viewer's day.** REST and MCP keep the UTC day.
- **Chat refuses a date-time without a UTC offset** and names the offset for the viewer's zone. REST and MCP keep reading it as UTC.
- **MCP and chat filter errors name the path of the node to fix,** as REST does.
- **A custom field filter error uses the same sentence as its native one.**
```

Their commits, in order: `cf172879a`; `241cf6797` and `757c37974`; `2a5484520`; `821f29503`; `3147b996f`; `9f606df1e`; `f4ac278a3`; `78cbfd9a1`; `572423050`.

3. Under `## Deploy notes`, replace the first bullet, the one about the required `relaticle/custom-fields` version, with the line below. Use it only if `facts.txt` shows the `^3.13.1` constraint and a release of `v3.13.1` or later. Otherwise write what `facts.txt` shows.

```markdown
- Requires `relaticle/custom-fields` ^3.13.1, which is released. `composer.json` already holds that constraint.
```

In the bullet that starts `- The command logs one summary line`, add this sentence at the end (commit `5ee0d6bde`):

```markdown
The summary line carries the count of domains that several companies share.
```

4. Under `## Verification`, replace the first bullet, the one that reports CI on `5008885f6`, with one built from `facts.txt`: the head SHA, the number of checks that passed, and what they are. Replace the bullet that starts `- [Final review]` with:

```markdown
- [Review](https://github.com/relaticle/relaticle/pull/906#issuecomment-5997703573): one comment holds every round. The last full review ran at `5691ee41a`. The commits after it are listed there, each with the test that covers it.
```

5. Under `## Known limits`, replace the bullet that starts `- A custom date-time value is stored as the typed wall clock` with (commit `e0accf5b1`):

```markdown
- A custom date-time value written with an offset before `e0accf5b1` holds the typed wall clock, not the instant. Nothing repairs those rows.
```

and replace the bullet that starts `- The activity tools label a change` with (commit `beeac5c98`):

```markdown
- The record timeline labels a change by a deleted account as "System". The activity page, MCP and chat say "Former member".
```

6. Leave every other line as it is, including each note of the form "This pass ran on `<sha>`".

- [ ] **Step 4: Draft the single review in `.context/pr906/review.md`**

Below, C1 is `comment-5984058674.before.md` and C2 is `comment-5997703573.before.md`. Use this skeleton, in this order:

```markdown
# Review of #906, one filter language across the API, MCP and chat

## Verdict

## Blockers before merge

## Fixed in this review

## Applied security fixes

## Refactors applied

## Open questions

## Reported, no change made

## Performance

## Walked journeys

## Test results

fp-sha:5691ee41a
```

Fill each part from these sources, by this rule:

| Part | Sources | Rule |
|---|---|---|
| Opening paragraph | C2 line 3, C1 line 9, `facts.txt` | Name the rounds and their heads: the review at `5008885f6`, the decisions round, the review at `5691ee41a`. Then the count of commits since, and that no full pass re-reviewed them. |
| Verdict | C2 lines 5 to 9 | Quote the last computed verdict and its reason, with `5691ee41a`. Add one line: how many of its asks a commit answered, and how many stay open. Write no new verdict word. |
| Blockers before merge | C1 lines 11 to 16, C2 lines 11 to 22, `facts.txt` | Keep only what is still undone. The deploy step stays. Drop the package release if `facts.txt` shows it merged and released. Drop Q1 and Q4 if their commits are in `facts.txt`. |
| Fixed in this review | C1 lines 66 to 84, C2 lines 24 to 48, the commit list in `facts.txt` | Three groups: to `5008885f6`, to `5691ee41a`, after the last review. In the last group, one row per fix commit: SHA, what was wrong (from the commit body), and the test file from `git show --stat <sha>`. Add one row for the move to `app/Queries`: behavior-neutral, gated by the new arch tests. |
| Applied security fixes | C1 lines 85 to 97, C2 lines 49 to 58 | Join both. Drop a row whose commit SHA repeats. |
| Refactors applied | C1 lines 98 to 114, C2 lines 59 to 87, `572423050`, the move commits | Join them. Keep each `net:` line beside its round. |
| Open questions | C1 lines 18 to 48, C2 lines 88 to 130 | One table: ask, decision, commit, status. Rows A1 to A11 come from C1's table unchanged. Rows B1 to B3 and Q1 to Q10 use the candidate commits below. An ask with no commit stays open, with its options and recommendation. |
| Reported, no change made | C1 lines 115 to 139, C2 lines 131 to 145 | Join both. Drop an item a later commit fixed, and name that commit under Fixed. |
| Performance | C2 lines 146 to 170, then C1 lines 140 to 157 | C2's tables first. Add a C1 row only if C2 lacks it. Keep the measured-on SHA with each table. |
| Walked journeys | C2 lines 171 to 292, C1 lines 49 to 65, C1 lines 158 to 222 | Keep C2's walk whole, images included. Keep C1's MCP client pass whole. Reduce C1's walk to one table: journey, result, SHA. |
| Test results | `facts.txt`, the gate runs of Tasks 1 to 5, C2 lines 293 to 325 | A gate table for the final head: each command this plan ran and the checks in `facts.txt`. Then C2's mutation audit table, headed "at `5691ee41a`". |
| Footer | C2 lines 326 to 328 | Keep both `_Assessed_` lines. Add: `_Commits after 5691ee41a were not re-reviewed by a full pass. Each fix among them has a test, and CI is green on <head>._` The last line stays `fp-sha:5691ee41a`, the head of the last full review. |

Candidate commits for the asks. Read each commit before writing its row:

| Ask | Candidate |
|---|---|
| B1, activity author after someone leaves | `beeac5c98` |
| B2, offsets on a custom date-time field | `e0accf5b1` |
| B3, the app-side guard from `03f29a755` | none found: check whether `CanonicalValue::of()` still holds the guard |
| Q1, unknown key in a `query` body | `cf172879a` |
| Q2, chat filters the UTC day | `9f606df1e` |
| Q3, descending sort puts empty values first | `821f29503` |
| Q4, cost of a link operand | `29f7ebb28`, `b064b9c76` |
| Q5, date-times written before `e0accf5b1` | none: a release note |
| Q6, the backfill has no pre-image | `5ee0d6bde` for the log count. The `\copy` deploy step has no commit |
| Q7, trim and blank operands | `2a5484520` |
| Q8, `links.next` drops the query string | `3147b996f` |
| Q9, the timeline says "System" | none: a package follow-up |
| Q10, smaller contract choices | `241cf6797`, `757c37974`, `572423050`, `78cbfd9a1`, `f4ac278a3`, `ed21a5054`. The repeated grammar in five MCP descriptions has no commit |

- [ ] **Step 5: Check both drafts**

```bash
for f in body review; do printf '%s: %s characters, %s em-dashes\n' "$f" "$(wc -m < .context/pr906/$f.md | tr -d ' ')" "$(grep -c $'\xe2\x80\x94' .context/pr906/$f.md)"; done
grep -n -i 'generated with\|co-authored' .context/pr906/body.md .context/pr906/review.md
grep -n -i -w 'attio\|twenty' .context/pr906/body.md .context/pr906/review.md
grep -ohE '`[0-9a-f]{9}`' .context/pr906/body.md .context/pr906/review.md | tr -d '`' | sort -u | while read sha; do git cat-file -e "$sha^{commit}" 2>/dev/null || echo "not a commit in this repo: $sha"; done
grep -n 'issuecomment-' .context/pr906/body.md .context/pr906/review.md
grep -c '^## ' .context/pr906/review.md
tail -1 .context/pr906/review.md
```

Expected:

- both files under 65,000 characters, with 0 em-dashes
- no output from the two `grep -n -i` lines
- no "not a commit" line, unless the SHA belongs to `relaticle/custom-fields`, which you confirm by hand
- every `issuecomment-` link points at `5997703573`
- `10` sections in the review, and its last line is `fp-sha:5691ee41a`

- [ ] **Step 6: Show both drafts to Manuk and wait**

Print `.context/pr906/body.md` and `.context/pr906/review.md` in full. List what Step 8 will delete: comment `5984058674`. Then stop. Continue only on an explicit "post" given after the drafts were shown. This task's wording is not that approval.

- [ ] **Step 7: Publish the body and the single review**

```bash
grep '^head:' .context/pr906/facts.txt
git rev-parse --short=9 HEAD
```

Expected: the same SHA twice. A moved head makes both texts stale: return to Step 2.

```bash
gh api --method PATCH repos/relaticle/relaticle/pulls/906 -F body=@.context/pr906/body.md --jq .html_url
gh api --method PATCH repos/relaticle/relaticle/issues/comments/5997703573 -F body=@.context/pr906/review.md --jq .html_url
gh api repos/relaticle/relaticle/pulls/906 --jq '.body | length'
gh api repos/relaticle/relaticle/issues/comments/5997703573 --jq '.body | length'
wc -m .context/pr906/body.md .context/pr906/review.md
```

Expected: two URLs, then two lengths that equal the two `wc -m` counts, or fall one short for a trailing newline.

- [ ] **Step 8: Delete the superseded comment**

Run this only after Step 7 passed. A deleted comment cannot be restored from GitHub, so the backup must exist first.

```bash
test -s .context/pr906/comment-5984058674.before.md && gh api --method DELETE repos/relaticle/relaticle/issues/comments/5984058674
gh api --paginate repos/relaticle/relaticle/issues/906/comments --jq '.[] | "\(.id) \(.user.login)"'
cat .context/finalize-pr/906/posted-comment-id.txt
```

Expected: one line, `5997703573 ManukMinasyan`, and then `5997703573` from the bundle file.

---

## Where later work lands

These are not tasks in this plan. They record where each follow-up file goes, so the layout holds.

| Follow-up | Lands in | Note |
|---|---|---|
| Operator enum | `app/Enums/FilterOperator.php`, plus `CustomFieldType::filterOperators()` | Spatie ships `Spatie\QueryBuilder\Enums\FilterOperator` too. Alias one if a file ever needs both. |
| Model scope `matchingFilter` | `app/Models/Concerns/FiltersByTree.php` | It imports `App\Queries`, the allowed direction. |
| `ListQuery` | `app/Data/ListQuery.php` | The arch test `API controllers must depend on actions for write operations` allows no `App\Data` import yet. Extend its list in that change. |
| List definitions | `app/Queries/EntityLists.php` and `app/Queries/ListDefinition.php` | Query classes in the Mailcoach shape would be `app/Queries/CompaniesQuery.php`. |
| Parsed node tree | `app/Queries/Nodes/` | Add the folder to the role tests when it appears. |
| Filament filter adapter | under `app/Filament/` | It imports `App\Queries`. `App\Queries` never imports it. |

## Out of scope

- Splitting `CustomFieldFilterSchema` or turning operators into an enum.
- Folding the 12 engine-only `validation.custom_field.*` lang keys into `validation.filter.*`.
- Moving `app/Concerns/PaginatesListQuery.php`. The list actions own it.
- Editing `docs/superpowers/plans/2026-10-02-crm-filter-language.md`. It is an executed record.
- Moving `Operand::lacksOffset()` and `Operand::offsetRequired()`. `CustomFieldInput` on the write path calls them since commit `f4ac278a3`, so `app/Support/CustomFields` and `app/Queries` import each other. Untangling that is a code change, not a move.
- Refreshing `tests/.pest/shards.json`. PR #906 owes that for its new test classes, with or without this move.
