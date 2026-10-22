<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class DecideEmailAccessRequest
{
    public function execute(EmailAccessRequest $request, EmailAccessRequestStatus $outcome, ?User $actor): bool
    {
        $decidedAt = now();
        $decidedBy = $actor?->getKey();

        $decided = EmailAccessRequest::query()
            ->whereKey($request->getKey())
            ->when(
                $outcome === EmailAccessRequestStatus::EXPIRED,
                fn (Builder $pending): Builder => $pending
                    ->where('status', EmailAccessRequestStatus::PENDING)
                    ->where('expires_at', '<=', $decidedAt),
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

        return true;
    }
}
