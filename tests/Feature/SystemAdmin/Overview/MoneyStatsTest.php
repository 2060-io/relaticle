<?php

declare(strict_types=1);

use App\Features\Billing;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\AiProviderCost;
use Relaticle\Chat\Settings\ChatSettings;
use Relaticle\SystemAdmin\Filament\Pages\Overview;
use Relaticle\SystemAdmin\Filament\Resources\AiCreditBalanceResource\Pages\ListAiCreditBalances;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Helpers\OverviewData;

mutates(MoneyStats::class, Overview::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    Feature::define(Billing::class, true);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

function seedProviderBudgets(int $openaiBilledDollars, int $anthropicEstimateDollars): void
{
    $settings = resolve(ChatSettings::class);
    $settings->provider_monthly_budgets = ['anthropic' => 200, 'openai' => 50];
    $settings->save();
    config($settings->toConfig());
    config(['services.openai.admin_key' => 'sk-admin-test', 'services.anthropic.admin_key' => null]);

    AiProviderCost::query()->create(['provider' => 'openai', 'date' => now()->startOfMonth(), 'amount_micros' => $openaiBilledDollars * 1_000_000, 'fetched_at' => now()]);

    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'p-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => $anthropicEstimateDollars * 1_000_000, 'metadata' => [], 'created_at' => now(),
    ]);
}

function useStringifyingCache(): void
{
    Cache::extend('stringifying', fn (): Repository => new Repository(new class extends ArrayStore
    {
        public function put(mixed $key, mixed $value, mixed $seconds): bool
        {
            return parent::put($key, is_int($value) || is_float($value) ? (string) $value : $value, $seconds);
        }
    }));

    config()->set('cache.stores.stringifying', ['driver' => 'stringifying']);
    config()->set('cache.default', 'stringifying');
}

function seedCost(int $micros): AiCreditTransaction
{
    $workspace = OverviewData::workspaceOf(OverviewData::owner());

    return AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'k-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => $micros, 'metadata' => [], 'created_at' => now(),
    ]);
}

it('renders on an empty database with placeholders and no errors', function (): void {
    livewire(MoneyStats::class)
        ->assertOk()
        ->assertSee('Are we making money?')
        ->assertSee('MRR')
        ->assertSee('AI cost this month')
        ->assertSee('Provider budget left')
        ->assertSee('Unused trial credits')
        ->assertSee('Not enough priced usage yet');
});

it('shows this month\'s AI cost and links it to workspaces sorted by cost', function (): void {
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'm-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => 4_560_000, 'metadata' => [], 'created_at' => now(),
    ]);

    livewire(MoneyStats::class)
        ->assertSee('$4.56')
        ->assertSee('Since Oct 15')
        ->assertSee('sort=ai_cost_this_month', escape: false);
});

it('counts unpriced calls only since cost tracking began, leaving out failed streams', function (): void {
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    $row = fn (string $model, ?int $micros, CarbonImmutable $at): AiCreditTransaction => AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'u-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => $model, 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => $micros, 'metadata' => [], 'created_at' => $at,
    ]);
    $row('claude-sonnet-5', null, CarbonImmutable::parse('2026-10-03 09:00:00'));
    $row('claude-sonnet-5', 1_000_000, CarbonImmutable::parse('2026-10-10 09:00:00'));
    $row('gpt-6-luna', null, CarbonImmutable::parse('2026-10-11 09:00:00'));
    $row('incomplete', null, CarbonImmutable::parse('2026-10-12 09:00:00'));

    livewire(MoneyStats::class)
        ->assertSee('Since Oct 10')
        ->assertSee('(1 calls unpriced)');
});

it('values unused trial credits at the recent cost per credit', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 1000, 'credits_used' => 0, 'period_starts_at' => now()->startOfMonth(), 'period_ends_at' => now()->endOfMonth(),
    ]);
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'c-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 200, 'cost_micros' => 10_000_000, 'metadata' => [], 'created_at' => now(),
    ]);

    livewire(MoneyStats::class)->assertSee('$50.00');
});

it('hides MRR and trial tiles when billing is off', function (): void {
    Feature::define(Billing::class, false);

    livewire(MoneyStats::class)
        ->assertDontSee('MRR')
        ->assertDontSee('Unused trial credits')
        ->assertSee('AI cost this month');
});

it('is the panel home and shows changed numbers after Refresh redraws the page', function (): void {
    $this->get(Overview::getUrl())->assertOk()->assertSee('Overview');

    seedCost(1_000_000);
    livewire(MoneyStats::class)->assertSee('$1.00');

    seedCost(2_000_000);
    livewire(MoneyStats::class)->assertSee('$1.00')->assertDontSee('$3.00');

    livewire(Overview::class)
        ->callAction('refresh')
        ->assertNotified('Numbers refreshed')
        ->assertRedirect(Overview::getUrl());

    livewire(MoneyStats::class)->assertSee('$3.00');
});

it('keeps whole numbers and decimals typed when the cache hands them back as strings', function (): void {
    useStringifyingCache();
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 1000, 'credits_used' => 0, 'period_starts_at' => now()->startOfMonth(), 'period_ends_at' => now()->endOfMonth(),
    ]);
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'c-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 200, 'cost_micros' => 10_000_000, 'metadata' => [], 'created_at' => now(),
    ]);

    livewire(MoneyStats::class)->assertSee('+$0.00 vs last week')->assertSee('$50.00');

    livewire(MoneyStats::class)
        ->assertSee('+$0.00 vs last week')
        ->assertDontSee('Stripe unavailable')
        ->assertSee('$50.00')
        ->assertDontSee('Not enough priced usage yet');
});

it('starts a new month of cost numbers as soon as the month turns', function (): void {
    $this->travelTo('2026-10-31 23:58:00');
    seedCost(5_000_000);

    livewire(MoneyStats::class)->assertSeeInOrder(['AI cost this month', '$5.00', 'Since Oct 31']);

    $this->travelTo('2026-11-01 00:02:00');

    livewire(MoneyStats::class)->assertSeeInOrder(['AI cost this month', '$0.00', 'Was $5.00 last month']);
});

it('links the cost tile to a workspaces list that sorts by cost from the query string', function (): void {
    $expensive = OverviewData::workspaceOf(OverviewData::owner());
    $cheap = OverviewData::workspaceOf(OverviewData::owner());
    foreach ([[$cheap, 100_000], [$expensive, 2_500_000]] as [$workspace, $micros]) {
        AiCreditTransaction::query()->create([
            'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 's-'.Str::ulid(),
            'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
            'credits_charged' => 1, 'cost_micros' => $micros, 'metadata' => [], 'created_at' => now(),
        ]);
    }

    Livewire::withQueryParams(['sort' => 'ai_cost_this_month:desc'])
        ->test(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$expensive, $cheap], inOrder: true);
});

it('links the trial credits tile to the trialing balances from the query string', function (): void {
    $trial = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $free = OverviewData::workspaceOf(OverviewData::owner());
    $period = ['period_starts_at' => now()->startOfMonth(), 'period_ends_at' => now()->endOfMonth()];
    $trialBalance = AiCreditBalance::query()->updateOrCreate(['workspace_id' => $trial->getKey()], ['credits_remaining' => 1500, 'credits_used' => 500, ...$period]);
    $freeBalance = AiCreditBalance::query()->updateOrCreate(['workspace_id' => $free->getKey()], ['credits_remaining' => 300, 'credits_used' => 0, ...$period]);

    Livewire::withQueryParams(['filters' => ['trialing' => ['isActive' => true]], 'sort' => 'credits_used:desc'])
        ->test(ListAiCreditBalances::class)
        ->assertCanSeeTableRecords([$trialBalance])
        ->assertCanNotSeeTableRecords([$freeBalance]);
});

it('totals the budget left and names the lowest provider as billed when it has billed data', function (): void {
    seedProviderBudgets(openaiBilledDollars: 40, anthropicEstimateDollars: 20);

    livewire(MoneyStats::class)
        ->assertSee('$190.00')
        ->assertSee('Lowest: OpenAI at 20%')
        ->assertSee('billed through Oct 15')
        ->assertDontSee('(our estimate)');
});

it('marks the lowest provider as our estimate when it has no billed data', function (): void {
    seedProviderBudgets(openaiBilledDollars: 10, anthropicEstimateDollars: 180);

    livewire(MoneyStats::class)
        ->assertSee('$60.00')
        ->assertSee('Lowest: Anthropic at 10% (our estimate)');
});

it('shows MRR and AI cost red when MRR does not cover the AI cost', function (): void {
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'r-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => 4_560_000, 'metadata' => [], 'created_at' => now(),
    ]);

    $html = livewire(MoneyStats::class)->html();

    expect(substr_count($html, 'fi-color-danger'))->toBe(2)
        ->and($html)->not->toContain('fi-color-success');
});

it('shows MRR and AI cost gray, not red, when Stripe is unavailable', function (): void {
    config()->set('cashier.secret', 'sk_test_fake');
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    $workspace->forceFill(['stripe_id' => 'cus_unreachable'])->save();
    $workspace->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_unreachable', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
    ]);
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(), 'user_id' => $workspace->user_id, 'idempotency_key' => 'g-'.Str::ulid(),
        'type' => AiCreditType::Chat, 'model' => 'claude-sonnet-5', 'input_tokens' => 0, 'output_tokens' => 0,
        'credits_charged' => 1, 'cost_micros' => 4_560_000, 'metadata' => [], 'created_at' => now(),
    ]);
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            throw new RuntimeException('Stripe is unreachable');
        }
    });

    $component = livewire(MoneyStats::class)->assertSee('Stripe unavailable');
    $html = $component->html();

    expect($html)->not->toContain('fi-color-danger')
        ->and($html)->not->toContain('fi-color-success');
});
