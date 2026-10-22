<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Filament\Facades\Filament;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(ApproveEmailAccessRequestAction::class, CancelEmailAccessRequestAction::class, DenyEmailAccessRequestAction::class, RequestEmailAccessAction::class, EmailAccessRequestedNotification::class, EmailAccessRespondedNotification::class);

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
        'subject' => 'Confidential Thread',
    ]);
});

describe('ApproveEmailAccessRequestAction', function (): void {
    it('shares the email with the requester at the requested tier', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        $this->assertDatabaseHas('email_shares', [
            'email_id' => $this->email->getKey(),
            'shared_with' => $this->requester->id,
            'tier' => EmailPrivacyTier::FULL->value,
        ]);
    });

    it('updates request status to APPROVED', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::SUBJECT)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('removes the owner access-request notification when approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('leaves other pending access-request notifications in place', function (): void {
        $otherEmail = Email::factory()->private()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $otherRequest = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $this->owner->notify(new EmailAccessRequestedNotification($otherRequest));

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        $remaining = $this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->get();

        expect($remaining)->toHaveCount(1)
            ->and($remaining->first()->subject_id)->toBe($otherRequest->getKey());
    });

    it('sends a notification to the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $request->update(['status' => EmailAccessRequestStatus::APPROVED]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('aborts with 403 when actor is not the owner', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        Notification::fake();

        expect(fn () => app(ApproveEmailAccessRequestAction::class)->execute($request, $intruder))
            ->toThrow(HttpException::class);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('approves when the requester switched workspaces but remains on the email team', function (): void {
        $otherTeam = Workspace::factory()->create();
        $otherTeam->users()->attach($this->requester, ['role' => 'member']);
        $this->requester->forceFill(['current_workspace_id' => $otherTeam->getKey()])->save();

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('aborts with 403 when the requester left the email team', function (): void {
        $this->workspace->users()->detach($this->requester);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        expect(fn () => app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner))
            ->toThrow(HttpException::class);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
    });
});

describe('DenyEmailAccessRequestAction', function (): void {
    it('updates request status to DENIED', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('removes the owner access-request notification when denied', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('leaves other pending access-request notifications in place', function (): void {
        $otherEmail = Email::factory()->private()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $otherRequest = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $this->owner->notify(new EmailAccessRequestedNotification($otherRequest));

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        $remaining = $this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->get();

        expect($remaining)->toHaveCount(1)
            ->and($remaining->first()->subject_id)->toBe($otherRequest->getKey());
    });

    it('sends a notification to the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does not create a share', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect(EmailShare::where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $request->update(['status' => EmailAccessRequestStatus::DENIED]);

        Notification::fake();

        app(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('aborts with 403 when actor is not the owner', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);

        Notification::fake();

        expect(fn () => app(DenyEmailAccessRequestAction::class)->execute($request, $intruder))
            ->toThrow(HttpException::class);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('CancelEmailAccessRequestAction', function (): void {
    it('deletes a pending request', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        app(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeFalse();
    });

    it('removes the owner access-request notification when cancelled', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        app(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));
        $request->update(['status' => EmailAccessRequestStatus::APPROVED]);

        app(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        app(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('aborts when the actor is not the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(fn () => app(CancelEmailAccessRequestAction::class)->execute($request, $this->owner))
            ->toThrow(HttpException::class);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
    });
});

describe('RequestEmailAccessAction', function (): void {
    it('creates a pending access request for a team member', function (): void {
        Notification::fake();

        $request = app(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        expect($request)->not->toBeNull()
            ->and($request->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($request->owner_id)->toBe($this->owner->id);
    });

    it('does not create a second pending request for the same email', function (): void {
        Notification::fake();

        $first = app(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        $second = app(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::SUBJECT);

        expect($first)->not->toBeNull()
            ->and($second)->toBeNull()
            ->and(EmailAccessRequest::query()->where('email_id', $this->email->getKey())->count())->toBe(1)
            ->and($first?->fresh()?->tier_requested)->toBe(EmailPrivacyTier::FULL->value);

        Notification::assertSentToTimes($this->owner, EmailAccessRequestedNotification::class, 1);
    });

    it('aborts with 403 when the requester is not in the email\'s team', function (): void {
        $outsider = User::factory()->withWorkspace()->create();

        expect($outsider->current_workspace_id)->not->toBe($this->workspace->id);

        expect(fn () => app(RequestEmailAccessAction::class)
            ->execute($this->email, $outsider, EmailPrivacyTier::FULL))
            ->toThrow(HttpException::class);

        expect(EmailAccessRequest::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });
});

describe('EmailAccessRequestedNotification', function (): void {
    it('stores the access request notice in the email\'s workspace', function (): void {
        $elsewhere = Workspace::factory()->create();
        $this->owner->forceFill(['current_workspace_id' => $elsewhere->getKey()])->save();
        $this->requester->forceFill(['current_workspace_id' => $elsewhere->getKey()])->save();
        resolve(CurrentWorkspace::class)->set($elsewhere);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->owner->notify(new EmailAccessRequestedNotification($request));

        $row = $this->owner->notifications()->sole();

        expect($row->type)->toBe(EmailAccessRequestedNotification::class)
            ->and($row->workspace_id)->toBe($this->workspace->getKey())
            ->and($row->actor_id)->toBe($this->requester->getKey())
            ->and($row->subject_type)->toBe('email_access_request')
            ->and($row->subject_id)->toBe($request->getKey())
            ->and($row->reason)->toBe(NotificationReason::Requested)
            ->and($row->data)->toEqual([
                'email_subject' => 'Confidential Thread',
                'email_id' => $this->email->getKey(),
            ]);
    });

    it('stores no access request notice once the request is decided before the job runs', function (): void {
        Queue::fake();

        $request = app(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);
        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        $queued = Queue::pushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job): bool => $job->notification instanceof EmailAccessRequestedNotification,
        )->sole();

        unserialize(serialize($queued))->handle(app(ChannelManager::class));

        expect($this->owner->notifications()->count())->toBe(0);
    });

    it('dismisses an access request notice written before this change', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $legacyNoticeFor = fn (EmailAccessRequest $for): NotificationRow => NotificationRow::factory()->create([
            'type' => EmailAccessRequestedNotification::class,
            'notifiable_type' => $this->owner->getMorphClass(),
            'notifiable_id' => $this->owner->getKey(),
            'data' => [
                'title' => 'Requester requested email access',
                'viewData' => ['request_id' => (string) $for->getKey()],
            ],
        ]);

        $legacyNoticeFor($request);
        $other = $legacyNoticeFor(EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]));

        app(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($this->owner->notifications()->pluck('id')->all())->toBe([$other->getKey()]);
    });
});

describe('EmailAccessRespondedNotification', function (): void {
    it('translates the response notice', function (string $status, string $title, string $body): void {
        $this->owner->update(['name' => 'Mira Okafor']);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $action = $status === 'approved' ? ApproveEmailAccessRequestAction::class : DenyEmailAccessRequestAction::class;
        app($action)->execute($request, $this->owner);

        $row = $this->requester->notifications()->sole();
        $presented = EmailAccessRespondedNotification::present($row);

        expect($row->workspace_id)->toBe($this->workspace->getKey())
            ->and($row->actor_id)->toBe($this->owner->getKey())
            ->and($row->subject_id)->toBe($request->getKey())
            ->and($row->reason)->toBe(NotificationReason::Requested)
            ->and($presented->getTitle())->toBe($title)
            ->and($presented->getBody())->toBe($body);
    })->with([
        'approved' => ['approved', 'Email access approved', 'Mira Okafor approved your request.'],
        'denied' => ['denied', 'Email access denied', 'Mira Okafor denied your request.'],
    ]);

    it('still sends the denial notice after the mailbox is disconnected', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->account->delete();

        expect($request->fresh()->email)->toBeNull();

        app(DenyEmailAccessRequestAction::class)->execute($request->fresh(), $this->owner);

        $row = $this->requester->notifications()->sole();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($row->type)->toBe(EmailAccessRespondedNotification::class)
            ->and($row->workspace_id)->toBe($this->workspace->getKey());
    });
});
