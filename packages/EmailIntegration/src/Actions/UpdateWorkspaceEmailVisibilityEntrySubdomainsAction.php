<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

final readonly class UpdateWorkspaceEmailVisibilityEntrySubdomainsAction
{
    public function execute(Workspace $workspace, User $actor, WorkspaceEmailBlocklist $entry, bool $includeSubdomains): void
    {
        abort_unless(
            $actor->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        abort_unless($entry->workspace_id === $workspace->getKey(), 403);

        if ($entry->type !== EmailBlocklistType::DOMAIN) {
            return;
        }

        $entry->update(['include_subdomains' => $includeSubdomains]);
    }
}
