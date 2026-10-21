<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailSendHeldNotification;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

mutates(EmailAccessNotificationHandler::class, EmailAccessRequestedNotification::class, EmailSendHeldNotification::class, QueuedSendNotifier::class, RequestEmailAccessAction::class, CancelQueuedEmailAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->requester = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->requester, ['role' => 'member']);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Q4 pipeline review',
    ]);
});

describe('EmailAccessRequestedNotification', function (): void {
    it('opens the emails-tab reader overlay from view without dismissing the notification', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $payload = (new EmailAccessRequestedNotification($request))->toDatabase($this->owner);
        $actions = collect($payload['actions'] ?? [])->keyBy('name');

        expect($actions->keys()->all())->toBe(['viewEmail', 'accept', 'decline'])
            ->and($payload['body'])->toBe('Q4 pipeline review')
            ->and($payload['viewData']['request_id'])->toBe((string) $request->getKey())
            ->and($actions['viewEmail']['label'])->toBe('View')
            ->and($actions['viewEmail']['shouldMarkAsRead'] ?? false)->toBeFalse()
            ->and($actions['viewEmail']['shouldClose'] ?? false)->toBeFalse()
            ->and($actions['viewEmail']['event'])->toBe('open-email-from-access-request')
            ->and($actions['viewEmail']['eventData'])->toBe(['emailId' => $this->email->getKey()])
            ->and($actions['accept']['event'])->toBe('approve-email-access-request')
            ->and($actions['accept']['shouldClose'] ?? false)->toBeFalse()
            ->and($actions['accept']['shouldMarkAsRead'] ?? false)->toBeFalse()
            ->and($actions['decline']['event'])->toBe('deny-email-access-request')
            ->and($actions['decline']['shouldClose'] ?? false)->toBeFalse()
            ->and($actions['decline']['shouldMarkAsRead'] ?? false)->toBeFalse();

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $notification = $this->owner->unreadNotifications()->latest()->first();

        expect($notification)->not->toBeNull()
            ->and($notification->read_at)->toBeNull();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey());

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1)
            ->and($notification->fresh()->read_at)->toBeNull();
    });

    it('is sent when a teammate requests access', function (): void {
        NotificationFacade::fake();

        app(RequestEmailAccessAction::class)->execute(
            $this->email,
            $this->requester,
            EmailPrivacyTier::FULL,
        );

        NotificationFacade::assertSentTo($this->owner, EmailAccessRequestedNotification::class);
    });
});

describe('EmailAccessNotificationHandler', function (): void {
    it('opens the emails-tab reader overlay from the notification event', function (): void {
        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->assertSee(__('filament/pages/email-inbox.reader.heading'))
            ->assertSee('Q4 pipeline review')
            ->assertSee('fi-email-reader-panel')
            ->assertDispatched('close-modal', id: 'database-notifications');
    });

    it('shows approve and deny controls in the overlay for a pending request', function (): void {
        EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee($this->requester->name)
            ->assertSee(__('filament/pages/email-inbox.pending_access.approve'))
            ->assertSee(__('filament/pages/email-inbox.pending_access.deny'));
    });

    it('approves a pending request from the notification event', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertSet('selectedEmailId', null)
            ->assertDispatched('close-modal', id: 'database-notifications')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.approved'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('denies a pending request from the notification event', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('deny-email-access-request', requestId: $request->getKey())
            ->assertSet('selectedEmailId', null)
            ->assertDispatched('close-modal', id: 'database-notifications')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('closes the overlay after approving from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('fi-email-reader-panel')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.approved'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('closes the overlay after denying from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->callAction(TestAction::make('denyAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('fi-email-reader-panel')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('ignores approve for requests the viewer does not own', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->actingAs($intruder);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('ignores deny for requests the viewer does not own', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->actingAs($intruder);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('deny-email-access-request', requestId: $request->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('does not claim success when accepting a request that is no longer pending', function (): void {
        $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('dismisses the inline composer when selectedEmailId is cleared', function (): void {
        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->set('selectedEmailId', null)
            ->assertDispatched('composer:dismiss-inline');
    });
});

describe('undo queued send', function (): void {
    it('cancels the sender\'s queued email from the undo toast', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.cancelled.title'))
            ->assertDispatched('outbox:changed');

        expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
    });

    it('does not cancel another user\'s queued email from the undo toast', function (): void {
        $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $theirAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
        ]));
        $theirs = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
            'connected_account_id' => $theirAccount->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $theirs->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.not_found.title'));

        expect($theirs->refresh()->status)->toBe(EmailStatus::QUEUED);
    });

    it('says the email is already cancelled when undo is pressed twice', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.cancelled.title'))
            ->assertNotNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
    });

    it('notifies too late when the queued email has already been sent', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::SENT,
            'sent_at' => now(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::SENT);
    });

    it('notifies too late when the queued email has already started sending', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::SENDING,
            'scheduled_for' => now()->subSecond(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::SENDING);
    });
});

describe('EmailSendHeldNotification', function (): void {
    beforeEach(function (): void {
        $this->held = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Next steps',
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(300),
        ]);
    });

    it('stores the held notice in the email\'s workspace', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, $this->workspace, 'Claude', 300);

        $row = $this->owner->notifications()->sole();

        expect($row->type)->toBe(EmailSendHeldNotification::class)
            ->and($row->workspace_id)->toBe($this->workspace->getKey())
            ->and($row->subject_type)->toBe('email')
            ->and($row->subject_id)->toBe($this->held->getKey())
            ->and($row->reason)->toBe(NotificationReason::System)
            ->and($row->group_key)->toBe("send-held:{$this->held->getKey()}")
            ->and($row->data['minutes'])->toBe(5)
            ->and($row->data['via'])->toBe('Claude');
    });

    it('stops offering cancel once the hold has passed', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, $this->workspace, 'Claude', 300);

        $row = $this->owner->notifications()->sole();
        $held = EmailSendHeldNotification::present($row);

        expect($held->getActions())->toHaveCount(1)
            ->and($held->getActions()[0]->getName())->toBe('cancelSend')
            ->and($held->getBody())->toContain('goes to');

        $this->travelTo(now()->addSeconds(301));

        $sent = EmailSendHeldNotification::present($row);

        expect($sent->getActions())->toBe([])
            ->and($sent->getBody())->toBe(__('filament/concerns/email-compose.notifications.held.sent_body', [
                'subject' => 'Next steps',
                'recipients' => '',
                'workspace' => e($this->workspace->name),
            ]));
    });

    it('shows the held notice in the bell until the hold passes', function (): void {
        resolve(CurrentWorkspace::class)->set($this->workspace);
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, $this->workspace, 'Claude', 300);

        Livewire::actingAs($this->owner)->test(AppDatabaseNotifications::class)
            ->assertSee('Claude queued an email')
            ->assertSee(__('filament/concerns/email-compose.actions.cancel_send.label'));

        $this->travelTo(now()->addSeconds(301));

        Livewire::actingAs($this->owner)->test(AppDatabaseNotifications::class)
            ->assertSee('Claude queued an email')
            ->assertDontSee(__('filament/concerns/email-compose.actions.cancel_send.label'));
    });

    it('stores the notice at once instead of queueing it', function (): void {
        Queue::fake();

        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, $this->workspace, 'Claude', 300);

        expect($this->owner->notifications()->count())->toBe(1);
        Queue::assertNotPushed(SendQueuedNotifications::class);
    });
});
