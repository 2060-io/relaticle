<?php

declare(strict_types=1);

namespace App\Queries;

use App\Data\ListQuery;
use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;
use UnexpectedValueException;

abstract readonly class EntityQuery
{
    abstract public static function entity(): CrmEntity;

    /** @return list<string> */
    abstract public static function fields(): array;

    /** @return list<string> */
    abstract public static function includes(): array;

    /** @return array<string, string> */
    abstract public static function countIncludes(): array;

    /** @return list<string> */
    public static function sorts(): array
    {
        return [static::entity()->titleColumn(), 'created_at', 'updated_at'];
    }

    /** @return QueryBuilder<Model> */
    final public function for(User $user, ListQuery $list): QueryBuilder
    {
        $entity = static::entity();
        $model = $entity->model();

        abort_unless($user->can('viewAny', $model), 403);

        FilterTree::validate($list->filter, $entity);

        $builder = QueryBuilder::for($model, $list->toRequest());
        $builder->scopes('withCustomFieldValues');
        $builder->whereBelongsTo($user->currentWorkspace);

        return $builder
            ->allowedFilters(...new EntityFilters($user, $list->viewerZone)->for($entity))
            ->allowedFields(...static::fields())
            ->allowedIncludes(...static::includes(), ...$this->countAllowedIncludes())
            ->allowedSorts(
                ...static::sorts(),
                ...($list->cursor ? [] : new CustomFieldFilterSchema()->allowedSorts($user, $entity->value)),
            )
            ->defaultSort('-created_at');
    }

    /** @return CursorPaginator<int, Model>|LengthAwarePaginator<int, Model> */
    final public function paginate(User $user, ListQuery $list): CursorPaginator|LengthAwarePaginator
    {
        $ordered = $this->for($user, $list)->orderBy('id');

        if (! $list->cursor) {
            return $ordered->paginate($list->perPage, ['*'], 'page', $list->page);
        }

        try {
            return $ordered->cursorPaginate($list->perPage);
        } catch (UnexpectedValueException) {
            // A cursor holds the columns of the sort it was issued under, and the paginator throws when one is missing.
            throw FilterErrors::at('cursor', __('validation.filter.cursor'));
        }
    }

    /** @return list<AllowedInclude> */
    private function countAllowedIncludes(): array
    {
        $counts = [];

        foreach (static::countIncludes() as $name => $relation) {
            $counts[] = AllowedInclude::count($name, $relation);
        }

        return $counts;
    }
}
