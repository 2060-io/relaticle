<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\SetupExitReason;
use App\Models\Workspace;

final readonly class RecordSetupExitReason
{
    public function execute(Workspace $workspace, SetupExitReason $reason, ?string $note): void
    {
        $workspace->forceFill([
            'setup_exit_reason' => $reason,
            'setup_exit_note' => filled($note) ? $note : null,
            'setup_exit_reason_at' => now(),
        ])->save();
    }
}
