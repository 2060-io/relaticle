<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Support\LikePattern;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Enums\FieldDataType;

final readonly class FindEntitiesByFieldValue
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
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @return Collection<int, TModel>
     */
    public function execute(string $modelClass, CustomField $field, string $value, int $limit): Collection
    {
        $column = $field->getValueColumn();

        if ($value === '' || ! in_array($column, ['string_value', 'text_value', 'json_value'], true)) {
            return new Collection;
        }

        $model = new $modelClass;
        $pattern = LikePattern::escape($value);

        $entityIds = CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->select('entity_id')
            ->where((string) config('custom-fields.database.column_names.tenant_foreign_key'), $field->tenant_id)
            ->where('entity_type', $model->getMorphClass())
            ->where('custom_field_id', $field->getKey());

        if ($column === 'json_value') {
            // A value saved before the field became multi-value can still be a bare scalar.
            $entityIds->whereRaw(
                "exists (select 1 from jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else jsonb_build_array(json_value::jsonb) end) as element(value) where element.value ilike ?)",
                [$pattern],
            );
        } else {
            $entityIds->whereLike($column, $pattern);
        }

        return $modelClass::query()
            ->where('workspace_id', $field->tenant_id)
            ->whereIn($model->getKeyName(), $entityIds)
            ->oldest()
            ->orderBy($model->getKeyName())
            ->limit($limit)
            ->get();
    }
}
