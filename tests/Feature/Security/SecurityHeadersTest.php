<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(SecurityHeaders::class);

it('sets framing and sniffing headers on the login page', function (): void {
    $this->get('/app/login')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('denies framing on the rendered oauth consent screen', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Test MCP Client',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://example.com/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'test-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Test MCP Client')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

function secureLoginUrl(): string
{
    return 'https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login';
}

function asEnvironment(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);
}

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('sends HSTS without subdomains on a secure production request', function (): void {
    asEnvironment('production');

    $this->get(secureLoginUrl())
        ->assertHeader('Strict-Transport-Security', 'max-age=15552000');
});

it('adds includeSubDomains only when opted in', function (): void {
    asEnvironment('production');
    config()->set('app.hsts.include_subdomains', true);
    config()->set('app.hsts.max_age', 31536000);

    $this->get(secureLoginUrl())
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('sends no HSTS on plain HTTP in production', function (): void {
    asEnvironment('production');

    $this->get('http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends no HSTS outside production', function (string $environment): void {
    asEnvironment($environment);

    $this->get(secureLoginUrl())
        ->assertHeaderMissing('Strict-Transport-Security');
})->with(['local', 'staging', 'testing']);

it('keeps the headers a response already carries', function (): void {
    Route::middleware('web')->get('/_test/own-headers', fn () => response('ok', 200, [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'no-referrer',
    ]));

    $this->get('/_test/own-headers')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('leaves the media response content security policy intact', function (): void {
    Storage::fake('local');
    $user = User::factory()->withPersonalWorkspace()->create();

    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});
