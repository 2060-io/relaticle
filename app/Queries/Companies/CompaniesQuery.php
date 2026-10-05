<?php

declare(strict_types=1);

namespace App\Queries\Companies;

use App\Enums\CrmEntity;
use App\Queries\EntityQuery;

final readonly class CompaniesQuery extends EntityQuery
{
    public static function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    public static function fields(): array
    {
        return ['id', 'name', 'creator_id', 'account_owner_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return ['creator', 'accountOwner', 'people', 'opportunities'];
    }

    public static function countIncludes(): array
    {
        return [
            'peopleCount' => 'people',
            'opportunitiesCount' => 'opportunities',
            'tasksCount' => 'tasks',
            'notesCount' => 'notes',
        ];
    }
}
