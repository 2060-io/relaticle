<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\SetupWizardStep;
use App\Models\User;

final readonly class RecordOnboardingStep
{
    public function execute(User $user, SetupWizardStep $step): void
    {
        $user->forceFill(['onboarding_step' => $step])->save();
    }
}
