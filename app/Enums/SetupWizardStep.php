<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SetupWizardStep: string implements HasLabel
{
    case Workspace = 'workspace';
    case Attribution = 'attribution';

    public function isBefore(self $other): bool
    {
        return $this->position() < $other->position();
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Workspace => 'Finished workspace details',
            self::Attribution => 'Finished attribution',
        };
    }

    private function position(): int
    {
        return match ($this) {
            self::Workspace => 1,
            self::Attribution => 2,
        };
    }
}
