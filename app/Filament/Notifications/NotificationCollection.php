<?php

declare(strict_types=1);

namespace App\Filament\Notifications;

use Filament\Notifications\Collection;

/**
 * Stale or multi-tab Livewire clients occasionally send back a notifications
 * payload containing non-array junk (e.g. a bare int), which the parent
 * collection's typed closure turns into a TypeError on every subsequent
 * request from that tab (Sentry #120218486). Drop anything that is not a
 * notification-shaped array before transforming.
 */
final class NotificationCollection extends Collection
{
    public static function fromLivewire(mixed $value): static
    {
        return parent::fromLivewire(array_filter(is_array($value) ? $value : [], is_array(...)));
    }
}
