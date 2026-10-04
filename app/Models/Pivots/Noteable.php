<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Enums\CrmEntity;
use App\Models\Concerns\LogsLinkChanges;
use App\Models\Note;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

final class Noteable extends MorphPivot
{
    use LogsLinkChanges;

    protected function linkOwner(): ?Model
    {
        return $this->findUnscoped(Note::class, $this->getAttribute('note_id'));
    }

    protected function linkedRecord(): ?Model
    {
        $entity = CrmEntity::tryFrom((string) $this->getAttribute('noteable_type'));

        return $entity instanceof CrmEntity
            ? $this->findUnscoped($entity->model(), $this->getAttribute('noteable_id'))
            : null;
    }

    protected function linkRelation(): string
    {
        return CrmEntity::from((string) $this->getAttribute('noteable_type'))->relationName();
    }
}
