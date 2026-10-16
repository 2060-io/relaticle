<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Contracts\DeletesTeams;

#[DeleteWhenMissingModels]
#[Timeout(300)]
#[Tries(2)]
#[UniqueFor(900)]
final class PurgeWorkspace implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly Workspace $workspace)
    {
        $this->onQueue('imports');
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->workspace->getKey();
    }

    public function handle(DeletesTeams $deletesWorkspaces): void
    {
        if (! $this->workspace->isDeletionDue()) {
            return;
        }

        DB::transaction(function () use ($deletesWorkspaces): void {
            $deletesWorkspaces->delete($this->workspace);
        });

        Log::info('Purged workspace', ['workspace_id' => $this->workspace->getKey(), 'name' => $this->workspace->name]);
    }
}
