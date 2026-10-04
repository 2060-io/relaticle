<?php

declare(strict_types=1);

namespace App\Support\ActivityLog;

use Illuminate\Database\Eloquent\Model;

final readonly class LabelledChangeLog
{
    public const string EVENT = 'custom_field_changes';

    /**
     * @param  array{value: mixed, label: string}  $old
     * @param  array{value: mixed, label: string}  $new
     */
    public static function write(Model $record, string $code, string $label, string $type, array $old, array $new): void
    {
        activity((string) config('activitylog.default_log_name'))
            ->performedOn($record)
            ->causedBy(auth()->user())
            ->withProperties([
                self::EVENT => [[
                    'code' => $code,
                    'label' => $label,
                    'type' => $type,
                    'old' => $old,
                    'new' => $new,
                ]],
            ])
            ->event(self::EVENT)
            ->log(self::EVENT);
    }
}
