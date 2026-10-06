<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\Auth\InvitationOnlySignup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Passes unless the instance is invitation-only and nobody invited this email.
 * Attached to every path that creates a user from a caller-supplied address.
 */
final readonly class InvitedEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! resolve(InvitationOnlySignup::class)->allows($value)) {
            $fail(__('auth.signup.invitation_only'));
        }
    }
}
