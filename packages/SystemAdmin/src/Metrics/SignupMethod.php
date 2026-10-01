<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use App\Models\User;
use App\Models\UserSocialAccount;

final readonly class SignupMethod
{
    public static function for(User $user): string
    {
        // A social signup creates its account in the same request as the user; a later one is a link.
        $provider = $user->socialAccounts
            ->first(fn (UserSocialAccount $account): bool => $account->created_at->lessThanOrEqualTo($user->created_at->addMinute()))
            ?->getAttribute('provider_name');

        return is_string($provider) ? ucfirst($provider) : 'Password';
    }
}
