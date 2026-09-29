<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Enums\CreationSource;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use Relaticle\EmailIntegration\Jobs\RelinkRecordHistoryJob;

final readonly class QueueRecordHistoryRelink
{
    private const int IMPORT_SETTLE_SECONDS = 120;

    public function forIdentityValue(CustomFieldValue $value): void
    {
        if (! $value->wasRecentlyCreated && ! $value->wasChanged()) {
            return;
        }

        $identityCode = match ($value->entity_type) {
            'people' => PeopleField::EMAILS->value,
            'company' => CompanyField::DOMAINS->value,
            default => null,
        };

        if ($identityCode === null) {
            return;
        }

        $fieldCode = CustomField::query()->withoutGlobalScopes()->whereKey($value->custom_field_id)->value('code');

        if ($fieldCode !== $identityCode) {
            return;
        }

        $record = $value->entity;

        if (! $record instanceof People && ! $record instanceof Company) {
            return;
        }

        if (! RelinkRecordHistoryJob::shouldRelink((string) $record->workspace_id)) {
            return;
        }

        dispatch(new RelinkRecordHistoryJob($record))->afterCommit();
    }

    public function forImportedRecord(People|Company $record): void
    {
        if ($record->creation_source !== CreationSource::IMPORT) {
            return;
        }

        if (! RelinkRecordHistoryJob::shouldRelink((string) $record->workspace_id)) {
            return;
        }

        // Imports bulk-write custom field values after each chunk, bypassing model events.
        dispatch(new RelinkRecordHistoryJob($record))->afterCommit()->delay(now()->addSeconds(self::IMPORT_SETTLE_SECONDS));
    }
}
