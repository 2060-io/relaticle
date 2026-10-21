<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\CurrentWorkspace;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Notifications with a compact trigger sized for the sidebar's search row.
 * Filament picks between a topbar icon button and a full-width sidebar button
 * from the configured position; neither fits beside the search field.
 */
final class AppDatabaseNotifications extends DatabaseNotifications
{
    public function getTrigger(): View
    {
        return view('filament.app.notifications-trigger');
    }

    /** @return MorphMany<NotificationRow, User> */
    public function getNotificationsQuery(): MorphMany
    {
        $user = $this->getUser();

        abort_unless($user instanceof User, 401);

        return $user->notifications()
            ->visibleIn(resolve(CurrentWorkspace::class)->get())
            ->with(['actor', 'workspace', 'subject'])
            ->reorder()
            ->latest('updated_at');
    }

    protected function presentNotification(DatabaseNotification $notification): FilamentNotification
    {
        $type = $notification->type;

        if (! $notification instanceof NotificationRow || $notification->reason === null || ! is_subclass_of($type, InboxNotification::class)) {
            return $this->getNotification($notification);
        }

        return $type::present($notification)
            ->date($this->formatNotificationDate($notification->getAttributeValue('updated_at')));
    }

    public function render(): View
    {
        return view('livewire.app.database-notifications');
    }
}
