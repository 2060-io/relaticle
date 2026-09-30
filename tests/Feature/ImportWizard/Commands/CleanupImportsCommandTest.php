<?php

declare(strict_types=1);

use App\Events\WorkspaceCreated;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Commands\CleanupImportsCommand;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

mutates(CleanupImportsCommand::class);

beforeEach(function (): void {
    Event::fake()->except([WorkspaceCreated::class]);

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->imports = [];
});

afterEach(function (): void {
    foreach ($this->imports as $import) {
        ImportStore::delete($import->id);
        $import->delete();
    }
});

function createTestImport(object $context, ImportStatus $status, string $updatedAt): Import
{
    $import = Import::factory()->create([
        'workspace_id' => (string) $context->workspace->id,
        'user_id' => (string) $context->user->id,
        'entity_type' => ImportEntityType::People,
        'file_name' => 'test.csv',
        'status' => $status,
        'total_rows' => 0,
        'headers' => [],
    ]);

    $import->updated_at = $updatedAt;
    $import->saveQuietly();

    $store = ImportStore::create($import->id);
    $store->persist();
    $store->close();

    $context->imports[] = $import;

    return $import;
}

it('deletes completed import files older than completed-hours threshold', function (): void {
    $import = createTestImport($this, ImportStatus::Completed, now()->subHours(3)->toIso8601String());

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($storePath))->toBeFalse();
    expect(Import::find($import->id))->not->toBeNull();
});

it('preserves recently completed import files', function (): void {
    $import = createTestImport($this, ImportStatus::Completed, now()->subMinutes(30)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});

it('deletes abandoned imports older than hours threshold', function (): void {
    $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(25)->toIso8601String());

    $storePath = storage_path("app/imports/{$import->id}");

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($storePath))->toBeFalse();
    expect(Import::find($import->id))->toBeNull();
});

it('preserves active in-progress imports', function (): void {
    $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(2)->toIso8601String());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});

it('deletes orphaned directories without DB records', function (): void {
    $path = storage_path('app/imports/orphaned-dir');
    File::ensureDirectoryExists($path);
    touch($path, now()->subHours(25)->getTimestamp());

    $this->artisan('import:cleanup')
        ->expectsOutputToContain('Cleaned up 1 import(s)')
        ->assertExitCode(0);

    expect(File::isDirectory($path))->toBeFalse();
});

it('preserves orphaned directories younger than the staleness threshold', function (): void {
    // ImportStore::create() writes the directory before the Import row is
    // committed, so a freshly created directory with no DB record is an
    // in-flight import, not garbage. The hourly schedule would otherwise
    // delete it out from under the running job.
    $path = storage_path('app/imports/just-created-dir');
    File::ensureDirectoryExists($path);

    $this->artisan('import:cleanup')->assertExitCode(0);

    expect(File::isDirectory($path))->toBeTrue();

    File::deleteDirectory($path);
});

it('respects custom hours option', function (): void {
    $import = createTestImport($this, ImportStatus::Reviewing, now()->subHours(5)->toIso8601String());

    $this->artisan('import:cleanup --hours=48')
        ->expectsOutputToContain('Cleaned up 0 import(s)')
        ->assertExitCode(0);

    $storePath = storage_path("app/imports/{$import->id}");
    expect(File::isDirectory($storePath))->toBeTrue();
    expect(Import::find($import->id))->not->toBeNull();
});

describe('on a remote store disk', function (): void {
    beforeEach(function (): void {
        useRemoteImportStore();
        config()->set('import-wizard.storage_path', sys_get_temp_dir().'/import-cleanup-'.Str::ulid());
    });

    it('deletes the remote file of a completed import past the threshold', function (): void {
        $import = createTestImport($this, ImportStatus::Completed, now()->subHours(3)->toIso8601String());
        Storage::disk('s3')->assertExists("imports/{$import->id}.sqlite");

        $this->artisan('import:cleanup')
            ->expectsOutputToContain('Cleaned up 1 import(s)')
            ->assertExitCode(0);

        Storage::disk('s3')->assertMissing("imports/{$import->id}.sqlite");
        expect(Import::find($import->id))->not->toBeNull();
    });

    it('finds the remote files of completed imports with one listing instead of a lookup each', function (): void {
        $first = createTestImport($this, ImportStatus::Completed, now()->subHours(3)->toIso8601String());
        $second = createTestImport($this, ImportStatus::Failed, now()->subHours(3)->toIso8601String());
        $fake = Storage::disk('s3');
        $disk = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public int $lookups = 0;

            public function exists(mixed $path): bool
            {
                $this->lookups++;

                return parent::exists($path);
            }
        };
        Storage::set('s3', $disk);

        $this->artisan('import:cleanup')
            ->expectsOutputToContain('Cleaned up 2 import(s)')
            ->assertExitCode(0);

        expect($disk->lookups)->toBe(0);
        Storage::disk('s3')->assertMissing("imports/{$first->id}.sqlite");
        Storage::disk('s3')->assertMissing("imports/{$second->id}.sqlite");
    });

    it('deletes an abandoned import row and its remote file', function (): void {
        $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(25)->toIso8601String());

        $this->artisan('import:cleanup')
            ->expectsOutputToContain('Cleaned up 1 import(s)')
            ->assertExitCode(0);

        Storage::disk('s3')->assertMissing("imports/{$import->id}.sqlite");
        expect(Import::find($import->id))->toBeNull();
    });

    it('deletes stale remote files with no import row and keeps fresh ones', function (): void {
        $stale = (string) Str::ulid();
        $fresh = (string) Str::ulid();
        Storage::disk('s3')->put("imports/{$stale}.sqlite", 'stale');
        Storage::disk('s3')->put("imports/{$fresh}.sqlite", 'fresh');
        $root = Storage::disk('s3')->getConfig()['root'];
        touch("{$root}/imports/{$stale}.sqlite", now()->subHours(25)->getTimestamp());

        $this->artisan('import:cleanup')
            ->expectsOutputToContain('Cleaned up 1 import(s)')
            ->assertExitCode(0);

        Storage::disk('s3')->assertMissing("imports/{$stale}.sqlite");
        Storage::disk('s3')->assertExists("imports/{$fresh}.sqlite");
    });
});

describe('a stale remote file whose import row exists', function (): void {
    beforeEach(function (): void {
        useRemoteImportStore();
        config()->set('import-wizard.storage_path', sys_get_temp_dir().'/import-cleanup-'.Str::ulid());
    });

    it('is kept', function (): void {
        $import = createTestImport($this, ImportStatus::Mapping, now()->subHours(2)->toIso8601String());
        $root = Storage::disk('s3')->getConfig()['root'];
        touch("{$root}/imports/{$import->id}.sqlite", now()->subHours(25)->getTimestamp());

        $this->artisan('import:cleanup')
            ->expectsOutputToContain('Cleaned up 0 import(s)')
            ->assertExitCode(0);

        Storage::disk('s3')->assertExists("imports/{$import->id}.sqlite");
    });
});
