<?php

declare(strict_types=1);

namespace App\Livewire\App;

use App\Actions\Notifications\ArchiveNotification;
use App\Data\InboxRequest;
use App\Filament\Pages\NotificationPreferences;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use App\Support\CurrentWorkspace;
use App\Support\Inbox\InboxRequests;
use Carbon\CarbonImmutable;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The inbox panel: notifications and requests, opened beside the sidebar.
 * Filament's own trigger variants do not fit the sidebar's search row.
 */
final class AppDatabaseNotifications extends DatabaseNotifications
{
    private const string APPROVED = 'approved';

    private const string DENIED = 'denied';

    private const string ALREADY_DECIDED = 'already_decided';

    /** @var array<string, array{outcome: string, title: string, body: ?string, avatar: ?string, created_at: string}> */
    public array $decidedRequests = [];

    public int $announcedUnread = 0;

    public function mount(): void
    {
        $this->announcedUnread = $this->getUnreadNotificationsCount();
    }

    public function getTrigger(): View
    {
        return view('filament.app.notifications-trigger');
    }

    public function placeholder(): string
    {
        $workspace = resolve(CurrentWorkspace::class)->get();

        return '<div>'.$this->getTrigger()->with([
            'unreadNotificationsCount' => $this->getUnreadNotificationsCount(),
            'pendingRequestsCount' => $workspace instanceof Workspace ? resolve(InboxRequests::class)->countFor($this->requireUser(), $workspace) : 0,
            'isPlaceholder' => true,
        ])->render().'</div>';
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

    public function archiveNotification(string $id): void
    {
        abort_unless(Str::isUuid($id), 404);

        resolve(ArchiveNotification::class)->execute($this->requireUser(), $this->getNotificationsQuery()->findOrFail($id));
    }

    public function approveRequest(string $kind, string $id): void
    {
        $this->decideRequest($kind, $id, self::APPROVED);
    }

    public function denyRequest(string $kind, string $id): void
    {
        $this->decideRequest($kind, $id, self::DENIED);
    }

    public function render(): View
    {
        $user = $this->requireUser();
        $workspace = resolve(CurrentWorkspace::class)->get();
        $notifications = $this->getNotifications();
        $unreadCount = $this->getUnreadNotificationsCount();
        $pending = $workspace instanceof Workspace ? resolve(InboxRequests::class)->for($user, $workspace) : new Collection;

        $this->loadPresentedRelations($notifications);

        return view('livewire.app.database-notifications', [
            'groups' => $this->groupByDay($notifications, $user->effectiveTimezone()),
            'present' => $this->presentNotification(...),
            'requests' => $this->requestRows($pending),
            'unreadCount' => $unreadCount,
            'pendingCount' => $pending->count(),
            'newCount' => $this->takeNewCount($unreadCount),
            'settingsUrl' => $workspace instanceof Workspace ? NotificationPreferences::getUrl(tenant: $workspace) : null,
        ]);
    }

    private function decideRequest(string $kind, string $id, string $outcome): void
    {
        $user = $this->requireUser();
        $workspace = resolve(CurrentWorkspace::class)->get();

        abort_unless($workspace instanceof Workspace, 404);

        $requests = resolve(InboxRequests::class);
        $request = $requests->for($user, $workspace)
            ->first(fn (InboxRequest $pending): bool => $pending->kind === $kind && $pending->id === $id);

        $decided = $outcome === self::APPROVED
            ? $requests->approve($user, $workspace, $kind, $id)
            : $requests->deny($user, $workspace, $kind, $id);

        if (! $request instanceof InboxRequest) {
            return;
        }

        $this->decidedRequests[$id] = [
            'outcome' => $decided ? $outcome : self::ALREADY_DECIDED,
            'title' => $request->title,
            'body' => $request->body,
            'avatar' => $request->requester instanceof User ? filament()->getUserAvatarUrl($request->requester) : null,
            'created_at' => $request->createdAt->toIso8601String(),
        ];
    }

    private function requireUser(): User
    {
        $user = $this->getUser();

        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function takeNewCount(int $unreadCount): int
    {
        $new = max(0, $unreadCount - $this->announcedUnread);

        $this->announcedUnread = $unreadCount;

        return $new;
    }

    /**
     * @param  DatabaseNotificationCollection<int, NotificationRow>|Paginator<int, NotificationRow>  $notifications
     * @return array<string, list<NotificationRow>>
     */
    private function groupByDay(DatabaseNotificationCollection|Paginator $notifications, string $timezone): array
    {
        $startOfToday = today($timezone);
        $startOfYesterday = $startOfToday->subDay();
        $groups = ['today' => [], 'yesterday' => [], 'earlier' => []];
        $rows = $notifications instanceof Paginator ? $notifications->items() : $notifications->all();

        foreach ($rows as $row) {
            $createdAt = $row->created_at;

            $key = match (true) {
                $createdAt === null || $createdAt->greaterThanOrEqualTo($startOfToday) => 'today',
                $createdAt->greaterThanOrEqualTo($startOfYesterday) => 'yesterday',
                default => 'earlier',
            };

            $groups[$key][] = $row;
        }

        return $groups;
    }

    /**
     * @param  Collection<int, InboxRequest>  $pending
     * @return array<int, array{
     *     id: string,
     *     kind: string,
     *     title: string,
     *     body: ?string,
     *     avatar: ?string,
     *     createdAt: CarbonImmutable,
     *     expiresAt: ?CarbonImmutable,
     *     url: ?string,
     *     outcome: ?string
     * }>
     */
    private function requestRows(Collection $pending): array
    {
        $rows = $pending
            ->map(fn (InboxRequest $request): array => [
                'id' => $request->id,
                'kind' => $request->kind,
                'title' => $request->title,
                'body' => $request->body,
                'avatar' => $request->requester instanceof User ? filament()->getUserAvatarUrl($request->requester) : null,
                'createdAt' => $request->createdAt,
                'expiresAt' => $request->expiresAt,
                'url' => $request->url,
                'outcome' => null,
            ]);

        $decided = collect($this->decidedRequests)
            ->reject(fn (array $row, string $id): bool => $pending->contains('id', $id))
            ->map(fn (array $row, string $id): array => [
                'id' => $id,
                'kind' => '',
                'title' => $row['title'],
                'body' => $row['body'],
                'avatar' => $row['avatar'],
                'createdAt' => CarbonImmutable::parse($row['created_at']),
                'expiresAt' => null,
                'url' => null,
                'outcome' => $row['outcome'],
            ]);

        return $rows->concat($decided)->sortByDesc('createdAt')->values()->all();
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
