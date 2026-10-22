<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;

final class EmailSharedNotification extends InboxNotification
{
    public function __construct(
        public readonly Email $email,
        public readonly User $sharer,
        public readonly EmailPrivacyTier $tier,
    ) {}

    public function workspace(): Workspace
    {
        return $this->email->workspace;
    }

    public function subject(): Email
    {
        return $this->email;
    }

    public function actor(): User
    {
        return $this->sharer;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Shared;
    }

    public function preference(): NotificationType
    {
        return NotificationType::EmailShared;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => $this->email->subject,
            'tier' => $this->tier->value,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('filament/notifications/email-shared.mail.subject', ['name' => $this->sharer->name]))
            ->line(__('filament/notifications/email-shared.mail.line', [
                'subject' => filled($this->email->subject) ? $this->email->subject : __('filament/pages/email-inbox.subject.none'),
            ]))
            ->action(
                __('filament/notifications/email-shared.mail.action'),
                EmailInboxPage::getUrl(panel: 'app', tenant: $this->email->workspace),
            );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $emailSubject = data_get($row->data, 'email_subject');
        $sharerName = $row->actor?->name;

        return FilamentNotification::make((string) $row->getKey())
            ->title($sharerName === null
                ? __('filament/notifications/email-shared.title_without_actor')
                : __('filament/notifications/email-shared.title', ['name' => e($sharerName)]))
            ->body(is_string($emailSubject) && $emailSubject !== '' ? e($emailSubject) : __('filament/pages/email-inbox.subject.none'))
            ->icon(Heroicon::OutlinedEnvelope)
            ->iconColor('primary')
            ->actions([
                Action::make('viewEmail')
                    ->button()
                    ->label(__('filament/notifications/email-shared.actions.view'))
                    ->dispatchTo(EmailAccessNotificationHandler::LIVEWIRE_ALIAS, 'open-email-from-access-request')
                    ->eventData(['emailId' => (string) $row->subject_id]),
            ]);
    }
}
