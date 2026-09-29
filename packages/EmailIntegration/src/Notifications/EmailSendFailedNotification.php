<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Enums\EmailPageTab;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;

final class EmailSendFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $count,
        public readonly ?string $subject,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $body = $this->count === 1
            ? __('filament/notifications/email-send-failed.body_one', [
                'subject' => filled($this->subject) ? $this->subject : __('filament/notifications/email-send-failed.no_subject'),
            ])
            : __('filament/notifications/email-send-failed.body_many');

        return FilamentNotification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->title(trans_choice('filament/notifications/email-send-failed.title', $this->count, ['count' => $this->count]))
            ->body($body)
            ->actions([
                Action::make('viewFailed')
                    ->label(__('filament/notifications/email-send-failed.action'))
                    ->url(EmailInboxPage::getUrl(['tab' => EmailPageTab::FAILED->value], panel: 'app', tenant: $this->workspace)),
            ])
            ->getDatabaseMessage();
    }
}
