<?php

declare(strict_types=1);

namespace App\Features;

final readonly class SetupConversation
{
    // The setup conversation is a chat: with the assistant switched off there is
    // nothing to open it in, so the chat flag wins over this one.
    public function resolve(): bool
    {
        return (bool) config('relaticle.features.chat', true)
            && (bool) config('relaticle.features.setup_conversation', true);
    }
}
