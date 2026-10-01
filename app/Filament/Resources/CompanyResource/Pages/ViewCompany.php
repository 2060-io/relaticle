<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\RelationManagers\MeetingsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager;
use Filament\Resources\Pages\ViewRecord;
use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;
use Relaticle\EmailIntegration\Filament\Concerns\ProvidesComposerToAddress;

final class ViewCompany extends ViewRecord
{
    use HasRecordPageLayout;
    use ProvidesComposerToAddress;

    protected static string $resource = CompanyResource::class;

    public function getRelationManagers(): array
    {
        return [
            PeopleRelationManager::class,
            TasksRelationManager::class,
            NotesRelationManager::class,
            MeetingsRelationManager::class,
            ActivityLogRelationManager::class,
        ];
    }

    protected function nativeDetailEntries(): array
    {
        return [
            RecordChipEntry::make('accountOwner.name')
                ->label(__('filament/resources/company.pages.view.infolist.fields.account_owner.label')),
        ];
    }

    protected function recordLangFile(): string
    {
        return 'filament/resources/company';
    }
}
