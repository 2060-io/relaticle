@php
    $requestId = $request['id'];
    $kind = $request['kind'];
    $outcome = $request['outcome'];
    $outcomeLabel = match ($outcome) {
        'approved' => __('notifications.panel.requests.approved'),
        'denied' => __('notifications.panel.requests.denied'),
        'already_decided' => __('notifications.panel.requests.already_decided'),
        default => null,
    };
@endphp

<li wire:key="inbox-request-{{ $requestId }}" data-inbox-key="request-{{ $requestId }}" class="flex gap-3 px-4 py-3">
    @if ($request['avatar'])
        <img src="{{ $request['avatar'] }}" alt="" class="size-8 shrink-0 rounded-full object-cover" />
    @else
        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
            <x-filament::icon icon="ri-inbox-line" class="size-4" />
        </span>
    @endif

    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $request['title'] }}</p>

        @if (filled($request['body']))
            <p class="mt-0.5 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">{{ $request['body'] }}</p>
        @endif

        <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
            <time datetime="{{ $request['createdAt']->toIso8601String() }}">{{ $request['createdAt']->diffForHumans() }}</time>

            @if ($request['expiresAt'] && ! $outcome)
                <span aria-hidden="true">&middot;</span>
                <span>{{ __('notifications.panel.requests.expires', ['when' => $request['expiresAt']->diffForHumans()]) }}</span>
            @endif
        </p>

        <div class="mt-2 flex flex-wrap items-center gap-2">
            @if ($outcomeLabel)
                <span
                    @class([
                        'inline-flex items-center gap-1 text-xs font-medium',
                        'text-success-600 dark:text-success-400' => $outcome === 'approved',
                        'text-gray-500 dark:text-gray-400' => $outcome !== 'approved',
                    ])
                >
                    <x-filament::icon :icon="match ($outcome) { 'approved' => 'ri-check-line', 'denied' => 'ri-close-line', default => 'ri-information-line' }" class="size-4" />
                    {{ $outcomeLabel }}
                </span>
            @else
                <x-filament::button
                    size="xs"
                    data-inbox-approve
                    x-on:click="act(() => $wire.approveRequest(@js($kind), @js($requestId)), $el, '[data-inbox-approve]')"
                    wire:loading.attr="disabled"
                    wire:target="approveRequest, denyRequest"
                >
                    {{ __('notifications.panel.requests.approve') }}
                </x-filament::button>

                <x-filament::button
                    size="xs"
                    color="gray"
                    x-on:click="act(() => $wire.denyRequest(@js($kind), @js($requestId)), $el, '[data-inbox-approve]')"
                    wire:loading.attr="disabled"
                    wire:target="approveRequest, denyRequest"
                >
                    {{ __('notifications.panel.requests.deny') }}
                </x-filament::button>

                @if ($request['url'])
                    <a
                        {{ \Filament\Support\generate_href_html($request['url']) }}
                        x-on:click="hide(false)"
                        class="ms-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400"
                    >
                        {{ __('notifications.panel.requests.view') }}
                    </a>
                @endif
            @endif
        </div>
    </div>
</li>
