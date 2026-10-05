<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Concerns\PaginatesListQuery;
use App\Enums\CrmEntity;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\People;
use App\Models\User;
use App\Queries\EntityFilters;
use App\Queries\FilterTree;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListPeople
{
    use PaginatesListQuery;

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, People>|LengthAwarePaginator<int, People>
     */
    public function execute(
        User $user,
        int $perPage = 15,
        bool $useCursor = false,
        array $filters = [],
        ?int $page = null,
        ?Request $request = null,
        ?string $viewerZone = null,
    ): CursorPaginator|LengthAwarePaginator {
        abort_unless($user->can('viewAny', People::class), 403);

        $request ??= new Request(['filter' => $filters]);
        FilterTree::validate($request->input('filter'), CrmEntity::People);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(
            People::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $request,
        )
            ->allowedFilters(...new EntityFilters($user, $viewerZone)->for(CrmEntity::People))
            ->allowedFields('id', 'name', 'company_id', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'company',
                AllowedInclude::count('tasksCount', 'tasks'),
                AllowedInclude::count('notesCount', 'notes'),
            )
            ->allowedSorts(
                'name', 'created_at', 'updated_at',
                ...($useCursor ? [] : $filterSchema->allowedSorts($user, 'people')),
            )
            ->defaultSort('-created_at');

        return $this->paginateList($query, $perPage, $useCursor, $page);
    }
}
