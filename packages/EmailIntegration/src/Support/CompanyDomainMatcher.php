<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\Company;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

/**
 * Single source of truth for resolving an email/attendee domain to an existing
 * Company via the workspace's "domains" custom field. Shared by the email and meeting
 * link actions (fast path) and the system auto-create action (locked re-check),
 * so the matching semantics never drift between them.
 *
 * Identity is the full host: accounts.printtest.com and ideas.printtest.com
 * are distinct companies. A www. prefix on the same host is not. Parent
 * domains are not a match (cap.so does not own send.cap.so).
 */
final class CompanyDomainMatcher
{
    /**
     * Find the first Company in the workspace whose "domains" custom field contains
     * the given host as a complete host token. Returns null when no company
     * owns the domain.
     */
    public function firstMatching(string $domain, string $workspaceId): ?Company
    {
        $host = $this->host($domain);

        return Company::query()->where('workspace_id', $workspaceId)
            ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('code', 'domains'))
                ->whereRaw('json_value::text ~* ?', [$this->matchPattern($host)])
            )
            ->first();
    }

    /**
     * Lowercase host with a single leading www. stripped. The create lock and
     * stored domain both use this so www.cap.so and cap.so collide, while
     * send.cap.so does not.
     */
    public function host(string $domain): string
    {
        $domain = Str::lower(trim($domain, '.'));

        if (Str::before($domain, '.') !== 'www') {
            return $domain;
        }

        return Str::after($domain, '.');
    }

    /**
     * Build a POSIX regex matching the host as a complete hostname inside the
     * JSON-encoded domains array. Allows a www. prefix, scheme, and path.
     * Does not treat a leading "." as a boundary, so a parent like "cap.so"
     * cannot collide with "send.cap.so", and "acme.co" cannot collide with
     * "acme.com.au". Sender-controlled LIKE wildcards are quoted.
     */
    public function matchPattern(string $domain): string
    {
        return '(^|["/])(www\.)?'.preg_quote($this->host($domain)).'(["/:]|$)';
    }

    /**
     * @return Collection<int, lowercase-string>
     */
    public function skippedHosts(string $workspaceId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $d): string => strtolower($this->host((string) $d)));

        $workspaceDomains = PublicEmailDomain::query()->where('workspace_id', $workspaceId)
            ->pluck('domain')
            ->map(fn (mixed $d): string => strtolower($this->host((string) $d)));

        return $configDomains->merge($workspaceDomains)->unique()->values();
    }
}
