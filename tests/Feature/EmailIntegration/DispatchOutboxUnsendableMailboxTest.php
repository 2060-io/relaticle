<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\MarkEmailsSendFailedAction;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\SendEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;

mutates(DispatchOutboxCommand::class, MarkEmailsSendFailedAction::class, SyncEmailBatchCountersAction::class, EmailSendFailedNotification::class, ConnectedAccount::class);

beforeEach(function (): void {
    Bus::fake();
    Notification::fake();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => EmailAccountStatus::REAUTH_REQUIRED,
    ]));

    $this->queue = fn (array $attributes = []): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'scheduled_for' => null,
        ...$attributes,
    ]);
});

it('fails due queued mail on a mailbox that needs reconnecting and tells the sender once', function (): void {
    $first = ($this->queue)();
    $second = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($first->fresh())
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe(__('filament/notifications/email-send-failed.reasons.mailbox_needs_reconnect'))
        ->and($second->fresh()->status)->toBe(EmailStatus::FAILED);

    Bus::assertNotDispatched(SendEmailJob::class);
    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, function (EmailSendFailedNotification $notification): bool {
        $message = $notification->toDatabase($this->user);

        return $notification->count === 2
            && $message['title'] === '2 emails not sent'
            && $message['body'] === 'Their mailbox needs reconnecting. Reconnect it, then retry them from the Failed tab.'
            && $message['actions'][0]['url'] === EmailAccountsPage::getUrl(panel: 'app', tenant: $this->workspace);
    });
});

it('fails due queued mail on a disconnected mailbox', function (): void {
    $email = ($this->queue)();
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect(Email::withoutGlobalScopes()->findOrFail($email->getKey())->status)->toBe(EmailStatus::FAILED);
    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->mailboxNeedsReconnect);
});

it('fails due queued mail on an active mailbox that lost send access', function (): void {
    $this->account->update(['status' => EmailAccountStatus::ACTIVE, 'capabilities' => ['email' => true, 'send' => false]]);
    $email = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->toBe(EmailStatus::FAILED);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('fails mail stuck sending on a disconnected mailbox instead of sending it after reconnect', function (): void {
    $stuck = ($this->queue)(['status' => EmailStatus::SENDING, 'provider_message_id' => null]);
    Email::withoutGlobalScopes()->whereKey($stuck->getKey())->toBase()->update(['updated_at' => now()->subDay()]);
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();
    $this->account->restore();
    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(EmailStatus::FAILED);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('counts failed mass-send mail on a disconnected mailbox and tells the sender once', function (): void {
    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'total_recipients' => 2,
    ]);
    ($this->queue)(['batch_id' => $batch->getKey()]);
    ($this->queue)(['batch_id' => $batch->getKey()]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($batch->fresh())
        ->failed_count->toBe(2)
        ->status->toBe(EmailBatchStatus::PartialFailure);

    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->count === 2
        && $notification->mailboxNeedsReconnect);
});

it('leaves mail scheduled for later queued on a mailbox that needs reconnecting', function (): void {
    $later = ($this->queue)(['scheduled_for' => now()->addDay()]);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($later->fresh()->status)->toBe(EmailStatus::QUEUED);
    Notification::assertNothingSent();
});

it('keeps sending due mail on an active mailbox', function (): void {
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $email = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->not->toBe(EmailStatus::FAILED);
    Notification::assertNothingSent();
});
