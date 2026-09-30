<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Closure;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Exceptions\ImportStoreException;
use Throwable;

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
        $checksum = (string) self::disk()->checksum($remotePath);

        if (! File::exists("{$directory}/data.sqlite") || rescue(fn (): string => File::get("{$directory}/checksum"), '', report: false) !== $checksum) {
            File::ensureDirectoryExists($directory);
            $download = "{$directory}/".Str::ulid().'.download';
            self::download($importId, $remotePath, $download);
            rename($download, "{$directory}/data.sqlite");
            File::put("{$directory}/checksum", $checksum);
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
        $lock = self::lock(self::lockName($importId), (int) config('import-wizard.store.lock.ttl'));
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

        $lock = self::lock(self::lockName($importId), (int) config('import-wizard.store.lock.execution_ttl'), "execute:{$importId}");

        if (! $lock->get()) {
            $lock->release();
            self::block($lock, $importId, (int) config('import-wizard.store.lock.wait.job'));
        }

        try {
            return self::downloadForWrite($importId, $lock);
        } catch (ImportStoreException $e) {
            return $e->isNotFound() ? null : throw $e;
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

    public static function load(string $importId): ?self
    {
        return self::isRemote() ? null : self::forLocalWrite($importId);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function path(): string
    {
        return $this->directory;
    }

    public function sqlitePath(): string
    {
        return $this->directory.'/data.sqlite';
    }

    public function connectionName(): string
    {
        return $this->readOnly ? "import_read_{$this->id}" : "import_{$this->id}";
    }

    public function connection(): Connection
    {
        return $this->connection ??= $this->createConnection();
    }

    /** @return EloquentBuilder<ImportRow> */
    public function query(): EloquentBuilder
    {
        $this->connection();

        return ImportRow::on($this->connectionName());
    }

    public function ensureProcessedColumn(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasColumn('import_rows', 'processed')) {
            return;
        }

        $schema->table('import_rows', function (Blueprint $table): void {
            $table->boolean('processed')->default(false);
        });
    }

    /**
     * @param  array<string, int|string|null>  $resolvedMap
     */
    public function bulkUpdateMatches(string $jsonPath, array $resolvedMap, RowMatchAction $unmatchedAction): void
    {
        $connection = $this->connection();

        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_match_results (
                lookup_value TEXT,
                match_action TEXT,
                matched_id TEXT
            )
        ');

        try {
            $inserts = collect($resolvedMap)
                ->map(fn (int|string|null $id, int|string $value): array => [
                    'lookup_value' => $value,
                    'match_action' => $id !== null ? RowMatchAction::Update->value : $unmatchedAction->value,
                    'matched_id' => $id !== null ? (string) $id : null,
                ])
                ->values()
                ->all();

            if ($inserts === []) {
                return;
            }

            foreach (array_chunk($inserts, 5000) as $chunk) {
                $connection->table('temp_match_results')->insert($chunk);
            }

            $connection->statement('
                UPDATE import_rows
                SET match_action = temp.match_action,
                    matched_id = temp.matched_id
                FROM temp_match_results AS temp
                WHERE json_extract(import_rows.raw_data, ?) = temp.lookup_value
                  AND import_rows.match_action IS NULL
            ', [$jsonPath]);
        } finally {
            $connection->statement('DROP TABLE IF EXISTS temp_match_results');
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

    public function destroy(): void
    {
        $this->close();

        self::delete($this->id);
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

        try {
            File::ensureDirectoryExists($store->directory);
            self::download($importId, $remotePath, $store->sqlitePath());
        } catch (Throwable $e) {
            $store->close();

            throw $e;
        }

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

    private static function lock(string $name, int $seconds, ?string $owner = null): Lock
    {
        $lock = Cache::lock($name, $seconds, $owner);

        assert($lock instanceof Lock);

        return $lock;
    }

    private static function download(string $importId, string $remotePath, string $localPath): void
    {
        $source = self::disk()->readStream($remotePath);
        $target = fopen($localPath, 'wb');

        try {
            throw_unless(
                is_resource($source) && is_resource($target) && stream_copy_to_stream($source, $target) !== false,
                ImportStoreException::snapshotFailed($importId, 'download failed'),
            );
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            if (is_resource($target)) {
                fclose($target);
            }
        }
    }

    private static function forLocalWrite(string $importId): ?self
    {
        if (! Str::isUlid($importId)) {
            return null;
        }

        return File::exists(self::localDirectory($importId).'/data.sqlite')
            ? new self($importId, self::localDirectory($importId))
            : null;
    }

    private static function disk(): FilesystemAdapter
    {
        return Storage::disk((string) config('import-wizard.store.disk'));
    }

    private static function remotePath(string $importId): string
    {
        return "imports/{$importId}.sqlite";
    }

    private static function lockName(string $importId): string
    {
        return "import-store:{$importId}";
    }

    private static function localDirectory(string $importId): string
    {
        return config('import-wizard.storage_path')."/{$importId}";
    }

    private static function readCacheDirectory(string $importId): string
    {
        return config('import-wizard.store.read_cache_path')."/{$importId}";
    }

    private static function temporaryDirectory(string $importId): string
    {
        return sys_get_temp_dir()."/import-{$importId}-".Str::ulid();
    }

    private function createTableSafely(): void
    {
        $schema = $this->connection()->getSchemaBuilder();

        if ($schema->hasTable('import_rows')) {
            return;
        }

        $schema->create('import_rows', function (Blueprint $table): void {
            $table->integer('row_number')->primary();
            $table->text('raw_data');
            $table->text('validation')->nullable();
            $table->text('corrections')->nullable();
            $table->text('skipped')->nullable();
            $table->string('match_action')->nullable();
            $table->string('matched_id')->nullable();
            $table->text('relationships')->nullable();
            $table->boolean('processed')->default(false);
        });

        $this->connection()->statement('
            CREATE TRIGGER validate_raw_data_insert
            BEFORE INSERT ON import_rows
            BEGIN
                SELECT CASE
                    WHEN NEW.raw_data IS NULL OR NEW.raw_data = \'\' OR NEW.raw_data = \'{}\'
                    THEN RAISE(ABORT, \'raw_data cannot be null or empty\')
                END;
            END
        ');

        $schema->table('import_rows', function (Blueprint $table): void {
            $table->index('validation');
            $table->index('match_action');
            $table->index('skipped');
        });
    }
}
