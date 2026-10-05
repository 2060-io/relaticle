<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Enums\CrmEntity;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\MeetingAttendee;
use Relaticle\EmailIntegration\Services\MeetingAttendeePresenter;

final class MeetingAttendeeEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-attendee';

    /**
     * @return array{name: string, email: string, avatar: string, has_name: bool, is_organizer: bool, response_status: AttendeeResponseStatus|null, url: string|null}
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof MeetingAttendee) {
            return [
                'name' => '',
                'email' => '',
                'avatar' => '',
                'has_name' => false,
                'is_organizer' => false,
                'response_status' => null,
                'url' => null,
            ];
        }

        $workspace = filament()->getTenant();

        return [
            ...resolve(MeetingAttendeePresenter::class)->present($record),
            'url' => $record->contact_id !== null && $workspace instanceof Workspace
                ? resolve(CanonicalRecordUrl::class)->build(CrmEntity::People, (string) $record->contact_id, $workspace)
                : null,
        ];
    }
}
