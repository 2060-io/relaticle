<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

final class EmailSendHeldNotification extends InboxNotification
{
    public function __construct(
        private readonly Email $email,
        private readonly Workspace $workspace,
        private readonly string $via,
        private readonly int $holdSeconds,
    ) {}

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Email
    {
        return $this->email;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "send-held:{$this->email->getKey()}";
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => (string) $this->email->subject,
            'recipients' => $this->recipients(),
            'via' => $this->via,
            'minutes' => max(1, intdiv($this->holdSeconds, 60)),
            'deadline' => now()->addSeconds($this->holdSeconds)->toIso8601ZuluString(),
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $holding = now()->isBefore(Date::parse((string) data_get($row->data, 'deadline')));

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title(__('filament/concerns/email-compose.notifications.held.title', ['via' => e((string) data_get($row->data, 'via'))]))
            ->body(__(
                $holding
                    ? 'filament/concerns/email-compose.notifications.held.body'
                    : 'filament/concerns/email-compose.notifications.held.sent_body',
                [
                    'subject' => e((string) data_get($row->data, 'email_subject')),
                    'recipients' => e(implode(', ', (array) data_get($row->data, 'recipients'))),
                    'minutes' => trans_choice('filament/concerns/email-compose.notifications.held.minutes', (int) data_get($row->data, 'minutes')),
                    'workspace' => e($row->workspace->name ?? ''),
                ],
            ))
            ->warning();

        return $holding ? $notification->actions([self::cancelAction($row)]) : $notification;
    }

    private static function cancelAction(NotificationRow $row): Action
    {
        return Action::make('cancelSend')
            ->label(__('filament/concerns/email-compose.actions.cancel_send.label'))
            ->button()
            ->markAsRead()
            ->dispatchTo(
                EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                'undo-queued-send',
            )
            ->eventData(['emailId' => (string) $row->subject_id]);
    }

    /** @return list<string> */
    private function recipients(): array
    {
        $order = [EmailParticipantRole::TO, EmailParticipantRole::CC, EmailParticipantRole::BCC];

        $addresses = $this->email->participants()
            ->whereIn('role', $order)
            ->get()
            ->sortBy(fn (EmailParticipant $participant): int|false => array_search($participant->role, $order, true))
            ->map(fn (EmailParticipant $participant): string => $participant->email_address);

        return array_values($addresses->all());
    }
}
