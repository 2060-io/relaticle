<?php

declare(strict_types=1);

namespace App\Queries\Contracts;

use App\Data\ListQuery;
use App\Enums\CrmEntity;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Spatie\QueryBuilder\QueryBuilder;

interface EntityQuery
{
    public static function entity(): CrmEntity;

    /** @return list<string> */
    public static function fields(): array;

    /** @return list<string> */
    public static function includes(): array;

    /** @return list<string> */
    public static function sorts(): array;

    /** @return QueryBuilder<Model> */
    public function for(User $user, ListQuery $list): QueryBuilder;

    /** @return CursorPaginator<int, Model>|LengthAwarePaginator<int, Model> */
    public function paginate(User $user, ListQuery $list): CursorPaginator|LengthAwarePaginator;
}
