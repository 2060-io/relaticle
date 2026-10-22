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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Support\MarkdownText;

final class EmailAccessRequestedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-access-requested';

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
        return $this->request->requester;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Requested;
    }

    public function preference(): NotificationType
    {
        return NotificationType::EmailAccess;
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if ($this->request->status !== EmailAccessRequestStatus::PENDING) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => $this->request->emailIncludingHidden->subject,
            'email_id' => $this->request->email_id,
        ];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $email = $this->request->emailIncludingHidden;
        $requesterName = $this->request->requester->name;

        return (new MailMessage)
            ->subject(__(self::LANG.'.mail.subject', ['name' => $requesterName]))
            ->line(__(self::LANG.'.mail.line', ['name' => MarkdownText::escape($requesterName)]))
            ->line(__(self::LANG.'.mail.subject_line', [
                'subject' => MarkdownText::escape(filled($email->subject) ? $email->subject : __('filament/pages/email-inbox.subject.none')),
            ]))
            ->action(
                __(self::LANG.'.mail.action'),
                EmailAccessRequestsPage::getUrl(panel: 'app', tenant: $email->workspace),
            );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $emailSubject = data_get($row->data, 'email_subject');
        $requesterName = $row->actor?->name;

        return FilamentNotification::make((string) $row->getKey())
            ->title($requesterName === null
                ? __('filament/notifications/email-access-requested.title_without_requester')
                : __('filament/notifications/email-access-requested.title', ['name' => e($requesterName)]))
            ->body(is_string($emailSubject) && $emailSubject !== '' ? e($emailSubject) : __('filament/pages/email-inbox.subject.none'))
            ->warning()
            ->icon(Heroicon::OutlinedKey)
            ->actions([
                self::action('viewEmail', 'view', 'info', 'open-email-from-access-request', ['emailId' => data_get($row->data, 'email_id')]),
                self::action('accept', 'accept', 'success', 'approve-email-access-request', ['requestId' => $row->subject_id]),
                self::action('decline', 'decline', 'warning', 'deny-email-access-request', ['requestId' => $row->subject_id]),
            ]);
    }

    /**
     * Delete the owner's bell row for this request. Same outcome as the
     * notification close (X) action: the row is removed, not marked read.
     */
    public static function dismissFor(EmailAccessRequest $request): void
    {
        $owner = $request->owner ?? User::query()->whereKey($request->owner_id)->first();

        if (! $owner instanceof User) {
            return;
        }

        $owner->notifications()
            ->where('type', self::class)
            ->where(fn (Builder $rows): Builder => $rows
                ->where(fn (Builder $current): Builder => $current
                    ->where('subject_type', $request->getMorphClass())
                    ->where('subject_id', $request->getKey()))
                ->orWhere('data->viewData->request_id', (string) $request->getKey()))
            ->delete();
    }

    /** @param  array<string, mixed>  $eventData */
    private static function action(string $name, string $label, string $color, string $event, array $eventData): Action
    {
        return Action::make($name)
            ->label(__("filament/notifications/email-access-requested.actions.{$label}"))
            ->link()
            ->color($color)
            ->dispatchTo(EmailAccessNotificationHandler::LIVEWIRE_ALIAS, $event)
            ->eventData($eventData);
    }
}
