<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\SystemAdmin\Metrics\SalesLeadsQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(SalesLeads::class, SalesLeadsQuery::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

it('lists non-paying customers with real use, most active first, and says why', function (): void {
    $light = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($light), $light, now()->subDay());

    $bulk = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    for ($record = 0; $record < 5; $record++) {
        OverviewData::ownRecord(OverviewData::workspaceOf($bulk), $bulk, now()->subDay());
    }

    $steady = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    foreach ([1, 2, 3] as $daysAgo) {
        OverviewData::ownRecord(OverviewData::workspaceOf($steady), $steady, now()->subDays($daysAgo));
    }

    $busy = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    foreach ([1, 2, 3] as $daysAgo) {
        OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDays($daysAgo));
    }
    OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDay(), CreationSource::API);

    $sampleOnly = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::sampleRecord(OverviewData::workspaceOf($sampleOnly), now()->subDay());

    $internal = OverviewData::internalOwner();
    OverviewData::ownRecord(OverviewData::workspaceOf($internal), $internal, now()->subDay());

    livewire(SalesLeads::class)
        ->assertCanSeeTableRecords([
            OverviewData::workspaceOf($busy),
            OverviewData::workspaceOf($steady),
            OverviewData::workspaceOf($bulk),
            OverviewData::workspaceOf($light),
        ], inOrder: true)
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($sampleOnly), OverviewData::workspaceOf($internal)])
        ->assertSee('4 records, 3 active days')
        ->assertSee('uses API');
});

it('leaves out workspaces that already pay or are on a negotiated plan', function (): void {
    $subscriber = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $subscribed = OverviewData::workspaceOf($subscriber);
    OverviewData::ownRecord($subscribed, $subscriber, now()->subDay());
    $subscribed->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_paying', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
    ]);

    $negotiated = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($negotiated), $negotiated, now()->subDay());
    OverviewData::workspaceOf($negotiated)->forceFill(['plan' => Plan::Enterprise])->save();

    $free = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($free), $free, now()->subDay());

    livewire(SalesLeads::class)
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($free)])
        ->assertCanNotSeeTableRecords([$subscribed, OverviewData::workspaceOf($negotiated)]);
});

it('renders an empty list when nobody qualifies', function (): void {
    livewire(SalesLeads::class)
        ->assertSuccessful()
        ->assertCountTableRecords(0);
});

it('shows at most ten workspaces', function (): void {
    for ($workspace = 0; $workspace < 11; $workspace++) {
        $owner = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
        OverviewData::ownRecord(OverviewData::workspaceOf($owner), $owner, now()->subDay());
    }

    expect(livewire(SalesLeads::class)->instance()->getTableRecords())->toHaveCount(10);
});

it('counts the last 30 calendar days, today included, as active days', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-08-01'));
    $workspace = OverviewData::workspaceOf($owner);
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-15 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-16 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, now());

    livewire(SalesLeads::class)->assertSee('3 records, 2 active days');
});

it('lists a workspace whose owner no longer exists, without an email action', function (): void {
    $departed = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $orphaned = OverviewData::workspaceOf($departed);
    OverviewData::ownRecord($orphaned, $departed, now()->subDay());
    $orphaned->forceFill(['user_id' => (string) Str::ulid()])->save();

    $present = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($present), $present, now()->subDay());

    livewire(SalesLeads::class)
        ->assertCanSeeTableRecords([$orphaned, OverviewData::workspaceOf($present)])
        ->assertActionHidden(TestAction::make('emailOwner')->table($orphaned))
        ->assertActionVisible(TestAction::make('emailOwner')->table(OverviewData::workspaceOf($present)));
});
