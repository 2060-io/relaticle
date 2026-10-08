<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Support\EmailAddress;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class PasswordGuessLimitListener
{
    private const int PER_WINDOW = 10;

    private const int WINDOW_SECONDS = 900;

    private const int PER_DAY = 50;

    private const int DAY_SECONDS = 86400;

    public function handle(Attempting|Failed|Login $event): void
    {
        match (true) {
            $event instanceof Attempting => $this->assertAllowed($event),
            $event instanceof Failed => $this->recordFailure($event),
            $event instanceof Login => $this->clear($event),
        };
    }

    private function assertAllowed(Attempting $event): void
    {
        $email = $this->email($event->credentials);

        if ($email === '') {
            return;
        }

        $field = $event->guard === 'sysadmin' ? 'data.email' : 'email';
        $windowKey = $this->windowKey($event->guard, $email);

        if (RateLimiter::tooManyAttempts($windowKey, self::PER_WINDOW)) {
            throw ValidationException::withMessages([
                $field => __('auth.throttle', ['seconds' => RateLimiter::availableIn($windowKey)]),
            ]);
        }

        if (RateLimiter::tooManyAttempts($this->dayKey($event->guard, $email), self::PER_DAY)) {
            throw ValidationException::withMessages([
                $field => __('auth.throttle_long'),
            ]);
        }
    }

    private function recordFailure(Failed $event): void
    {
        $email = $this->email($event->credentials);

        if ($email === '') {
            return;
        }

        RateLimiter::hit($this->windowKey($event->guard, $email), self::WINDOW_SECONDS);
        RateLimiter::hit($this->dayKey($event->guard, $email), self::DAY_SECONDS);
    }

    private function clear(Login $event): void
    {
        $email = EmailAddress::canonicalize((string) data_get($event->user, 'email'));

        RateLimiter::clear($this->windowKey($event->guard, $email));
        RateLimiter::clear($this->dayKey($event->guard, $email));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function email(array $credentials): string
    {
        return EmailAddress::canonicalize((string) ($credentials['email'] ?? ''));
    }

    private function windowKey(string $guard, string $email): string
    {
        return "password-guess-window:{$guard}:{$email}";
    }

    private function dayKey(string $guard, string $email): string
    {
        return "password-guess-day:{$guard}:{$email}";
    }
}
