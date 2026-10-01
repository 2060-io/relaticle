<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\SetupWizardStep;
use App\Models\User;

final readonly class RecordOnboardingStep
{
    public function execute(User $user, SetupWizardStep $step): void
    {
        $recorded = $user->onboarding_step;

        if ($recorded instanceof SetupWizardStep && ! $recorded->isBefore($step)) {
            return;
        }

        $user->forceFill(['onboarding_step' => $step])->save();
    }
}
