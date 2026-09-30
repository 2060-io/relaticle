<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

final readonly class TokenCost
{
    public function __construct(private ModelRegistry $registry) {}

    public function micros(string $model, int $uncachedInput, int $cacheRead, int $cacheWrite, int $output): ?int
    {
        $rate = $this->registry->ratesFor($model);

        if ($rate === null) {
            return null;
        }

        $total = 0.0;

        foreach ([
            [$uncachedInput, $rate['input_per_mtok']],
            [$cacheRead, $rate['cache_read_per_mtok']],
            [$cacheWrite, $rate['cache_write_per_mtok']],
            [$output, $rate['output_per_mtok']],
        ] as [$tokens, $dollarsPerMillion]) {
            if ($tokens === 0) {
                continue;
            }

            if ($dollarsPerMillion === null) {
                return null;
            }

            // Dollars per million tokens is micro-dollars per token.
            $total += $tokens * $dollarsPerMillion;
        }

        return (int) round($total);
    }
}
