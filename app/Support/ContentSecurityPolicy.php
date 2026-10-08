<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

final readonly class ContentSecurityPolicy
{
    private const array ALWAYS_ENFORCED = [
        "frame-ancestors 'none'",
        "object-src 'none'",
        "base-uri 'self'",
    ];

    private const string FATHOM = 'https://cdn.usefathom.com';

    private const string SCALAR_CDN = 'https://cdn.jsdelivr.net';

    private const string SCALAR_FONTS = 'https://fonts.scalar.com';

    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public static function alwaysEnforced(): string
    {
        return implode('; ', self::ALWAYS_ENFORCED);
    }

    public static function full(bool $apiDocs = false): string
    {
        $directives = [];

        foreach (self::sources($apiDocs) as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', array_unique($sources));
        }

        return implode('; ', [...$directives, ...self::ALWAYS_ENFORCED]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function sources(bool $apiDocs): array
    {
        $sources = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", self::FATHOM, self::TURNSTILE],
            'style-src' => ["'self'", "'unsafe-inline'"],
            'img-src' => ["'self'", 'data:', 'blob:', self::FATHOM],
            'font-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", self::FATHOM, ...self::reverbOrigins()],
            'media-src' => ["'self'", 'blob:'],
            'frame-src' => ["'self'", self::TURNSTILE],
        ];

        foreach (self::viteDevOrigins() as $origin) {
            foreach (['script-src', 'style-src', 'font-src', 'img-src', 'connect-src'] as $directive) {
                $sources[$directive][] = $origin;
            }
        }

        if ($apiDocs) {
            $sources['script-src'][] = self::SCALAR_CDN;
            $sources['font-src'][] = self::SCALAR_FONTS;
        }

        foreach ((array) config('app.csp.extra') as $directive => $extra) {
            $sources[$directive] = [...$sources[$directive], ...$extra];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private static function reverbOrigins(): array
    {
        $host = config('app.csp.reverb.host');

        if (! is_string($host) || $host === '') {
            return [];
        }

        $secure = config('app.csp.reverb.scheme') === 'https';
        $port = config()->integer('app.csp.reverb.port');

        return [($secure ? 'wss' : 'ws')."://{$host}:{$port}"];
    }

    /**
     * @return array<int, string>
     */
    private static function viteDevOrigins(): array
    {
        if (! Vite::isRunningHot()) {
            return [];
        }

        $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');
        $websocket = preg_replace('/^http/', 'ws', $origin);

        return array_filter([$origin, $websocket]);
    }
}
