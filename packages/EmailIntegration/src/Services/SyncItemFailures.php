<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SyncItemFailures
{
    private const int MAX_FAILURES = 3;

    private const int FAILING_FOR_HOURS = 24;

    private const int TTL_DAYS = 7;

    /**
     * @param  class-string  $storeJob
     */
    public static function record(ConnectedAccount $account, string $storeJob, string $itemId): void
    {
        $key = self::key($account, $storeJob, $itemId);
        $expiresAt = now()->addDays(self::TTL_DAYS);

        Cache::add("{$key}:first-failed-at", now()->getTimestamp(), $expiresAt);
        // Redis creates a missing key without a TTL on increment, so seed it first.
        Cache::add("{$key}:count", 0, $expiresAt);
        Cache::increment("{$key}:count");
    }

    /**
     * @param  class-string  $storeJob
     * @param  array<int, string>  $itemIds
     * @return list<string>
     */
    public static function toSkip(ConnectedAccount $account, string $storeJob, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $keys = array_map(static fn (string $itemId): string => self::key($account, $storeJob, $itemId), $itemIds);
        $stored = Cache::many(array_merge(
            array_map(static fn (string $key): string => "{$key}:count", $keys),
            array_map(static fn (string $key): string => "{$key}:first-failed-at", $keys),
        ));
        $failingSince = now()->subHours(self::FAILING_FOR_HOURS)->getTimestamp();

        $skipped = array_values(array_filter(
            $itemIds,
            static fn (string $itemId, int $index): bool => (int) ($stored["{$keys[$index]}:count"] ?? 0) >= self::MAX_FAILURES
                && (int) ($stored["{$keys[$index]}:first-failed-at"] ?? PHP_INT_MAX) <= $failingSince,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($skipped !== []) {
            Log::warning('Skipped mailbox items that keep failing to store.', [
                'connected_account_id' => $account->getKey(),
                'store_job' => class_basename($storeJob),
                'item_ids' => $skipped,
            ]);
        }

        return $skipped;
    }

    /**
     * @param  class-string  $storeJob
     */
    private static function key(ConnectedAccount $account, string $storeJob, string $itemId): string
    {
        return 'sync-item-failures:'.class_basename($storeJob).":{$account->getKey()}:".hash('xxh3', $itemId);
    }
}
