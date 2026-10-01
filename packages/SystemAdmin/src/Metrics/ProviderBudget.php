<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use Carbon\CarbonImmutable;
use Laravel\Ai\Enums\Lab;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\AiProviderCost;
use Relaticle\Chat\Support\CatalogEntry;

final readonly class ProviderBudget
{
    private const array PROVIDERS = ['anthropic', 'openai', 'gemini'];

    private const array COST_API_PROVIDERS = ['anthropic', 'openai'];

    /**
     * laravel/ai already spells every provider it supports, on the enum case name:
     * `OpenAI`, `DeepSeek`, `xAI`. `headline()` renders those as `Openai`, `Deepseek`,
     * `Xai`, so ask the enum first and fall back for a provider it does not know.
     */
    public static function label(string $provider): string
    {
        return Lab::tryFrom($provider)?->name ?? str($provider)->headline()->toString();
    }

    public static function unbilledNote(string $provider): string
    {
        return match (true) {
            ! self::hasCostApi($provider) => 'No cost API',
            blank(config("services.{$provider}.admin_key")) => 'No admin key',
            default => 'Not synced yet',
        };
    }

    /**
     * @return list<array{provider: string, budget_micros: int|null, billed_micros: int|null, estimate_micros: int, spent_micros: int, last_fetched: CarbonImmutable|null}>
     */
    public static function rows(?CarbonImmutable $monthStart = null): array
    {
        $monthStart ??= now()->startOfMonth();
        $estimates = self::estimates($monthStart);

        /** @var array<string, mixed> $budgets */
        $budgets = (array) config('chat.provider_monthly_budgets', []);

        return array_map(function (string $provider) use ($monthStart, $estimates, $budgets): array {
            $hasKey = self::hasCostApi($provider) && filled(config("services.{$provider}.admin_key"));
            $synced = $hasKey
                ? AiProviderCost::query()
                    ->where('provider', $provider)
                    ->where('date', '>=', $monthStart->toDateString())
                    ->toBase()
                    ->selectRaw('count(*) as days, coalesce(sum(amount_micros), 0) as micros')
                    ->first()
                : null;
            $billed = $synced !== null && (int) $synced->days > 0 ? (int) $synced->micros : null;
            $lastFetched = $hasKey ? AiProviderCost::query()->where('provider', $provider)->max('fetched_at') : null;
            $budget = is_numeric($budgets[$provider] ?? null) ? ((int) $budgets[$provider]) * 1_000_000 : null;
            $estimate = $estimates[$provider] ?? 0;

            return [
                'provider' => $provider,
                'budget_micros' => $budget,
                'billed_micros' => $billed,
                'estimate_micros' => $estimate,
                'spent_micros' => $billed ?? $estimate,
                'last_fetched' => $lastFetched === null ? null : CarbonImmutable::parse((string) $lastFetched),
            ];
        }, self::PROVIDERS);
    }

    /**
     * @return array{left_micros: int|null, lowest_provider: string|null, lowest_share: float|null, estimated: bool, last_fetched: string|null}
     */
    public static function summary(): array
    {
        $budgeted = array_values(array_filter(self::rows(), fn (array $row): bool => $row['budget_micros'] !== null && $row['budget_micros'] > 0));

        if ($budgeted === []) {
            return ['left_micros' => null, 'lowest_provider' => null, 'lowest_share' => null, 'estimated' => false, 'last_fetched' => null];
        }

        $left = 0;
        $lowest = null;
        $lastFetched = null;

        foreach ($budgeted as $row) {
            $remaining = (int) $row['budget_micros'] - $row['spent_micros'];
            $share = $remaining / (int) $row['budget_micros'];
            $left += $remaining;

            if ($row['last_fetched'] !== null && ($lastFetched === null || $row['last_fetched']->greaterThan($lastFetched))) {
                $lastFetched = $row['last_fetched'];
            }

            if ($lowest === null || $share < $lowest['share']) {
                $lowest = ['provider' => $row['provider'], 'share' => $share, 'estimated' => $row['billed_micros'] === null];
            }
        }

        return [
            'left_micros' => $left,
            'lowest_provider' => $lowest['provider'],
            'lowest_share' => $lowest['share'],
            'estimated' => $lowest['estimated'],
            'last_fetched' => $lastFetched?->format('M j'),
        ];
    }

    private static function hasCostApi(string $provider): bool
    {
        return in_array($provider, self::COST_API_PROVIDERS, true);
    }

    /**
     * @return array<string, int>
     */
    private static function estimates(CarbonImmutable $monthStart): array
    {
        /** @var array<int, mixed> $catalog */
        $catalog = (array) config('chat.models', []);
        $providerByModel = collect($catalog)
            ->map(fn (mixed $entry): ?CatalogEntry => is_array($entry) ? CatalogEntry::fromArray($entry) : null)
            ->filter(fn (?CatalogEntry $entry): bool => $entry instanceof CatalogEntry && $entry->provider !== null)
            ->mapWithKeys(fn (CatalogEntry $entry): array => [$entry->model => (string) $entry->provider]);

        $estimates = [];

        foreach (AiCreditTransaction::query()
            ->where('created_at', '>=', $monthStart)
            ->whereNotNull('cost_micros')
            ->groupBy('model')
            ->selectRaw('model, sum(cost_micros) as micros')
            ->toBase()
            ->get() as $row) {
            $provider = $providerByModel->get((string) $row->model);

            if (is_string($provider)) {
                $estimates[$provider] = ($estimates[$provider] ?? 0) + (int) $row->micros;
            }
        }

        return $estimates;
    }
}
