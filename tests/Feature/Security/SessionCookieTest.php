<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

function sessionCookieOn(string $uri): Cookie
{
    $cookies = test()->get($uri)->baseResponse->headers->getCookies();

    return collect($cookies)->firstWhere(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));
}

function sessionSecureConfigFor(string $appEnv, ?string $override): mixed
{
    $result = Process::path(base_path())
        ->env(['APP_ENV' => $appEnv, 'SESSION_SECURE_COOKIE' => $override ?? false])
        ->run([PHP_BINARY, 'artisan', 'config:show', 'session.secure', '--no-ansi'])
        ->throw();

    return match (Str::afterLast(trim($result->output()), ' ')) {
        'true' => true,
        'false' => false,
        default => null,
    };
}

it('defaults the session cookie to secure in production', function (): void {
    expect(sessionSecureConfigFor('production', null))->toBeTrue();
});

it('leaves the session cookie insecure by default outside production', function (): void {
    expect(sessionSecureConfigFor('local', null))->toBeFalse();
});

it('lets SESSION_SECURE_COOKIE override the production default', function (): void {
    expect(sessionSecureConfigFor('production', 'false'))->toBeFalse();
});

it('sends the session cookie with Secure over plain http when configured secure', function (): void {
    config(['session.secure' => true]);

    expect(sessionCookieOn('/app/login')->isSecure())->toBeTrue();
});

it('sends the session cookie without Secure over plain http when configured insecure', function (): void {
    config(['session.secure' => false]);

    expect(sessionCookieOn('/app/login')->isSecure())->toBeFalse();
});
