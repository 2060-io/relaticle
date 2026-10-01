<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin RelationManager
 */
trait CountsRelatedRecords
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->{static::getRelationshipName()}()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): string
    {
        return 'gray';
    }

    protected function afterActionCalled(Action $action): void
    {
        $this->dispatch('related-records-changed')->to($this->getPageClass());
    }
}
