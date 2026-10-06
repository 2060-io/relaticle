# Laravel Cloud readiness, part 3: import store off local SQLite

> **For agentic workers:** REQUIRED SUB-SKILL: execute with `sdd-lean` (the user's standing choice). Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The import wizard works when web replicas and queue workers have separate, ephemeral disks, by keeping each import's SQLite store on a shared disk when `IMPORT_STORE_DISK` is set.

**Architecture:** Port the Tapix remote-store layer into `Relaticle\ImportWizard\Store\ImportStore`. The canonical file lives on the configured disk at `imports/{id}.sqlite`. Writers take a cache lock, download to a temp directory, mutate, and upload a `VACUUM INTO` snapshot. Readers open a per-replica copy that is refreshed when the remote checksum changes. With `IMPORT_STORE_DISK` unset, the store stays at `storage/app/imports/{id}/data.sqlite` and no lock is taken, so Forge keeps today's behaviour.

**Tech Stack:** Laravel 13, Livewire 4, Filament 5, SQLite via PDO, Flysystem 3 (`league/flysystem-aws-s3-v3`), Redis cache locks, Pest 4.

**Spec:** `docs/superpowers/specs/2026-09-28-laravel-cloud-readiness-design.md` (section "Part 3"). Tapix reference: `~/Herd/tapix-core`, read `origin/1.x` (`git -C ~/Herd/tapix-core show origin/1.x:src/Store/ImportStore.php`); the working tree is 32 commits behind.

## Global Constraints

- `IMPORT_STORE_DISK` unset keeps today's local file and takes no lock. Every existing test runs in that mode and must stay green unchanged, apart from the API migration in Task 5.
- Cloud sets `IMPORT_STORE_DISK=s3` (the private bucket from part 1). The `local` disk is never remapped.
- `tests/Arch/ConventionsTest.php` "keeps runtime file access off local-only disks and paths": this plan removes `ImportStore.php` and `CleanupImportsCommand.php` from its allowlist. Paths come from `config/import-wizard.php` (config files are not scanned) or `sys_get_temp_dir()`.
- `max_rows` is 10,000. A 10,000-row store snapshots to 3.8 MB in 12 ms (measured 2026-09-30). Network round trips dominate, estimated 0.3 to 0.5 s per locked write on Cloud.
- PostgreSQL only for the app database; the store stays SQLite.
- No isolated unit tests of `ImportStore`. Test through the Livewire steps, the three jobs, the loader, and `import:cleanup`.
- Remote tests use `fakeDiskWithoutLocalPaths('s3')` (`tests/Pest.php:125`) and `config()->set('import-wizard.store.disk', 's3')`. `CACHE_STORE=array` and `QUEUE_CONNECTION=sync` in `phpunit.xml`; array locks honour owners.
- Never dispatch a job from inside a `withWriteLock` closure. Under the sync queue the job would wait on the lock its dispatcher holds.
- Pre-commit per task: `vendor/bin/pint --dirty --format agent`, `vendor/bin/rector --dry-run`, `vendor/bin/phpstan analyse`, targeted tests. Type coverage 100% (`composer test:type-coverage`) at the end.
- Comments: none unless a non-obvious why, two lines max. No comments in tests.

## Decisions (sign-off needed before Task 1)

| # | Decision | Why |
|---|---|---|
| D1 | Lock TTL 150 s for web and validation writes, 360 s for execution | Each exceeds the longest holder: `ValidateColumnJob`/`ResolveMatchesJob` time out at 120 s, `ExecuteImportJob` at 300 s. Tapix's 120 s TTL against a 600 s job is a latent bug we do not copy. |
| D2 | Execution locks with owner `execute:{id}` | A retried attempt releases the lock its killed predecessor left, instead of waiting out the TTL and failing all three tries. `Lock::release()` checks the owner, so no other writer's lock can be taken. |
| D3 | Validation and match jobs read and compute outside the lock, then write inside it | `ReviewStep::mount()` dispatches one `ValidateColumnJob` per column plus `ResolveMatchesJob` at once (`ReviewStep.php:176-180`). Holding the lock only for the SQL keeps 20 columns under about 10 s of queueing. Job wait 60 s, web wait 10 s. |
| D4 | `ExecuteImportJob` uploads a snapshot after every 500-row chunk, before it persists the counters | A crash re-processes at most one chunk and never double-counts. Today a crash loses at most one row's `processed` flag. |
| D5 | `ExecuteImportJob` time-boxes each run to 240 s, then adds a fresh job to its batch and stops | Without it, a large import that hits the 300 s timeout is killed mid-chunk and its retry duplicates up to 499 records. `ExecuteImportJobScaleTest` runs 1,000 rows in 1.8 to 10.6 s locally, so a 500-row chunk costs about 1 to 5 s and the 60 s margin holds. **Changes Forge behaviour**: a run past 240 s resumes in a new job instead of being killed and retried. |
| D6 | Read copies are refreshed per replica with no cross-replica lock | Tapix takes a global `refresh` lock, which makes replicas wait on each other for copies they each need. Unique temp name plus atomic `rename()` is enough. |
| D7 | Read stores open with `PRAGMA query_only=1` in both modes | A write that bypasses `withWriteLock` fails in the local-mode suite instead of silently vanishing on Cloud. |

## Review Focus

1. A user corrects a value while column validation jobs are still running. Expected: both the correction and the validation results survive, in either order. Test in Task 4.
2. A worker is killed mid-import and the job retries. Expected: the retry resumes after the last uploaded chunk and does not wait out the lock. Test in Task 3.
3. A web write cannot get the lock within 10 s. Expected: a notification, no 500, and nothing written. Test in Task 4.
4. An import is cancelled or cleaned up. Expected: the remote file and this replica's read copy are both gone. Test in Task 1.
5. A Livewire request writes, then re-renders in the same request. Expected: the render shows the new value, not the stale read copy. Test in Task 4.

---

## File structure

| File | Responsibility |
|---|---|
| `packages/ImportWizard/src/Store/ImportStore.php` | Rewrite: local and remote modes, read copies, write lock, execution lock, snapshot upload, delete |
| `packages/ImportWizard/src/Exceptions/ImportStoreException.php` | Create: `notFound`, `lockTimeout`, `lockLost`, `snapshotFailed` |
| `packages/ImportWizard/config/import-wizard.php` | Add the `store` block |
| `packages/ImportWizard/src/Support/ImportFileLoader.php` | Persist the new store; delete on failure |
| `packages/ImportWizard/src/Commands/CleanupImportsCommand.php` | Delete through `ImportStore::delete()`; sweep orphaned remote files |
| `packages/ImportWizard/src/Jobs/ValidateColumnJob.php` | Read, compute, then write under the lock |
| `packages/ImportWizard/src/Jobs/ResolveMatchesJob.php`, `Support/MatchResolver.php` | Resolve under the lock |
| `packages/ImportWizard/src/Jobs/ExecuteImportJob.php` | Execution store, per-chunk persist, time-box handoff |
| `packages/ImportWizard/src/Livewire/Concerns/WithImportStore.php` | `store()` reads, `writeStore()` writes |
| `packages/ImportWizard/src/Livewire/Steps/ReviewStep.php` | Every write through `writeStore()` |
| `packages/ImportWizard/src/Livewire/Steps/UploadStep.php`, `Livewire/ImportWizard.php` | Delete through `ImportStore::delete()` |
| `packages/ImportWizard/resources/lang/en/store.php` | Create: the busy notification copy |
| `tests/Arch/ConventionsTest.php` | Drop two allowlist entries |
| Tests (existing files, extended) | `Livewire/UploadStepTest.php`, `Livewire/ImportWizardTest.php`, `Commands/CleanupImportsCommandTest.php`, `Jobs/ValidateColumnJobTest.php`, `Jobs/ResolveMatchesJobTest.php`, `Jobs/ExecuteImportJobCoreTest.php`, `Livewire/ReviewStepTest.php`, `Livewire/PreviewStepTest.php`, `tests/Helpers/ImportExecutionFixture.php` |

## Store API (every task uses these names)

```php
final class ImportStore
{
    public static function create(string $importId): self;                    // new empty store; local: canonical path, remote: temp dir
    public static function forRead(string $importId): ?self;                  // query_only; remote: per-replica copy, refreshed on checksum change
    public static function withWriteLock(string $importId, Closure $mutator, ?int $waitSeconds = null): mixed; // throws ImportStoreException
    public static function forExecution(string $importId): ?self;             // remote: holds the execute:{id} lock until close()
    public static function delete(string $importId): void;                    // local dir, remote file, this replica's read copy
    public static function isRemote(): bool;

    public function id(): string;
    public function connection(): Connection;
    /** @return EloquentBuilder<ImportRow> */
    public function query(): EloquentBuilder;
    public function ensureProcessedColumn(): void;
    public function bulkUpdateMatches(string $jsonPath, array $resolvedMap, RowMatchAction $unmatchedAction): void;
    public function persist(): void;                                          // remote: upload snapshot, lock kept; local: no-op
    public function close(): void;                                            // purge connection; remote: delete temp dir, release lock
}
```

`load()`, `path()`, `sqlitePath()` and `destroy()` are removed in Task 5 after every caller has moved.

---

### Task 1: Store core, loader, delete paths, cleanup

**Files:**
- Create: `packages/ImportWizard/src/Exceptions/ImportStoreException.php`
- Modify: `packages/ImportWizard/src/Store/ImportStore.php` (add the new API beside `load()`/`destroy()`, which stay until Task 5)
- Modify: `packages/ImportWizard/config/import-wizard.php`
- Modify: `packages/ImportWizard/src/Support/ImportFileLoader.php:59-95`
- Modify: `packages/ImportWizard/src/Livewire/Steps/UploadStep.php:47,140,158-169`
- Modify: `packages/ImportWizard/src/Livewire/ImportWizard.php:257`
- Modify: `packages/ImportWizard/src/Commands/CleanupImportsCommand.php`
- Modify: `tests/Arch/ConventionsTest.php:575-576`
- Test: `tests/Feature/ImportWizard/Livewire/UploadStepTest.php`, `tests/Feature/ImportWizard/Livewire/ImportWizardTest.php`, `tests/Feature/ImportWizard/Commands/CleanupImportsCommandTest.php`

**Interfaces:**
- Produces: the full Store API above, `ImportStoreException`, config keys `import-wizard.storage_path`, `import-wizard.store.{disk,read_cache_path,lock.ttl,lock.execution_ttl,lock.wait.web,lock.wait.job}`.

- [ ] **Step 1: Config**

```php
return [
    'max_rows' => 10_000,
    'max_file_size' => 10 * 1024 * 1024, // 10MB
    'storage_path' => storage_path('app/imports'),
    'chunk_size' => 500,
    'store' => [
        'disk' => env('IMPORT_STORE_DISK'),
        'read_cache_path' => storage_path('framework/cache/imports'),
        'lock' => [
            'ttl' => 150,
            'execution_ttl' => 360,
            'wait' => ['web' => 10, 'job' => 60],
        ],
    ],
];
```

- [ ] **Step 2: Exception**

```php
final class ImportStoreException extends RuntimeException
{
    private bool $notFound = false;

    public static function notFound(string $importId): self
    {
        $exception = new self("Import store {$importId} does not exist.");
        $exception->notFound = true;

        return $exception;
    }

    public function isNotFound(): bool
    {
        return $this->notFound;
    }

    public static function lockTimeout(string $importId, int $waitSeconds): self
    {
        return new self("Could not lock import store {$importId} within {$waitSeconds}s.");
    }

    public static function lockLost(string $importId): self
    {
        return new self("The write lock on import store {$importId} expired before the upload.");
    }

    public static function snapshotFailed(string $importId, string $reason): self
    {
        return new self("Could not upload import store {$importId}: {$reason}");
    }
}
```

- [ ] **Step 3: Write the failing tests**

`UploadStepTest.php`, new `describe('on a remote store disk', ...)` with `beforeEach` setting `config()->set('import-wizard.store.disk', 's3'); fakeDiskWithoutLocalPaths('s3');`:

```php
it('keeps the uploaded rows on the store disk and nothing under storage', function (): void {
    $component = mountUploadStep($this)
        ->set('uploadedFile', makeCsvFile("name,email\nAda,ada@example.com\nGrace,grace@example.com\n"))
        ->call('continueToMapping');

    $import = Import::query()->latest()->firstOrFail();
    $this->createdStoreIds[] = $import->id;

    Storage::disk('s3')->assertExists("imports/{$import->id}.sqlite");
    expect(File::exists(config('import-wizard.storage_path')."/{$import->id}"))->toBeFalse()
        ->and(ImportStore::forRead($import->id)?->query()->count())->toBe(2);
});

it('deletes the remote store when the file is removed', function (): void {
    $component = mountUploadStep($this)
        ->set('uploadedFile', makeCsvFile("name\nAda\n"))
        ->call('continueToMapping');
    $import = Import::query()->latest()->firstOrFail();

    $component->call('removeFile');

    Storage::disk('s3')->assertMissing("imports/{$import->id}.sqlite");
});
```

Match the method names the existing tests use to trigger parsing (read the file's "Upload" section first; replace `continueToMapping` if it is named differently).

`ImportWizardTest.php`: a remote-disk test that cancelling an import removes `imports/{id}.sqlite` and the read copy directory `config('import-wizard.store.read_cache_path')."/{$id}"` (create it first by calling `ImportStore::forRead($id)`).

`CleanupImportsCommandTest.php`: remote-disk tests that (a) a completed import older than `--completed-hours` loses its remote file, (b) an abandoned import loses both its row and its remote file, (c) a remote `imports/{ulid}.sqlite` with no `Import` row and a `lastModified` older than `--hours` is deleted, while one modified now survives.

- [ ] **Step 4: Run them, confirm they fail**

Run: `php artisan test --compact tests/Feature/ImportWizard/Livewire/UploadStepTest.php tests/Feature/ImportWizard/Livewire/ImportWizardTest.php tests/Feature/ImportWizard/Commands/CleanupImportsCommandTest.php`
Expected: the new remote tests FAIL (file missing or `forRead` undefined).

- [ ] **Step 5: Implement the store**

Key parts. Until Task 5, keep the old methods working for the callers Tasks 2 to 4 have not moved yet: `load()` delegates to `forLocalWrite()` (the writable canonical file, never a `query_only` read copy, because `ReviewStep` still writes through it) and returns null in remote mode; `destroy()` calls `close()` then `delete()`; `path()` and `sqlitePath()` keep returning the local paths.

```php
final class ImportStore
{
    private ?Connection $connection = null;

    private function __construct(
        private readonly string $id,
        private readonly string $directory,
        private readonly bool $readOnly = false,
        private readonly bool $temporary = false,
        private ?Lock $lock = null,
    ) {}

    // Lock is Illuminate\Cache\Lock, not the contract: persist() needs isOwnedByCurrentProcess().

    public static function isRemote(): bool
    {
        return filled(config('import-wizard.store.disk'));
    }

    public static function create(string $importId): self
    {
        $store = self::isRemote()
            ? new self($importId, self::temporaryDirectory($importId), temporary: true)
            : new self($importId, self::localDirectory($importId));

        File::ensureDirectoryExists($store->directory);
        file_put_contents($store->sqlitePath(), '');
        $store->createTableSafely();

        return $store;
    }

    public static function forRead(string $importId): ?self
    {
        if (! Str::isUlid($importId)) {
            return null;
        }

        if (! self::isRemote()) {
            return File::exists(self::localDirectory($importId).'/data.sqlite')
                ? new self($importId, self::localDirectory($importId), readOnly: true)
                : null;
        }

        $remotePath = self::remotePath($importId);

        if (! self::disk()->exists($remotePath)) {
            return null;
        }

        $directory = self::readCacheDirectory($importId);
        $checksum = self::disk()->checksum($remotePath);

        if (! File::exists("{$directory}/data.sqlite") || rescue(fn (): string => File::get("{$directory}/checksum"), '', report: false) !== $checksum) {
            File::ensureDirectoryExists($directory);
            $download = "{$directory}/".Str::ulid().'.download';
            self::download($remotePath, $download);
            rename($download, "{$directory}/data.sqlite");
            File::put("{$directory}/checksum", (string) $checksum);
        }

        return new self($importId, $directory, readOnly: true);
    }

    public static function withWriteLock(string $importId, Closure $mutator, ?int $waitSeconds = null): mixed
    {
        if (! self::isRemote()) {
            $store = self::forLocalWrite($importId) ?? throw ImportStoreException::notFound($importId);

            try {
                return $mutator($store);
            } finally {
                $store->close();
            }
        }

        $waitSeconds ??= (int) config('import-wizard.store.lock.wait.job');
        $lock = Cache::lock(self::lockName($importId), (int) config('import-wizard.store.lock.ttl'));
        self::block($lock, $importId, $waitSeconds);

        $store = self::downloadForWrite($importId, $lock);

        try {
            $result = $mutator($store);
            $store->persist();

            return $result;
        } finally {
            $store->close();
        }
    }

    public static function forExecution(string $importId): ?self
    {
        if (! self::isRemote()) {
            return self::forLocalWrite($importId);
        }

        $lock = Cache::lock(self::lockName($importId), (int) config('import-wizard.store.lock.execution_ttl'), "execute:{$importId}");

        if (! $lock->get()) {
            $lock->release();
            self::block($lock, $importId, (int) config('import-wizard.store.lock.wait.job'));
        }

        try {
            return self::downloadForWrite($importId, $lock);
        } catch (ImportStoreException) {
            return null;
        }
    }

    public static function delete(string $importId): void
    {
        if (! Str::isUlid($importId)) {
            return;
        }

        DB::purge("import_{$importId}");
        DB::purge("import_read_{$importId}");
        File::deleteDirectory(self::localDirectory($importId));
        File::deleteDirectory(self::readCacheDirectory($importId));

        if (self::isRemote()) {
            self::disk()->delete(self::remotePath($importId));
        }
    }

    public function persist(): void
    {
        if (! $this->temporary) {
            return;
        }

        throw_if($this->lock instanceof Lock && ! $this->lock->isOwnedByCurrentProcess(), ImportStoreException::lockLost($this->id));

        $snapshot = "{$this->directory}/snapshot.sqlite";
        File::delete($snapshot);
        $this->connection()->statement('VACUUM INTO ?', [$snapshot]);
        $size = filesize($snapshot);
        $stream = fopen($snapshot, 'rb');

        try {
            throw_unless(is_resource($stream) && self::disk()->writeStream(self::remotePath($this->id), $stream), ImportStoreException::snapshotFailed($this->id, 'write failed'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            File::delete($snapshot);
        }

        throw_unless(self::disk()->size(self::remotePath($this->id)) === $size, ImportStoreException::snapshotFailed($this->id, 'size mismatch'));
    }

    public function close(): void
    {
        DB::purge($this->connectionName());
        $this->connection = null;

        if ($this->temporary) {
            File::deleteDirectory($this->directory);
        }

        $this->lock?->release();
        $this->lock = null;
    }

    public function connectionName(): string
    {
        return $this->readOnly ? "import_read_{$this->id}" : "import_{$this->id}";
    }

    private function createConnection(): Connection
    {
        $name = $this->connectionName();

        config()->set("database.connections.{$name}", [
            'driver' => 'sqlite',
            'database' => $this->sqlitePath(),
            'foreign_key_constraints' => true,
        ]);
        DB::purge($name);

        $connection = DB::connection($name);

        if ($this->readOnly) {
            $connection->statement('PRAGMA query_only = 1');
        }

        return $connection;
    }

    private static function downloadForWrite(string $importId, Lock $lock): self
    {
        $remotePath = self::remotePath($importId);

        if (! self::disk()->exists($remotePath)) {
            $lock->release();

            throw ImportStoreException::notFound($importId);
        }

        $store = new self($importId, self::temporaryDirectory($importId), temporary: true, lock: $lock);
        File::ensureDirectoryExists($store->directory);
        self::download($remotePath, $store->sqlitePath());

        return $store;
    }

    private static function block(Lock $lock, string $importId, int $waitSeconds): void
    {
        try {
            $lock->block($waitSeconds);
        } catch (LockTimeoutException) {
            throw ImportStoreException::lockTimeout($importId, $waitSeconds);
        }
    }

    private static function download(string $remotePath, string $localPath): void { /* readStream + stream_copy_to_stream, close both, throw snapshotFailed on null */ }
    private static function forLocalWrite(string $importId): ?self { /* ULID check, canonical file exists, new self(..., localDirectory) */ }
    private static function disk(): FilesystemAdapter { return Storage::disk((string) config('import-wizard.store.disk')); }
    private static function remotePath(string $importId): string { return "imports/{$importId}.sqlite"; }
    private static function lockName(string $importId): string { return "import-store:{$importId}"; }
    private static function localDirectory(string $importId): string { return config('import-wizard.storage_path')."/{$importId}"; }
    private static function readCacheDirectory(string $importId): string { return config('import-wizard.store.read_cache_path')."/{$importId}"; }
    private static function temporaryDirectory(string $importId): string { return sys_get_temp_dir()."/import-{$importId}-".Str::ulid(); }
}
```

Fill the four one-line bodies shown as `/* ... */` with the code their comment describes. Check `VACUUM INTO ?` binding works; if SQLite rejects a bound parameter there, quote the path with `str_replace("'", "''", $snapshot)` and interpolate. `checksum()` returns the S3 ETag on the S3 adapter and an MD5 of the content on the local adapter (`vendor/league/flysystem-aws-s3-v3/AwsS3V3Adapter.php:488`).

- [ ] **Step 6: Migrate the create and delete callers**

- `ImportFileLoader::load()`: after the insert loop and the `$rowCount === 0` check, call `$store->persist(); $store->close();`. Replace both `$store->destroy()` calls with `$store->close(); ImportStore::delete($import->id);`.
- `UploadStep`: drop the `$store` property and the `ImportStore::load()` at line 140; `cleanupExisting()` calls `ImportStore::delete($this->import->id)` before deleting the `Import`.
- `ImportWizard.php:257`: `ImportStore::delete($importId);`
- `CleanupImportsCommand`: replace `ImportStore::load($id)` plus `destroy()` with `ImportStore::delete($id)`; the terminal-import branch keeps counting only imports whose store existed, so check `ImportStore::forRead($import->id) instanceof ImportStore` first and close it. `cleanupOrphanedDirectories()` reads `config('import-wizard.storage_path')`. Add `cleanupOrphanedRemoteFiles(int $staleHours)` that, when `ImportStore::isRemote()`, lists `Storage::disk(config('import-wizard.store.disk'))->files('imports')`, takes the ULID from each `{id}.sqlite`, skips ids with an `Import` row or a `lastModified` newer than the cutoff, and deletes the rest through `ImportStore::delete()`. Add its count to `handle()`.
- `tests/Arch/ConventionsTest.php`: delete the two allowlist lines for `CleanupImportsCommand.php` and `ImportStore.php`.

- [ ] **Step 7: Run the tests, confirm they pass**

Run: `php artisan test --compact tests/Feature/ImportWizard tests/Feature/Chat/ChatAttachmentTest.php tests/Arch`
Expected: PASS, including every pre-existing test in local mode.

- [ ] **Step 8: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse
git add packages/ImportWizard tests
git commit -m "feat(imports): keep the import store on a configurable disk"
```

### Task 2: Validation and match jobs

**Files:**
- Modify: `packages/ImportWizard/src/Jobs/ValidateColumnJob.php`
- Modify: `packages/ImportWizard/src/Jobs/ResolveMatchesJob.php`
- Modify: `packages/ImportWizard/src/Support/MatchResolver.php` (no signature change)
- Test: `tests/Feature/ImportWizard/Jobs/ValidateColumnJobTest.php`, `tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php`

**Interfaces:**
- Consumes: `ImportStore::forRead`, `ImportStore::withWriteLock`, `ImportStoreException`.

- [ ] **Step 1: Write the failing tests**

In each file add a `describe('on a remote store disk', ...)` whose `beforeEach` sets the disk config, calls `fakeDiskWithoutLocalPaths('s3')`, and seeds through the store helper the file already uses (`createValidationStore()` in `ValidateColumnJobTest.php`) followed by `$store->persist(); $store->close();`. Change the helper to do that itself when `ImportStore::isRemote()`.

```php
it('writes validation errors into the remote store', function (): void {
    [$import] = createValidationStore($this, ['email'], [
        ['row_number' => 2, 'raw_data' => json_encode(['email' => 'not-an-email'])],
        ['row_number' => 3, 'raw_data' => json_encode(['email' => 'ada@example.com'])],
    ], [ColumnData::toField(source: 'email', target: 'email')]);

    new ValidateColumnJob($import->id, $import->getColumnMapping('email'))->handle();

    $rows = ImportStore::forRead($import->id)->query()->orderBy('row_number')->get();
    expect($rows[0]->validation)->not->toBeNull()
        ->and($rows[1]->validation)->toBeNull();
});

it('leaves validation off a row whose value was corrected', function (): void {
    [$import] = createValidationStore($this, ['email'], [
        ['row_number' => 2, 'raw_data' => json_encode(['email' => 'not-an-email']), 'corrections' => json_encode(['email' => 'ada@example.com'])],
        ['row_number' => 3, 'raw_data' => json_encode(['email' => 'not-an-email'])],
    ], [ColumnData::toField(source: 'email', target: 'email')]);

    new ValidateColumnJob($import->id, $import->getColumnMapping('email'))->handle();

    $rows = ImportStore::forRead($import->id)->query()->orderBy('row_number')->get();
    expect($rows[0]->validation)->toBeNull()
        ->and($rows[1]->validation)->not->toBeNull();
});

it('fails with a lock timeout instead of writing when another writer holds the store', function (): void {
    [$import] = createValidationStore($this, ['email'], [
        ['row_number' => 2, 'raw_data' => json_encode(['email' => 'not-an-email'])],
    ], [ColumnData::toField(source: 'email', target: 'email')]);
    $held = Cache::lock("import-store:{$import->id}", 150);
    $held->get();
    config()->set('import-wizard.store.lock.wait.job', 0);

    expect(fn () => new ValidateColumnJob($import->id, $import->getColumnMapping('email'))->handle())
        ->toThrow(ImportStoreException::class);

    $held->release();
});
```

Use the real `ColumnData` factory calls the file already uses; the ones above are illustrative of shape. The second test pins the guard (`json_extract(corrections, ?) IS NULL`) that makes the read-then-write split safe: a value corrected after the job read it never receives the job's error.

`ResolveMatchesJobTest.php`: a remote test that an email match against an existing person writes `match_action = update` and `matched_id` into the remote store, and unmatched rows become `create`.

- [ ] **Step 2: Run them, confirm they fail**

Run: `php artisan test --compact tests/Feature/ImportWizard/Jobs/ValidateColumnJobTest.php tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php`
Expected: the remote tests FAIL (the job writes to a store that is never uploaded).

- [ ] **Step 3: Implement**

`ValidateColumnJob::handle()`:

```php
$import = Import::query()->findOrFail($this->importId);
$reader = ImportStore::forRead($this->importId);

if (! $reader instanceof ImportStore) {
    return;
}

$jsonPath = '$.'.$this->column->source;

try {
    $this->column->isEntityLinkMapping()
        ? $this->validateEntityLink($import, $reader, $jsonPath)
        : $this->validateField($import, $reader, $jsonPath);
} finally {
    $reader->close();
}
```

- `validateField()`: compute `$uniqueValues = $this->fetchUncorrectedUniqueValues($reader, $jsonPath)` and `$results = $uniqueValues === [] ? [] : $this->validateValues($import, $uniqueValues)` outside the lock, then:

```php
ImportStore::withWriteLock($this->importId, function (ImportStore $store) use ($jsonPath, $results): void {
    $this->clearValidationForCorrectedDateFields($store->connection(), $jsonPath);

    if ($results !== []) {
        $this->updateValidationErrors($store->connection(), $jsonPath, $results);
    }
});
```

- `validateEntityLink()`: read the unique values from `$reader`, run `batchValidateFromColumn()` and `batchResolve()` outside the lock. Split `writeEntityLinkRelationships()` into `relationshipInserts(...): array` (everything up to building `$inserts`, returns `array<string, string|null>` keyed by value) and `applyRelationships(Connection $connection, string $jsonPath, string $linkKey, string $matcherField, array $uniqueValues, array $inserts): void` (the temp table and `UPDATE`). Then one `withWriteLock` calls `updateValidationErrors()` and `applyRelationships()`.
- Catch `ImportStoreException` only for `notFound` semantics: if the store vanished between read and write (import cancelled), return quietly. Let `lockTimeout` propagate so the batch records a failure and the column shows its retry state (`ReviewStep::checkProgress()` already handles failed batches).

`ResolveMatchesJob::handle()`:

```php
$import = Import::query()->findOrFail($this->importId);
$importer = $import->getImporter();

try {
    ImportStore::withWriteLock($this->importId, fn (ImportStore $store) => new MatchResolver($store, $import, $importer)->resolve());
} catch (ImportStoreException $e) {
    if (ImportStore::forRead($this->importId) instanceof ImportStore) {
        throw $e;
    }
}
```

Prefer `$e->isNotFound()` over the re-read shown above; it is defined in Task 1. Use it in both jobs.

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `php artisan test --compact tests/Feature/ImportWizard/Jobs/ValidateColumnJobTest.php tests/Feature/ImportWizard/Jobs/ResolveMatchesJobTest.php`
Expected: PASS, local and remote.

- [ ] **Step 5: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse
git add packages/ImportWizard tests
git commit -m "feat(imports): validate and resolve matches under the store write lock"
```

### Task 3: Execution

**Files:**
- Modify: `packages/ImportWizard/src/Jobs/ExecuteImportJob.php:125-216`
- Modify: `tests/Helpers/ImportExecutionFixture.php`
- Test: `tests/Feature/ImportWizard/Jobs/ExecuteImportJobCoreTest.php`

**Interfaces:**
- Consumes: `ImportStore::forExecution`, `persist()`, `close()`.
- Produces: config `import-wizard.execution_time_box` (seconds, default 240).

- [ ] **Step 1: Write the failing tests**

`ImportExecutionFixture::readyStore()`: after the insert, `if (ImportStore::isRemote()) { $store->persist(); $store->close(); $store = ImportStore::forRead($import->id); }` so callers still get a store to assert against. Add `public static function freshStore(object $context): ImportStore` returning `ImportStore::forRead($context->import->id)`; remote assertions must re-read, because a read copy is a snapshot.

`ExecuteImportJobCoreTest.php`, `describe('on a remote store disk', ...)`:

```php
it('imports every row and marks them processed in the remote store', function (): void {
    ImportExecutionFixture::readyStore($this, ['name'], [
        ImportExecutionFixture::row(2, ['name' => 'Ada Lovelace'], ['match_action' => 'create']),
        ImportExecutionFixture::row(3, ['name' => 'Grace Hopper'], ['match_action' => 'create']),
    ], [ColumnData::toField(source: 'name', target: 'name')]);

    ImportExecutionFixture::run($this);

    expect(People::query()->whereIn('name', ['Ada Lovelace', 'Grace Hopper'])->count())->toBe(2)
        ->and(ImportExecutionFixture::freshStore($this)->query()->where('processed', false)->count())->toBe(0)
        ->and($this->import->refresh()->status)->toBe(ImportStatus::Completed);
});

it('resumes after a killed attempt without waiting out its lock', function (): void {
    ImportExecutionFixture::readyStore($this, ['name'], [
        ImportExecutionFixture::row(2, ['name' => 'Ada Lovelace'], ['match_action' => 'create']),
        ImportExecutionFixture::row(3, ['name' => 'Grace Hopper'], ['match_action' => 'create']),
    ], [ColumnData::toField(source: 'name', target: 'name')]);
    Cache::lock("import-store:{$this->import->id}", 360, "execute:{$this->import->id}")->get();

    ImportExecutionFixture::run($this);

    expect($this->import->refresh()->status)->toBe(ImportStatus::Completed);
});

it('waits for a lock another writer holds', function (): void {
    ImportExecutionFixture::readyStore($this, ['name'], [
        ImportExecutionFixture::row(2, ['name' => 'Ada Lovelace'], ['match_action' => 'create']),
    ], [ColumnData::toField(source: 'name', target: 'name')]);
    Cache::lock("import-store:{$this->import->id}", 150)->get();
    config()->set('import-wizard.store.lock.wait.job', 0);

    expect(fn () => ImportExecutionFixture::run($this))->toThrow(ImportStoreException::class);
});

it('hands the rest of the import to a fresh job when the time box runs out', function (): void {
    Bus::fake();
    config()->set('import-wizard.execution_time_box', 0);
    ImportExecutionFixture::readyStore($this, ['name'], array_map(
        fn (int $n): array => ImportExecutionFixture::row($n + 1, ['name' => "Person {$n}"], ['match_action' => 'create']),
        range(1, 501),
    ), [ColumnData::toField(source: 'name', target: 'name')]);

    ImportExecutionFixture::run($this);

    expect(ImportExecutionFixture::freshStore($this)->query()->where('processed', true)->count())->toBe(500)
        ->and($this->import->refresh()->status)->toBe(ImportStatus::Importing);
    Bus::assertDispatched(ExecuteImportJob::class);
});
```

Use the real `row()` helper and People model the file already uses. The handoff test runs outside a batch in the fixture, so the job dispatches directly when `$this->batch()` is null and adds to the batch otherwise; add a PreviewStep test in Task 4 for the batch path. Run the time-box test in local mode too (it applies to both).

- [ ] **Step 2: Run them, confirm they fail**

Run: `php artisan test --compact tests/Feature/ImportWizard/Jobs/ExecuteImportJobCoreTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

- `$store = ImportStore::forExecution($this->importId);` replaces `load()`. Wrap everything after it in `try { ... } finally { $store->close(); }`.
- Record `$startedAt = hrtime(true)` at the top of `runImport()`.
- In the `chunkById` callback, after `flushFailedRows($import)` and before `persistResults()`: `$store->persist();`. After `persistResults()`: `if ($this->timeBoxExpired($startedAt)) { $handedOff = true; return false; }`.
- After `withCauser(...)` returns: if `$handedOff`, skip the completion update, summary, and notification. `runImport()` returns `bool $handedOff`. `handle()` dispatches the continuation only after `CurrentSource::during()` returns, so it never fires from a `finally` on an exception and, under the sync queue, never runs inline inside this job's `CurrentSource` or before `CurrentImport::clear()`:

```php
public function handle(): void
{
    if (CurrentSource::during(CreationSource::IMPORT, $this->runImport(...))) {
        $this->handOff();
    }
}

private function handOff(): void
{
    $next = new self($this->importId, $this->workspaceId);

    $this->batch() instanceof Batch
        ? $this->batch()->add([$next])
        : dispatch($next);
}

private function timeBoxExpired(int $startedAt): bool
{
    return (hrtime(true) - $startedAt) / 1e9 >= (int) config('import-wizard.execution_time_box', 240);
}
```

Add `'execution_time_box' => 240,` to `config/import-wizard.php`. `chunkById` over `processed = false` restarts cleanly in the next job because `processed` flags are in the uploaded snapshot.

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `php artisan test --compact tests/Feature/ImportWizard/Jobs tests/Feature/ActivityLog/ImportActivityTest.php`
Expected: PASS.

- [ ] **Step 5: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse
git add packages/ImportWizard tests
git commit -m "feat(imports): run imports from a locked store copy in time-boxed jobs"
```

### Task 4: Livewire steps

**Files:**
- Modify: `packages/ImportWizard/src/Livewire/Concerns/WithImportStore.php:47-54`
- Modify: `packages/ImportWizard/src/Livewire/Steps/ReviewStep.php:58-61,90-106,140-143,300-380`
- Create: `packages/ImportWizard/resources/lang/en/store.php`
- Test: `tests/Feature/ImportWizard/Livewire/ReviewStepTest.php`, `tests/Feature/ImportWizard/Livewire/PreviewStepTest.php`, `tests/Feature/ImportWizard/Livewire/MappingStepTest.php`

**Interfaces:**
- Consumes: `forRead`, `withWriteLock`, `ImportStoreException`.
- Produces: `WithImportStore::store(): ImportStore` (read), `WithImportStore::writeStore(Closure $mutator): bool` (true when written).

- [ ] **Step 1: Write the failing tests**

`ReviewStepTest.php`, `describe('on a remote store disk', ...)` seeding through the file's existing helper plus `persist()`/`close()`. `/* mount ... */` below means: call the mount helper `ReviewStepTest.php` already defines, with those rows.

```php
it('saves a correction to the remote store and renders it in the same request', function (): void {
    $component = /* mount ReviewStep as the file does, email column with 'bad-email' */;

    $component->call('updateMappedValue', 'bad-email', 'ada@example.com');

    expect(ImportStore::forRead($this->import->id)->query()->first()->corrections)->toContain('ada@example.com');
    $component->assertSee('ada@example.com');
});

it('tells the user to retry when the store stays locked', function (): void {
    $component = /* mount as above */;
    Cache::lock("import-store:{$this->import->id}", 150)->get();
    config()->set('import-wizard.store.lock.wait.web', 0);

    $component->call('skipValue', 'bad-email')->assertNotified(__('import-wizard::store.busy'));

    expect(ImportStore::forRead($this->import->id)->query()->first()->skipped)->toBeNull();
});

it('keeps a correction when the column validates again after it', function (): void {
    $component = /* mount on an email column seeded with rows 'bad-email' and 'also-bad' */;

    $component->call('updateMappedValue', 'bad-email', 'ada@example.com');
    new ValidateColumnJob($this->import->id, $this->import->getColumnMapping('email'))->handle();

    $rows = ImportStore::forRead($this->import->id)->query()->orderBy('row_number')->get();
    expect($rows[0]->corrections)->toContain('ada@example.com')
        ->and($rows[0]->validation)->toBeNull()
        ->and($rows[1]->validation)->not->toBeNull();
});
```

`PreviewStepTest.php`: remote test that `startImport` with `execution_time_box = 0` on 501 rows leaves the batch unfinished after the first job and completes the import once the added job runs (sync queue runs it inline; assert `status` Completed and all 501 rows processed).

`MappingStepTest.php`: remote test that `previewValues('email')` returns the seeded values.

- [ ] **Step 2: Run them, confirm they fail**

Run: `php artisan test --compact tests/Feature/ImportWizard/Livewire`
Expected: the remote tests FAIL with a 404, because `store()` still calls `load()`, which returns null in remote mode. Local tests pass.

- [ ] **Step 3: Implement**

`packages/ImportWizard/resources/lang/en/store.php`:

```php
return [
    'busy' => 'This import is still saving changes. Try again in a moment.',
];
```

Check how the package registers its `validation.php` lang namespace (`import-wizard::`) in its service provider and use the same namespace.

`WithImportStore`:

```php
protected function store(): ImportStore
{
    $store = $this->store ??= ImportStore::forRead($this->storeId);

    abort_if(! $store instanceof ImportStore, 404, 'Import session not found or expired.');

    return $store;
}

protected function writeStore(Closure $mutator): bool
{
    $this->store?->close();
    $this->store = null;

    try {
        ImportStore::withWriteLock($this->storeId, $mutator, (int) config('import-wizard.store.lock.wait.web'));
    } catch (ImportStoreException $e) {
        abort_if($e->isNotFound(), 404, 'Import session not found or expired.');

        Notification::make()->title(__('import-wizard::store.busy'))->warning()->send();

        return false;
    }

    return true;
}
```

`ReviewStep`: delete `connection()`. Each public write method validates first (outside the lock), then does all its SQL in one `writeStore()` closure, then dispatches any revalidation after the closure returns and only when it returned true:

```php
public function skipValue(string $rawValue): void
{
    $jsonPath = $this->selectedColumnJsonPath();
    $error = $this->validateValue($this->selectedColumn, $rawValue, isCorrection: false);

    $written = $this->writeStore(function (ImportStore $store) use ($jsonPath, $rawValue, $error): void {
        $store->connection()->statement("
            UPDATE import_rows
            SET skipped = json_set(COALESCE(skipped, '{}'), ?, json('true')),
                corrections = json_remove(corrections, ?)
            WHERE json_extract(raw_data, ?) = ?
        ", [$jsonPath, $jsonPath, $jsonPath, $rawValue]);

        $this->updateValidationForRawValue($store->connection(), $jsonPath, $rawValue, $error);
    });

    if (! $written) {
        return;
    }

    $this->revalidateEntityLinkColumn();

    unset($this->columnErrorStatuses);
}
```

Apply the same shape to `updateMappedValue()` (return `[]` when not written), `undoCorrection()`, `unskipValue()`, and `clearRelationshipsForReentry()` (called from `mount()` before dispatching validation). `updateValidationForRawValue()` takes the `Connection` as its first parameter. `MappingStep` and `PreviewStep` only read and need no change beyond what `store()` now returns; confirm with `grep -n "statement(\|->update(\|->insert(" packages/ImportWizard/src/Livewire/Steps/{MappingStep,PreviewStep}.php` that neither writes.

- [ ] **Step 4: Run the tests, confirm they pass**

Run: `php artisan test --compact tests/Feature/ImportWizard/Livewire`
Expected: PASS, local and remote.

- [ ] **Step 5: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse
git add packages/ImportWizard tests
git commit -m "feat(imports): read the wizard from a store copy and write under its lock"
```

### Task 5: Remove the old store API

**Files:**
- Modify: `packages/ImportWizard/src/Store/ImportStore.php` (delete `load()`, `path()`, `sqlitePath()` as public, `destroy()`)
- Modify: every test that calls them (20 `ImportStore::load` sites; list with `grep -rn "ImportStore::load\|->destroy()" tests packages app | grep "\.php:"`)

- [ ] **Step 1: Migrate callers**

- `ImportStore::load($id)?->destroy();` in `afterEach` blocks becomes `ImportStore::delete($id);`.
- `ImportStore::load($id)` used for assertions becomes `ImportStore::forRead($id)`.
- `expect(ImportStore::load($id))->toBeNull()` becomes `expect(ImportStore::forRead($id))->toBeNull()`.
- `tests/Browser/Chat/ComposerAttachmentTest.php:21` is in the Browser suite; migrate it too.

- [ ] **Step 2: Delete the old methods, make `sqlitePath()` private**

- [ ] **Step 3: Run everything that touches imports**

Run: `php artisan test --compact tests/Feature/ImportWizard tests/Feature/Chat tests/Feature/ActivityLog tests/Arch` and `php artisan test --compact tests/Browser/Chat/ComposerAttachmentTest.php`
Expected: PASS.

- [ ] **Step 4: Gates and commit**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/rector --dry-run && vendor/bin/phpstan analyse && composer test:type-coverage
git add packages/ImportWizard tests
git commit -m "refactor(imports): drop the local-only import store api"
```

### Task 6: Finalize

- [ ] **Step 1: Env docs.** Add `IMPORT_STORE_DISK=` (empty) to `.env.example` next to `MEDIA_DISK`, matching how part 1 documented `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`.
- [ ] **Step 2: Rule.** Add one bullet to `.ai/rules/file-uploads.md`: import store writes go through `ImportStore::withWriteLock()`, never a job dispatch inside the closure. Keep it to two lines.
- [ ] **Step 3: Full gate.** `composer test:lint`, `vendor/bin/phpstan analyse`, `composer test:type-coverage`, `composer test:pest:full`.
- [ ] **Step 4: Browser, local mode.** With Horizon on `QUEUE_CONNECTION=redis`, walk People import: upload, map, review (correct one value, skip one), preview, import. Screenshot each step.
- [ ] **Step 5: Browser, remote mode.** Set `IMPORT_STORE_DISK` to a local `s3`-shaped disk (MinIO if available, otherwise a second `local`-driver disk rooted outside `storage/app/imports`), repeat step 4, and confirm `storage/app/imports` stays empty.
- [ ] **Step 6: Commit and push the branch.** PR body drafted for approval, not posted.
