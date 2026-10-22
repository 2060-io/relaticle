<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Models\ActivityLog\Activity;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\ApproveEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\DecideEmailAccessRequest;
use Relaticle\EmailIntegration\Actions\DenyEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Console\Commands\ExpireEmailAccessRequestsCommand;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(ApproveEmailAccessRequestAction::class, DecideEmailAccessRequest::class, CancelEmailAccessRequestAction::class, DenyEmailAccessRequestAction::class, RequestEmailAccessAction::class, EmailAccessRequestedNotification::class, EmailAccessRespondedNotification::class, ExpireEmailAccessRequestsCommand::class);

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

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('removes the owner access-request notification when approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        legacyAccessRequestNotice($this->owner, $request);
        legacyAccessRequestNotice($this->owner, $otherRequest);

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);
        $request->update(['status' => EmailAccessRequestStatus::APPROVED]);

        Notification::fake();

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->denied()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('aborts with 403 when actor is not the owner', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);

        legacyAccessRequestNotice($this->owner, $request);

        Notification::fake();

        expect(fn () => resolve(ApproveEmailAccessRequestAction::class)->execute($request, $intruder))
            ->toThrow(HttpException::class);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
        expect(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0)
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

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        expect(fn () => resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner))
            ->toThrow(HttpException::class);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
        expect(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('records who decided and when', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        $request->refresh();

        expect($request->decided_by)->toBe($this->owner->getKey())
            ->and($request->decided_at)->not->toBeNull()
            ->and(Activity::query()->where('subject_id', $request->getKey())->where('event', 'approved')->count())->toBe(1);
    });

    it('lets one decision win when the same request is decided twice', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);
        $staleCopy = EmailAccessRequest::query()->findOrFail($request->getKey());

        Notification::fake();

        $outcomes = [
            resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner),
            resolve(ApproveEmailAccessRequestAction::class)->execute($staleCopy, $this->owner),
            resolve(DenyEmailAccessRequestAction::class)->execute($staleCopy, $this->owner),
        ];

        expect($outcomes)->toBe([true, false, false]);
        Notification::assertSentToTimes($this->requester, EmailAccessRespondedNotification::class, 1);
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(1);
    });

    it('does not approve an expired request', function (): void {
        $request = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        $this->travelTo(now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS)->addMinute());

        Notification::fake();

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('still approves a pending request that has no deadline', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'expires_at' => null,
        ]);

        Notification::fake();

        expect(resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner))->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('reports that an overdue request was not approved', function (): void {
        $request = EmailAccessRequest::factory()->overdue()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner))->toBeFalse();
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

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('removes the owner access-request notification when denied', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        legacyAccessRequestNotice($this->owner, $request);
        legacyAccessRequestNotice($this->owner, $otherRequest);

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class);
    });

    it('does not create a share', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        expect(EmailShare::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->approved()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('does nothing when request is already denied', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);
        $request->update(['status' => EmailAccessRequestStatus::DENIED]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

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

        expect(fn () => resolve(DenyEmailAccessRequestAction::class)->execute($request, $intruder))
            ->toThrow(HttpException::class);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });

    it('records who decided and when', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        $request->refresh();

        expect($request->decided_by)->toBe($this->owner->getKey())
            ->and($request->decided_at)->not->toBeNull()
            ->and(Activity::query()->where('subject_id', $request->getKey())->where('event', 'denied')->count())->toBe(1);
    });

    it('does not deny an expired request', function (): void {
        $request = EmailAccessRequest::factory()->overdue()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertNothingSent();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });

    it('reports whether it decided', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);
        $staleCopy = EmailAccessRequest::query()->findOrFail($request->getKey());

        Notification::fake();

        expect(resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner))->toBeTrue()
            ->and(resolve(DenyEmailAccessRequestAction::class)->execute($staleCopy, $this->owner))->toBeFalse();
    });
});

describe('CancelEmailAccessRequestAction', function (): void {
    it('keeps the request as cancelled', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        $request->refresh();

        expect($request->status)->toBe(EmailAccessRequestStatus::CANCELLED)
            ->and($request->decided_by)->toBe($this->requester->getKey())
            ->and($request->decided_at)->not->toBeNull();
    });

    it('removes the owner access-request notification when cancelled', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);

        resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('does nothing when request is already approved', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);
        $request->update(['status' => EmailAccessRequestStatus::APPROVED]);

        resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

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

        resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('aborts when the actor is not the requester', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(fn () => resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->owner))
            ->toThrow(HttpException::class);

        expect(EmailAccessRequest::query()->whereKey($request->getKey())->exists())->toBeTrue();
    });

    it('does not cancel a request someone else decided first', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);
        $staleCopy = EmailAccessRequest::query()->findOrFail($request->getKey());

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);
        resolve(CancelEmailAccessRequestAction::class)->execute($staleCopy, $this->requester);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('reports whether it cancelled', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester))->toBeTrue()
            ->and(resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester))->toBeFalse();
    });
});

describe('RequestEmailAccessAction', function (): void {
    it('creates a pending access request for a team member', function (): void {
        Notification::fake();

        $request = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        expect($request)->not->toBeNull()
            ->and($request->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($request->owner_id)->toBe($this->owner->id);
    });

    it('does not create a second pending request for the same email', function (): void {
        Notification::fake();

        $first = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        $second = resolve(RequestEmailAccessAction::class)
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

        expect(fn () => resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $outsider, EmailPrivacyTier::FULL))
            ->toThrow(HttpException::class);

        expect(EmailAccessRequest::query()->where('email_id', $this->email->getKey())->count())->toBe(0);
    });

    it('stores the email workspace and a thirty day expiry', function (): void {
        Notification::fake();

        $this->travelTo(now()->startOfSecond());

        $request = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        expect($request->workspace_id)->toBe($this->workspace->getKey())
            ->and($request->expires_at->equalTo(now()->addDays(30)))->toBeTrue();
    });

    it('allows a new request once the previous one ended', function (string $ending): void {
        Notification::fake();

        $first = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        match ($ending) {
            'cancelled' => resolve(CancelEmailAccessRequestAction::class)->execute($first, $this->requester),
            'denied' => resolve(DenyEmailAccessRequestAction::class)->execute($first, $this->owner),
            'expired' => $this->travelTo(now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS)->addMinute()),
        };

        $second = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::SUBJECT);

        expect($second)->not->toBeNull()
            ->and($second->getKey())->not->toBe($first->getKey());
    })->with(['cancelled', 'denied', 'expired']);

    it('closes an overdue request without telling the requester when they ask again', function (): void {
        Notification::fake();

        $first = resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        $this->travelTo(now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS)->addMinute());

        resolve(RequestEmailAccessAction::class)
            ->execute($this->email, $this->requester, EmailPrivacyTier::SUBJECT);

        $this->artisan('email:expire-access-requests')->expectsOutputToContain('Expired 0 access request(s).');

        expect($first->fresh()->status)->toBe(EmailAccessRequestStatus::EXPIRED);

        Notification::assertNotSentTo($this->requester, EmailAccessRespondedNotification::class);
    });
});

describe('EmailAccessRequestedNotification', function (): void {
    it('mails the owner and stores no database row', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $notification = new EmailAccessRequestedNotification($request);

        $this->owner->notify($notification);

        expect($notification->via($this->owner))->toBe(['mail'])
            ->and($this->owner->notifications()->count())->toBe(0);
    });

    it('sends the owner mail when the Email access email preference is on', function (): void {
        Notification::fake();

        resolve(RequestEmailAccessAction::class)->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        Notification::assertSentTo($this->owner, EmailAccessRequestedNotification::class, fn (EmailAccessRequestedNotification $notification): bool => $notification->via($this->owner) === ['mail']);
    });

    it('sends no mail when the Email access email preference is off', function (): void {
        Notification::fake();
        $this->owner->update(['notification_preferences' => ['email_access' => ['email' => false]]]);

        resolve(RequestEmailAccessAction::class)->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        Notification::assertNothingSent();
    });

    it('names the requester and links to the access requests page in the mail', function (): void {
        $this->requester->update(['name' => 'Dana *Whitfield*']);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $mail = new EmailAccessRequestedNotification($request->fresh())->toMail($this->owner);

        expect($mail->subject)->toBe('Dana *Whitfield* requested email access')
            ->and($mail->introLines)->toContain('Dana \\*Whitfield\\* asked for access to one of your emails.')
            ->and($mail->introLines)->toContain('Subject: Confidential Thread')
            ->and($mail->actionUrl)->toBe(EmailAccessRequestsPage::getUrl(panel: 'app', tenant: $this->workspace));
    });

    it('sends no mail once the request is decided before the job runs', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'status' => EmailAccessRequestStatus::DENIED,
        ]);

        expect(new EmailAccessRequestedNotification($request)->shouldSend($this->owner, 'mail'))->toBeFalse();
    });

    it('dismisses an access request notice written before this change', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        legacyAccessRequestNotice($this->owner, $request);
        $other = legacyAccessRequestNotice($this->owner, EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]));

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

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
        resolve($action)->execute($request, $this->owner);

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

    it('mails the outcome with the subject and a reader link once the requester can read the email', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(ApproveEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class, function (EmailAccessRespondedNotification $notification, array $channels): bool {
            $mail = $notification->toMail($this->requester);
            $html = (string) $mail->render();

            return in_array('mail', $channels, true)
                && $mail->subject === 'Email access approved'
                && str_contains($html, 'Confidential Thread')
                && $mail->actionText === 'View email'
                && str_contains($mail->actionUrl, 'email='.$this->email->getKey());
        });
    });

    it('mails a denial without the subject and links to the app home', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class, function (EmailAccessRespondedNotification $notification): bool {
            $mail = $notification->toMail($this->requester);

            return $mail->subject === 'Email access denied'
                && ! str_contains((string) $mail->render(), 'Confidential Thread')
                && $mail->actionText === 'Open Relaticle'
                && ! str_contains($mail->actionUrl, 'email=');
        });
    });

    it('escapes Markdown in the owner name', function (): void {
        $this->owner->update(['name' => '[x](https://evil.example)']);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class, fn (EmailAccessRespondedNotification $notification): bool => ! str_contains((string) $notification->toMail($this->requester)->render(), '<a href="https://evil.example'));
    });

    it('sends the outcome mail only while the Email channel of Email access is on', function (): void {
        $this->requester->update(['notification_preferences' => ['email_access' => ['email' => false]]]);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Notification::fake();

        resolve(DenyEmailAccessRequestAction::class)->execute($request, $this->owner);

        Notification::assertSentTo($this->requester, EmailAccessRespondedNotification::class, fn (EmailAccessRespondedNotification $notification, array $channels): bool => array_values($channels) === ['database']);
    });

    it('presents an expired request in gray', function (): void {
        $this->owner->update(['name' => 'Mira Okafor']);

        $request = EmailAccessRequest::factory()->overdue()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->artisan('email:expire-access-requests')->assertSuccessful();

        $row = $this->requester->notifications()->sole();
        $presented = EmailAccessRespondedNotification::present($row);

        expect($row->data)->toBe(['outcome' => 'expired'])
            ->and($row->subject_id)->toBe($request->getKey())
            ->and($presented->getTitle())->toBe('Email access request expired')
            ->and($presented->getBody())->toBe('Mira Okafor did not respond in time.')
            ->and($presented->getColor())->toBe('gray');
    });

    it('still sends the denial notice after the mailbox is disconnected', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->account->delete();

        expect($request->fresh()->email)->toBeNull();

        resolve(DenyEmailAccessRequestAction::class)->execute($request->fresh(), $this->owner);

        $row = $this->requester->notifications()->sole();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($row->type)->toBe(EmailAccessRespondedNotification::class)
            ->and($row->workspace_id)->toBe($this->workspace->getKey());
    });

    it('presents a response notice written before outcomes were stored', function (): void {
        $this->owner->update(['name' => 'Mira Okafor']);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $row = NotificationRow::factory()->create([
            'type' => EmailAccessRespondedNotification::class,
            'notifiable_type' => $this->requester->getMorphClass(),
            'notifiable_id' => $this->requester->getKey(),
            'actor_id' => $this->owner->getKey(),
            'subject_type' => $request->getMorphClass(),
            'subject_id' => $request->getKey(),
            'data' => ['approved' => true],
        ]);

        $presented = EmailAccessRespondedNotification::present($row);

        expect($presented->getTitle())->toBe('Email access approved')
            ->and($presented->getBody())->toBe('Mira Okafor approved your request.');
    });
});

describe('open requests', function (): void {
    it('counts a pending request until its deadline and one without a deadline', function (): void {
        $make = fn (array $state): EmailAccessRequest => EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            ...$state,
        ]);

        $current = $make([]);
        $noDeadline = $make(['expires_at' => null]);
        $overdue = $make(['expires_at' => now()->subMinute()]);
        $denied = $make(['status' => EmailAccessRequestStatus::DENIED]);

        expect(EmailAccessRequest::query()->open()->pluck('id')->sort()->values()->all())
            ->toBe(collect([$current, $noDeadline])->pluck('id')->sort()->values()->all())
            ->and(EmailAccessRequest::query()->pendingIncomingFor($this->owner)->count())->toBe(2)
            ->and([$current->isOpen(), $noDeadline->isOpen(), $overdue->isOpen(), $denied->isOpen()])->toBe([true, true, false, false]);
    });
});

describe('email:expire-access-requests', function (): void {
    it('expires a request without a deadline thirty days after it was made', function (): void {
        $undated = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'expires_at' => null,
        ]);

        $this->travelTo(now()->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS - 1));
        $this->artisan('email:expire-access-requests')->expectsOutputToContain('Expired 0 access request(s).');

        expect($undated->fresh()->isOpen())->toBeTrue();

        $this->travelTo(now()->addDays(2));
        $this->artisan('email:expire-access-requests')->expectsOutputToContain('Expired 1 access request(s).');

        expect($undated->fresh()->status)->toBe(EmailAccessRequestStatus::EXPIRED);
    });

    it('expires only overdue pending requests and tells the requester once', function (): void {
        $overdue = EmailAccessRequest::factory()->overdue()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);
        $current = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);
        $denied = EmailAccessRequest::factory()->denied()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'expires_at' => now()->subDay(),
        ]);
        $noDeadline = EmailAccessRequest::factory()->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
            'expires_at' => null,
        ]);
        legacyAccessRequestNotice($this->owner, $overdue);

        $this->artisan('email:expire-access-requests')
            ->expectsOutputToContain("Expiring access request `{$overdue->getKey()}`")
            ->expectsOutputToContain('Expired 1 access request(s).')
            ->assertSuccessful();

        $overdue->refresh();

        expect($overdue->status)->toBe(EmailAccessRequestStatus::EXPIRED)
            ->and($overdue->decided_at)->not->toBeNull()
            ->and($overdue->decided_by)->toBeNull()
            ->and($current->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($noDeadline->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($denied->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
            ->and($this->requester->notifications()->where('type', EmailAccessRespondedNotification::class)->count())->toBe(1)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);

        $this->artisan('email:expire-access-requests')
            ->expectsOutputToContain('Expired 0 access request(s).')
            ->assertSuccessful();

        expect($this->requester->notifications()->where('type', EmailAccessRespondedNotification::class)->count())->toBe(1);
    });
});
