<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Queries\FilterErrors;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;
use UnexpectedValueException;

trait PaginatesListQuery
{
    /**
     * @template TModel of Model
     *
     * @param  QueryBuilder<TModel>  $query
     * @return CursorPaginator<int, TModel>|LengthAwarePaginator<int, TModel>
     */
    private function paginateList(QueryBuilder $query, int $perPage, bool $useCursor, ?int $page): CursorPaginator|LengthAwarePaginator
    {
        $ordered = $query->orderBy('id');

        if (! $useCursor) {
            return $ordered->paginate($perPage, ['*'], 'page', $page);
        }

        try {
            return $ordered->cursorPaginate($perPage);
        } catch (UnexpectedValueException) {
            // A cursor holds the columns of the sort it was issued under, and the paginator throws when one is missing.
            throw FilterErrors::at('cursor', __('validation.filter.cursor'));
        }
    }
}
