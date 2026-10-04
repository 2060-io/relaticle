<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;

final readonly class UpdateWorkspaceContactCreationSettingsAction
{
    public function execute(
        Workspace $workspace,
        User $actor,
        ContactCreationMode $contactCreationMode,
        bool $autoCreateCompanies,
    ): void {
        abort_unless(
            $actor->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        $workspace->update([
            'contact_creation_mode' => $contactCreationMode,
            'auto_create_companies' => $contactCreationMode === ContactCreationMode::None
                ? false
                : $autoCreateCompanies,
        ]);
    }
}
