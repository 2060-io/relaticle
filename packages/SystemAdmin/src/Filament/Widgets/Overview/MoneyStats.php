<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Metrics\Money;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Revenue;

final class MoneyStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Are we making money?';

    protected ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return BillingStatus::billingEnabled();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $mrr = $cache->remember('money.mrr', fn (): ?int => resolve(Revenue::class)->monthlyMicros());
        $mrr = is_int($mrr) ? $mrr : null;
        $lastWeek = $mrr === null ? null : $cache->remember('money.mrr.previous', fn (): ?int => resolve(Revenue::class)->monthlyMicros(now()->subWeek()));
        $lastWeek = is_int($lastWeek) ? $lastWeek : null;

        $description = match (true) {
            $mrr === null => 'Stripe unavailable',
            $lastWeek === null => 'Per month, before tax',
            default => $this->delta($mrr - $lastWeek).' vs last week',
        };

        return [
            Stat::make('MRR', Money::format($mrr))
                ->description($description)
                ->color($mrr === null ? 'gray' : 'primary')
                ->extraAttributes(['title' => 'What each paying customer paid on their last invoice, before tax, per month. Your own workspaces are excluded.'])
                ->url(SubscriptionResource::getUrl('index', ['filters' => ['counts_toward_mrr' => ['isActive' => true]]])),
        ];
    }

    private function delta(int $micros): string
    {
        return ($micros >= 0 ? '+' : '-').Money::format(abs($micros));
    }
}
