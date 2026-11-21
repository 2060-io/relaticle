<?php

declare(strict_types=1);

namespace App\Http\Controllers\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController as BaseOAuthRegisterController;

final class OAuthRegisterController extends BaseOAuthRegisterController
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Fork: reports the MCP_REDIRECT_DOMAINS entries config/mcp.php ignored for not being
     * https origins, at the one moment they matter: a client registration they could have
     * let through. Logging them on every request instead would flood a healthy log.
     */
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

    protected function isValidRedirectUri(string $value): bool
    {
        if (! parent::isValidRedirectUri($value)) {
            return false;
        }

        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        if (in_array('*', $domains, true)) {
            return true;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === 'http') {
            return in_array($host, self::LOOPBACK_HOSTS, true)
                && collect($domains)->contains(fn (string $domain): bool => in_array(strtolower((string) parse_url($domain, PHP_URL_HOST)), self::LOOPBACK_HOSTS, true));
        }

        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }

        return collect($domains)->contains(fn (string $domain): bool => strtolower((string) parse_url($domain, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($domain, PHP_URL_HOST)) === $host);
    }

    /**
     * @return array<int, string>
     */
    protected function allowedDomains(): array
    {
        return ['https://', 'http://'];
    }
}
