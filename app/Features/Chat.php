<?php

declare(strict_types=1);

namespace App\Features;

final readonly class Chat
{
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.chat', true);
    }
}
