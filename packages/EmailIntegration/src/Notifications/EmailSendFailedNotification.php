<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Enums\SendFailureRemedy;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailSendFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $count,
        public readonly ?string $subject,
        public readonly SendFailureRemedy $remedy,
    ) {}

    public static function forMailbox(Workspace $workspace, ?ConnectedAccount $account, int $count, ?string $subject): self
    {
        return new self($workspace, $count, $subject, SendFailureRemedy::for($workspace, $account));
    }

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
        $copy = $this->remedy->value;

        $parameters = ['workspace' => $this->workspace->name];

        $body = $this->count === 1
            ? __("filament/notifications/email-send-failed.{$copy}.body_one", [
                ...$parameters,
                'subject' => filled($this->subject) ? $this->subject : __('filament/pages/email-inbox.subject.none'),
            ])
            : __("filament/notifications/email-send-failed.{$copy}.body_many", $parameters);

        return FilamentNotification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->title(trans_choice('filament/notifications/email-send-failed.title', $this->count, ['count' => $this->count]))
            ->body($body)
            ->actions([
                Action::make('viewFailed')
                    ->label(__("filament/notifications/email-send-failed.{$copy}.action"))
                    ->url($this->remedy->url($this->workspace)),
            ])
            ->getDatabaseMessage();
    }
}
