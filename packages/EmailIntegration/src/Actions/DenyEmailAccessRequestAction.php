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
    public function __construct(private DecideEmailAccessRequest $decide) {}

    public function execute(EmailAccessRequest $accessRequest, User $actor): bool
    {
        abort_unless($accessRequest->owner_id === $actor->getKey(), 403);

        if (! $accessRequest->isOpen()) {
            return false;
        }

        $requester = $accessRequest->requester;

        if ($requester === null) {
            return false;
        }

        $denied = DB::transaction(function () use ($accessRequest, $actor): bool {
            if (! $this->decide->execute($accessRequest, EmailAccessRequestStatus::DENIED, $actor)) {
                return false;
            }

            activity()->performedOn($accessRequest)->causedBy($actor)->event('denied')->log('denied');

            return true;
        });

        if (! $denied) {
            return false;
        }

        EmailAccessRequestedNotification::dismissFor($accessRequest);

        $requester->notify(new EmailAccessRespondedNotification($accessRequest));

        return true;
    }
}
