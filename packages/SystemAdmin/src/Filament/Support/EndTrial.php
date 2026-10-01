<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Relaticle\SystemAdmin\Actions\EndWorkspaceTrial;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class EndTrial
{
    public static function action(): Action
    {
        return Action::make('endTrial')
            ->label('End trial now')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize('endTrial')
            ->requiresConfirmation()
            ->modalHeading('End this trial now?')
            ->modalDescription('The workspace pauses immediately and shows the plan choice. Tonight the plan moves to Free and the owner gets the standard trial-ended email.')
            ->modalSubmitActionLabel('End trial')
            ->action(function (Workspace $record): void {
                resolve(EndWorkspaceTrial::class)->execute(self::administrator(), $record);

                Notification::make()->title('Trial ended')->success()->send();
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('endTrials')
            ->label('End trials now')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Each selected workspace that is still trialing pauses immediately. The others are skipped.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $administrator = self::administrator();
                $ended = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Workspace || Gate::forUser($administrator)->denies('endTrial', $record)) {
                        continue;
                    }

                    resolve(EndWorkspaceTrial::class)->execute($administrator, $record);
                    $ended++;
                }

                Notification::make()->title("Ended {$ended} trial(s)")->success()->send();
            });
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }
}
