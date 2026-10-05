<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use Filament\Infolists\Components\Entry;
use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingTimeEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-time';

    /**
     * @return array{location: string|null, location_url: string|null, calendar_url: string|null, calendar_label: string}
     */
    public function getState(): array
    {
        $record = $this->getRecord();
        $location = $record instanceof Meeting ? $record->location : null;
        $calendarUrl = $record instanceof Meeting ? $record->html_link : null;

        return [
            'location' => filled($location) ? $location : null,
            'location_url' => filled($location) && str_starts_with((string) $location, 'http') && filter_var($location, FILTER_VALIDATE_URL) !== false
                ? $location
                : null,
            'calendar_url' => filled($calendarUrl) ? $calendarUrl : null,
            'calendar_label' => __('filament/resources/meeting.fields.html_link.label'),
        ];
    }
}
