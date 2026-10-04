<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\CustomField;

final class WorkspaceCustomField
{
    public static function byCode(string $workspaceId, string $entityType, string $code): CustomField
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->firstOrFail();
    }
}
