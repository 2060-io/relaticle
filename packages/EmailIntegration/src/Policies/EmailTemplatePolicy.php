<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Policies;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Relaticle\EmailIntegration\Models\EmailTemplate;

final readonly class EmailTemplatePolicy
{
    use HandlesAuthorization;

    public function before(User $user, string $ability, mixed $template = null): ?bool
    {
        if (! $template instanceof EmailTemplate) {
            return null;
        }

        return $user->belongsToWorkspaceId($template->workspace_id) ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $template->is_shared || $template->created_by === $user->getKey();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function update(User $user, EmailTemplate $template): bool
    {
        return $this->createdByOrManagesOrphan($user, $template);
    }

    public function delete(User $user, EmailTemplate $template): bool
    {
        return $this->createdByOrManagesOrphan($user, $template);
    }

    public function deleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function restore(User $user, EmailTemplate $template): bool
    {
        return $this->createdByOrManagesOrphan($user, $template);
    }

    public function restoreAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    public function forceDelete(User $user, EmailTemplate $template): bool
    {
        return $this->createdByOrManagesOrphan($user, $template);
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail() && $user->currentWorkspace !== null;
    }

    private function createdByOrManagesOrphan(User $user, EmailTemplate $template): bool
    {
        if ($template->created_by === $user->getKey()) {
            return true;
        }

        return $template->created_by === null
            && $template->is_shared
            && $user->hasWorkspaceCapability($template->workspace_id, WorkspaceCapability::EmailManage);
    }
}
