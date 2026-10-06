<?php

declare(strict_types=1);

namespace App\Enums;

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;

enum EmailGrant: string
{
    case Read = 'email:read';
    case Draft = 'email:draft';
    case Send = 'email:send';

    /** @return list<self> */
    public static function offered(): array
    {
        return Feature::active(EmailIntegration::class) ? self::cases() : [];
    }

    /** @return list<self> */
    public static function fromValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            self::offered(),
            fn (self $grant): bool => in_array($grant->value, $values, true),
        ));
    }

    /** @return array<string, string> */
    public static function passportScopes(): array
    {
        return [
            self::Read->value => 'Read your email',
            self::Draft->value => 'Save email drafts',
            self::Send->value => 'Send email as you',
        ];
    }
}
