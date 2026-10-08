<?php

declare(strict_types=1);

use Illuminate\Http\Request;

function withProdHostTrust(): void
{
    config()->set('app.url', 'https://crm.example.com');
    app()->detectEnvironment(fn () => 'production');
}

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('ignores a forged X-Forwarded-Host when building URLs', function () {
    withProdHostTrust();

    $response = $this->withHeaders(['X-Forwarded-Host' => 'evil.test'])
        ->get('https://crm.example.com/app/login');

    expect($response->getContent())->not->toContain('evil.test');
});

it('admits the configured application host', function () {
    withProdHostTrust();

    $this->get('https://crm.example.com/up')->assertSuccessful();
});

it('rejects a request whose Host is not an allowed host', function () {
    withProdHostTrust();

    $this->get('https://evil.test/app/login')->assertStatus(400);
});

it('admits a host listed in TRUSTED_HOSTS', function () {
    withProdHostTrust();
    config()->set('app.trusted_hosts', ['health.internal']);

    $this->get('https://health.internal/up')->assertSuccessful();
});
