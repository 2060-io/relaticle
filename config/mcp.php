<?php

declare(strict_types=1);

use App\Support\Mcp\RedirectDomains;

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains.
    |
    | A deployment adds its own through MCP_REDIRECT_DOMAINS, a comma-separated
    | list of https origins on port 443 (https://agent.example.com). An entry
    | that is not one, "*" included, is ignored and logged when a client
    | registers. A redirect URI matches a domain on its exact host.
    |
    */

    'redirect_domains' => array_values(array_unique([
        'https://claude.ai',
        'https://chatgpt.com',
        'https://console.mistral.ai',
        'https://www.perplexity.ai',
        'https://enterprise.perplexity.ai',
        'http://localhost',
        ...RedirectDomains::origins((string) env('MCP_REDIRECT_DOMAINS', '')),
    ])),

    'ignored_redirect_domains' => RedirectDomains::ignored((string) env('MCP_REDIRECT_DOMAINS', '')),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        'cursor',
        'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

];
