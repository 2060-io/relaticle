<?php

declare(strict_types=1);

namespace App\Support\Mcp;

/**
 * The redirect domains a deployment adds to config('mcp.redirect_domains') through
 * MCP_REDIRECT_DOMAINS, a comma-separated list. laravel/mcp accepts a client's redirect
 * URI when it starts with one of the domains followed by "/", so an entry must be an https
 * origin (scheme, host and an optional port, nothing else): anything wider, such as "*",
 * a plain-http origin, a path or a wildcard host, would let a client registration send
 * authorization codes somewhere the operator did not mean. Such an entry is ignored, never
 * widened or repaired, and reported by ignored() so that it can be logged.
 */
final readonly class RedirectDomains
{
    /** One DNS label: letters, digits and inner hyphens, at most 63 characters. */
    private const string LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * The https origins of the list, lowercased, without a trailing slash, each once.
     *
     * @return list<string>
     */
    public static function origins(string $list): array
    {
        return array_values(array_unique(array_filter(array_map(
            self::origin(...),
            self::entries($list),
        ), fn (?string $origin): bool => $origin !== null)));
    }

    /**
     * The entries of the list that are not https origins, as written.
     *
     * @return list<string>
     */
    public static function ignored(string $list): array
    {
        return array_values(array_filter(
            self::entries($list),
            fn (string $entry): bool => self::origin($entry) === null,
        ));
    }

    /** @return list<string> */
    private static function entries(string $list): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(',', $list)),
            fn (string $entry): bool => $entry !== '',
        ));
    }

    private static function origin(string $entry): ?string
    {
        $parts = parse_url($entry);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }

        $host = strtolower($parts['host'] ?? '');

        if (strlen($host) > 253 || preg_match('/^'.self::LABEL.'(?:\.'.self::LABEL.')*$/', $host) !== 1) {
            return null;
        }

        $origin = 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');

        // Rebuilt from its parts, the origin must be the whole entry: a path, a query, a
        // fragment, credentials or anything parse_url() skipped over leaves it different.
        return strtolower(rtrim($entry, '/')) === $origin && substr_count($entry, '/') <= 3 ? $origin : null;
    }
}
