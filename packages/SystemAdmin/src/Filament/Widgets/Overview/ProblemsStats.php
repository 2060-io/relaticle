<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\ChatMessageFeedback;
use Relaticle\SystemAdmin\Filament\Resources\ChatMessageFeedbackResource;
use Relaticle\SystemAdmin\Filament\Resources\UserResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\ViewerTime;
use Relaticle\SystemAdmin\Metrics\OverviewCache;
use Relaticle\SystemAdmin\Metrics\Scopes\AbuseSuspect;
use Relaticle\SystemAdmin\Metrics\Scopes\GenuineSignup;
use Relaticle\SystemAdmin\Metrics\Scopes\RecentGenuineWorkspace;
use Relaticle\SystemAdmin\Metrics\Scopes\StuckAfterSetup;

final class ProblemsStats extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = "What's going wrong?";

    protected ?string $pollingInterval = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $cache = new OverviewCache;
        $stats = [];

        if (BillingStatus::billingEnabled()) {
            $stats[] = $this->abuse($cache);
        }

        $stats[] = $this->stuck($cache);
        $stats[] = $this->leftWizard($cache);
        $stats[] = $this->thumbsDown($cache);

        return $stats;
    }

    public static function thumbsDownThisWeek(): int
    {
        return ChatMessageFeedback::query()
            ->where('rating', ChatMessageFeedback::RATING_DOWN)
            ->createdThisWeek()
            ->count();
    }

    private function abuse(OverviewCache $cache): Stat
    {
        $suspects = (int) $cache->remember('problems.abuse', fn (): int => Workspace::query()
            ->withGlobalScope(AbuseSuspect::class, new AbuseSuspect)
            ->count());
        $spending = (bool) $cache->remember('problems.abuse.spending', fn (): bool => AiCreditTransaction::query()
            ->where('type', AiCreditType::Chat)
            ->where('created_at', '>=', now()->subDays(7))
            ->whereIn('workspace_id', Workspace::query()->withGlobalScope(AbuseSuspect::class, new AbuseSuspect)->select('id'))
            ->exists());

        return Stat::make('Trial abuse suspects', number_format($suspects))
            ->description($spending ? 'Some are still spending credits' : 'None spent credits this week')
            ->color($suspects === 0 ? 'success' : ($spending ? 'danger' : 'warning'))
            ->extraAttributes(['title' => 'Trialing workspaces with no own data that mostly used premium models or sit in a region our AI providers do not serve.'])
            ->url(WorkspaceResource::getUrl('index', ['filters' => ['abuse_suspect' => ['isActive' => true]]]));
    }

    private function stuck(OverviewCache $cache): Stat
    {
        $recent = (int) $cache->remember('problems.recent', fn (): int => Workspace::query()
            ->withGlobalScope(RecentGenuineWorkspace::class, new RecentGenuineWorkspace)
            ->count());
        $stuck = (int) $cache->remember('problems.stuck', fn (): int => Workspace::query()
            ->withGlobalScope(StuckAfterSetup::class, new StuckAfterSetup)
            ->count());
        $share = $recent === 0 ? null : (int) round($stuck / $recent * 100);

        return Stat::make('Stuck after setup', $share === null ? "\u{2014}" : "{$share}%")
            ->description($share === null ? 'No owners 3 to 30 days old yet' : "{$stuck} of {$recent} new owners did nothing in their first 3 days")
            ->color(match (true) {
                $share === null => 'gray',
                $share >= 60 => 'danger',
                $share >= 40 => 'warning',
                default => 'success',
            })
            ->extraAttributes(['title' => 'Genuine owners whose workspace is 3 to 30 days old, with no own data and no typed chat message in its first 3 days.'])
            ->url(WorkspaceResource::getUrl('index', ['filters' => ['stuck_after_setup' => ['isActive' => true]]]));
    }

    private function leftWizard(OverviewCache $cache): Stat
    {
        $from = ViewerTime::today()->subDays(29)->toDateString();
        $until = ViewerTime::today()->toDateString();

        /** @var array{all: int, left: int, methods: array<string, int>} $counts */
        $counts = $cache->remember('problems.wizard.'.ViewerTime::timezone().".{$from}", function () use ($from, $until): array {
            $recent = User::query()
                ->withGlobalScope(GenuineSignup::class, new GenuineSignup)
                ->where('users.created_at', '>=', ViewerTime::startOfDayUtc($from))
                ->where('users.created_at', '<=', ViewerTime::endOfDayUtc($until));
            $left = (clone $recent)->whereDoesntHave('ownedWorkspaces')->whereDoesntHave('workspaces');
            $leftCount = $left->count();
            $methods = ['Password' => $leftCount - (clone $left)->signedUpWith()->count()];

            foreach (UserSocialAccount::query()->distinct()->orderBy('provider_name')->pluck('provider_name') as $provider) {
                $methods[ucfirst((string) $provider)] = (clone $left)->signedUpWith((string) $provider)->count();
            }

            return ['all' => $recent->count(), 'left' => $leftCount, 'methods' => array_filter($methods)];
        });
        $share = $counts['all'] === 0 ? null : (int) round($counts['left'] / $counts['all'] * 100);

        return Stat::make('Left the setup wizard', $share === null ? "\u{2014}" : "{$share}%")
            ->description($share === null ? 'No signups in the last 30 days' : "{$counts['left']} of {$counts['all']} signups in 30 days".$this->methodSplit($counts['methods']))
            ->color(match (true) {
                $share === null => 'gray',
                $share >= 10 => 'warning',
                default => 'success',
            })
            ->extraAttributes(['title' => 'Genuine verified signups of the last 30 days who never made a workspace.'])
            ->url(UserResource::getUrl('index', ['filters' => [
                'genuine_signup' => ['isActive' => true],
                'no_workspace' => ['isActive' => true],
                'signed_up' => ['from' => $from, 'until' => $until],
            ]]));
    }

    /**
     * @param  array<string, int>  $methods
     */
    private function methodSplit(array $methods): string
    {
        if ($methods === []) {
            return '';
        }

        return ': '.implode(', ', array_map(
            fn (string $method, int $count): string => "{$count} {$method}",
            array_keys($methods),
            $methods,
        ));
    }

    private function thumbsDown(OverviewCache $cache): Stat
    {
        $week = now()->startOfWeek(CarbonInterface::MONDAY)->toDateString();
        $count = (int) $cache->remember("problems.thumbs.{$week}", fn (): int => self::thumbsDownThisWeek());

        return Stat::make('Thumbs down this week', number_format($count))
            ->description('Answers people rated down since Monday')
            ->color(match (true) {
                $count === 0 => 'success',
                $count <= 2 => 'warning',
                default => 'danger',
            })
            ->url(ChatMessageFeedbackResource::getUrl('index', ['filters' => [
                'rating' => ['value' => ChatMessageFeedback::RATING_DOWN],
                'this_week' => ['isActive' => true],
            ]]));
    }
}
