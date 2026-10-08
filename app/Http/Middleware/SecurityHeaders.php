<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headersFor($request) as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Request $request): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        if ($request->isSecure() && app()->isProduction()) {
            $headers['Strict-Transport-Security'] = $this->strictTransportSecurity();
        }

        return $headers;
    }

    private function strictTransportSecurity(): string
    {
        $value = 'max-age='.config()->integer('app.hsts.max_age');

        return config()->boolean('app.hsts.include_subdomains')
            ? "{$value}; includeSubDomains"
            : $value;
    }
}
