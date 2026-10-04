@php
    $views = [
        'list' => [
            'label' => __('filament/pages/boards.view_switcher.list'),
            'icon' => 'heroicon-o-list-bullet',
            'url' => $listUrl,
        ],
        'board' => [
            'label' => __('filament/pages/boards.view_switcher.board'),
            'icon' => 'heroicon-o-view-columns',
            'url' => $boardUrl,
        ],
    ];
@endphp

<x-filament::dropdown placement="bottom-start" class="fi-view-switcher">
    <x-slot name="trigger">
        <x-filament::button
            color="gray"
            size="sm"
            :icon="$views[$active]['icon']"
            :aria-label="__('filament/pages/boards.view_switcher.label')"
        >
            {{ $views[$active]['label'] }}

            <x-filament::icon icon="heroicon-m-chevron-down" class="fi-view-switcher-chevron" />
        </x-filament::button>
    </x-slot>

    <x-filament::dropdown.list>
        @foreach ($views as $key => $view)
            <x-filament::dropdown.list.item
                tag="a"
                :href="$view['url']"
                :icon="$view['icon']"
                :color="$key === $active ? 'primary' : 'gray'"
                :spa-mode="true"
                :aria-current="$key === $active ? 'page' : null"
            >
                {{ $view['label'] }}
            </x-filament::dropdown.list.item>
        @endforeach
    </x-filament::dropdown.list>
</x-filament::dropdown>
