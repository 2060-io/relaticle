<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('every scheduled task runs on one server so replicas do not repeat it', function (): void {
    config()->set('app.health_checks_enabled', true);

    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(resolve(Schedule::class)->events());

    expect($events)->not->toBeEmpty()
        ->and($events->reject(fn (Event $event): bool => $event->onOneServer)->map(fn (Event $event): string => (string) $event->command)->values()->all())
        ->toBe([]);
});
