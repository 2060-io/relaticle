@php
    /** @var list<array{id: string, name: string, type: \Relaticle\EmailIntegration\Enums\MeetingLinkedRecordType, url: string|null}> $items */
    $items = $getState();
    $unlinkAction = $getAction('unlinkRecord');
@endphp

<ul class="-my-1 divide-y divide-[var(--surface-block-border)]" data-testid="meeting-linked-records">
    @foreach ($items as $item)
        <li class="group flex items-center gap-3 py-2">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                <x-filament::icon :icon="$item['type']->getIcon()" class="size-4" aria-hidden="true" />
            </span>

            <div class="min-w-0 flex-1">
                @if ($item['url'] !== null)
                    <a
                        href="{{ $item['url'] }}"
                        wire:navigate
                        title="{{ $item['name'] }}"
                        class="block truncate text-sm font-medium leading-5 text-gray-950 hover:text-primary-600 hover:underline dark:text-white dark:hover:text-primary-400"
                    >{{ $item['name'] }}</a>
                @else
                    <span title="{{ $item['name'] }}" class="block truncate text-sm font-medium leading-5 text-gray-950 dark:text-white">{{ $item['name'] }}</span>
                @endif

                <span class="block text-xs leading-4 text-gray-500 dark:text-gray-400">{{ $item['type']->getLabel() }}</span>
            </div>

            @if ($unlinkAction?->isVisible())
                <span class="shrink-0 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100 [@media(hover:none)]:opacity-100">
                    {{ $unlinkAction(['type' => $item['type']->linkTargetType(), 'id' => $item['id'], 'name' => $item['name']]) }}
                </span>
            @endif
        </li>
    @endforeach
</ul>
