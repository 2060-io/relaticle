<?php

declare(strict_types=1);

it('re-delivers no job while a horizon worker may still be running it', function (): void {
    $supervisors = collect([config('horizon.defaults'), ...array_values(config('horizon.environments'))])
        ->flatMap(fn (array $supervisors): array => array_values($supervisors))
        ->filter(fn (array $supervisor): bool => isset($supervisor['connection'], $supervisor['timeout']));

    expect($supervisors)->not->toBeEmpty();

    foreach ($supervisors as $supervisor) {
        $retryAfter = (int) config("queue.connections.{$supervisor['connection']}.retry_after");

        expect($retryAfter)->toBeGreaterThan((int) $supervisor['timeout']);
    }
});
