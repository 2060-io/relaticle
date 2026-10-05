<?php

declare(strict_types=1);

namespace App\Queries\Tasks;

use App\Enums\CrmEntity;
use App\Queries\EntityQuery;

final readonly class TasksQuery extends EntityQuery
{
    public static function entity(): CrmEntity
    {
        return CrmEntity::Task;
    }

    public static function fields(): array
    {
        return ['id', 'title', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return ['creator', 'assignees', 'companies', 'people', 'opportunities'];
    }

    public static function countIncludes(): array
    {
        return [
            'assigneesCount' => 'assignees',
            'companiesCount' => 'companies',
            'peopleCount' => 'people',
            'opportunitiesCount' => 'opportunities',
        ];
    }
}
