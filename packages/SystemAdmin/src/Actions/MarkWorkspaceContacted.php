<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Models\Workspace;

final readonly class MarkWorkspaceContacted
{
    public function execute(Workspace $workspace): void
    {
        $workspace->forceFill(['sales_contacted_at' => now()])->save();
    }
}
