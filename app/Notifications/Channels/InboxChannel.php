<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\InboxNotification;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

final class InboxChannel extends DatabaseChannel
{
    public function send(mixed $notifiable, Notification $notification): Model
    {
        if (! $notification instanceof InboxNotification || ! $notifiable instanceof User) {
            return parent::send($notifiable, $notification);
        }

        $columns = [
            'type' => $notification::class,
            'workspace_id' => $notification->workspace()->getKey(),
            'actor_id' => $notification->actor()?->getKey(),
            'subject_type' => $notification->subject()->getMorphClass(),
            'subject_id' => $notification->subject()->getKey(),
            'reason' => $notification->reason()->value,
            'group_key' => $notification->groupKey(),
        ];

        $row = $this->collapse($notifiable, $notification, $columns)
            ?? $this->insertOrCollapse($notifiable, $notification, $columns);

        event(new DatabaseNotificationsSent($notifiable));

        return $row;
    }

    /** @param  array<string, mixed>  $columns */
    private function collapse(User $notifiable, InboxNotification $notification, array $columns): ?NotificationRow
    {
        if ($columns['group_key'] === null) {
            return null;
        }

        return DB::transaction(function () use ($notifiable, $notification, $columns): ?NotificationRow {
            $row = $notifiable->notifications()
                ->where('group_key', $columns['group_key'])
                ->whereNull('read_at')
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->first();

            if (! $row instanceof NotificationRow) {
                return null;
            }

            $row->forceFill([
                ...$columns,
                'data' => $notification->mergeFacts($row->data),
                'updated_at' => now(),
            ])->save();

            return $row;
        });
    }

    /** @param  array<string, mixed>  $columns */
    private function insertOrCollapse(User $notifiable, InboxNotification $notification, array $columns): NotificationRow
    {
        try {
            return DB::transaction(fn (): NotificationRow => $notifiable->notifications()->create([
                ...$columns,
                'id' => $notification->id,
                'data' => $notification->toDatabase($notifiable),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->collapse($notifiable, $notification, $columns) ?? throw $exception;
        }
    }
}
