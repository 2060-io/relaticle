<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomField;
use App\Models\Workspace;
use Illuminate\Support\Collection;

final class WorkspaceCustomFields
{
    /** @var array<string, Collection<int, CustomField>> */
    private array $byTenant = [];

    /**
     * @return Collection<int, CustomField>
     */
    public function forEntity(Workspace $workspace, string $entityType): Collection
    {
        return $this->all($workspace)
            ->where('entity_type', $entityType)
            ->values();
    }

    public function forget(int|string $tenantId): void
    {
        unset($this->byTenant[(string) $tenantId]);
    }

    /**
     * @return Collection<int, CustomField>
     */
    private function all(Workspace $workspace): Collection
    {
        $tenantId = (string) $workspace->getKey();

        return $this->byTenant[$tenantId] ??= CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->with('options')
            ->get();
    }
}
