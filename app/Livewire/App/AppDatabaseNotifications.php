<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\CurrentWorkspace;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;

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
            ->orderByDesc('id');
    }

    public function render(): View
    {
        $notifications = $this->getNotifications();

        $this->loadPresentedRelations($notifications);

        return view('livewire.app.database-notifications', [
            'notifications' => $notifications,
            'present' => $this->presentNotification(...),
        ]);
    }

    private function presentNotification(DatabaseNotification $notification): FilamentNotification
    {
        $type = $notification->type;

        if (! $notification instanceof NotificationRow || $notification->reason === null || ! is_subclass_of($type, InboxNotification::class)) {
            return $this->getNotification($notification);
        }

        // A non-persistent notice closes itself in the open bell, and closing deletes its row.
        return $type::present($notification)
            ->persistent()
            ->date($this->formatNotificationDate($notification->getAttributeValue('created_at')));
    }

    /** @param  DatabaseNotificationCollection<int, NotificationRow>|Paginator<int, NotificationRow>  $notifications */
    private function loadPresentedRelations(DatabaseNotificationCollection|Paginator $notifications): void
    {
        $rows = $notifications instanceof Paginator
            ? new EloquentCollection($notifications->items())
            : $notifications;

        $rows->load(['actor', 'workspace', 'subject']);
    }
}
