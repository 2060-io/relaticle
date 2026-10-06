@php
    $state = $getState();
    $time = $state['time'];
@endphp

<div class="flex min-w-0 items-center gap-3" data-testid="meeting-when">
    <div class="flex w-12 shrink-0 flex-col items-center justify-center rounded-lg border border-[var(--surface-block-border)] py-1.5 text-pico" aria-hidden="true">
        <span class="font-medium uppercase text-gray-500 dark:text-gray-400">{{ $state['month'] }}</span>
        <span class="text-xl font-semibold leading-6 text-gray-950 dark:text-white">{{ $state['day'] }}</span>
    </div>

    @if ($time !== null)
        <div class="min-w-0 flex-1">
            <time
                class="flex min-w-0 flex-wrap items-center gap-x-1.5 text-sm font-medium leading-5 text-gray-950 dark:text-white"
                datetime="{{ $time['datetime'] }}"
            >
                <span>{{ $time['start_date'] }}</span>

                @if ($time['start_time'] !== null)
                    <span aria-hidden="true" class="font-normal text-gray-300 dark:text-gray-600">|</span>
                    <span>{{ $time['start_time'] }}</span>
                @endif

                @if ($time['end_time'] !== null || $time['end_date'] !== null)
                    <span aria-hidden="true" class="font-normal text-gray-400 dark:text-gray-500">→</span>
                @endif

                @if ($time['end_time'] !== null)
                    <span>{{ $time['end_time'] }}</span>
                @endif

                @if ($time['duration'] !== null)
                    <span class="font-normal text-gray-500 dark:text-gray-400">({{ $time['duration'] }})</span>
                @endif

                @if ($time['end_date'] !== null && $time['end_time'] !== null)
                    <span aria-hidden="true" class="font-normal text-gray-300 dark:text-gray-600">|</span>
                @endif

                @if ($time['end_date'] !== null)
                    <span>{{ $time['end_date'] }}</span>
                @endif

                @if ($time['all_day'])
                    <span class="font-normal text-gray-500 dark:text-gray-400">({{ __('filament/resources/meeting.time.all_day') }})</span>
                @endif
            </time>

            @if ($time['relative'] !== null || $time['timezone'] !== null)
                <p class="mt-0.5 flex items-center gap-x-1.5 text-xs leading-4 text-gray-500 dark:text-gray-400">
                    @if ($time['relative'] !== null)
                        <span data-testid="meeting-relative-time">{{ $time['relative'] }}</span>
                    @endif

                    @if ($time['relative'] !== null && $time['timezone'] !== null)
                        <span aria-hidden="true">·</span>
                    @endif

                    @if ($time['timezone'] !== null)
                        <span data-testid="meeting-timezone">{{ $time['timezone'] }}</span>
                    @endif
                </p>
            @endif
        </div>
    @endif

    @if ($state['response_status'] !== null && ! $state['can_respond'])
        @include('email-integration::filament.infolists.partials.rsvp-pill', ['status' => $state['response_status']])
    @endif
</div>
