<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\SystemAdmin\Metrics\Scopes\InternalWorkspace;

final readonly class WorkspaceJourney
{
    /**
     * @return array<string, string>
     */
    public static function facts(Workspace $workspace): array
    {
        return once(fn (): array => self::compute($workspace));
    }

    /**
     * @return array<string, string>
     */
    private static function compute(Workspace $workspace): array
    {
        $owner = $workspace->owner;
        $activity = ActivityDays::from()->where('activity.workspace_id', (string) $workspace->getKey());
        $firstRecord = (clone $activity)->where('activity.kind', 'record')->min('activity.day');
        $activeDays = (clone $activity)->where('activity.day', '>=', now()->subDays(30)->toDateString())->distinct()->count('activity.day');
        $messages = (clone $activity)->where('activity.kind', 'message')->count();
        $creditsUsed = (int) AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_used');

        return [
            'Signed up' => $owner instanceof User && $owner->created_at !== null ? $owner->created_at->format('M j, Y') : Money::EMPTY,
            'Signup method' => $owner instanceof User ? SignupMethod::for($owner) : Money::EMPTY,
            'Last wizard step' => $owner instanceof User && $owner->onboarding_step !== null ? $owner->onboarding_step->getLabel() : 'Finished',
            'First own record' => $firstRecord === null ? 'None yet' : CarbonImmutable::parse((string) $firstRecord)->format('M j, Y'),
            'Active days (30d)' => "{$activeDays} active ".Str::plural('day', $activeDays),
            'Typed chat messages' => number_format($messages),
            'Credits used this period' => number_format($creditsUsed),
            'AI cost this month' => Money::format(AiCost::workspaceMonthMicros($workspace)),
            'Setup exit reason' => $workspace->setup_exit_reason?->getLabel() ?? Money::EMPTY,
            'Internal' => Workspace::query()->whereKey($workspace->getKey())->withGlobalScope(InternalWorkspace::class, new InternalWorkspace)->exists() ? 'Yes' : 'No',
        ];
    }
}
