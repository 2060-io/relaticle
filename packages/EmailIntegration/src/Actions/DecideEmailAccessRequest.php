<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class DecideEmailAccessRequest
{
    public function execute(EmailAccessRequest $request, EmailAccessRequestStatus $outcome, ?User $actor): bool
    {
        return DB::transaction(function () use ($request, $outcome, $actor): bool {
            $decidedAt = now();
            $decidedBy = $actor?->getKey();

            $decided = EmailAccessRequest::query()
                ->whereKey($request->getKey())
                ->when(
                    $outcome === EmailAccessRequestStatus::EXPIRED,
                    fn (Builder $pending): Builder => $pending->overdue(),
                    fn (Builder $pending): Builder => $pending->open(),
                )
                ->update([
                    'status' => $outcome->value,
                    'decided_at' => $decidedAt,
                    'decided_by' => $decidedBy,
                ]);

            if ($decided === 0) {
                return false;
            }

            $request->forceFill([
                'status' => $outcome,
                'decided_at' => $decidedAt,
                'decided_by' => $decidedBy,
            ])->syncOriginal();

            $this->logOutcome($request, $outcome, $actor);
            $this->refreshOwnerBadge($request);

            return true;
        });
    }

    private function logOutcome(EmailAccessRequest $request, EmailAccessRequestStatus $outcome, ?User $actor): void
    {
        $activity = activity()->performedOn($request)->event($outcome->value);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        } else {
            $activity->causedByAnonymous();
        }

        $activity->log($outcome->value);
    }

    private function refreshOwnerBadge(EmailAccessRequest $request): void
    {
        $owner = $request->owner;

        if ($owner === null) {
            return;
        }

        DB::afterCommit(static function () use ($owner): void {
            event(new DatabaseNotificationsSent($owner));
        });
    }
}
