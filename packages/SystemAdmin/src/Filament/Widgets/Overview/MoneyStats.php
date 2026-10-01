<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\SystemAdmin\Filament\Pages\Settings\ManageAiSettings;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Metrics\AiCost;
use Relaticle\SystemAdmin\Metrics\Money;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\ProviderBudget;
use Relaticle\SystemAdmin\Metrics\Revenue;

final class MoneyStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $heading = 'Are we making money?';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $cost = (int) $cache->remember('money.cost', fn (): int => AiCost::monthToDateMicros());
        $lastMonthCost = (int) $cache->remember('money.cost.previous', fn (): int => AiCost::monthToDateMicros(now()->subMonthNoOverflow()->startOfMonth()) - AiCost::monthToDateMicros());
        $unpriced = (int) $cache->remember('money.unpriced', fn (): int => AiCost::unpricedCount());
        /** @var array{left_micros: int|null, lowest_provider: string|null, lowest_share: float|null, estimated: bool, last_fetched: string|null} $budget */
        $budget = $cache->remember('money.budget', fn (): array => ProviderBudget::summary());
        $trackingSince = $cache->remember('money.since', fn (): ?string => AiCost::trackingSince()?->format('M j'));

        $stats = [];
        $mrr = null;

        if (BillingStatus::billingEnabled()) {
            $mrr = $cache->remember('money.mrr', fn (): ?int => resolve(Revenue::class)->monthlyMicros());
            $mrr = is_int($mrr) ? $mrr : null;
            $mrrLastWeek = $mrr === null ? null : $cache->remember('money.mrr.previous', fn (): ?int => resolve(Revenue::class)->monthlyMicros(now()->subWeek()));
            $stats[] = $this->mrr($mrr, is_int($mrrLastWeek) ? $mrrLastWeek : null, $cost);
        }

        $stats[] = Stat::make('AI cost this month', Money::format($cost))
            ->description((is_string($trackingSince) ? "Since {$trackingSince}" : 'Was '.Money::format($lastMonthCost).' last month').($unpriced > 0 ? " ({$unpriced} calls unpriced)" : ''))
            ->color(BillingStatus::billingEnabled() ? $this->coverageColor($mrr, $cost) : 'success')
            ->extraAttributes(['title' => 'Every model call at list price, cached tokens included, since cost tracking started.'])
            ->url(WorkspaceResource::getUrl('index', ['sort' => 'ai_cost_this_month:desc']));

        $stats[] = Stat::make('Provider budget left', Money::format($budget['left_micros']))
            ->description($budget['lowest_provider'] === null
                ? 'Set budgets on the AI settings page'
                : 'Lowest: '.ProviderBudget::label($budget['lowest_provider']).' at '.round(((float) $budget['lowest_share']) * 100).'%'
                    .($budget['estimated'] ? ' (our estimate)' : '')
                    .($budget['last_fetched'] !== null ? ", billed through {$budget['last_fetched']}" : ''))
            ->color($this->budgetColor($budget['lowest_share']))
            ->extraAttributes(['title' => 'Monthly budget minus what each provider billed, or our estimate where there is no admin key.'])
            ->url(ManageAiSettings::getUrl());

        if (BillingStatus::billingEnabled()) {
            $stats[] = $this->unusedTrialCredits($cache, $budget['left_micros']);
        }

        return $stats;
    }

    private function mrr(?int $mrr, ?int $lastWeek, int $cost): Stat
    {
        $description = $mrr === null
            ? 'Stripe unavailable'
            : ($lastWeek === null ? 'Per month, before tax' : $this->delta($mrr - $lastWeek).' vs last week');

        return Stat::make('MRR', Money::format($mrr))
            ->description($description)
            ->color($this->coverageColor($mrr, $cost))
            ->extraAttributes(['title' => 'What each paying customer paid on their last invoice, before tax, per month. Your own workspaces are excluded.'])
            ->url(SubscriptionResource::getUrl('index', ['filters' => ['counts_toward_mrr' => ['isActive' => true]]]));
    }

    private function unusedTrialCredits(OverviewCache $cache, ?int $budgetLeft): Stat
    {
        $perCredit = $cache->remember('money.per_credit', fn (): ?float => AiCost::costPerCreditMicros());
        $credits = (int) $cache->remember('money.trial_credits', fn (): int => (int) AiCreditBalance::query()
            ->whereHas('workspace', fn (Builder $workspace): Builder => BillingStatus::Trialing->applyToQuery($workspace))
            ->sum('credits_remaining'));
        $exposure = is_float($perCredit) ? (int) round($credits * $perCredit) : null;

        return Stat::make('Unused trial credits', Money::format($exposure))
            ->description($exposure === null ? 'Not enough priced usage yet' : number_format($credits).' credits held by active trials')
            ->color($exposure !== null && $budgetLeft !== null && $exposure > $budgetLeft ? 'danger' : 'gray')
            ->extraAttributes(['title' => 'What active trials could still cost if they spend every credit, at the last 30 days of cost per credit.'])
            ->url(AiCreditBalanceResource::getUrl('index', ['filters' => ['trialing' => ['isActive' => true]], 'sort' => 'credits_used:desc']));
    }

    private function coverageColor(?int $mrr, int $cost): string
    {
        return match (true) {
            $mrr === null => 'gray',
            $mrr >= $cost => 'success',
            default => 'danger',
        };
    }

    private function budgetColor(?float $share): string
    {
        return match (true) {
            $share === null => 'gray',
            $share < 0.2 => 'danger',
            $share < 0.5 => 'warning',
            default => 'success',
        };
    }

    private function delta(int $micros): string
    {
        return ($micros >= 0 ? '+' : '-').Money::format(abs($micros));
    }
}
