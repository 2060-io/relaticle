<?php

declare(strict_types=1);

use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Metrics\Revenue;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Helpers\OverviewData;

mutates(Revenue::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('cashier.secret', 'sk_test_fake');
    Cache::flush();
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

/**
 * @param  array<string, array{cents: int|null, interval: string}>  $bySubscription
 */
function fakeStripeSubscriptions(array $bySubscription, bool $fails = false): void
{
    ApiRequestor::setHttpClient(new readonly class($bySubscription, $fails) implements ClientInterface
    {
        /** @param  array<string, array{cents: int|null, interval: string}>  $bySubscription */
        public function __construct(private array $bySubscription, private bool $fails) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            throw_if($this->fails, RuntimeException::class, 'Stripe is unreachable');

            $id = basename(parse_url((string) $absUrl, PHP_URL_PATH) ?: '');
            $data = $this->bySubscription[$id];

            return [json_encode([
                'id' => $id,
                'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_'.$id,
                    'object' => 'subscription_item',
                    'price' => ['id' => 'price_x', 'object' => 'price', 'recurring' => ['interval' => $data['interval'], 'interval_count' => 1]],
                ]]],
                'latest_invoice' => $data['cents'] === null ? null : ['id' => 'in_'.$id, 'object' => 'invoice', 'total_excluding_tax' => $data['cents']],
            ]), 200, []];
        }
    });
}

function payingWorkspace(Workspace $workspace, string $stripeId): Subscription
{
    $workspace->forceFill(['stripe_id' => 'cus_'.$stripeId])->save();

    return $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_x',
        'quantity' => 1,
    ]);
}

it('sums what customers actually paid per month, before tax, without internal workspaces', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_yearly');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_promo');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_unbilled');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal');

    fakeStripeSubscriptions([
        'sub_yearly' => ['cents' => 22_800, 'interval' => 'year'],
        'sub_promo' => ['cents' => 1_200, 'interval' => 'month'],
        'sub_unbilled' => ['cents' => null, 'interval' => 'month'],
        'sub_internal' => ['cents' => 2_400, 'interval' => 'month'],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(31_000_000);
});

it('reports Stripe being unavailable instead of a wrong number', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_down');
    fakeStripeSubscriptions([], fails: true);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('lists the subscriptions that count toward MRR', function (): void {
    $counted = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_counted');
    $internal = payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal_list');
    $ended = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_ended');
    $ended->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()])->save();

    livewire(ListSubscriptions::class)
        ->filterTable('counts_toward_mrr')
        ->assertCanSeeTableRecords([$counted])
        ->assertCanNotSeeTableRecords([$internal, $ended]);
});
