<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Widgets\Overview;

use App\Enums\BillingStatus;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Relaticle\SystemAdmin\Actions\MarkWorkspaceContacted;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;
use Relaticle\SystemAdmin\Metrics\SalesLeadsQuery;

final class SalesLeads extends TableWidget
{
    protected static ?string $heading = 'Who should I talk to next?';

    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SalesLeadsQuery::make())
            ->description('Customers using the product for real who do not pay yet. Contacted hides a row for 14 days.')
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->label('Workspace')
                    ->weight('semibold')
                    ->color('primary')
                    ->url(fn (Workspace $record): string => WorkspaceResource::getUrl('view', ['record' => $record])),
                TextColumn::make('why')
                    ->label('Why')
                    ->state(fn (Workspace $record): string => $this->why($record)),
                TextColumn::make('billing_status')
                    ->label('Plan')
                    ->state(fn (Workspace $record): BillingStatus => $record->billingStatus())
                    ->badge(),
                TextColumn::make('last_active')
                    ->label('Last active')
                    ->date(),
            ])
            ->recordActions([
                Impersonate::workspaceOwner()->label('Open as user'),
                Action::make('emailOwner')
                    ->label('Email owner')
                    ->icon('heroicon-o-envelope')
                    ->color('gray')
                    ->visible(fn (Workspace $record): bool => $record->owner !== null)
                    ->url(fn (Workspace $record): string => "mailto:{$record->owner?->email}"),
                Action::make('contacted')
                    ->label('Contacted')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->authorize('markContacted')
                    ->action(function (Workspace $record): void {
                        resolve(MarkWorkspaceContacted::class)->execute($record);
                        Notification::make()->title('Marked as contacted')->success()->send();
                    }),
            ]);
    }

    private function why(Workspace $record): string
    {
        $records = (int) $record->getAttribute('own_records');
        $activeDays = (int) $record->getAttribute('active_days_30');
        $teammates = max(0, (int) $record->getAttribute('users_count'));

        $parts = [
            number_format($records).' '.Str::plural('record', $records),
            number_format($activeDays).' active '.Str::plural('day', $activeDays),
        ];

        if ($teammates > 0) {
            $parts[] = "{$teammates} ".Str::plural('teammate', $teammates);
        }

        $sources = explode(',', (string) $record->getAttribute('sources'));

        foreach (['api' => 'uses API', 'mcp' => 'uses MCP', 'import' => 'imported'] as $source => $label) {
            if (in_array($source, $sources, true)) {
                $parts[] = $label;
            }
        }

        return implode(', ', $parts);
    }
}
