<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

final class WorkspaceInvitationRateLimitedException extends ValidationException
{
    public int $retryAfterSeconds = 0;

    public static function afterSeconds(int $seconds): self
    {
        $exception = self::withMessages([
            'email' => __('workspaces.notifications.invite_rate_limited.body', ['seconds' => $seconds]),
        ])->errorBag('addWorkspaceMember');

        $exception->retryAfterSeconds = $seconds;

        return $exception;
    }
}
