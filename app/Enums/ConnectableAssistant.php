<?php

declare(strict_types=1);

namespace App\Enums;

enum ConnectableAssistant: string
{
    case Claude = 'claude';
    case ChatGPT = 'chatgpt';

    public function label(): string
    {
        return match ($this) {
            self::Claude => 'Claude',
            self::ChatGPT => 'ChatGPT',
        };
    }

    public function sibling(): self
    {
        return match ($this) {
            self::Claude => self::ChatGPT,
            self::ChatGPT => self::Claude,
        };
    }

    public function routeName(): string
    {
        return "assistants.{$this->value}";
    }
}
