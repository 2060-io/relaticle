<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Commands\SyncProviderCostsCommand;
use Relaticle\Chat\Models\AiProviderCost;

mutates(SyncProviderCostsCommand::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 06:00:00'));
    Http::preventStrayRequests();
});

function anthropicPage(array $buckets, ?string $nextPage): array
{
    return ['data' => $buckets, 'has_more' => $nextPage !== null, 'next_page' => $nextPage];
}

it('stores Anthropic cost for the configured workspace across pages, cents to micros', function (): void {
    config()->set('services.anthropic.admin_key', 'sk-ant-admin-test');
    config()->set('services.anthropic.workspace_id', 'wrkspc_relaticle');
    config()->set('services.openai.admin_key', null);

    Http::fake([
        'api.anthropic.com/v1/organizations/cost_report*' => Http::sequence()
            ->push(anthropicPage([[
                'starting_at' => '2026-10-01T00:00:00Z', 'ending_at' => '2026-10-02T00:00:00Z',
                'results' => [
                    ['amount' => '123.45', 'currency' => 'USD', 'workspace_id' => 'wrkspc_relaticle'],
                    ['amount' => '999.00', 'currency' => 'USD', 'workspace_id' => 'wrkspc_other'],
                ],
            ]], 'page_2'))
            ->push(anthropicPage([[
                'starting_at' => '2026-10-02T00:00:00Z', 'ending_at' => '2026-10-03T00:00:00Z',
                'results' => [['amount' => '50', 'currency' => 'USD', 'workspace_id' => 'wrkspc_relaticle']],
            ]], null)),
    ]);

    $this->artisan('ai:sync-provider-costs')->assertSuccessful();

    expect(AiProviderCost::query()->where('provider', 'anthropic')->orderBy('date')->pluck('amount_micros', 'date')->mapWithKeys(fn (int $micros, mixed $date): array => [CarbonImmutable::parse($date)->toDateString() => $micros])->all())
        ->toBe(['2026-10-01' => 1_234_500, '2026-10-02' => 500_000]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page=page_2')
        && $request->hasHeader('x-api-key', 'sk-ant-admin-test')
        && $request->hasHeader('anthropic-version', '2023-06-01'));
});

it('stores OpenAI cost for the configured project, dollars to micros, and upserts on re-run', function (): void {
    config()->set('services.anthropic.admin_key', null);
    config()->set('services.openai.admin_key', 'sk-admin-openai');
    config()->set('services.openai.project_id', 'proj_relaticle');

    Http::fake([
        'api.openai.com/v1/organization/costs*' => Http::response([
            'data' => [[
                'start_time' => CarbonImmutable::parse('2026-10-01')->getTimestamp(),
                'end_time' => CarbonImmutable::parse('2026-10-02')->getTimestamp(),
                'results' => [['amount' => ['value' => 0.13, 'currency' => 'usd'], 'project_id' => 'proj_relaticle']],
            ]],
            'has_more' => false,
            'next_page' => null,
        ]),
    ]);

    $this->artisan('ai:sync-provider-costs')->assertSuccessful();
    $this->artisan('ai:sync-provider-costs')->assertSuccessful();

    expect(AiProviderCost::query()->where('provider', 'openai')->count())->toBe(1)
        ->and(AiProviderCost::query()->where('provider', 'openai')->value('amount_micros'))->toBe(130_000);

    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'project_ids[]=proj_relaticle')
        && $request->hasHeader('Authorization', 'Bearer sk-admin-openai'));
});

it('skips a provider without a key and keeps going when another provider fails', function (): void {
    config()->set('services.anthropic.admin_key', 'sk-ant-admin-test');
    config()->set('services.anthropic.workspace_id', null);
    config()->set('services.openai.admin_key', 'sk-admin-openai');
    config()->set('services.openai.project_id', null);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'down'], 500),
        'api.openai.com/*' => Http::response([
            'data' => [[
                'start_time' => CarbonImmutable::parse('2026-10-03')->getTimestamp(),
                'end_time' => CarbonImmutable::parse('2026-10-04')->getTimestamp(),
                'results' => [['amount' => ['value' => 1.5, 'currency' => 'usd'], 'project_id' => null]],
            ]],
            'has_more' => false,
            'next_page' => null,
        ]),
    ]);

    $this->artisan('ai:sync-provider-costs')->assertSuccessful();

    expect(AiProviderCost::query()->where('provider', 'anthropic')->count())->toBe(0)
        ->and(AiProviderCost::query()->where('provider', 'openai')->value('amount_micros'))->toBe(1_500_000);
});

it('sums one day split across pages and stores nothing for a provider whose later page fails', function (): void {
    config()->set('services.anthropic.admin_key', 'sk-ant-admin-test');
    config()->set('services.anthropic.workspace_id', null);
    config()->set('services.openai.admin_key', 'sk-admin-openai');
    config()->set('services.openai.project_id', null);

    $day = ['starting_at' => '2026-10-05T00:00:00Z', 'ending_at' => '2026-10-06T00:00:00Z'];
    $openAiDay = fn (float $dollars, bool $hasMore): array => [
        'data' => [[
            'start_time' => CarbonImmutable::parse('2026-10-05')->getTimestamp(),
            'end_time' => CarbonImmutable::parse('2026-10-06')->getTimestamp(),
            'results' => [['amount' => ['value' => $dollars, 'currency' => 'usd'], 'project_id' => null]],
        ]],
        'has_more' => $hasMore,
        'next_page' => $hasMore ? 'page_2' : null,
    ];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicPage([[...$day, 'results' => [['amount' => '100', 'currency' => 'USD', 'workspace_id' => null]]]], 'page_2'))
            ->push(anthropicPage([[...$day, 'results' => [['amount' => '50', 'currency' => 'USD', 'workspace_id' => null]]]], null)),
        'api.openai.com/*' => Http::sequence()
            ->push($openAiDay(2.0, true))
            ->push(['error' => 'down'], 500),
    ]);

    $this->artisan('ai:sync-provider-costs')->assertSuccessful();

    expect(AiProviderCost::query()->where('provider', 'anthropic')->value('amount_micros'))->toBe(1_500_000)
        ->and(AiProviderCost::query()->where('provider', 'openai')->count())->toBe(0);
});
