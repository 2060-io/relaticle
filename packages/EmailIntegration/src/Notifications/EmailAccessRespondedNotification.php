<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRespondedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-access-responded';

    public function __construct(public readonly EmailAccessRequest $request) {}

    public function workspace(): Workspace
    {
        return $this->request->email->workspace;
    }

    public function subject(): EmailAccessRequest
    {
        return $this->request;
    }

    public function actor(): User
    {
        return $this->request->owner;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Requested;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['approved' => $this->request->status === EmailAccessRequestStatus::APPROVED];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $outcome = data_get($row->data, 'approved') === true ? 'approved' : 'denied';
        $ownerName = $row->actor?->name;

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title(__(self::LANG.".{$outcome}.title"))
            ->body($ownerName === null
                ? __(self::LANG.".{$outcome}.body_without_owner")
                : __(self::LANG.".{$outcome}.body", ['owner' => e($ownerName)]));

        return $outcome === 'approved'
            ? $notification->success()->icon(Heroicon::OutlinedCheckCircle)
            : $notification->danger()->icon(Heroicon::OutlinedXCircle);
    }
}
