<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\ActivityLog\RelationChangeLog;
use Illuminate\Database\Eloquent\Model;

trait LogsLinkChanges
{
    abstract protected function linkOwner(): ?Model;

    abstract protected function linkedRecord(): ?Model;

    abstract protected function linkRelation(): string;

    public static function bootLogsLinkChanges(): void
    {
        static::created(function (Model $pivot): void {
            /** @var self $pivot */
            $pivot->logLinkChange(attached: true);
        });

        static::deleted(function (Model $pivot): void {
            /** @var self $pivot */
            $pivot->logLinkChange(attached: false);
        });
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function findUnscoped(string $model, mixed $key): ?Model
    {
        if ($this->pivotParent instanceof $model && $this->pivotParent->getKey() === $key) {
            return $this->pivotParent;
        }

        return $model::query()->withoutGlobalScopes()->whereKey($key)->first();
    }

    private function logLinkChange(bool $attached): void
    {
        $owner = $this->linkOwner();
        $record = $this->linkedRecord();

        if (! $owner instanceof Model || ! $record instanceof Model) {
            return;
        }

        $relation = $this->linkRelation();

        resolve(RelationChangeLog::class)->link(
            $owner,
            $relation,
            __("filament/resources/{$owner->getMorphClass()}.fields.{$relation}.label"),
            removed: $attached ? null : $record,
            added: $attached ? $record : null,
        );
    }
}
