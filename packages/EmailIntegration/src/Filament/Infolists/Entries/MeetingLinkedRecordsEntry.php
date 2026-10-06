<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Infolists\Entries;

use App\Enums\CrmEntity;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Infolists\Components\Entry;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Enums\MeetingLinkedRecordType;
use Relaticle\EmailIntegration\Models\Meeting;

final class MeetingLinkedRecordsEntry extends Entry
{
    protected string $view = 'email-integration::filament.infolists.meeting-linked-records';

    /**
     * @return list<array{id: string, name: string, type: MeetingLinkedRecordType, url: string|null}>
     */
    public function getState(): array
    {
        $record = $this->getRecord();

        if (! $record instanceof Meeting) {
            return [];
        }

        return [
            ...$record->people->map(fn (Model $person): array => $this->item($person, MeetingLinkedRecordType::People, CrmEntity::People))->all(),
            ...$record->companies->map(fn (Model $company): array => $this->item($company, MeetingLinkedRecordType::Company, CrmEntity::Company))->all(),
            ...$record->opportunities->map(fn (Model $opportunity): array => $this->item($opportunity, MeetingLinkedRecordType::Opportunity, CrmEntity::Opportunity))->all(),
        ];
    }

    /**
     * @return array{id: string, name: string, type: MeetingLinkedRecordType, url: string|null}
     */
    private function item(Model $linked, MeetingLinkedRecordType $type, CrmEntity $entity): array
    {
        $workspace = filament()->getTenant();

        return [
            'id' => (string) $linked->getKey(),
            'name' => (string) $linked->getAttribute('name'),
            'type' => $type,
            'url' => $workspace instanceof Workspace
                ? resolve(CanonicalRecordUrl::class)->build($entity, (string) $linked->getKey(), $workspace)
                : null,
        ];
    }
}
