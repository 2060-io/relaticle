<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Inbox\InboxRequests;
use Filament\Facades\Filament;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Actions\CancelEmailAccessRequestAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Inbox\EmailAccessRequestSource;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

mutates(InboxRequests::class, EmailAccessRequestSource::class);

beforeEach(function (): void {
    Feature::for(null)->activate(EmailIntegration::class);

    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->requester = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->requester, ['role' => 'member']);

    $this->emailIn = function (Workspace $workspace, string $subject = 'Confidential Thread'): Email {
        $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $this->owner->id,
        ]));

        return Email::factory()->private()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $account->getKey(),
            'subject' => $subject,
        ]);
    };

    $this->requestFor = fn (Email $email, ?User $requester = null): EmailAccessRequest => EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
        'requester_id' => ($requester ?? $this->requester)->id,
        'owner_id' => $this->owner->id,
        'email_id' => $email->getKey(),
        'workspace_id' => $email->workspace_id,
        'expires_at' => now()->addDays(30),
    ]);

    $this->email = ($this->emailIn)($this->workspace);
    $this->requests = resolve(InboxRequests::class);
});

it('lists the owner\'s pending request with its sentence, subject and link', function (): void {
    $this->requester->update(['name' => 'Dana Whitfield']);
    $request = ($this->requestFor)($this->email);

    $listed = $this->requests->for($this->owner, $this->workspace);

    expect($listed)->toHaveCount(1)
        ->and($listed->first()->id)->toBe($request->getKey())
        ->and($listed->first()->kind)->toBe('email_access')
        ->and($listed->first()->title)->toBe('Dana Whitfield requested email access')
        ->and($listed->first()->body)->toBe('Confidential Thread')
        ->and($listed->first()->requester?->is($this->requester))->toBeTrue()
        ->and($listed->first()->expiresAt?->equalTo($request->expires_at))->toBeTrue()
        ->and($listed->first()->url)->toBe($this->email->appUrlFor($this->owner))
        ->and($this->requests->countFor($this->owner, $this->workspace))->toBe(1);
});

it('lists only the current workspace\'s requests for a member of two workspaces', function (): void {
    $elsewhere = Workspace::factory()->create();
    $elsewhere->users()->attach($this->owner, ['role' => 'admin']);
    $elsewhere->users()->attach($this->requester, ['role' => 'member']);

    ($this->requestFor)($this->email);
    ($this->requestFor)(($this->emailIn)($elsewhere));

    expect($this->requests->for($this->owner, $this->workspace))->toHaveCount(1)
        ->and($this->requests->countFor($this->owner, $this->workspace))->toBe(1)
        ->and($this->requests->for($this->owner, $elsewhere))->toHaveCount(1)
        ->and($this->requests->for($this->owner, $elsewhere)->first()->id)->not->toBe($this->requests->for($this->owner, $this->workspace)->first()->id);
});

it('never shows a request to its requester', function (): void {
    ($this->requestFor)($this->email);

    expect($this->requests->for($this->requester, $this->workspace))->toBeEmpty()
        ->and($this->requests->countFor($this->requester, $this->workspace))->toBe(0);
});

it('leaves out decided and overdue requests', function (): void {
    $approved = ($this->requestFor)($this->email);
    $approved->update(['status' => EmailAccessRequestStatus::APPROVED]);

    $overdue = ($this->requestFor)(($this->emailIn)($this->workspace, 'Older'));
    $overdue->update(['expires_at' => now()->subMinute()]);

    expect($this->requests->for($this->owner, $this->workspace))->toBeEmpty()
        ->and($this->requests->countFor($this->owner, $this->workspace))->toBe(0);
});

it('lists the newest request first', function (): void {
    $older = ($this->requestFor)($this->email);
    $this->travelTo(now()->addMinute());
    $newer = ($this->requestFor)(($this->emailIn)($this->workspace, 'Newer'));

    expect($this->requests->for($this->owner, $this->workspace)->pluck('id')->all())
        ->toBe([$newer->getKey(), $older->getKey()]);
});

it('returns nothing while the email integration flag is off', function (): void {
    ($this->requestFor)($this->email);
    Feature::for(null)->deactivate(EmailIntegration::class);

    expect($this->requests->for($this->owner, $this->workspace))->toBeEmpty()
        ->and($this->requests->countFor($this->owner, $this->workspace))->toBe(0);
});

it('approves through the source and shares the email', function (): void {
    Notification::fake();
    $request = ($this->requestFor)($this->email);

    $decided = $this->requests->approve($this->owner, $this->workspace, 'email_access', $request->getKey());

    expect($decided)->toBeTrue()
        ->and($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
        ->and(EmailShare::query()->where('email_id', $this->email->getKey())->where('shared_with', $this->requester->id)->exists())->toBeTrue()
        ->and($this->requests->for($this->owner, $this->workspace))->toBeEmpty();
});

it('denies through the source without sharing', function (): void {
    Notification::fake();
    $request = ($this->requestFor)($this->email);

    $decided = $this->requests->deny($this->owner, $this->workspace, 'email_access', $request->getKey());

    expect($decided)->toBeTrue()
        ->and($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
        ->and(EmailShare::query()->where('email_id', $this->email->getKey())->exists())->toBeFalse();
});

it('returns false when the request was already decided or expired', function (string $decision): void {
    Notification::fake();
    $decidedElsewhere = ($this->requestFor)($this->email);
    $decidedElsewhere->update(['status' => EmailAccessRequestStatus::DENIED]);
    $overdue = ($this->requestFor)(($this->emailIn)($this->workspace, 'Older'));
    $overdue->update(['expires_at' => now()->subMinute()]);

    expect($this->requests->{$decision}($this->owner, $this->workspace, 'email_access', $decidedElsewhere->getKey()))->toBeFalse()
        ->and($this->requests->{$decision}($this->owner, $this->workspace, 'email_access', $overdue->getKey()))->toBeFalse()
        ->and($decidedElsewhere->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
        ->and($overdue->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
        ->and(EmailShare::query()->exists())->toBeFalse();
})->with(['approve', 'deny']);

it('reports the thirty day deadline of an undated open request', function (): void {
    $request = ($this->requestFor)($this->email);
    $request->forceFill(['expires_at' => null])->save();

    $listed = $this->requests->for($this->owner, $this->workspace)->sole();

    expect($listed->expiresAt?->equalTo($request->fresh()->created_at->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS)))->toBeTrue();
});

it('reads the same number of queries for one pending request as for three', function (): void {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->requests->for($this->owner->fresh(), $this->workspace);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    ($this->requestFor)($this->email);
    $one = $countQueries();

    ($this->requestFor)(($this->emailIn)($this->workspace, 'Second'));
    ($this->requestFor)(($this->emailIn)($this->workspace, 'Third'));

    expect($countQueries())->toBe($one);
});

it('answers 404 for a request id from another workspace', function (string $decision): void {
    $elsewhere = Workspace::factory()->create();
    $elsewhere->users()->attach($this->owner, ['role' => 'admin']);
    $request = ($this->requestFor)(($this->emailIn)($elsewhere));

    expect(fn () => $this->requests->{$decision}($this->owner, $this->workspace, 'email_access', $request->getKey()))
        ->toThrow(ModelNotFoundException::class);

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
})->with(['approve', 'deny']);

it('answers 404 for a request that belongs to another owner', function (): void {
    $other = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($other, ['role' => 'admin']);
    $request = ($this->requestFor)($this->email);

    expect(fn () => $this->requests->approve($other, $this->workspace, 'email_access', $request->getKey()))
        ->toThrow(ModelNotFoundException::class);

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
});

it('answers 404 for an unknown kind', function (): void {
    expect(fn () => $this->requests->approve($this->owner, $this->workspace, 'workspace_join', 'any'))
        ->toThrow(NotFoundHttpException::class);
});

it('answers 404 to a decision while the flag is off', function (): void {
    $request = ($this->requestFor)($this->email);
    Feature::for(null)->deactivate(EmailIntegration::class);

    expect(fn () => $this->requests->approve($this->owner, $this->workspace, 'email_access', $request->getKey()))
        ->toThrow(NotFoundHttpException::class);
});

describe('owner badge', function (): void {
    beforeEach(function (): void {
        $this->ownerChannel = 'private-App.Models.User.'.$this->owner->getKey();
        $this->sentToOwner = fn (): bool => Event::dispatched(DatabaseNotificationsSent::class, fn (DatabaseNotificationsSent $event): bool => $event->broadcastOn() === $this->ownerChannel)->isNotEmpty();
    });

    it('moves when a request is created', function (): void {
        Notification::fake();
        Event::fake([DatabaseNotificationsSent::class]);

        resolve(RequestEmailAccessAction::class)->execute($this->email, $this->requester, EmailPrivacyTier::FULL);

        expect(($this->sentToOwner)())->toBeTrue();
    });

    it('moves when the requester cancels', function (): void {
        $request = ($this->requestFor)($this->email);
        Event::fake([DatabaseNotificationsSent::class]);

        resolve(CancelEmailAccessRequestAction::class)->execute($request, $this->requester);

        expect(($this->sentToOwner)())->toBeTrue();
    });

    it('moves when a request expires', function (): void {
        Notification::fake();
        $request = ($this->requestFor)($this->email);
        $request->update(['expires_at' => now()->subMinute()]);
        Event::fake([DatabaseNotificationsSent::class]);

        $this->artisan('email:expire-access-requests')->assertSuccessful();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::EXPIRED)
            ->and(($this->sentToOwner)())->toBeTrue();
    });
});
