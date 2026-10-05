<?php

declare(strict_types=1);

namespace App\Queries\Notes;

use App\Enums\CrmEntity;
use App\Queries\Concerns\ListsEntity;
use App\Queries\Contracts\EntityQuery;

final readonly class NotesQuery implements EntityQuery
{
    use ListsEntity;

    public static function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    public static function fields(): array
    {
        return ['id', 'title', 'creator_id', 'created_at', 'updated_at'];
    }

    public static function includes(): array
    {
        return ['creator', 'companies', 'people', 'opportunities'];
    }

    public static function countIncludes(): array
    {
        return [
            'companiesCount' => 'companies',
            'peopleCount' => 'people',
            'opportunitiesCount' => 'opportunities',
        ];
    }
}
