<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Concerns\LogsLinkChanges;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class TaskAssignee extends Pivot
{
    use LogsLinkChanges;

    protected function linkOwner(): ?Model
    {
        return $this->findUnscoped(Task::class, $this->getAttribute('task_id'));
    }

    protected function linkedRecord(): ?Model
    {
        return $this->findUnscoped(User::class, $this->getAttribute('user_id'));
    }

    protected function linkRelation(): string
    {
        return 'assignees';
    }
}
