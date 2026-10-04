<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\PrivacyService;

final readonly class ApplyDefaultSharingTierToExistingEmailsAction
{
    public function executeForWorkspace(Workspace $workspace, EmailPrivacyTier $tier): int
    {
        $userIds = User::query()
            ->whereNull('default_email_sharing_tier')
            ->where(function (Builder $query) use ($workspace): void {
                $query->whereHas('workspaces', fn (Builder $workspaceQuery) => $workspaceQuery->whereKey($workspace->getKey()))
                    ->orWhereKey($workspace->user_id);
            })
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return 0;
        }

        return Email::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('user_id', $userIds)
            ->where('privacy_tier_customized', false)
            ->update(['privacy_tier' => $tier->value]);
    }

    public function executeForUser(User $user, EmailPrivacyTier $tier): int
    {
        // ActiveAccountScope hides disconnected mailboxes from normal reads; the same
        // filter applies here so retroactive updates never touch orphaned rows.
        return Email::query()
            ->where('user_id', $user->getKey())
            ->where('privacy_tier_customized', false)
            ->update(['privacy_tier' => $tier->value]);
    }

    public function executeForUserUsingWorkspaceDefaults(User $user): int
    {
        $workspaceIds = Email::query()
            ->where('user_id', $user->getKey())
            ->where('privacy_tier_customized', false)
            ->distinct()
            ->pluck('workspace_id');

        if ($workspaceIds->isEmpty()) {
            return 0;
        }

        $workspaces = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->get()
            ->keyBy('id');

        $updated = 0;

        foreach ($workspaceIds as $workspaceId) {
            $workspace = $workspaces->get($workspaceId);

            if ($workspace === null) {
                continue;
            }

            $tier = resolve(PrivacyService::class)->workspaceSharingTier($workspace);

            $updated += Email::query()
                ->where('user_id', $user->getKey())
                ->where('workspace_id', $workspaceId)
                ->where('privacy_tier_customized', false)
                ->update(['privacy_tier' => $tier->value]);
        }

        return $updated;
    }
}
