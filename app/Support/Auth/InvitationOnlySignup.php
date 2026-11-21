<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\WorkspaceInvitation;
use App\Support\EmailAddress;

/**
 * Decides whether an email may open a brand-new account when the instance is
 * invitation-only (relaticle.registration.invitation_only).
 *
 * An invitation is a pending, unexpired workspace invitation addressed to that
 * email. Upstream removed shareable workspace join links in 3.11, so an email
 * invitation is the only way in. Existing users are never affected: this is
 * consulted only before creating a user.
 */
final readonly class InvitationOnlySignup
{
    public static function enabled(): bool
    {
        return (bool) config('relaticle.registration.invitation_only', false);
    }

    public function allows(string $email): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return WorkspaceInvitation::hasPendingInvitationFor(EmailAddress::canonicalize($email));
    }
}
