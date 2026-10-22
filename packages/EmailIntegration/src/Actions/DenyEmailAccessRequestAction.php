<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

final readonly class DenyEmailAccessRequestAction
{
    public function execute(EmailAccessRequest $accessRequest, User $actor): void
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if ($accessRequest->status !== EmailAccessRequestStatus::PENDING) {
            return;
        }

        $requester = $accessRequest->requester;

        if ($requester === null) {
            return;
        }

        $denied = DB::transaction(function () use ($accessRequest, $actor): bool {
            if (! $accessRequest->decide(EmailAccessRequestStatus::DENIED, $actor)) {
                return false;
            }

            activity()->performedOn($accessRequest)->causedBy($actor)->event('denied')->log('denied');

            return true;
        });

        if (! $denied) {
            return;
        }

        EmailAccessRequestedNotification::dismissFor($accessRequest);

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));
    }
}
