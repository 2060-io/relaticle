<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Relaticle\SystemAdmin\Filament\Resources\UserResource\Pages\ListUsers;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\CohortTable;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\ValueStats;
use Relaticle\SystemAdmin\Metrics\Cohorts;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(ValueStats::class, CohortTable::class, Cohorts::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
});

it('renders on an empty database', function (): void {
    livewire(ValueStats::class)
        ->assertOk()
        ->assertSee('Is anyone getting value?')
        ->assertSee('Real signups')
        ->assertSee('Reached first value')
        ->assertSee('Formed a habit');

    livewire(CohortTable::class)->assertOk();
});

it('counts real signups and first value for the latest week with 7 days of follow-up', function (): void {
    $fast = OverviewData::owner(CarbonImmutable::parse('2026-09-16 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($fast), $fast, CarbonImmutable::parse('2026-09-18 10:00:00'));
    $slow = OverviewData::owner(CarbonImmutable::parse('2026-09-17 10:00:00'));
    $nextWeek = OverviewData::owner(CarbonImmutable::parse('2026-09-24 10:00:00'));

    livewire(ValueStats::class)
        ->assertSee('Week of Sep 14')
        ->assertSee('50%')
        ->assertSee('filters%5Bgenuine_signup%5D%5BisActive%5D=1', escape: false);

    livewire(ListUsers::class)
        ->filterTable('genuine_signup')
        ->filterTable('signed_up', ['from' => '2026-09-14', 'until' => '2026-09-20'])
        ->assertCanSeeTableRecords([$fast, $slow])
        ->assertCanNotSeeTableRecords([$nextWeek])
        ->filterTable('reached_first_value')
        ->assertCanSeeTableRecords([$fast])
        ->assertCanNotSeeTableRecords([$slow, $nextWeek]);
});

it('counts workspaces that formed a habit as the workspace list shows them', function (): void {
    $steady = OverviewData::owner(CarbonImmutable::parse('2026-08-20 10:00:00'));
    $steadyWorkspace = OverviewData::workspaceOf($steady);

    foreach (['2026-09-08', '2026-09-15', '2026-09-22'] as $day) {
        OverviewData::ownRecord($steadyWorkspace, $steady, CarbonImmutable::parse("{$day} 10:00:00"));
    }

    $sporadic = OverviewData::owner(CarbonImmutable::parse('2026-08-20 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($sporadic), $sporadic, CarbonImmutable::parse('2026-09-22 10:00:00'));

    livewire(ValueStats::class)->assertSeeInOrder(['Formed a habit', '1', '+1 vs a week earlier']);

    livewire(ListWorkspaces::class)
        ->filterTable('formed_habit')
        ->assertCanSeeTableRecords([$steadyWorkspace])
        ->assertCanNotSeeTableRecords([OverviewData::workspaceOf($sporadic)]);
});

it('builds six weekly cohorts with a dot for weeks that have not happened', function (): void {
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-09-15 10:00:00'));
    OverviewData::ownRecord(OverviewData::workspaceOf($owner), $owner, CarbonImmutable::parse('2026-09-23 10:00:00'));

    $rows = Cohorts::rows();
    $week = collect($rows)->first(fn (array $row): bool => $row['week']->toDateString() === '2026-09-14');

    expect($rows)->toHaveCount(6)
        ->and($week['size'])->toBe(1)
        ->and($week['shares'])->toBe([0, 100, null, null]);

    livewire(CohortTable::class)->assertSee('Sep 14');
});
