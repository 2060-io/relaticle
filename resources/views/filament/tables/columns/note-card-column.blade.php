@php
    $note = $getRecord();
    $chips = $getLinkedRecordChips();
    $excerpt = $getExcerpt();
    $creator = $getCreatorChip();
@endphp

<article class="fi-note-card">
    @if ($chips !== [])
        <div class="fi-note-card-records">
            @foreach ($chips as $chip)
                {{ $chip }}
            @endforeach
        </div>
    @endif

    <h3 class="fi-note-card-title">
        {{ filled($note->title) ? $note->title : __('filament/resources/note.cards.untitled') }}
    </h3>

    <p @class(['fi-note-card-excerpt', 'fi-note-card-excerpt-empty' => blank($excerpt)])>
        {{ filled($excerpt) ? $excerpt : __('filament/resources/note.cards.no_content') }}
    </p>

    <footer class="fi-note-card-footer">
        <span class="fi-note-card-creator">
            {{ $creator ?? $note->created_by }}
        </span>

        <time datetime="{{ $note->created_at?->toIso8601String() }}" class="fi-note-card-date">
            {{ $getCreatedLabel() }}
        </time>
    </footer>
</article>
