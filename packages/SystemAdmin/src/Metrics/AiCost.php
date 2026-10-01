<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;

final readonly class AiCost
{
    private const int MIN_PRICED_CREDITS = 100;

    public static function monthToDateMicros(?CarbonImmutable $monthStart = null): int
    {
        return (int) AiCreditTransaction::query()
            ->where('created_at', '>=', $monthStart ?? now()->startOfMonth())
            ->sum('cost_micros');
    }

    public static function unpricedCount(?CarbonImmutable $monthStart = null): int
    {
        $monthStart ??= now()->startOfMonth();
        $firstPriced = AiCreditTransaction::query()->whereNotNull('cost_micros')->min('created_at');
        $since = $firstPriced === null ? $monthStart : $monthStart->max(CarbonImmutable::parse((string) $firstPriced));

        return AiCreditTransaction::query()
            ->where('created_at', '>=', $since)
            ->whereIn('type', [AiCreditType::Chat, AiCreditType::Internal])
            ->where('model', '!=', 'incomplete')
            ->whereNull('cost_micros')
            ->count();
    }

    public static function costPerCreditMicros(): ?float
    {
        $priced = AiCreditTransaction::query()
            ->where('type', AiCreditType::Chat)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('cost_micros')
            ->selectRaw('coalesce(sum(cost_micros), 0) as micros, coalesce(sum(credits_charged), 0) as credits')
            ->toBase()
            ->first();

        $credits = (int) ($priced->credits ?? 0);

        if ($credits < self::MIN_PRICED_CREDITS) {
            return null;
        }

        return ((int) ($priced->micros ?? 0)) / $credits;
    }

    public static function trackingSince(): ?CarbonImmutable
    {
        $first = AiCreditTransaction::query()->whereNotNull('cost_micros')->min('created_at');

        if ($first === null) {
            return null;
        }

        $since = CarbonImmutable::parse((string) $first);

        return $since->greaterThan(now()->startOfMonth()) ? $since : null;
    }

    public static function workspaceMonthMicros(Workspace $workspace): int
    {
        return (int) AiCreditTransaction::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_micros');
    }

    public static function workspaceMonthSubquery(): QueryBuilder
    {
        return DB::table('ai_credit_transactions')
            ->selectRaw('coalesce(sum(ai_credit_transactions.cost_micros), 0)')
            ->whereColumn('ai_credit_transactions.workspace_id', 'workspaces.id')
            ->where('ai_credit_transactions.created_at', '>=', now()->startOfMonth());
    }
}
