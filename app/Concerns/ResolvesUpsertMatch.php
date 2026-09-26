<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\CustomFields\FindEntityByFieldValue;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Relaticle\CustomFields\Facades\CustomFieldsType;

trait ResolvesUpsertMatch
{
    private bool $matchResolved = false;

    private ?Model $matchedRecord = null;

    /**
     * @param  array<int, string>  $nativeColumns
     * @return array<string, array<int, mixed>>
     */
    protected function matchRules(string $entityType, array $nativeColumns = []): array
    {
        return [
            'match' => ['required', 'array'],
            'match.field' => ['required', 'string', Rule::in([...$nativeColumns, ...$this->matchableCustomFieldCodes($entityType)])],
            'match.value' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, string>  $nativeColumns
     */
    protected function resolveMatch(string $modelClass, array $nativeColumns = []): ?Model
    {
        if ($this->matchResolved) {
            return $this->matchedRecord;
        }

        $this->matchResolved = true;

        $field = $this->input('match.field');
        $value = $this->input('match.value');

        // Resolved before validation runs, so input the rules would reject is skipped here.
        if (! is_string($field) || ! is_string($value)) {
            return null;
        }

        return $this->matchedRecord = resolve(FindEntityByFieldValue::class)
            ->execute($modelClass, $this->workspaceId(), $field, $value, $nativeColumns);
    }

    protected function workspaceId(): string
    {
        /** @var User $user */
        $user = $this->user();

        return (string) $user->currentWorkspace->getKey();
    }

    /** @return array<int, string> */
    private function matchableCustomFieldCodes(string $entityType): array
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->workspaceId())
            ->where('entity_type', $entityType)
            ->active()
            ->get()
            ->filter(fn (CustomField $field): bool => in_array(
                CustomFieldsType::getFieldType($field->type)?->dataType,
                FindEntityByFieldValue::MATCHABLE_DATA_TYPES,
                true,
            ))
            ->map(fn (CustomField $field): string => (string) $field->code)
            ->values()
            ->all();
    }
}
