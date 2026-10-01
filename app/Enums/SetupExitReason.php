<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SetupExitReason: string implements HasLabel
{
    case JustLooking = 'just_looking';
    case MissingSomething = 'missing_something';
    case TooHard = 'too_hard';
    case ChoseAnother = 'chose_another';

    public function getLabel(): string
    {
        return __("mail.setup_feedback.reasons.{$this->value}");
    }
}
