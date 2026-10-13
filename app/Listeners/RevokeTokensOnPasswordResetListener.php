<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Auth\RevokeUserTokens;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;

final readonly class RevokeTokensOnPasswordResetListener
{
    public function __construct(private RevokeUserTokens $revokeUserTokens) {}

    public function handle(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->revokeUserTokens->execute($event->user);
    }
}
