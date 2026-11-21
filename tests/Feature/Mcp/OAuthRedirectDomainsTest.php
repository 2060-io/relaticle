<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\RegisterClientController;
use App\Support\Mcp\RedirectDomains;
use Illuminate\Support\Facades\Log;

mutates(RedirectDomains::class, RegisterClientController::class);

/** The redirect URI the Ayni Dev Agent registers. */
function ayniDevAgentCallback(): string
{
    return 'https://dev-agent.dev.ayni.org/tools/oauth/callback';
}

/**
 * Load config/mcp.php again with MCP_REDIRECT_DOMAINS set the way a deployment sets it,
 * then leave the environment as it was.
 */
function loadMcpConfigWithRedirectDomains(string $value): void
{
    putenv("MCP_REDIRECT_DOMAINS={$value}");

    try {
        config(['mcp' => require config_path('mcp.php')]);
    } finally {
        putenv('MCP_REDIRECT_DOMAINS');
    }
}

it('refuses the Ayni Dev Agent\'s redirect uri while the deployment lists no domain of its own', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Ayni Dev Agent',
        'redirect_uris' => [ayniDevAgentCallback()],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('registers the Ayni Dev Agent once the deployment lists its origin', function (): void {
    loadMcpConfigWithRedirectDomains('https://dev-agent.dev.ayni.org');

    $this->postJson('/oauth/register', [
        'client_name' => 'Ayni Dev Agent',
        'redirect_uris' => [ayniDevAgentCallback()],
    ])
        ->assertCreated()
        ->assertJsonPath('redirect_uris', [ayniDevAgentCallback()]);
});

it('keeps the built-in connector hosts next to the listed origins', function (): void {
    loadMcpConfigWithRedirectDomains(' https://dev-agent.dev.ayni.org , https://agent.example.com/ ');

    expect(config('mcp.redirect_domains'))->toBe([
        'https://claude.ai',
        'https://chatgpt.com',
        'https://console.mistral.ai',
        'http://localhost',
        'https://dev-agent.dev.ayni.org',
        'https://agent.example.com',
    ]);

    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])->assertCreated();
});

it('refuses a host that only starts like a listed origin', function (string $redirectUri): void {
    loadMcpConfigWithRedirectDomains('https://dev-agent.dev.ayni.org');

    $this->postJson('/oauth/register', [
        'client_name' => 'Lookalike',
        'redirect_uris' => [$redirectUri],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'longer host' => 'https://dev-agent.dev.ayni.org.attacker.example/tools/oauth/callback',
    'listed host over plain http' => 'http://dev-agent.dev.ayni.org/tools/oauth/callback',
    'listed host on another port' => 'https://dev-agent.dev.ayni.org:8443/tools/oauth/callback',
]);

it('opens nothing for an entry that is not an https origin', function (): void {
    loadMcpConfigWithRedirectDomains('*, http://dev-agent.dev.ayni.org, https://*.ayni.org, dev-agent.dev.ayni.org, https://dev-agent.dev.ayni.org/tools, https://user@dev-agent.dev.ayni.org, https://dev-agent.dev.ayni.org?x=1');

    expect(config('mcp.redirect_domains'))->toBe([
        'https://claude.ai',
        'https://chatgpt.com',
        'https://console.mistral.ai',
        'http://localhost',
    ]);

    foreach ([ayniDevAgentCallback(), 'https://attacker.example/callback', 'https://sub.ayni.org/callback'] as $redirectUri) {
        $this->postJson('/oauth/register', [
            'client_name' => 'Connector',
            'redirect_uris' => [$redirectUri],
        ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid_redirect_uri');
    }
});

it('logs the ignored entries when a client registers', function (): void {
    loadMcpConfigWithRedirectDomains('*, https://dev-agent.dev.ayni.org, http://agent.example.com');

    Log::spy();

    $this->postJson('/oauth/register', [
        'client_name' => 'Ayni Dev Agent',
        'redirect_uris' => [ayniDevAgentCallback()],
    ])->assertCreated();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'MCP_REDIRECT_DOMAINS:')
            && $context === ['ignored' => ['*', 'http://agent.example.com']]);
});

it('logs nothing when every entry is an https origin', function (): void {
    loadMcpConfigWithRedirectDomains('https://dev-agent.dev.ayni.org');

    Log::spy();

    $this->postJson('/oauth/register', [
        'client_name' => 'Ayni Dev Agent',
        'redirect_uris' => [ayniDevAgentCallback()],
    ])->assertCreated();

    Log::shouldNotHaveReceived('warning');
});

it('keeps an https origin, lowercased and without its trailing slash', function (string $entry, string $origin): void {
    expect(RedirectDomains::origins($entry))->toBe([$origin])
        ->and(RedirectDomains::ignored($entry))->toBe([]);
})->with([
    'origin' => ['https://dev-agent.dev.ayni.org', 'https://dev-agent.dev.ayni.org'],
    'trailing slash' => ['https://dev-agent.dev.ayni.org/', 'https://dev-agent.dev.ayni.org'],
    'upper case' => ['HTTPS://Dev-Agent.Dev.Ayni.Org', 'https://dev-agent.dev.ayni.org'],
    'port' => ['https://agent.example.com:8443', 'https://agent.example.com:8443'],
    'spaces around it' => ['  https://agent.example.com  ', 'https://agent.example.com'],
]);

it('ignores an entry that is not an https origin', function (string $entry): void {
    expect(RedirectDomains::origins($entry))->toBe([])
        ->and(RedirectDomains::ignored($entry))->toBe([trim($entry)]);
})->with([
    'wildcard' => '*',
    'plain http' => 'http://dev-agent.dev.ayni.org',
    'no scheme' => 'dev-agent.dev.ayni.org',
    'custom scheme' => 'cursor://anysphere.cursor-mcp',
    'wildcard host' => 'https://*.ayni.org',
    'path' => 'https://dev-agent.dev.ayni.org/tools/oauth/callback',
    'double slash' => 'https://dev-agent.dev.ayni.org//',
    'query' => 'https://dev-agent.dev.ayni.org?next=x',
    'fragment' => 'https://dev-agent.dev.ayni.org#x',
    'credentials' => 'https://user:pass@dev-agent.dev.ayni.org',
    'backslash' => 'https://dev-agent.dev.ayni.org\\@attacker.example',
    'empty port' => 'https://dev-agent.dev.ayni.org:',
    'no host' => 'https://',
    'underscore host' => 'https://dev_agent.example.com',
]);

it('reads every entry of the list, each once, and skips empty ones', function (): void {
    $list = 'https://a.example.com,, https://b.example.com ,http://c.example.com,https://a.example.com/';

    expect(RedirectDomains::origins($list))->toBe(['https://a.example.com', 'https://b.example.com'])
        ->and(RedirectDomains::ignored($list))->toBe(['http://c.example.com'])
        ->and(RedirectDomains::origins(''))->toBe([])
        ->and(RedirectDomains::ignored(''))->toBe([]);
});
