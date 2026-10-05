<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\Sorts\Sort;

/**
 * @implements Sort<Model>
 */
final readonly class CustomFieldSort implements Sort
{
    public function __construct(private CustomField $field) {}

    /**
     * @param  Builder<Model>  $query
     */
    public function __invoke(Builder $query, bool $descending, string $property): void
    {
        $model = $query->getModel();

        $query->orderBy(
            CustomFieldValue::query()
                ->select($this->field->getValueColumn())
                ->whereColumn('entity_id', $model->getTable().'.id')
                ->where('entity_type', $model->getMorphClass())
                ->where('custom_field_id', $this->field->getKey())
                ->limit(1),
            $descending ? 'desc' : 'asc',
        );
    }
}
