<?php

declare(strict_types=1);

namespace App\Filament\Notifications;

use Filament\Notifications\Collection;

// Stale or multi-tab clients send back non-array junk (Sentry #120218486): drop it so
// the parent's payload check validates real notifications instead of 419ing the tab.
final class NotificationCollection extends Collection
{
    public static function fromLivewire(mixed $value): static
    {
        return parent::fromLivewire(array_filter(is_array($value) ? $value : [], is_array(...)));
    }
}
