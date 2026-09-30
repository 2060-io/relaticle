<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Throwable;

#[Description('Clean up stale and completed import files')]
#[Signature('import:cleanup
        {--hours=24 : Delete abandoned imports older than this many hours}
        {--completed-hours=2 : Delete completed/failed import files older than this many hours}')]
final class CleanupImportsCommand extends Command
{
    public function handle(): void
    {
        $staleHours = (int) $this->option('hours');
        $completedHours = (int) $this->option('completed-hours');
        $deleted = 0;

        $deleted += $this->cleanupTerminalImportFiles($completedHours);
        $deleted += $this->cleanupAbandonedImports($staleHours);
        $deleted += $this->cleanupOrphanedDirectories($staleHours);
        $deleted += $this->cleanupOrphanedRemoteFiles($staleHours);

        $this->comment("Cleaned up {$deleted} import(s).");
    }

    private function cleanupTerminalImportFiles(int $completedHours): int
    {
        $deleted = 0;

        $terminalImports = Import::query()
            ->whereIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($completedHours))
            ->get();

        $remoteStoreIds = ImportStore::isRemote() && $terminalImports->isNotEmpty()
            ? $this->remoteStoreIds()
            : null;

        foreach ($terminalImports as $import) {
            try {
                $hasStore = $remoteStoreIds === null
                    ? ImportStore::exists($import->id)
                    : isset($remoteStoreIds[$import->id]);

                if (! $hasStore) {
                    continue;
                }

                $this->info("Cleaning up files for import {$import->id} (status: {$import->status->value})");
                ImportStore::delete($import->id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $deleted++;
        }

        return $deleted;
    }

    /** @return array<string, true> */
    private function remoteStoreIds(): array
    {
        $ids = [];

        foreach (Storage::disk((string) config('import-wizard.store.disk'))->files('imports') as $file) {
            $ids[basename((string) $file, '.sqlite')] = true;
        }

        return $ids;
    }

    private function cleanupAbandonedImports(int $staleHours): int
    {
        $deleted = 0;

        $abandonedImports = Import::query()
            ->whereNotIn('status', [ImportStatus::Completed, ImportStatus::Failed])
            ->where('updated_at', '<', now()->subHours($staleHours))
            ->get();

        foreach ($abandonedImports as $import) {
            $this->info("Cleaning up abandoned import {$import->id} (status: {$import->status->value})");

            try {
                ImportStore::delete($import->id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $import->delete();
            $deleted++;
        }

        return $deleted;
    }

    private function cleanupOrphanedDirectories(int $staleHours): int
    {
        $deleted = 0;
        $importsPath = (string) config('import-wizard.storage_path');

        if (! File::isDirectory($importsPath)) {
            return 0;
        }

        $staleBefore = now()->subHours($staleHours)->getTimestamp();

        foreach (File::directories($importsPath) as $directory) {
            $id = basename((string) $directory);

            if (Import::query()->where('id', $id)->exists()) {
                continue;
            }

            // ImportStore::create() writes the directory before the Import row is
            // committed, so a recent directory with no record is an in-flight
            // import. Deleting it would pull the SQLite file out from under a
            // running job, which then fails with "readonly database".
            //
            // The directory can also vanish between being listed and being
            // stat'd, when a concurrent import finishes and destroys its store.
            $lastModified = rescue(fn (): int => File::lastModified($directory), null, report: false);
            if ($lastModified === null) {
                continue;
            }
            if ($lastModified >= $staleBefore) {
                continue;
            }

            $this->info("Cleaning up orphaned directory {$id}");
            File::deleteDirectory($directory);
            $deleted++;
        }

        return $deleted;
    }

    private function cleanupOrphanedRemoteFiles(int $staleHours): int
    {
        if (! ImportStore::isRemote()) {
            return 0;
        }

        $deleted = 0;
        $staleBefore = now()->subHours($staleHours)->getTimestamp();
        $disk = Storage::disk((string) config('import-wizard.store.disk'));

        foreach ($disk->files('imports') as $file) {
            $id = basename((string) $file, '.sqlite');

            if (! Str::isUlid($id) || Import::query()->where('id', $id)->exists()) {
                continue;
            }

            $lastModified = rescue(fn (): int => $disk->lastModified($file), null, report: false);

            if ($lastModified === null || $lastModified >= $staleBefore) {
                continue;
            }

            $this->info("Cleaning up orphaned remote store {$id}");

            try {
                ImportStore::delete($id);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $deleted++;
        }

        return $deleted;
    }
}
