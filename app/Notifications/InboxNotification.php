<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;

abstract class InboxNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    abstract public function workspace(): Workspace;

    abstract public function subject(): Model;

    abstract public function reason(): NotificationReason;

    /** @return array<string, mixed> */
    abstract public function facts(): array;

    abstract public static function present(NotificationRow $row): FilamentNotification;

    public function actor(): ?User
    {
        return null;
    }

    public function groupKey(): ?string
    {
        return null;
    }

    public function preference(): ?NotificationType
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function mergeFacts(array $existing): array
    {
        return $this->facts();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return method_exists($this, 'toMail') ? ['database', 'mail'] : ['database'];
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $actor = $this->actor();

        if ($actor instanceof User && $actor->is($notifiable)) {
            return false;
        }

        if (! $notifiable->belongsToWorkspaceId((string) $this->workspace()->getKey())) {
            return false;
        }

        $subject = $this->subject();

        if (CrmEntity::tryFromModel($subject) instanceof CrmEntity && $notifiable->cannot('view', $subject)) {
            return false;
        }

        return $this->allowedByPreference($notifiable, $channel);
    }

    /** @return array<string, mixed> */
    final public function toDatabase(User $notifiable): array
    {
        return $this->facts();
    }

    private function allowedByPreference(User $notifiable, string $channel): bool
    {
        $type = $this->preference();

        $preferenceChannel = match ($channel) {
            'database' => NotificationChannel::InApp,
            'mail' => NotificationChannel::Email,
            default => null,
        };

        if (! $type instanceof NotificationType || $preferenceChannel === null) {
            return true;
        }

        return $notifiable->wantsNotification($type, $preferenceChannel);
    }
}
