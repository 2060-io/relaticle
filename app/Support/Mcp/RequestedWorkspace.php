<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The workspace an OAuth client names in its authorization request (`workspace=<id>`), for a
 * client that must be connected to one workspace and no other. The consent then offers that
 * workspace alone, and the approval is refused for any other.
 *
 * The parameter lives in the authorization URL, so it survives the sign-in with the rest of
 * that URL (the intended URL). The approval form posts nothing of it: the consent binds it in
 * the session to the auth token Passport issued for that same authorization request, and the
 * approval reads it back from there, never from the form.
 */
final readonly class RequestedWorkspace
{
    public const string PARAMETER = 'workspace';

    private const string SESSION_KEY = 'mcp.oauth.requested_workspace';

    /**
     * The id the request names; '' when the parameter is present but names nothing usable
     * (empty, or an array), which matches no workspace; null when the request names none.
     */
    public static function of(Request $request): ?string
    {
        $query = $request->query->all();

        if (! array_key_exists(self::PARAMETER, $query)) {
            return null;
        }

        return is_string($query[self::PARAMETER]) ? trim($query[self::PARAMETER]) : '';
    }

    /** Bind the workspace a consent screen offers to the auth token of its request, or clear it. */
    public static function bind(Session $session, string $authToken, ?string $workspaceId): void
    {
        if ($workspaceId === null) {
            $session->forget(self::SESSION_KEY);

            return;
        }

        $session->put(self::SESSION_KEY, ['auth_token' => $authToken, 'workspace_id' => $workspaceId]);
    }

    /**
     * Whether an approval must be refused: a workspace is bound and the approval is for
     * another one, or carries another auth token than the consent that bound it.
     */
    public static function refuses(Session $session, mixed $authToken, string $workspaceId): bool
    {
        $bound = $session->get(self::SESSION_KEY);

        if ($bound === null) {
            return false;
        }

        return ! is_array($bound)
            || ! is_string($bound['auth_token'] ?? null)
            || ! is_string($authToken)
            || ! hash_equals($bound['auth_token'], $authToken)
            || ($bound['workspace_id'] ?? null) !== $workspaceId;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
    }
}
