<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * laravel/mcp's Dynamic Client Registration, bound in its place. It registers exactly as
 * the package does, against config('mcp.redirect_domains'); it only reports the entries of
 * MCP_REDIRECT_DOMAINS that were ignored for not being https origins, at the one moment they
 * matter: a client registration they could have let through. Logging them on every request
 * instead would flood the log of a deployment that is otherwise healthy.
 */
final class RegisterClientController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $ignored = config('mcp.ignored_redirect_domains', []);

        if (is_array($ignored) && $ignored !== []) {
            Log::warning('MCP_REDIRECT_DOMAINS: ignored the entries that are not https origins; no client can register a redirect URI on them.', [
                'ignored' => array_values($ignored),
            ]);
        }

        return parent::__invoke($request);
    }
}
