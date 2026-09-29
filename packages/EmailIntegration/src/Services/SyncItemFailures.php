<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SyncItemFailures
{
    private const int MAX_FAILED_SYNCS = 3;

    private const int TTL_DAYS = 7;

    /**
     * @param  class-string  $storeJob
     */
    public static function record(ConnectedAccount $account, string $storeJob, string $itemId): void
    {
        $key = self::key($account, $storeJob, $itemId);

        // Redis creates a missing key without a TTL on increment, so seed it first.
        Cache::add($key, 0, now()->addDays(self::TTL_DAYS));
        Cache::increment($key);
    }

    /**
     * @param  class-string  $storeJob
     * @param  array<int, string>  $itemIds
     * @return list<string>
     */
    public static function exhausted(ConnectedAccount $account, string $storeJob, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $keys = array_map(static fn (string $itemId): string => self::key($account, $storeJob, $itemId), $itemIds);
        $failedSyncs = Cache::many($keys);

        return array_values(array_filter(
            $itemIds,
            static fn (string $itemId, int $index): bool => (int) ($failedSyncs[$keys[$index]] ?? 0) >= self::MAX_FAILED_SYNCS,
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * @param  class-string  $storeJob
     */
    private static function key(ConnectedAccount $account, string $storeJob, string $itemId): string
    {
        return 'sync-item-failures:'.class_basename($storeJob).":{$account->getKey()}:".sha1($itemId);
    }
}
