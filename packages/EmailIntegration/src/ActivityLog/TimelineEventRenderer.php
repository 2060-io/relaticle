<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\ActivityLog;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Relaticle\ActivityLog\Contracts\TimelineRenderer;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final readonly class TimelineEventRenderer implements TimelineRenderer
{
    public function __construct(private ViewFactory $viewFactory) {}

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return [
            ...array_column(EmailEventPalette::cases(), 'value'),
            ...array_column(MeetingEventPalette::cases(), 'value'),
        ];
    }

    public function render(TimelineEntry $entry): View|HtmlString
    {
        return $this->viewFactory->make('activity-log.entries.app-event', [
            'entry' => $entry,
            'palette' => EmailEventPalette::tryFrom($entry->event) ?? MeetingEventPalette::from($entry->event),
        ]);
    }
}
