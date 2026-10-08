<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(SecurityHeaders::class);

it('sets framing and sniffing headers on the login page', function (): void {
    $this->get('/app/login')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('denies framing on the oauth consent screen', function (): void {
    $this->get('/oauth/authorize')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('sends HSTS on a secure request outside local', function (): void {
    $this->get('https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('sends no HSTS on plain HTTP', function (): void {
    $this->get('http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends no HSTS in the local environment', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

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
