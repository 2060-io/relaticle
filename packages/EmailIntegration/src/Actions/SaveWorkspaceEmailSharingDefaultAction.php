<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class SaveWorkspaceEmailSharingDefaultAction
{
    public function __construct(
        private UpdateWorkspaceEmailPrivacySettingsAction $updateSettings,
        private ApplyDefaultSharingTierToExistingEmailsAction $applyRetroactive,
        private PrivacyService $privacy,
    ) {}

    public function execute(Workspace $workspace, User $actor, EmailPrivacyTier $newTier): void
    {
        $previousTier = $this->privacy->workspaceSharingTier($workspace);

        DB::transaction(function () use ($workspace, $actor, $newTier, $previousTier): void {
            $this->updateSettings->execute($workspace, $actor, $newTier);

            if ($previousTier !== $newTier) {
                $this->applyRetroactive->executeForWorkspace($workspace, $newTier);
            }
        });
    }
}
