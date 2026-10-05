<?php

declare(strict_types=1);

namespace App\Queries\People;

use App\Enums\CrmEntity;
use App\Queries\EntityQuery;

final readonly class PeopleQuery extends EntityQuery
{
    public static function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    public static function fields(): array
    {
        return ['id', 'name', 'company_id', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return ['creator', 'company'];
    }

    public static function countIncludes(): array
    {
        return [
            'tasksCount' => 'tasks',
            'notesCount' => 'notes',
        ];
    }
}
