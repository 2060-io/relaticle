<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\CurrentWorkspace;
use Filament\Actions\Exports\Jobs\ExportCompletion;

final class CompleteExport extends ExportCompletion
{
    public function handle(): void
    {
        $workspaceId = $this->export->getAttribute('workspace_id');

        if (! is_string($workspaceId)) {
            parent::handle();

            return;
        }

        resolve(CurrentWorkspace::class)->within($workspaceId, function (): void {
            parent::handle();
        });
    }
}
