<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Scopes\FormedHabit;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;
use Relaticle\SystemAdmin\Metrics\Scopes\ReachedFirstValue;

final class ValueStats extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Is anyone getting value?';

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $week = now()->subDays(14)->startOfWeek(CarbonInterface::MONDAY);
        $signups = (int) $cache->remember('value.signups', fn (): int => $this->signups($week));
        $previous = (int) $cache->remember('value.signups.previous', fn (): int => $this->signups($week->subWeek()));
        $firstValue = (int) $cache->remember('value.first_value', fn (): int => $this->signups($week, firstValue: true));
        $share = $signups === 0 ? null : (int) round($firstValue / $signups * 100);
        $currentWeek = now()->startOfWeek(CarbonInterface::MONDAY);
        $habits = (int) $cache->remember('value.habits', fn (): int => Workspace::query()->withGlobalScope(FormedHabit::class, new FormedHabit($currentWeek))->count());
        $habitsBefore = (int) $cache->remember('value.habits.previous', fn (): int => Workspace::query()->withGlobalScope(FormedHabit::class, new FormedHabit($currentWeek->subWeek()))->count());
        $filters = [
            'genuine_signup' => ['isActive' => true],
            'signed_up' => ['from' => $week->toDateString(), 'until' => $week->addDays(6)->toDateString()],
        ];

        return [
            Stat::make('Real signups', number_format($signups))
                ->description('Week of '.$week->format('M j').', '.($signups - $previous >= 0 ? '+' : '').($signups - $previous).' vs the week before')
                ->color('gray')
                ->extraAttributes(['title' => 'Verified, not invited, not abuse-flagged, not yours. The latest week that has had 7 days to act.'])
                ->url(UserResource::getUrl('index', ['filters' => $filters])),
            Stat::make('Reached first value', $share === null ? "\u{2014}" : "{$share}%")
                ->description("{$firstValue} of {$signups} added their own data within 7 days")
                ->color(match (true) {
                    $share === null => 'gray',
                    $share < 20 => 'danger',
                    $share < 30 => 'warning',
                    default => 'success',
                })
                ->url(UserResource::getUrl('index', ['filters' => [...$filters, 'reached_first_value' => ['isActive' => true]]])),
            Stat::make('Formed a habit', number_format($habits))
                ->description(($habits - $habitsBefore >= 0 ? '+' : '').($habits - $habitsBefore).' vs a week earlier')
                ->color(match (true) {
                    $habits > $habitsBefore => 'success',
                    $habits === $habitsBefore => 'warning',
                    default => 'danger',
                })
                ->extraAttributes(['title' => 'Customer workspaces with own data or typed chat in at least 3 of the last 4 complete weeks.'])
                ->url(WorkspaceResource::getUrl('index', ['filters' => ['formed_habit' => ['isActive' => true]]])),
        ];
    }

    private function signups(CarbonImmutable $week, bool $firstValue = false): int
    {
        $query = User::query()
            ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
            ->where('users.created_at', '>=', ViewerTime::startOfDayUtc($week->toDateString()))
            ->where('users.created_at', '<=', ViewerTime::endOfDayUtc($week->addDays(6)->toDateString()));

        if ($firstValue) {
            $query->withGlobalScope(ReachedFirstValue::class, new ReachedFirstValue);
        }

        return $query->count();
    }
}
