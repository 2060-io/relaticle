<?php

declare(strict_types=1);

namespace App\Support\Workspaces;

use App\Exceptions\WorkspaceInvitationRateLimitedException;
use App\Models\Workspace;
use Illuminate\Support\Facades\RateLimiter;

final readonly class InvitationCeiling
{
    private const int MAX_PER_HOUR = 50;

    private const int MAX_PER_DAY = 200;

    private const int HOUR_SECONDS = 3600;

    private const int DAY_SECONDS = 86400;

    public function consume(Workspace $workspace): void
    {
        $windows = [
            "workspace-invitations:hour:{$workspace->getKey()}" => [self::MAX_PER_HOUR, self::HOUR_SECONDS],
            "workspace-invitations:day:{$workspace->getKey()}" => [self::MAX_PER_DAY, self::DAY_SECONDS],
        ];

        $retryAfter = 0;

        foreach ($windows as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        if ($retryAfter > 0) {
            throw WorkspaceInvitationRateLimitedException::afterSeconds($retryAfter);
        }

        foreach ($windows as $key => [, $seconds]) {
            RateLimiter::hit($key, $seconds);
        }
    }
}
