<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

final readonly class CancelEmailAccessRequestAction
{
    public function execute(EmailAccessRequest $accessRequest, User $actor): void
    {
        // Only the requester may cancel their own request.
        abort_unless($accessRequest->requester_id === $actor->getKey(), 403);

        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $cancelled = DB::transaction(function () use ($accessRequest, $actor): bool {
            if (! $accessRequest->decide(EmailAccessRequestStatus::CANCELLED, $actor)) {
                return false;
            }

            activity()->performedOn($accessRequest)->causedBy($actor)->event('cancelled')->log('cancelled');

            return true;
        });

        if ($cancelled) {
            EmailAccessRequestedNotification::dismissFor($accessRequest);
        }
    }
}
