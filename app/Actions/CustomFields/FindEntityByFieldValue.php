<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;

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
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $model = new $modelClass;
        $query = $modelClass::query()->where('workspace_id', $workspaceId);

        if (in_array($field, $nativeColumns, true)) {
            $comparison = $this->nativeColumnComparison($field);

            if (! $comparison instanceof Expression) {
                return null;
            }

            $query->where($comparison, mb_strtolower($value));
        } else {
            $entityIds = $this->entityIdsCarryingValue($model->getMorphClass(), $workspaceId, $field, $value);

            if ($entityIds === []) {
                return null;
            }

            $query->whereIn($model->getKeyName(), $entityIds);
        }

        // Several records can carry the same value, so the oldest one wins.
        return $query->oldest()->orderBy($model->getKeyName())->first();
    }

    // Literal SQL per column, so no identifier taken from the request reaches the query.
    private function nativeColumnComparison(string $column): ?Expression
    {
        return match ($column) {
            'name' => DB::raw('LOWER(name)'),
            default => null,
        };
    }

    private function caseInsensitiveComparison(string $column): ?Expression
    {
        return match ($column) {
            'string_value' => DB::raw('LOWER(string_value)'),
            'text_value' => DB::raw('LOWER(text_value)'),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function entityIdsCarryingValue(string $entityType, string $workspaceId, string $code, string $value): array
    {
        $customField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->active()
            ->first();

        if (! $customField instanceof CustomField) {
            return [];
        }

        // The match resolves before validation rejects an unmatchable type.
        if (! in_array(CustomFieldsType::getFieldType($customField->type)?->dataType, self::MATCHABLE_DATA_TYPES, true)) {
            return [];
        }

        $column = $customField->getValueColumn();

        if ($column === 'json_value') {
            return $this->entityIdsFromJsonArray($entityType, $workspaceId, (string) $customField->getKey(), $value);
        }

        $comparison = $this->caseInsensitiveComparison($column);

        if (! $comparison instanceof Expression) {
            return [];
        }

        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where($this->tenantKey(), $workspaceId)
            ->where('entity_type', $entityType)
            ->where('custom_field_id', $customField->getKey())
            ->where($comparison, mb_strtolower($value))
            ->pluck('entity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /** @return array<int, string> */
    private function entityIdsFromJsonArray(string $entityType, string $workspaceId, string $customFieldId, string $value): array
    {
        // Same driver matrix as EntityLinkResolver; a value saved before the field became
        // multi-value can still be a bare scalar, hence the array coercion.
        $model = new CustomFieldValue;
        $connection = $model->getConnection();
        $table = $model->getTable();
        $tenantKey = $this->tenantKey();

        $sql = match ($connection->getDriverName()) {
            'sqlite' => "SELECT cfv.entity_id
                FROM {$table} cfv, json_each(
                    CASE WHEN JSON_TYPE(cfv.json_value) = 'array'
                        THEN cfv.json_value
                        ELSE JSON_ARRAY(cfv.json_value)
                    END
                ) je
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(CAST(je.value AS TEXT)) = ?",
            'pgsql' => "SELECT cfv.entity_id
                FROM {$table} cfv
                CROSS JOIN LATERAL jsonb_array_elements_text(
                    CASE WHEN jsonb_typeof(cfv.json_value::jsonb) = 'array'
                        THEN cfv.json_value::jsonb
                        ELSE jsonb_build_array(cfv.json_value::jsonb)
                    END
                ) AS je(value)
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(je.value) = ?",
            default => "SELECT cfv.entity_id
                FROM {$table} cfv
                JOIN JSON_TABLE(
                    IF(JSON_TYPE(cfv.json_value) = 'ARRAY', cfv.json_value, JSON_ARRAY(cfv.json_value)),
                    '\$[*]' COLUMNS(val TEXT PATH '\$')
                ) AS jt
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(jt.val) = ?",
        };

        return collect($connection->select($sql, [$workspaceId, $customFieldId, $entityType, mb_strtolower($value)]))
            ->pluck('entity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function tenantKey(): string
    {
        return (string) config('custom-fields.database.column_names.tenant_foreign_key');
    }
}
