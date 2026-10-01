<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\User;

final readonly class SignupMethod
{
    public static function for(User $user): string
    {
        $provider = $user->socialAccounts->sortBy('created_at')->first()?->getAttribute('provider_name');

        return is_string($provider) ? ucfirst($provider) : 'Password';
    }
}
