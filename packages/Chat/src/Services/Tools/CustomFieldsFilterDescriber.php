<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Enums\CrmEntity;
use App\Enums\FilterKind;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\User;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\FilterVocabulary;
use Relaticle\Chat\Support\PromptText;

final readonly class CustomFieldsFilterDescriber
{
    public function __construct(private FilterVocabulary $vocabulary) {}

    public function describe(User $user, string $entityType): string
    {
        $entity = CrmEntity::from($entityType);
        $vocabulary = $this->vocabulary->for($user, $entity);
        $customFields = $vocabulary['custom_fields'];
        $types = $vocabulary['types'];
        unset($vocabulary['custom_fields'], $vocabulary['types']);

        $lines = ['Names for this entity type:'];
        $rules = [];

        foreach ($vocabulary as $name => $entry) {
            $line = "- {$name} ({$entry['type']}".(isset($entry['entity']) ? " to {$entry['entity']}" : '').'; operators: '.implode(', ', $entry['operators']);
            $line .= isset($entry['values']) ? '; one of: '.implode(', ', $entry['values']) : '';

            if (isset($entry['operand']) && $entry['type'] === FilterKind::Computed->value) {
                $line .= "; takes {$entry['operand']}";
            } elseif (isset($entry['operand'])) {
                $rules[$entry['type']] ??= "- {$entry['type']}: takes {$entry['operand']}";
            }

            $line .= '; example: '.CustomFieldFilterSchema::json($entry['example']);
            $line .= isset($entry['nested_example']) ? '; nested example: '.CustomFieldFilterSchema::json($entry['nested_example']) : '';
            $lines[] = $line.')';

            if (isset($entry['nested_custom_field_example']) && isset($rules['relation']) && ! str_contains($rules['relation'], 'nested custom field example')) {
                $rules['relation'] .= '; nested custom field example '.CustomFieldFilterSchema::json([$name => $entry['nested_custom_field_example']]);
            }
        }

        if ($rules !== []) {
            array_push($lines, '', 'Rules by type:', ...array_values($rules));
        }

        $lines[] = '';
        $lines[] = 'Example: '.CustomFieldFilterSchema::json(EntityFilters::example($entity));

        if ($customFields === []) {
            $lines[] = '';
            $lines[] = 'No filterable custom fields are defined for this entity type.';

            return implode("\n", $lines);
        }

        array_push($lines, ...$this->customFieldLines($customFields, $types));

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<string, mixed>>  $customFields
     * @param  array<string, array<string, mixed>>  $types
     * @return list<string>
     */
    private function customFieldLines(array $customFields, array $types): array
    {
        $lines = [];

        $lines[] = '';
        $lines[] = EntityFilters::CUSTOM_FIELDS_RULE.' The keys MUST be one of the codes below, and each type allows only the operators listed under Field types.';
        $lines[] = CustomFieldOptionMap::choiceRule().' Pass the label as listed.';
        $lines[] = '';
        $lines[] = 'Field types:';

        foreach ($types as $type => $entry) {
            $line = "- {$type}: operators ".implode(', ', $entry['operators']);
            $line .= isset($entry['sub_fields']) ? '; sub-field domain takes '.implode(', ', $entry['sub_fields']['domain']['operators'])." and matches {$entry['sub_fields']['domain']['matches']}, example ".CustomFieldFilterSchema::json($entry['sub_fields']['domain']['example']) : '';
            $line .= isset($entry['matching']) ? "; values match {$entry['matching']}" : '';
            $lines[] = $line.(isset($entry['example']) ? '; example '.CustomFieldFilterSchema::json($entry['example']) : '');
        }

        $lines[] = '';
        $lines[] = 'Fields:';

        foreach ($customFields as $code => $entry) {
            $name = PromptText::sanitize($entry['name'], 120);
            $options = isset($entry['options']) ? '; one of: "'.implode('", "', array_map(fn (string $option): string => PromptText::sanitize($option, 120), $entry['options'])).'"' : '';
            $line = "- {$code} ({$name}, {$entry['type']}{$options}";
            $lines[] = $line.(isset($entry['example']) ? '; example '.CustomFieldFilterSchema::json($entry['example']) : '').')';
        }

        $firstCode = array_key_first($customFields);
        $lines[] = '';
        $lines[] = 'Custom field example: '.CustomFieldFilterSchema::json(['custom_fields' => [$firstCode => $customFields[$firstCode]['example'] ?? $types[$customFields[$firstCode]['type']]['example']]]);

        return $lines;
    }

    /**
     * The codes accepted by the `sort` slot, alongside the native columns.
     *
     * @return list<string>
     */
    public function sortableCodes(User $user, string $entityType): array
    {
        return $this->vocabulary->customFieldCodes($user, CrmEntity::from($entityType));
    }
}
