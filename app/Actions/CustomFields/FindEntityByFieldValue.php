<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Enums\FieldDataType;

final readonly class FindEntityByFieldValue
{
    // A string match value cannot be compared to boolean, numeric or date columns, and
    // single-choice fields store option keys, never the label a form submits.
    /** @var array<int, FieldDataType> */
    public const array MATCHABLE_DATA_TYPES = [
        FieldDataType::STRING,
        FieldDataType::TEXT,
        FieldDataType::MULTI_CHOICE,
    ];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, string>  $nativeColumns
     */
    public function execute(string $modelClass, string $workspaceId, string $field, string $value, array $nativeColumns = []): ?Model
    {
        $pattern = LikePattern::escape(trim($value));

        if ($pattern === '') {
            return null;
        }

        $model = new $modelClass;
        $query = $modelClass::query()->where('workspace_id', $workspaceId);

        if (in_array($field, $nativeColumns, true)) {
            $query->whereLike($field, $pattern);
        } else {
            $query->whereIn($model->getKeyName(), $this->entityIdsCarryingValue($model->getMorphClass(), $workspaceId, $field, $pattern));
        }

        // Several records can carry the same value, so the oldest one wins.
        return $query->oldest()->orderBy($model->getKeyName())->first();
    }

    /**
     * @return array<int, string>
     */
    private function entityIdsCarryingValue(string $entityType, string $workspaceId, string $code, string $pattern): array
    {
        $customField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->active()
            ->first();

        $column = $customField?->getValueColumn();

        if (! in_array($column, ['string_value', 'text_value', 'json_value'], true)) {
            return [];
        }

        $values = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where((string) config('custom-fields.database.column_names.tenant_foreign_key'), $workspaceId)
            ->where('entity_type', $entityType)
            ->where('custom_field_id', $customField->getKey());

        if ($column === 'json_value') {
            // A value saved before the field became multi-value can still be a bare scalar.
            $values->whereRaw(
                "exists (select 1 from jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else jsonb_build_array(json_value::jsonb) end) as element(value) where element.value ilike ?)",
                [$pattern],
            );
        } else {
            $values->whereLike($column, $pattern);
        }

        return $values->pluck('entity_id')->map(fn (mixed $id): string => (string) $id)->all();
    }
}
