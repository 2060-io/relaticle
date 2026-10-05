<?php

declare(strict_types=1);

namespace App\Actions\Opportunity;

use App\Concerns\PaginatesListQuery;
use App\Enums\CrmEntity;
use App\Models\Opportunity;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\FilterTree;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedInclude;
use Spatie\QueryBuilder\QueryBuilder;

final readonly class ListOpportunities
{
    use PaginatesListQuery;

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, Opportunity>|LengthAwarePaginator<int, Opportunity>
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
        abort_unless($user->can('viewAny', Opportunity::class), 403);

        $request ??= new Request(['filter' => $filters]);
        FilterTree::validate($request->input('filter'), CrmEntity::Opportunity);
        $filterSchema = new CustomFieldFilterSchema;

        $query = QueryBuilder::for(
            Opportunity::query()->withCustomFieldValues()->whereBelongsTo($user->currentWorkspace),
            $request,
        )
            ->allowedFilters(...new EntityFilters($user, $viewerZone)->for(CrmEntity::Opportunity))
            ->allowedFields('id', 'name', 'company_id', 'contact_id', 'creator_id', 'created_at', 'updated_at')
            ->allowedIncludes(
                'creator', 'company', 'contact',
                AllowedInclude::count('tasksCount', 'tasks'),
                AllowedInclude::count('notesCount', 'notes'),
            )
            ->allowedSorts(
                'name', 'created_at', 'updated_at',
                ...($useCursor ? [] : $filterSchema->allowedSorts($user, 'opportunity')),
            )
            ->defaultSort('-created_at');

        return $this->paginateList($query, $perPage, $useCursor, $page);
    }
}
