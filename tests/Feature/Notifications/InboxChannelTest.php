<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use App\Notifications\Channels\InboxChannel;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Helpers\GroupedInboxNotification;

mutates(InboxChannel::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->task = Task::factory()->for($this->user->currentWorkspace)->create();
});

it('collapses a send with the same group key into the unread row', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->sole()->data)->toBe(['labels' => ['first', 'second']]);
});

it('starts a new row once the earlier one with the group key is read', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));
    $this->user->notifications()->sole()->markAsRead();
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->count())->toBe(2);
});

it('collapses into the row a concurrent send inserted first', function (): void {
    $raced = false;

    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if ($raced || ! str_contains($query->sql, 'select * from "notifications"')) {
            return;
        }

        $raced = true;

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => GroupedInboxNotification::class,
            'notifiable_type' => $this->user->getMorphClass(),
            'notifiable_id' => $this->user->getKey(),
            'data' => json_encode(['labels' => ['competitor']]),
            'group_key' => 'task:1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    expect($this->user->notifications()->sole()->data)->toBe(['labels' => ['competitor', 'second']]);
});

it('locks the unread row it collapses into', function (): void {
    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'second'));

    $locked = collect($statements)->contains(fn (string $sql): bool => str_contains($sql, 'from "notifications"') && str_contains($sql, 'for update'));

    expect($locked)->toBeTrue();
});

it('rethrows a unique violation that no collapse explains', function (): void {
    $notification = new GroupedInboxNotification($this->task, 'task:1', 'first');
    $notification->id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $notification->id,
        'type' => GroupedInboxNotification::class,
        'notifiable_type' => $this->user->getMorphClass(),
        'notifiable_id' => $this->user->getKey(),
        'data' => json_encode(['labels' => ['taken']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => $this->user->notifyNow($notification))->toThrow(UniqueConstraintViolationException::class)
        ->and($this->user->notifications()->count())->toBe(1);
});

it('broadcasts only after the surrounding transaction commits', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    DB::transaction(function (): void {
        $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

        Event::assertNotDispatched(DatabaseNotificationsSent::class);
    });

    Event::assertDispatchedTimes(DatabaseNotificationsSent::class, 1);
});

it('does not broadcast when the surrounding transaction rolls back', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    try {
        DB::transaction(function (): void {
            $this->user->notifyNow(new GroupedInboxNotification($this->task, 'task:1', 'first'));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(DatabaseNotificationsSent::class);
    expect($this->user->notifications()->count())->toBe(0);
});
