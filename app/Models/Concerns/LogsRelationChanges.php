<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\ActivityLog\RelationChangeLog;
use Illuminate\Database\Eloquent\Model;

trait LogsRelationChanges
{
    /**
     * @return array<string, string>
     */
    abstract public function relationChangeLabels(): array;

    public static function bootLogsRelationChanges(): void
    {
        static::updated(function (Model $record): void {
            /** @var self $record */
            resolve(RelationChangeLog::class)->foreignKeys($record, $record->relationChangeLabels());
        });
    }
}
