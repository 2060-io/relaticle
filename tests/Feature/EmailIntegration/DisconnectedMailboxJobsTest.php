<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;

mutates(
    InitialEmailSyncJob::class,
    IncrementalEmailSyncJob::class,
    StoreEmailJob::class,
    InitialCalendarSyncJob::class,
    IncrementalCalendarSyncJob::class,
    StoreMeetingJob::class,
    EnsureCalendarPushChannelJob::class,
);

it('does nothing when a queued mailbox job runs after the mailbox was disconnected', function (Closure $makeJob): void {
    Http::fake();

    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'user_id' => $user->id,
        'refresh_token' => 'refresh-token',
        'token_expires_at' => now()->addHour(),
        'sync_cursor' => 'cursor-1',
        'capabilities' => ['email' => true, 'send' => true, 'calendar' => true],
    ]));

    /** @var ShouldQueue $job */
    $job = $makeJob($account);

    resolve(DisconnectConnectedAccountAction::class)->execute($account);

    $mailFactory = Mockery::mock(MailServiceFactoryInterface::class);
    $mailFactory->shouldNotReceive('make');
    app()->instance(MailServiceFactoryInterface::class, $mailFactory);

    $calendarFactory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $calendarFactory->shouldNotReceive('make');
    app()->instance(CalendarServiceFactoryInterface::class, $calendarFactory);

    dispatch($job)->onConnection('sync');

    $disconnected = ConnectedAccount::withTrashed()->findOrFail($account->getKey());

    expect($disconnected->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($disconnected->last_error)->toBeNull()
        ->and(Meeting::query()->withoutGlobalScopes()->where('connected_account_id', $account->getKey())->count())->toBe(0);
})->with([
    'initial email sync' => [fn (ConnectedAccount $account): ShouldQueue => new InitialEmailSyncJob($account)],
    'incremental email sync' => [fn (ConnectedAccount $account): ShouldQueue => new IncrementalEmailSyncJob($account)],
    'store email' => [fn (ConnectedAccount $account): ShouldQueue => new StoreEmailJob($account, 'message-1')],
    'initial calendar sync' => [fn (ConnectedAccount $account): ShouldQueue => new InitialCalendarSyncJob($account)],
    'incremental calendar sync' => [fn (ConnectedAccount $account): ShouldQueue => new IncrementalCalendarSyncJob($account)],
    'store meeting' => [fn (ConnectedAccount $account): ShouldQueue => new StoreMeetingJob($account, new CalendarEventData(
        providerEventId: 'event-1',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Kickoff',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    ))],
    'calendar push channel' => [fn (ConnectedAccount $account): ShouldQueue => new EnsureCalendarPushChannelJob($account)],
]);
