<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** @var array<string, array{float, float}> */
    private const array CACHE_RATIOS = [
        'anthropic' => [0.1, 1.25],
        'openai' => [0.1, 1.0],
        'gemini' => [0.1, 1.0],
    ];

    private const array CHEAPEST_PROVIDERS = ['anthropic', 'openai', 'gemini'];

    public function up(): void
    {
        $this->migrator->update('chat.models', function (array $models): array {
            $entries = array_map(fn (mixed $entry): array => $this->withCachePrices((array) $entry), $models);

            return [...$entries, ...$this->missingCheapestModels($entries)];
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function withCachePrices(array $entry): array
    {
        $input = is_numeric($entry['input_per_mtok'] ?? null) ? (float) $entry['input_per_mtok'] : null;
        [$readRatio, $writeRatio] = self::CACHE_RATIOS[$entry['provider'] ?? ''] ?? [null, null];

        $prices = [
            'cache_read_per_mtok' => $entry['cache_read_per_mtok'] ?? ($input !== null && $readRatio !== null ? round($input * $readRatio, 4) : null),
            'cache_write_per_mtok' => $entry['cache_write_per_mtok'] ?? ($input !== null && $writeRatio !== null ? round($input * $writeRatio, 4) : null),
        ];

        unset($entry['cache_read_per_mtok'], $entry['cache_write_per_mtok']);

        $position = array_search('output_per_mtok', array_keys($entry), true);
        $offset = $position === false ? count($entry) : $position + 1;

        return array_slice($entry, 0, $offset, true) + $prices + array_slice($entry, $offset, null, true);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function missingCheapestModels(array $entries): array
    {
        $present = array_column($entries, 'model');
        $missing = [];

        foreach (self::CHEAPEST_PROVIDERS as $provider) {
            $model = config("ai.providers.{$provider}.models.text.cheapest");

            if (! is_string($model) || $model === '' || in_array($model, $present, true)) {
                continue;
            }

            $missing[] = [
                'label' => $model,
                'provider' => $provider,
                'model' => $model,
                'min_plan' => 'free',
                'credit_multiplier' => 1.0,
                'input_per_mtok' => null,
                'output_per_mtok' => null,
                'cache_read_per_mtok' => null,
                'cache_write_per_mtok' => null,
                'auto' => false,
                'enabled' => false,
                'capabilities' => null,
                'verified_at' => null,
            ];
        }

        return $missing;
    }
};
