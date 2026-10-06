# Laravel Cloud Readiness, Part 1: Platform-Agnostic Storage Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use sdd-lean (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every runtime file read and write works when web replicas and queue workers have separate, ephemeral disks, while Forge keeps today's behaviour.

**Architecture:** Laravel Cloud's `CloudBootstrapper` replaces disk config by bucket disk name (`public`, `s3`). Code stops assuming a disk has local paths: files that a parser needs on disk are streamed to a private temp copy (`LocalCopy`), private user files follow `config('media-library.disk_name')`, and the sitemap lives on the `public` disk behind a route. Every default equals today's value.

**Tech Stack:** Laravel 13, PHP 8.5, Filament v5, Livewire v4, spatie/laravel-medialibrary, Pest v5, league/flysystem-aws-s3-v3.

**Spec:** `docs/superpowers/specs/2026-09-28-laravel-cloud-readiness-design.md` (part 1). Tracking issue: #858.

## Global Constraints

- Forge sees no behaviour change: every new env var defaults to today's value, and `MEDIA_DISK` stays `local` by default.
- The `local` disk is never remapped to a bucket; it holds build artifacts (`storage/app/scribe/openapi.yaml`).
- Cloud bucket disk names are exactly `public` (public bucket) and `s3` (private bucket).
- PostgreSQL only. No `down()` in migrations (none planned here).
- Dates: never name the mutable `Carbon` class.
- No comments in tests. Production comments only for a non-obvious why, 2 lines max.
- No em-dash (U+2014) in any file, commit, or PR text.
- No new PHPStan ignores. Type coverage stays at 100%: every parameter and return typed, closures included.
- Tests go through real entry points (HTTP routes, Livewire components, MCP tools, commands). No isolated unit tests of internals.
- Each test file declares `mutates(...)` for the classes it covers; extend the existing `mutates()` list when a task adds a covered class.
- Before each commit: `vendor/bin/pint --dirty --format agent`.

## Review Focus

1. A file on a disk whose `path()` points nowhere (real S3 behaviour) must never reach a `file_get_contents`, `is_file`, `finfo`, or CSV reader call. Pinned by the `fakeDiskWithoutLocalPaths()` tests in Tasks 3, 4, and 5.
2. Logos and other `public`-disk media must keep plain, cacheable URLs after Cloud strips `visibility` from the disk config. Pinned in Task 2.
3. A temp copy must be deleted even when the parser throws (a header-only CSV). Task 3 pins that the import still answers 422 on a bucket disk. Deletion itself rests on `LocalCopy`'s `finally`: a temp-dir glob assertion would race parallel test workers, so the reviewer checks the `finally` block by reading it.
4. Staged MCP uploads written by one request and consumed by a later one must land on the same shared disk. Pinned in Task 4.
5. The stale `public/sitemap.xml` on the Forge box would shadow the new route (nginx serves static files first). Not testable in CI: the PR body must carry the one-time deploy step `rm -f public/sitemap.xml` (Task 7, Step 7).

---

### Task 1: S3 driver and a disk-without-local-paths test helper

**Files:**
- Modify: `composer.json`, `composer.lock` (via composer)
- Modify: `tests/Pest.php` (add helper next to `pdfBytes()` at ~line 118)
- Test: `tests/Feature/Media/MediaStorageTest.php`

**Interfaces:**
- Produces: global test helper `fakeDiskWithoutLocalPaths(string $disk): Illuminate\Filesystem\FilesystemAdapter`. It fakes the disk, then swaps in an adapter whose `path()` returns `/nonexistent-remote-disk/<key>`, mimicking S3, where `path()` returns only the object key. Reads and writes through the disk API keep working.

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/Media/MediaStorageTest.php`, add `use League\Flysystem\AwsS3V3\AwsS3V3Adapter;`)

```php
it('resolves the s3 disk laravel cloud configures for a bucket', function (): void {
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'key',
        'secret' => 'secret',
        'bucket' => 'relaticle-private',
        'url' => 'https://files.example.test',
        'endpoint' => 'https://account.r2.cloudflarestorage.com',
        'region' => 'auto',
        'use_path_style_endpoint' => false,
        'throw' => false,
        'report' => false,
    ]);
    Storage::forgetDisk('s3');

    expect(Storage::disk('s3')->getAdapter())->toBeInstanceOf(AwsS3V3Adapter::class);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact --filter='resolves the s3 disk laravel cloud configures'`
Expected: FAIL with a missing `League\Flysystem\AwsS3V3` class error.

- [ ] **Step 3: Install the driver**

Run: `composer require league/flysystem-aws-s3-v3:"^3.0" --no-interaction`

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact --filter='resolves the s3 disk laravel cloud configures'`
Expected: PASS

- [ ] **Step 5: Add the helper to `tests/Pest.php`** (add `use Illuminate\Filesystem\FilesystemAdapter;` and `use Illuminate\Support\Facades\Storage;` if absent)

```php
function fakeDiskWithoutLocalPaths(string $disk): FilesystemAdapter
{
    $fake = Storage::fake($disk);

    $remote = new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
    {
        public function path(mixed $path): string
        {
            return '/nonexistent-remote-disk/'.ltrim((string) $path, '/');
        }
    };

    Storage::set($disk, $remote);

    return $remote;
}
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock tests/Pest.php tests/Feature/Media/MediaStorageTest.php
git commit -m "chore: install the s3 filesystem driver for bucket-backed disks"
```

---

### Task 2: Public media URLs by disk name

**Files:**
- Modify: `app/Support/Media/MediaUrlGenerator.php:19`
- Test: `tests/Feature/Media/PrivateDiskTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `MediaUrlGenerator::getUrl()` returns a plain URL for any media row whose `disk` is `public`, regardless of `filesystems.disks.public.visibility`.

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/Media/PrivateDiskTest.php`)

```php
it('keeps plain public urls when laravel cloud replaces the public disk config', function (): void {
    usePublicMediaDisk();
    config()->set('filesystems.disks.public.visibility', null);

    $media = uploadPdf($this->user);

    expect($media->disk)->toBe('public')
        ->and($media->getUrl())->not->toContain('signature=')
        ->and($media->getUrl())->not->toContain('/media/'.$media->uuid);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Media/PrivateDiskTest.php --filter='laravel cloud replaces the public disk'`
Expected: FAIL, the URL contains `signature=`.

- [ ] **Step 3: Implement** in `app/Support/Media/MediaUrlGenerator.php`, replace the condition

```php
        if ($this->media->disk === 'public') {
            return SameOriginUrl::rewrite(parent::getUrl());
        }
```

- [ ] **Step 4: Run the file to verify it passes**

Run: `php artisan test --compact tests/Feature/Media/PrivateDiskTest.php`
Expected: PASS (all tests, including the existing public and private URL tests)

- [ ] **Step 5: Commit**

```bash
git add app/Support/Media/MediaUrlGenerator.php tests/Feature/Media/PrivateDiskTest.php
git commit -m "fix(media): serve public media by disk name so cloud buckets keep plain urls"
```

---

### Task 3: Chat CSV attachments follow the media disk

**Files:**
- Create: `app/Support/Media/LocalCopy.php`
- Modify: `packages/Chat/src/Models/AgentConversation.php:90-97`
- Modify: `packages/Chat/src/Support/ChatAttachment.php:77-80`
- Modify: `packages/Chat/src/Actions/ImportAttachment.php:57-66`
- Modify: `packages/Chat/src/Support/AttachedRows.php:55-61`
- Modify: `.ai/rules/file-uploads.md` (the `chat-attachments pins local` sentences)
- Test: `tests/Feature/Chat/ChatAttachmentTest.php`, `tests/Feature/Chat/ChatAttachmentSendTest.php`

**Interfaces:**
- Consumes: `fakeDiskWithoutLocalPaths()` (Task 1).
- Produces:
  - `App\Support\Media\LocalCopy::of(mixed $stream, Closure $callback): mixed` copies a readable stream to a `0600` file under `sys_get_temp_dir()` named `local-copy-<ulid>`, calls `$callback(string $path)`, returns its result, and always closes the stream and deletes the file. Throws `UploadException::notFound()` when `$stream` is not a resource.
  - `Relaticle\Chat\Support\ChatAttachment::withLocalFile(Closure $callback): mixed` replaces `absolutePath()`, which is removed.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Chat/ChatAttachmentTest.php`:

```php
it('stores and imports an attachment on a media disk with no local paths', function (): void {
    config()->set('media-library.disk_name', 's3');
    fakeDiskWithoutLocalPaths('s3');

    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(5)])->json('id');

    expect(Media::query()->where('uuid', $id)->firstOrFail()->disk)->toBe('s3');

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']))->assertRedirect();

    $import = Import::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
    $this->createdStoreIds[] = $import->id;

    expect($import->total_rows)->toBe(5)
        ->and($import->headers)->toBe(['Name', 'Email', 'Company']);
});

it('rejects a header-only attachment on a media disk with no local paths', function (): void {
    config()->set('media-library.disk_name', 's3');
    fakeDiskWithoutLocalPaths('s3');

    $id = $this->postJson(route('chat.attachments.store'), ['file' => csvUpload(2)])->json('id');
    $media = Media::query()->where('uuid', $id)->firstOrFail();
    Storage::disk('s3')->put($media->getPathRelativeToRoot(), "Name,Email\n");

    $this->get(route('chat.attachments.import', ['attachment' => $id, 'entity' => 'people']))->assertStatus(422);

    expect(Import::query()->where('workspace_id', $this->workspace->getKey())->exists())->toBeFalse();
});
```

Append to `tests/Feature/Chat/ChatAttachmentSendTest.php`:

```php
it('inlines rows from an attachment on a media disk with no local paths', function (): void {
    config()->set('media-library.disk_name', 's3');
    fakeDiskWithoutLocalPaths('s3');
    Queue::fake();
    $attachmentId = attachCsv(2);

    $this->postJson(route('chat.send', ['conversation' => $this->conversationId]), [
        'document' => ChatDocument::fromText('Here are my contacts'),
        'attachment_id' => $attachmentId,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => str_contains($job->message, 'Attached file "contacts.csv" (2 rows)')
        && str_contains($job->message, "```\n"));
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Chat/ChatAttachmentTest.php tests/Feature/Chat/ChatAttachmentSendTest.php --filter='no local paths'`
Expected: the two `ChatAttachmentTest` tests FAIL because the media disk is `local` (the collection pins it). The send test passes for the same reason; it goes red after Step 3.

- [ ] **Step 3: Unpin the collection** in `packages/Chat/src/Models/AgentConversation.php`, delete the two comment lines above `registerMediaCollections()` and the `->useDisk('local')` call:

```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ATTACHMENTS_MEDIA_COLLECTION)
            ->acceptsMimeTypes(self::ATTACHMENT_MIME_TYPES);
    }
```

Run the Step 2 command again. Expected: all three FAIL, now because the CSV readers get `/nonexistent-remote-disk/...`.

- [ ] **Step 4: Create `app/Support/Media/LocalCopy.php`**

```php
<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Exceptions\UploadException;
use Closure;
use Illuminate\Support\Str;

final readonly class LocalCopy
{
    /**
     * @template TResult
     *
     * @param  resource|null  $stream
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public static function of(mixed $stream, Closure $callback): mixed
    {
        throw_unless(is_resource($stream), UploadException::notFound());

        $path = sys_get_temp_dir().'/local-copy-'.Str::ulid();

        try {
            touch($path);
            chmod($path, 0600);
            file_put_contents($path, $stream);

            return $callback($path);
        } finally {
            fclose($stream);
            @unlink($path);
        }
    }
}
```

- [ ] **Step 5: Replace `absolutePath()`** in `packages/Chat/src/Support/ChatAttachment.php` (add `use App\Support\Media\LocalCopy;` and `use Closure;`):

```php
    /**
     * @template TResult
     *
     * @param  Closure(string): TResult  $callback
     * @return TResult
     */
    public function withLocalFile(Closure $callback): mixed
    {
        return LocalCopy::of(
            Storage::disk($this->media->disk)->readStream($this->media->getPathRelativeToRoot()),
            $callback,
        );
    }
```

- [ ] **Step 6: Move both callers**

`packages/Chat/src/Actions/ImportAttachment.php`, inside the existing `try`:

```php
                $import = $locked->withLocalFile(fn (string $path): Import => $this->loader->load(
                    $path,
                    $locked->name(),
                    $entityType,
                    (string) $workspace->getKey(),
                    (string) $user->getKey(),
                ));
```

`packages/Chat/src/Support/AttachedRows.php`, in `block()`:

```php
        $rows = $attachment->withLocalFile(fn (string $path): array => SimpleExcelReader::create($path, 'csv')
            ->trimHeaderRow()
            ->getRows()
            ->reject(fn (array $row): bool => array_all($row, blank(...)))
            ->take(self::INLINE_ROW_LIMIT)
            ->map(fn (array $row): string => self::csvLine(array_values($row)))
            ->all());
```

- [ ] **Step 7: Run the Chat suites to verify they pass**

Run: `php artisan test --compact tests/Feature/Chat/ChatAttachmentTest.php tests/Feature/Chat/ChatAttachmentSendTest.php`
Expected: PASS (new and existing tests)

- [ ] **Step 8: Update the rule** in `.ai/rules/file-uploads.md`:
  - In the bullet starting ``- `logo` collections stay on the public disk``, replace ``and `chat-attachments` pins `local`. Everything else follows `MEDIA_DISK` `` with ``. Everything else, `chat-attachments` included, follows `MEDIA_DISK` ``.
  - In the chat CSV bullet, replace ``It is parsed by path and handed to the import wizard, never served, which is why it pins `local` and stays out of `pending-uploads` `` with ``It is parsed from a temp copy (`ChatAttachment::withLocalFile()`) and handed to the import wizard, never served, and it stays out of `pending-uploads` ``.
  - Add a bullet: ``A parser that needs a filesystem path reads through `App\Support\Media\LocalCopy`, never `Media::getPath()` or `getRealPath()`: on a bucket disk those name no local file.``

- [ ] **Step 9: Commit**

```bash
git add app/Support/Media/LocalCopy.php packages/Chat .ai/rules/file-uploads.md tests/Feature/Chat
git commit -m "feat(chat): keep csv attachments on the media disk and parse a temp copy"
```

---

### Task 4: MCP staged uploads on the media disk

**Files:**
- Modify: `app/Support/Media/TemporaryUploads.php:20-26`
- Test: `tests/Feature/Mcp/UploadToolsTest.php`

**Interfaces:**
- Consumes: `fakeDiskWithoutLocalPaths()` (Task 1).
- Produces: `TemporaryUploads::disk()` returns `Storage::disk(config('media-library.disk_name'))`. `ReceiveUploadController`, `StoreAgentUpload::takeTemporary()`, and `PurgePendingUploadsCommand` already stream through it, so they need no change.

- [ ] **Step 1: Write the failing test** (inside the `describe('StoreAgentUpload', ...)` block of `tests/Feature/Mcp/UploadToolsTest.php`)

```php
    it('moves a signed-put temp file staged on a media disk with no local paths', function (): void {
        config()->set('media-library.disk_name', 's3');
        fakeDiskWithoutLocalPaths('s3');
        $name = TemporaryUploads::newName('report.pdf', (string) $this->workspace->getKey());
        Storage::disk('s3')->put(TemporaryUploads::path($name), pdfBytes());

        $media = resolve(StoreAgentUpload::class)->execute($this->user, $this->workspace, ['upload_id' => $name]);

        expect($media->disk)->toBe('s3')
            ->and($media->getCustomProperty('source'))->toBe('signed_put');
        Storage::disk('s3')->assertMissing(TemporaryUploads::path($name));
    });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Mcp/UploadToolsTest.php --filter='staged on a media disk'`
Expected: FAIL with `UploadException` (not found), because staging reads the `local` disk.

- [ ] **Step 3: Implement** in `app/Support/Media/TemporaryUploads.php`

```php
    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(config('media-library.disk_name'));

        return $disk;
    }
```

- [ ] **Step 4: Run the file to verify it passes**

Run: `php artisan test --compact tests/Feature/Mcp/UploadToolsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Support/Media/TemporaryUploads.php tests/Feature/Mcp/UploadToolsTest.php
git commit -m "feat(mcp): stage signed uploads on the media disk"
```

---

### Task 5: Livewire temporary uploads on a configurable disk

**Files:**
- Modify: `config/livewire.php`
- Modify: `.env.example` (after `MEDIA_DISK=local`, line 79)
- Modify: `app/Support/Media/RichContentAttachments.php:58-72`
- Modify: `packages/ImportWizard/src/Livewire/Steps/UploadStep.php:98-99` and `:126-132`
- Test: `tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php`, `tests/Feature/ImportWizard/Livewire/UploadStepTest.php`

**Interfaces:**
- Consumes: `LocalCopy::of()` (Task 3), `fakeDiskWithoutLocalPaths()` (Task 1).
- Produces: `config('livewire.temporary_file_upload.disk')` reads `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` (default null, so Livewire falls back to `filesystems.default` as today). No code reads `TemporaryUploadedFile::getRealPath()` any more.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php`:

```php
it('saves a pasted image when temporary uploads live on a disk with no local paths', function (): void {
    fakeDiskWithoutLocalPaths(FileUploadConfiguration::disk());
    $editor = noteBodyEditor();

    $id = $editor->saveUploadedFileAttachment(livewireTemporaryUpload(onePixelPng(), 'shot.png'));

    expect(Media::query()->where('uuid', $id)->firstOrFail()->name)->toBe('shot.png');
});
```

Append to `tests/Feature/ImportWizard/Livewire/UploadStepTest.php` (add `use Livewire\Features\SupportFileUploads\FileUploadConfiguration;`):

```php
it('parses and loads a csv when temporary uploads live on a disk with no local paths', function (): void {
    fakeDiskWithoutLocalPaths(FileUploadConfiguration::disk());
    $csv = makeCsvFile("Name,Email\nJohn,john@test.com\nJane,jane@test.com\n");

    $component = mountUploadStep($this);
    $component->set('uploadedFile', $csv);

    expect($component->get('isParsed'))->toBeTrue()
        ->and($component->get('rowCount'))->toBe(2);

    $component->call('continueToMapping')->assertHasNoErrors();

    $import = Import::query()->where('workspace_id', $this->workspace->getKey())->firstOrFail();
    $this->createdStoreIds[] = $import->id;

    expect($import->total_rows)->toBe(2);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php tests/Feature/ImportWizard/Livewire/UploadStepTest.php --filter='disk with no local paths'`
Expected: both FAIL. The rich-editor save throws a `ValidationException` on `attachment` (from `UploadException::notFound()`), and the upload step reports "Unable to process this file". Both read `getRealPath()`, which names no local file.

- [ ] **Step 3: Implement `RichContentAttachments::saveUploadedFileAttachment()`** (`LocalCopy` shares the `App\Support\Media` namespace, so no import):

```php
        try {
            return LocalCopy::of(
                $file->readStream(),
                fn (string $path): string => resolve(StorePendingUpload::class)
                    ->execute($user, $workspace, $path, $file->getClientOriginalName(), UploadSource::Panel)
                    ->uuid,
            );
        } catch (UploadException $exception) {
```

- [ ] **Step 4: Implement `UploadStep`** (add `use App\Support\Media\LocalCopy;`)

In `validateFile()`:

```php
            ['headers' => $this->headers, 'row_count' => $this->rowCount] = LocalCopy::of(
                $this->uploadedFile->readStream(),
                fn (string $path): array => resolve(ImportFileLoader::class)->inspect($path),
            );
```

In `continueToMapping()`:

```php
            $this->import = LocalCopy::of(
                $this->uploadedFile->readStream(),
                fn (string $path): Import => resolve(ImportFileLoader::class)->load(
                    $path,
                    $this->uploadedFile->getClientOriginalName(),
                    $this->entityType,
                    $workspaceId,
                    (string) auth()->id(),
                ),
            );
```

- [ ] **Step 5: Make the disk configurable**

`config/livewire.php`, add after the `payload` entry:

```php
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
    ],
```

`.env.example`, after `MEDIA_DISK=local`:

```
LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=
```

- [ ] **Step 6: Sweep for remaining path readers**

Run: `rg -n "getRealPath\(\)|->getPath\(\)|absolutePath\(\)" app packages -g '*.php'`
Expected: no hit reads a `TemporaryUploadedFile` or a `Media` row. Any hit that does gets the same `LocalCopy::of()` treatment plus a test in its entry point's test file. `StoreChatAttachment` reading an `Illuminate\Http\UploadedFile` from a multipart request is fine: that file is always local to the receiving replica.

- [ ] **Step 7: Run both files to verify they pass**

Run: `php artisan test --compact tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php tests/Feature/ImportWizard/Livewire/UploadStepTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add config/livewire.php .env.example app/Support/Media/RichContentAttachments.php packages/ImportWizard/src/Livewire/Steps/UploadStep.php tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php tests/Feature/ImportWizard/Livewire/UploadStepTest.php
git commit -m "feat(uploads): read livewire temporary uploads through their disk"
```

---

### Task 6: CRM exports on a non-local Filament disk

**Files:**
- Test: `tests/Feature/Filament/App/Exports/PeopleExporterTest.php`

**Interfaces:**
- Consumes: `fakeDiskWithoutLocalPaths()` (Task 1). No production change: `FILAMENT_FILESYSTEM_DISK` is already env-driven. This pins that Cloud's `FILAMENT_FILESYSTEM_DISK=s3` keeps exports working end to end.

- [ ] **Step 1: Write the test**

```php
test('exports and downloads through a filament disk with no local paths', function () {
    config()->set('filament.default_filesystem_disk', 's3');
    fakeDiskWithoutLocalPaths('s3');

    Livewire::test(ListPeople::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->file_disk)->toBe('s3');

    $this->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
        ->assertOk();
});
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/Filament/App/Exports/PeopleExporterTest.php --filter='filament disk with no local paths'`
Expected: PASS. If it fails, stop and report the failure: it means a Filament export path reads a local path, which the spec does not yet cover.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Filament/App/Exports/PeopleExporterTest.php
git commit -m "test(exports): pin exports on a bucket-backed filament disk"
```

---

### Task 7: Sitemap on the public disk, served by a route

**Files:**
- Modify: `app/Console/Commands/GenerateSitemapCommand.php:39`
- Modify: `routes/web.php` (next to the `/.well-known/security.txt` route, ~line 119)
- Test: `tests/Feature/Commands/GenerateSitemapCommandTest.php`

**Interfaces:**
- Produces: `app:generate-sitemap` writes `sitemap.xml` at the root of the `public` disk. Route `sitemap` (`GET /sitemap.xml`) streams it as `application/xml`, 404 when absent.

- [ ] **Step 1: Rework the test file to read the disk**

In `tests/Feature/Commands/GenerateSitemapCommandTest.php`:
- Replace the `beforeEach` sitemap lines and delete the whole `afterEach` block:

```php
beforeEach(function (): void {
    fakeSitemapCrawl([config('app.url') => '<html><body></body></html>']);

    Storage::fake('public');
});
```

- Replace every `File::get($this->sitemap)` with `Storage::disk('public')->get('sitemap.xml')`.
- Replace `use Illuminate\Support\Facades\File;` with `use Illuminate\Support\Facades\Storage;` (keep `File` only if another line still uses it).
- The `beforeEach` comment line above `fakeSitemapCrawl` is dropped with the rewrite.

Append:

```php
it('serves the generated sitemap from the public disk', function (): void {
    $this->artisan('app:generate-sitemap')->assertSuccessful();

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(route('help.index'), false);
});

it('returns not found before the sitemap is generated', function (): void {
    $this->get('/sitemap.xml')->assertNotFound();
});
```

- [ ] **Step 2: Run the file to verify it fails**

Run: `php artisan test --compact tests/Feature/Commands/GenerateSitemapCommandTest.php`
Expected: FAIL. The command still writes `public_path('sitemap.xml')`, so the disk has no file, and `/sitemap.xml` has no route.

- [ ] **Step 3: Write to the disk** in `GenerateSitemapCommand::handle()`

```php
        $sitemap->writeToDisk('public', 'sitemap.xml');
```

- [ ] **Step 4: Add the route** in `routes/web.php`, after the `securityTxt` route (add `use Illuminate\Support\Facades\Storage;` if absent)

```php
Route::get('/sitemap.xml', function (): Response {
    $disk = Storage::disk('public');

    abort_unless($disk->exists('sitemap.xml'), Response::HTTP_NOT_FOUND);

    return response((string) $disk->get('sitemap.xml'), Response::HTTP_OK, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('sitemap');
```

- [ ] **Step 5: Run the file to verify it passes**

Run: `php artisan test --compact tests/Feature/Commands/GenerateSitemapCommandTest.php`
Expected: PASS

- [ ] **Step 6: Check the route is reachable on the marketing host**

Run: `php artisan route:list --path=sitemap`
Expected: one `GET|HEAD sitemap.xml` row named `sitemap`, with no domain restriction that excludes the marketing host (compare with the `securityTxt` row).

- [ ] **Step 7: Commit, and record the Forge deploy step**

```bash
git add app/Console/Commands/GenerateSitemapCommand.php routes/web.php tests/Feature/Commands/GenerateSitemapCommandTest.php
git commit -m "feat(seo): serve the sitemap from the public disk"
```

Record for the PR body: after deploy, run `rm -f public/sitemap.xml` once on the Forge box, then `php artisan app:generate-sitemap`, or nginx keeps serving the stale static file.

---

### Task 8: Env-tunable Horizon process counts

**Files:**
- Modify: `config/horizon.php:243-293` (production `supervisor-1`, `supervisor-2`, `supervisor-3`, `supervisor-imports`)
- Modify: `.env.example` (after `QUEUE_CONNECTION=database`, line 80)

**Interfaces:**
- Produces: env vars `HORIZON_DEFAULT_MIN` (1), `HORIZON_DEFAULT_MAX` (10), `HORIZON_IMPORTS_MIN` (3), `HORIZON_IMPORTS_MAX` (15). Defaults equal today's values.

This is configuration with unchanged defaults, so it gets a command check rather than a test.

- [ ] **Step 1: Implement** in the `production` block of `config/horizon.php`

In each of `supervisor-1`, `supervisor-2`, `supervisor-3`:

```php
                'maxProcesses' => env('HORIZON_DEFAULT_MAX', 10),
                'minProcesses' => env('HORIZON_DEFAULT_MIN', 1),
```

In `supervisor-imports`:

```php
                'maxProcesses' => env('HORIZON_IMPORTS_MAX', 15),
                'minProcesses' => env('HORIZON_IMPORTS_MIN', 3),
```

- [ ] **Step 2: Document** in `.env.example`, after `QUEUE_CONNECTION=database`

```
# Horizon process counts per supervisor. Every Laravel Cloud worker replica runs the full set.
# HORIZON_DEFAULT_MIN=1
# HORIZON_DEFAULT_MAX=10
# HORIZON_IMPORTS_MIN=3
# HORIZON_IMPORTS_MAX=15
```

- [ ] **Step 3: Verify defaults and overrides**

Run: `php artisan config:show horizon.environments.production.supervisor-imports`
Expected: `maxProcesses 15`, `minProcesses 3`.

Run: `HORIZON_IMPORTS_MIN=1 php artisan config:show horizon.environments.production.supervisor-imports`
Expected: `minProcesses 1`.

- [ ] **Step 4: Commit**

```bash
git add config/horizon.php .env.example
git commit -m "chore(horizon): make supervisor process counts tunable per environment"
```

---

### Task 9: Arch guard against local-disk assumptions

**Files:**
- Modify: `tests/Arch/ConventionsTest.php` (new test after `keeps new file uploads on medialibrary`, ~line 561)
- Modify: `packages/ImportWizard/src/Models/Import.php:126-129` (delete the unused `storagePath()`)

**Interfaces:**
- Produces: an arch test that fails on `Storage::disk('local')`, `Storage::disk('public')`, `->useDisk('local')`, `->useDisk('public')`, `public_path(`, or `storage_path(` in `app/` or `packages/*/src/`, outside a named allowlist.

- [ ] **Step 1: Confirm `Import::storagePath()` is dead, then delete it**

Run: `rg -n "storagePath\(\)" app packages tests -g '*.php'`
Expected: only the definition in `packages/ImportWizard/src/Models/Import.php`. Delete the method.

- [ ] **Step 2: Write the guard**

```php
it('keeps runtime file access off local-only disks and paths', function (): void {
    $root = dirname(__DIR__, 2);
    $allowed = [
        'app/Console/Commands/BackfillRichEditorAttachmentsCommand.php',
        'app/Console/Commands/InstallCommand.php',
        'app/Models/Company.php',
        'app/Models/Workspace.php',
        'app/Providers/AppServiceProvider.php',
        'app/Support/Media/RichContentAttachments.php',
        'packages/Documentation/src/Http/Controllers/OpenApiSpecController.php',
        'packages/ImportWizard/src/Commands/CleanupImportsCommand.php',
        'packages/ImportWizard/src/Store/ImportStore.php',
    ];
    $offenders = [];

    $directories = [$root.'/app', ...glob($root.'/packages/*/src', GLOB_ONLYDIR) ?: []];

    foreach ($directories as $directory) {
        $files = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/(?<!\.blade)\.php$/',
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());

            if (in_array($relative, $allowed, true)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match('/Storage::disk\([\'"](local|public)[\'"]\)|->useDisk\([\'"](local|public)[\'"]\)|\bpublic_path\(|\bstorage_path\(/', $source) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Runtime files go through a configurable disk so Laravel Cloud replicas share them (docs/superpowers/specs/2026-09-28-laravel-cloud-readiness-design.md). Offending files: '.implode(', ', $offenders),
    );
});
```

- [ ] **Step 3: Run it**

Run: `php artisan test --compact tests/Arch/ConventionsTest.php --filter='local-only disks'`
Expected: PASS. If a file outside the allowlist is reported, it is either a missed Cloud blocker (fix it with the Task 3 or Task 5 pattern, with a test) or a legitimate use (add it to `$allowed` and say why in the PR body). Never widen the regex to hide a hit.

- [ ] **Step 4: Prove it bites**

Temporarily add `Storage::disk('local');` inside any method of `app/Support/Media/LocalCopy.php`, run Step 3's command, and expect FAIL naming `app/Support/Media/LocalCopy.php`. Revert the line with `git checkout -- app/Support/Media/LocalCopy.php`.

- [ ] **Step 5: Commit**

```bash
git add tests/Arch/ConventionsTest.php packages/ImportWizard/src/Models/Import.php
git commit -m "test(arch): guard runtime file access against local-only disks"
```

---

### Task 10: Quality gates and PR

- [ ] **Step 1: Style and static analysis**

```bash
vendor/bin/pint --dirty --format agent
vendor/bin/rector --dry-run
vendor/bin/phpstan analyse --memory-limit=2G
composer test:type-coverage
composer test:lint
```

Expected: all clean. Apply rector suggestions with `vendor/bin/rector` and re-run if it proposes changes.

- [ ] **Step 2: Targeted suites**

```bash
php artisan test --compact tests/Feature/Media tests/Feature/Chat tests/Feature/Mcp/UploadToolsTest.php tests/Feature/Filament/App/Resources/RichEditorAttachmentTest.php tests/Feature/ImportWizard tests/Feature/Filament/App/Exports tests/Feature/Commands/GenerateSitemapCommandTest.php tests/Arch
```

Expected: PASS

- [ ] **Step 3: Full suite once**

Run: `composer test:pest:full`
Expected: PASS. This is the proof that Forge, running every default, is unchanged.

- [ ] **Step 4: Open the PR**

Title: `feat: make storage platform-agnostic for laravel cloud`. Body: link #858 and the spec; list the new env vars (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, `HORIZON_DEFAULT_MIN`, `HORIZON_DEFAULT_MAX`, `HORIZON_IMPORTS_MIN`, `HORIZON_IMPORTS_MAX`); state that Forge defaults are unchanged; include the Forge deploy step from Task 7 (`rm -f public/sitemap.xml` then `php artisan app:generate-sitemap`). Show the PR body to the user before creating it.
