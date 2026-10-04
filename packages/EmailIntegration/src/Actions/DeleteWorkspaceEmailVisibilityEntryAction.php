<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

final readonly class DeleteWorkspaceEmailVisibilityEntryAction
{
    public function execute(Workspace $workspace, User $actor, WorkspaceEmailBlocklist $entry): void
    {
        abort_unless(
            $actor->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        abort_unless($entry->workspace_id === $workspace->getKey(), 403);

        $entry->delete();
    }
}
