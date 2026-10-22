<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;

final readonly class ApproveEmailAccessRequestAction
{
    public function __construct(private EmailSharingService $sharingService) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): void
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $email = $accessRequest->email;
        $owner = $accessRequest->owner;
        $requester = $accessRequest->requester;

        if ($email === null || $owner === null || $requester === null) {
            return;
        }

        // Don't grant a share to a requester who is no longer in the email's workspace.
        abort_unless($requester->belongsToWorkspaceId($email->workspace_id), 403);

        $tier = EmailPrivacyTier::from($accessRequest->tier_requested);

        $approved = DB::transaction(function () use ($accessRequest, $actor, $email, $owner, $requester, $tier): bool {
            if (! $accessRequest->decide(EmailAccessRequestStatus::APPROVED, $actor)) {
                return false;
            }

            $this->sharingService->shareEmail($email, $owner, $requester, $tier);

            activity()->performedOn($accessRequest)->causedBy($actor)->event('approved')->log('approved');

            return true;
        });

        if (! $approved) {
            return;
        }

        EmailAccessRequestedNotification::dismissFor($accessRequest);

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));
    }
}
