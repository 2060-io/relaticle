<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

final readonly class UpdateWorkspaceProtectedRecipientsAction
{
    /**
     * @param  array<int, string>  $protectedEmails
     * @param  array<int, string>  $protectedDomains
     */
    public function execute(Workspace $workspace, User $actor, array $protectedEmails, array $protectedDomains): void
    {
        abort_unless(
            $actor->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailManage),
            403,
        );

        ProtectedRecipient::query()->where('workspace_id', $workspace->getKey())->delete();

        foreach ($protectedEmails as $email) {
            if (blank($email)) {
                continue;
            }

            ProtectedRecipient::query()->create([
                'workspace_id' => $workspace->getKey(),
                'type' => 'email',
                'value' => strtolower(trim($email)),
                'created_by' => $actor->getKey(),
            ]);
        }

        foreach ($protectedDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            ProtectedRecipient::query()->create([
                'workspace_id' => $workspace->getKey(),
                'type' => 'domain',
                'value' => strtolower(trim($domain)),
                'created_by' => $actor->getKey(),
            ]);
        }
    }
}
