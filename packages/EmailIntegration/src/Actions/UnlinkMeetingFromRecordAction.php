<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class UnlinkMeetingFromRecordAction
{
    public function execute(User $user, Meeting $meeting, Model $record): void
    {
        abort_unless($user->can('view', $meeting) && $user->can('update', $record), 403);

        throw_if(
            (string) $record->getAttribute('workspace_id') !== (string) $meeting->workspace_id,
            InvalidArgumentException::class,
            'Cannot unlink a meeting from a record in another workspace.',
        );

        match (true) {
            $record instanceof People => $meeting->people()->detach($record->getKey()),
            $record instanceof Company => $meeting->companies()->detach($record->getKey()),
            $record instanceof Opportunity => $meeting->opportunities()->detach($record->getKey()),
            default => throw new InvalidArgumentException('Unsupported record type: '.$record::class),
        };
    }
}
