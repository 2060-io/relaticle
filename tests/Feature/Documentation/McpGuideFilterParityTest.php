<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterKind;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterTree;
use Relaticle\Documentation\Support\DocsRepository;

mutates(CustomFieldFilterSchema::class, EntityFilters::class, FilterTree::class);

function mcpGuideBody(): string
{
    return resolve(DocsRepository::class)->find('docs/guides/mcp')->body ?? '';
}

/**
 * @return array<string, string>
 */
function mcpGuideTable(string $heading): array
{
    $section = str(mcpGuideBody())->after("#### {$heading}\n")->before("\n#### ");
    $rows = [];

    foreach ($section->explode("\n") as $line) {
        if (preg_match('/^\| (.+?) \| (.+) \|$/', (string) $line, $cells) === 1) {
            $rows[$cells[1]] = $cells[2];
        }
    }

    return array_slice($rows, 1, preserve_keys: true);
}

/**
 * @return list<string>
 */
function mcpGuideCodeSpans(string $text): array
{
    preg_match_all('/`([^`]+)`/', $text, $spans);

    return $spans[1];
}

test('the mcp guide lists exactly the filter names of each entity under their kind', function (CrmEntity $entity): void {
    $row = mcpGuideTable('Native fields and relations')[ucfirst($entity->relationName())] ?? '';
    $listed = ['Native field' => [], 'Record relation' => [], 'Member relation' => []];
    $defined = $listed;

    foreach (explode('. ', $row) as $sentence) {
        foreach (array_keys($listed) as $kind) {
            if (str_starts_with($sentence, $kind)) {
                $listed[$kind] = mcpGuideCodeSpans($sentence);
            }
        }
    }

    foreach (EntityFilters::definitions($entity) as $name => $definition) {
        $kind = match ($definition->kind) {
            FilterKind::Relation => 'Record relation',
            FilterKind::Members => 'Member relation',
            default => 'Native field',
        };
        $defined[$kind][] = $name;
    }

    expect(mcpGuideCodeSpans($row))->toEqualCanonicalizing(array_keys(EntityFilters::definitions($entity)), "names of {$entity->value} in the mcp guide");

    foreach ($defined as $kind => $names) {
        expect($listed[$kind])->toEqualCanonicalizing($names, "{$kind}s of {$entity->value} in the mcp guide");
    }
})->with(CrmEntity::cases());

test('the mcp guide gives each field type the operators the engine takes', function (): void {
    $operatorSets = static function (array $lists): array {
        $sets = [];

        foreach ($lists as $operators) {
            sort($operators);
            $sets[] = implode(' ', $operators);
        }

        return array_values(array_unique($sets));
    };

    $published = array_filter(array_map(
        static fn (CustomFieldType $type): array => CustomFieldFilterSchema::operatorKeys($type->value),
        CustomFieldType::cases(),
    ));
    $listed = array_map(mcpGuideCodeSpans(...), mcpGuideTable('Operators by field type'));

    expect($operatorSets($listed))->toEqualCanonicalizing($operatorSets($published));
});

test('the mcp guide states every filter limit from the constants', function (string $limit): void {
    expect(str_contains(mcpGuideBody(), $limit))->toBeTrue("the mcp guide does not say: {$limit}");
})->with([
    'conditions' => 'at most '.FilterTree::MAX_CONDITIONS.' conditions',
    'logic depth and relation hops' => 'at most '.FilterTree::MAX_LOGIC_DEPTH.' levels and relations at most '.FilterTree::MAX_HOPS.' levels',
    'relation hops under relations' => 'Relations nest at most '.FilterTree::MAX_HOPS.' levels',
    'list values' => 'at most '.CustomFieldFilterSchema::MAX_LIST_VALUES.' values',
]);
