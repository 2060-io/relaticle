<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages;

use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Override;
use Relaticle\SystemAdmin\Actions\MarkWorkspaceContacted;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\EndTrial;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;

final class ViewWorkspace extends ViewRecord
{
    protected static string $resource = WorkspaceResource::class;

    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Impersonate::workspaceOwner(),
            EndTrial::action(),
            Action::make('contacted')
                ->label('Mark contacted')
                ->icon('heroicon-o-check')
                ->color('gray')
                ->authorize('markContacted')
                ->action(function (Workspace $record): void {
                    resolve(MarkWorkspaceContacted::class)->execute($record);
                    Notification::make()->title('Marked as contacted')->success()->send();
                }),
            EditAction::make()->action(null),
        ];
    }
}
