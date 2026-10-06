<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\EmailGrant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireConsentForOptInScopes
{
    // Passport skips consent when any active token for the client already holds the requested
    // scopes, whichever workspace that token was consented for.
    public function handle(Request $request, Closure $next): Response
    {
        $requested = explode(' ', $request->string('scope')->value());

        if (array_any($requested, fn (string $scope): bool => EmailGrant::tryFrom($scope) instanceof EmailGrant)) {
            $request->merge(['prompt' => 'consent']);
        }

        return $next($request);
    }
}
