<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;

final readonly class QueuedSendNotifier
{
    public function send(Email $email): void
    {
        $notification = Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.queued.title'))
            ->body(__('filament/concerns/email-compose.notifications.queued.body'))
            ->success();

        if ($email->scheduled_for !== null && $email->scheduled_for->isFuture()) {
            $notification
                ->seconds(Config::integer('email-integration.outbox.undo_send_window_seconds'))
                ->actions([
                    Action::make('undo')
                        ->label(__('filament/concerns/email-compose.actions.undo.label'))
                        ->link()
                        ->close()
                        ->dispatchTo(
                            EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                            'undo-queued-send',
                        )
                        ->eventData(['emailId' => (string) $email->getKey()]),
                ]);
        }

        $notification->send();
    }

    public function sendHeld(Email $email, User $user, string $via): void
    {
        $recipients = $email->participants()
            ->where('role', EmailParticipantRole::TO)
            ->pluck('email_address')
            ->join(', ');

        Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.held.title', ['via' => $via]))
            ->body(__('filament/concerns/email-compose.notifications.held.body', [
                'subject' => (string) $email->subject,
                'recipients' => $recipients,
                'minutes' => (int) ceil(Config::integer('email-integration.outbox.agent_send_hold_seconds') / 60),
            ]))
            ->warning()
            ->actions([
                Action::make('cancelSend')
                    ->label(__('filament/concerns/email-compose.actions.cancel_send.label'))
                    ->button()
                    ->markAsRead()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'undo-queued-send',
                    )
                    ->eventData(['emailId' => (string) $email->getKey()]),
            ])
            ->sendToDatabase($user);
    }
}
