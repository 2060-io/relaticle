<?php

declare(strict_types=1);

use Relaticle\Chat\Settings\ChatSettings;

function runCachePricesMigration(): void
{
    (require database_path('settings/2026_10_01_000000_add_cache_prices_to_chat_models.php'))->up();
}

function storedCatalog(): array
{
    return array_map(fn (mixed $entry): array => (array) $entry, resolve(ChatSettings::class)->refresh()->models);
}

beforeEach(function (): void {
    config()->set('ai.providers.anthropic.models.text.cheapest', 'claude-haiku-4-5-20251001');
    config()->set('ai.providers.openai.models.text.cheapest', 'gpt-5.6-luna');
    config()->set('ai.providers.gemini.models.text.cheapest', 'gemini-3.1-flash-lite');

    $settings = resolve(ChatSettings::class);
    $settings->models = [
        ['label' => 'Sonnet 5', 'provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'min_plan' => 'free', 'credit_multiplier' => 1.0, 'input_per_mtok' => 3.0, 'output_per_mtok' => 15.0, 'auto' => true, 'enabled' => true, 'capabilities' => null, 'verified_at' => null],
        ['label' => 'GPT 5.4', 'provider' => 'openai', 'model' => 'gpt-5.4', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => 2.5, 'output_per_mtok' => 15.0, 'auto' => false, 'enabled' => true, 'capabilities' => null, 'verified_at' => null],
        ['label' => 'GPT 6 luna', 'provider' => 'openai', 'model' => 'gpt-6-luna', 'min_plan' => 'pro', 'credit_multiplier' => 1.0, 'input_per_mtok' => null, 'output_per_mtok' => null, 'auto' => true, 'enabled' => true, 'capabilities' => null, 'verified_at' => null],
        ['label' => 'Gemini 3.1 Pro', 'provider' => 'gemini', 'model' => 'gemini-3.1-pro', 'min_plan' => 'pro', 'credit_multiplier' => 1.5, 'input_per_mtok' => 2, 'output_per_mtok' => 12, 'auto' => false, 'enabled' => false, 'capabilities' => null, 'verified_at' => null],
    ];
    $settings->save();
});

it('prices cache reads and writes from each provider\'s input price', function (): void {
    runCachePricesMigration();

    $byModel = collect(storedCatalog())->keyBy('model');

    expect($byModel['claude-sonnet-5'])
        ->cache_read_per_mtok->toBe(0.3)
        ->cache_write_per_mtok->toBe(3.75)
        ->and($byModel['gpt-5.4'])
        ->cache_read_per_mtok->toBe(0.25)
        ->cache_write_per_mtok->toBe(2.5)
        ->and($byModel['gemini-3.1-pro'])
        ->cache_read_per_mtok->toBe(0.2)
        ->cache_write_per_mtok->toEqual(2.0)
        ->and($byModel['gpt-6-luna'])
        ->cache_read_per_mtok->toBeNull()
        ->cache_write_per_mtok->toBeNull();
});

it('places the cache prices right after the output price', function (): void {
    runCachePricesMigration();

    expect(array_keys(storedCatalog()[0]))->toBe([
        'label', 'provider', 'model', 'min_plan', 'credit_multiplier', 'input_per_mtok', 'output_per_mtok',
        'cache_read_per_mtok', 'cache_write_per_mtok', 'auto', 'enabled', 'capabilities', 'verified_at',
    ]);
});

it('adds a disabled, unpriced entry for each provider\'s cheapest model once', function (): void {
    runCachePricesMigration();
    runCachePricesMigration();

    $cheapest = collect(storedCatalog())->whereIn('model', ['claude-haiku-4-5-20251001', 'gpt-5.6-luna', 'gemini-3.1-flash-lite']);

    expect($cheapest)->toHaveCount(3)
        ->and($cheapest->pluck('enabled')->unique()->all())->toBe([false])
        ->and($cheapest->pluck('input_per_mtok')->unique()->all())->toBe([null]);
});
