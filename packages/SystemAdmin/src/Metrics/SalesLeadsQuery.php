<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Enums\Plan;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Relaticle\SystemAdmin\Metrics\Scopes\ExternalWorkspace;

final readonly class SalesLeadsQuery
{
    private const int LIMIT = 10;

    /**
     * @return Builder<Workspace>
     */
    public static function make(): Builder
    {
        $since = now()->subDays(29)->toDateString();

        return Workspace::query()
            ->withGlobalScope(ExternalWorkspace::class, new ExternalWorkspace)
            ->whereIn('workspaces.id', ActivityDays::workspacesWithOwnData())
            ->whereDoesntHave('subscriptions', fn (Builder $subscription): Builder => $subscription->active())
            ->where('workspaces.plan', '!=', Plan::Enterprise)
            ->select('workspaces.*')
            ->selectSub(self::activity()->selectRaw('count(distinct activity.day)')->where('activity.day', '>=', $since), 'active_days_30')
            ->selectSub(self::activity()->selectRaw('count(*)')->where('activity.kind', 'record'), 'own_records')
            ->selectSub(self::activity()->selectRaw('max(activity.day)'), 'last_active')
            ->selectSub(self::activity()->selectRaw("string_agg(distinct activity.source, ',')")->where('activity.kind', 'record'), 'sources')
            ->withCount('users')
            ->with(['owner', 'subscriptions'])
            ->orderByDesc('active_days_30')
            ->orderByDesc('own_records')
            ->limit(self::LIMIT);
    }

    private static function activity(): QueryBuilder
    {
        return ActivityDays::from()->whereColumn('activity.workspace_id', 'workspaces.id');
    }
}
