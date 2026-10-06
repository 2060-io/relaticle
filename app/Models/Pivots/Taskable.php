<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\CrmEntity;
use App\Models\Concerns\LogsLinkChanges;
use App\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

final class Taskable extends MorphPivot
{
    use LogsLinkChanges;

    protected function linkOwner(): ?Model
    {
        return $this->findUnscoped(Task::class, $this->getAttribute('task_id'));
    }

    protected function linkedRecord(): ?Model
    {
        $entity = CrmEntity::tryFrom((string) $this->getAttribute('taskable_type'));

        return $entity instanceof CrmEntity
            ? $this->findUnscoped($entity->model(), $this->getAttribute('taskable_id'))
            : null;
    }

    protected function linkRelation(): string
    {
        return CrmEntity::from((string) $this->getAttribute('taskable_type'))->relationName();
    }
}
