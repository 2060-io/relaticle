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

    private const string BUNNY_FONTS = 'https://fonts.bunny.net';

    private const string SCALAR_CDN = 'https://cdn.jsdelivr.net';

    private const string SCALAR_FONTS = 'https://fonts.scalar.com';

    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public static function alwaysEnforced(): string
    {
        return implode('; ', self::ALWAYS_ENFORCED);
    }

    public static function full(bool $apiDocs = false, bool $horizon = false): string
    {
        $directives = [];

        foreach (self::sources($apiDocs, $horizon) as $directive => $sources) {
            $directives[] = $directive.' '.implode(' ', array_unique($sources));
        }

        return implode('; ', [...$directives, ...self::ALWAYS_ENFORCED]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private static function sources(bool $apiDocs, bool $horizon): array
    {
        $storage = self::storageOrigins();
        $assets = self::assetOrigins();

        $sources = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", self::FATHOM, self::TURNSTILE, ...$assets],
            'style-src' => ["'self'", "'unsafe-inline'", ...$assets],
            'img-src' => ["'self'", 'data:', 'blob:', self::FATHOM, ...$assets, ...$storage],
            'font-src' => ["'self'", 'data:', ...$assets],
            'connect-src' => ["'self'", self::FATHOM, ...self::reverbOrigins(), ...$storage],
            'media-src' => ["'self'", 'blob:', ...$storage],
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

        if ($horizon) {
            $sources['style-src'][] = self::BUNNY_FONTS;
            $sources['font-src'][] = self::BUNNY_FONTS;
        }

        foreach ((array) config('app.csp.extra') as $directive => $extra) {
            $sources[$directive] = [...$sources[$directive], ...$extra];
        }

        return $sources;
    }

    /**
     * @return array<int, string>
     */
    private static function assetOrigins(): array
    {
        return self::foreignOrigins([config('app.asset_url')]);
    }

    /**
     * @return array<int, string>
     */
    private static function storageOrigins(): array
    {
        $urls = [config('filesystems.disks.public.url')];

        foreach (config()->array('filesystems.disks') as $disk) {
            if (is_array($disk) && ($disk['driver'] ?? null) === 's3' && filled($disk['bucket'] ?? null)) {
                $urls[] = $disk['url'] ?? null;
                $urls[] = self::presignedUrl($disk);
            }
        }

        return self::foreignOrigins($urls);
    }

    /**
     * @param  array<string, mixed>  $disk
     */
    private static function presignedUrl(array $disk): ?string
    {
        $bucket = (string) $disk['bucket'];
        $endpoint = $disk['endpoint'] ?? null;

        if (blank($endpoint)) {
            return filled($disk['region'] ?? null) ? "https://{$bucket}.s3.{$disk['region']}.amazonaws.com" : null;
        }

        if ($disk['use_path_style_endpoint'] ?? false) {
            return (string) $endpoint;
        }

        return preg_replace('#^(https?://)#', "$1{$bucket}.", (string) $endpoint);
    }

    /**
     * @param  array<int, mixed>  $urls
     * @return array<int, string>
     */
    private static function foreignOrigins(array $urls): array
    {
        $appHost = parse_url(config()->string('app.url'), PHP_URL_HOST);
        $origins = [];

        foreach ($urls as $url) {
            $parts = is_string($url) && $url !== '' ? parse_url($url) : false;

            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === $appHost) {
                continue;
            }

            $origins[] = "{$parts['scheme']}://{$parts['host']}".(isset($parts['port']) ? ":{$parts['port']}" : '');
        }

        return array_values(array_unique($origins));
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
