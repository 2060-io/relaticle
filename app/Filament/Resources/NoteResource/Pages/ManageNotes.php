<?php

declare(strict_types=1);

namespace App\Filament\Resources\NoteResource\Pages;

use App\Filament\Concerns\HasBoardViewSwitcher;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Concerns\HasNoteHeaderActions;
use App\Filament\Resources\NoteResource;
use Asmit\ResizedColumn\HasResizableColumn;
use Filament\Resources\Pages\ManageRecords;
use Livewire\Attributes\On;

final class ManageNotes extends ManageRecords
{
    use HasBoardViewSwitcher;
    use HasCustomFieldColumns;
    use HasNoteHeaderActions;
    use HasResizableColumn;

    protected static string $resource = NoteResource::class;

    #[On('ai-write-completed')]
    public function refreshOnAiWrite(): void
    {
        // Filament table auto-refreshes on Livewire re-render
    }
}
