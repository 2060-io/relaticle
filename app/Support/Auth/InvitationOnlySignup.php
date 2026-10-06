<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Concerns\DetectsWorkspaceInvitation;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Support\EmailAddress;

/**
 * Decides whether an email may open a brand-new account when the instance is
 * invitation-only (relaticle.registration.invitation_only).
 *
 * An invitation is either a pending, unexpired workspace invitation addressed
 * to that email, or an active workspace invite link the visitor arrived
 * through (its token sits in the intended URL for the session). Existing users
 * are never affected: this is consulted only before creating a user.
 */
final readonly class InvitationOnlySignup
{
    use DetectsWorkspaceInvitation;

    public static function enabled(): bool
    {
        return (bool) config('relaticle.registration.invitation_only', false);
    }

    public function allows(string $email): bool
    {
        if (! self::enabled()) {
            return true;
        }

        if (WorkspaceInvitation::hasPendingInvitationFor(EmailAddress::canonicalize($email))) {
            return true;
        }

        $workspace = $this->getWorkspaceFromInviteLinkInSession();

        return $workspace instanceof Workspace && ! $workspace->isInviteLinkTokenExpired();
    }
}
