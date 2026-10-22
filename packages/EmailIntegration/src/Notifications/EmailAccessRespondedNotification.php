<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Support\MarkdownText;

final class EmailAccessRespondedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-access-responded';

    public function __construct(public readonly EmailAccessRequest $request) {}

    public function workspace(): Workspace
    {
        return $this->request->emailIncludingHidden->workspace;
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

    public function preference(): NotificationType
    {
        return NotificationType::EmailAccess;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['outcome' => match ($this->request->status) {
            EmailAccessRequestStatus::APPROVED => 'approved',
            EmailAccessRequestStatus::EXPIRED => 'expired',
            default => 'denied',
        }];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $outcome = $this->facts()['outcome'];
        $email = $this->request->emailIncludingHidden;
        $ownerName = $this->request->owner->name;

        $mail = (new MailMessage)
            ->subject(__(self::LANG.".{$outcome}.title"))
            ->line(__(self::LANG.".{$outcome}.body", ['owner' => MarkdownText::escape($ownerName)]));

        if ($notifiable->can('viewSubject', $email)) {
            $mail->line(__(self::LANG.'.mail.subject_line', [
                'subject' => MarkdownText::escape(filled($email->subject) ? $email->subject : __('filament/pages/email-inbox.subject.none')),
            ]));
        }

        return $mail->action(
            __(self::LANG.($notifiable->can('viewBody', $email) ? '.mail.action_view' : '.mail.action_open')),
            $email->appUrlFor($notifiable),
        );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $outcome = self::outcomeOf($row);
        $ownerName = $row->actor?->name;

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title(__(self::LANG.".{$outcome}.title"))
            ->body($ownerName === null
                ? __(self::LANG.".{$outcome}.body_without_owner")
                : __(self::LANG.".{$outcome}.body", ['owner' => e($ownerName)]));

        return match ($outcome) {
            'approved' => $notification->success()->icon(Heroicon::OutlinedCheckCircle),
            'expired' => $notification->color('gray')->icon(Heroicon::OutlinedClock),
            default => $notification->danger()->icon(Heroicon::OutlinedXCircle),
        };
    }

    private static function outcomeOf(NotificationRow $row): string
    {
        $outcome = data_get($row->data, 'outcome');

        if (in_array($outcome, ['approved', 'denied', 'expired'], true)) {
            return $outcome;
        }

        return data_get($row->data, 'approved') === true ? 'approved' : 'denied';
    }
}
