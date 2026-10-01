<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Enums\Plan;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Relaticle\SystemAdmin\Actions\MarkWorkspaceContacted;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\SalesLeads;
use Relaticle\SystemAdmin\Metrics\SalesLeadsQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(SalesLeads::class, SalesLeadsQuery::class, MarkWorkspaceContacted::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

it('lists non-paying customers with real use, most active first, and says why', function (): void {
    $busy = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    foreach ([1, 2, 3] as $daysAgo) {
        OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDays($daysAgo));
    }
    OverviewData::ownRecord(OverviewData::workspaceOf($busy), $busy, now()->subDay(), CreationSource::API);

    $light = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::ownRecord(OverviewData::workspaceOf($light), $light, now()->subDay());

    $sampleOnly = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    OverviewData::sampleRecord(OverviewData::workspaceOf($sampleOnly), now()->subDay());

    $internal = OverviewData::internalOwner();
    OverviewData::ownRecord(OverviewData::workspaceOf($internal), $internal, now()->subDay());

    livewire(SalesLeads::class)
        ->assertCanSeeTableRecords([OverviewData::workspaceOf($busy), OverviewData::workspaceOf($light)], inOrder: true)
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($sampleOnly), OverviewData::workspaceOf($internal)])
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

it('hides a workspace for 14 days once marked contacted', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-09-01'));
    $workspace = OverviewData::workspaceOf($owner);
    OverviewData::ownRecord($workspace, $owner, now()->subDay());

    livewire(SalesLeads::class)
        ->callAction(TestAction::make('contacted')->table($workspace))
        ->assertCanNotSeeTableRecords([$workspace]);

    expect($workspace->refresh()->sales_contacted_at)->not->toBeNull();

    $this->travelTo(now()->addDays(15));
    Cache::flush();

    livewire(SalesLeads::class)->assertCanSeeTableRecords([$workspace]);
});
