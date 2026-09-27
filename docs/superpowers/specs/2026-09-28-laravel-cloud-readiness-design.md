# Laravel Cloud readiness

Date: 2026-09-28
Status: part 1 designed, parts 2 and 3 scoped

## Goal

Relaticle deploys cleanly to Laravel Cloud as a second target. Production stays on Forge
and sees no behaviour change. A later move to Cloud becomes a configuration change plus a
file copy, and self-hosters get a documented Laravel Cloud path.

"Ready" means proven on a real Cloud staging environment, not only free of known blockers.

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Target | Dual: Forge stays production, Cloud deploys cleanly | No cutover risk now; Cloud blockers get removed anyway |
| Storage | Env-driven disks; Forge keeps local disk | Forge unchanged; files move only at a real cutover |
| Queues on Cloud | Horizon as a background process on a worker cluster | Same `config/horizon.php` on both targets; queue clusters are SQS-backed, rule out Horizon, and are in developer preview |
| Staging deploys | Cloud auto-deploys every push to `main` | Cloud-only breakage surfaces before a release, not at it |
| Import store | Port the Tapix remote-store layer (part 3) | Proven in Tapix v1.3.0; unset disk falls back to today's local file |
| Self-host docs | Laravel Cloud section ships with this work | Written from the staging environment, so every claim is verified |

## Roadmap

Three sub-projects, each with its own plan, in this order:

1. **Platform-agnostic storage and config** (this spec, in full).
2. **Cloud staging environment and docs.** Stand up staging, verify part 1 on it, publish
   the self-hosting section. Imports are a known gap until part 3.
3. **Import store off local SQLite.** Port the Tapix remote store.

## Current state

Cloud blockers found by the audit:

- `packages/ImportWizard/src/Store/ImportStore.php:59-64` keeps a live SQLite file per
  import under `storage/app/imports/{id}`. The Livewire wizard (web) and `ValidateColumnJob`,
  `ResolveMatchesJob`, `ExecuteImportJob` (worker) all open it. Separate disks break it.
- `config/media-library.php:36` defaults `MEDIA_DISK` to `local`.
- `packages/Chat/src/Models/AgentConversation.php:96` pins `chat-attachments` to `local`
  and the CSV is parsed by path.
- `app/Support/Media/TemporaryUploads.php:23` stages MCP uploads on `local`.
- `config/livewire.php` sets no `temporary_file_upload.disk`, so Livewire temporary uploads
  land on the default `local` disk.
- `app/Console/Commands/GenerateSitemapCommand.php:39` writes `public_path('sitemap.xml')`.
- CRM exports (`app/Filament/Exports/*Exporter.php`) write through Filament's
  `Exporter::getFileDisk()`, which maps the `public` default disk to `local`. The worker
  writes the CSV to its own disk and the download on a web replica cannot find it.
- `.env.example:50` sets `APP_MAINTENANCE_DRIVER=file`.
- `league/flysystem-aws-s3-v3` is not installed, so no `s3` disk can resolve.
- `config/horizon.php:243` defines supervisors only for `production` and `local`.

Already fine:

- `resources/js/echo.js` and `config/broadcasting.php` read the standard `REVERB_*` and
  `VITE_REVERB_*` names that Cloud's managed Reverb injects.
- `app/Http/Controllers/Media/ShowMediaController.php` streams through `$disk->response()`,
  which works on S3 and keeps the auth and sandbox headers.
- Scheduled commands use `onOneServer()` and `withoutOverlapping()`, which work on a shared
  Redis cache.
- Sessions are database-backed. Proxies and panel domains are env-driven.

## Part 1: platform-agnostic storage and config

### How Cloud wires buckets

`Illuminate\Foundation\CloudBootstrapper` reads `LARAVEL_CLOUD_DISK_CONFIG` and overwrites
`filesystems.disks.<name>` for each attached bucket, using the disk name chosen at bucket
creation. Relaticle uses that instead of new env vars:

| Cloud bucket | Visibility | Disk name | Effect |
|---|---|---|---|
| Public | public | `public` | Replaces the local `public` disk: logos, Jetstream photos, legacy rich-editor files |
| Private | private | `s3` | Named by `MEDIA_DISK=s3` and `FILAMENT_FILESYSTEM_DISK=s3`: attachments, pending uploads, chat attachments, staged MCP uploads, CRM exports |

`FILAMENT_FILESYSTEM_DISK=s3` is required on Cloud. Left at `public`, exports fall back to
the replica-local `local` disk. Pointing it at the public bucket instead would publish
customer export CSVs.

The bootstrapper runs on Laravel's `bootstrapped: LoadConfiguration` event
(`Illuminate\Foundation\Application.php:332`), so the override also applies when config
is cached by `php artisan optimize`.

The `local` disk stays local on Cloud. It holds build artifacts such as
`storage/app/scribe/openapi.yaml`, which `OpenApiSpecController` reads, so it must never be
remapped to a bucket.

This replaces the `PUBLIC_MEDIA_DISK` variable proposed during brainstorming: naming the
bucket `public` does the same job with no code.

The bootstrapper writes the whole disk array and drops `visibility`.
`MediaUrlGenerator::getUrl()` decides between a plain URL and a signed `media.show` route
from `filesystems.disks.<disk>.visibility`. On Cloud that key is gone, so logos would get
signed, host-bound, uncacheable URLs. The generator must decide by disk name instead.

### Changes

1. **S3 driver.** Add `league/flysystem-aws-s3-v3` to `composer.json`.
2. **Public media URLs by disk name.** `MediaUrlGenerator` serves a plain URL when the
   media row's disk is `public`, whatever its config carries.
3. **Private user files follow `MEDIA_DISK`.** One owner for "the private user-file disk":
   `config('media-library.disk_name')`.
   - `chat-attachments` drops its `useDisk('local')` pin and follows the media disk.
     Parsing copies the file to a temp path first, then hands that path to the import
     wizard, and deletes the copy afterwards.
   - `TemporaryUploads::disk()` returns the media disk instead of `local`.
4. **Livewire temporary uploads.** `config/livewire.php` reads
   `temporary_file_upload.disk` from `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK`, default null
   (today's behaviour). Cloud sets it to `s3`.
   - Every consumer of a `TemporaryUploadedFile` that calls `getRealPath()` must work when
     the file lives on S3. `RichContentAttachments::saveUploadedFileAttachment()` passes
     `getRealPath()` into `StorePendingUpload` today. The plan must sweep every caller and
     read through the disk, or copy to a temp path, when the temp disk is remote.
   - A Livewire S3 temp upload goes straight from the browser to the bucket through a
     presigned URL. It needs bucket CORS for the app origins and a lifecycle rule for
     abandoned temp files. If Cloud buckets cannot provide either, Livewire temp uploads
     fall back to server-side upload onto the private bucket.
5. **Sitemap.** `app:generate-sitemap` writes `sitemap.xml` to the `public` disk. A
   `/sitemap.xml` route streams it with an XML content type. The stale
   `public/sitemap.xml` is removed so it stops shadowing the route on Forge.
6. **Horizon minimums.** Each production supervisor's `minProcesses` and `maxProcesses`
   read an env var with today's value as the default, following `HORIZON_CHAT_MIN` and
   `HORIZON_CHAT_MAX`. Every Cloud worker replica runs the full supervisor set, so
   minimums multiply per replica.
7. **Maintenance mode.** Cloud sets `APP_MAINTENANCE_DRIVER=cache`. No code change.
8. **Guard.** `tests/Arch/ConventionsTest.php` fails on a new `Storage::disk('local')`,
   `Storage::disk('public')`, `->useDisk('local')`, or `->useDisk('public')` in `app/` or
   `packages/*/src`, and on a new `public_path()` or `storage_path()` write at runtime.
   The allowlist names today's legitimate uses: the `logo` collections and Jetstream
   photos on `public`, `OpenApiSpecController` reading a build artifact from `local`,
   the legacy rich-editor lookup and its backfill command, and `ImportStore` until part 3.

### Forge stays unchanged

Every default equals today's value. Forge sets none of the new variables, attaches no
bucket, and keeps `MEDIA_DISK=local`. Existing media rows keep the disk they were
uploaded to, so nothing migrates. The existing suite passing on default config is the
proof.

### Tests

- Feature tests fake the media disk as a non-local disk and exercise the real entry points:
  record attachments through the panel, rich-editor images, workspace and company logos,
  MCP upload staging across two tool calls, and a chat CSV attachment parsed into the
  import wizard.
- A test covers `TemporaryUploadedFile` on a remote temp disk reaching
  `StorePendingUpload`.
- A test covers `/sitemap.xml` serving the generated file from the `public` disk.
- A test runs a CRM export with the Filament disk set to a non-local disk and downloads it.
- The arch guard from change 8.
- No isolated unit tests of internals, per the testing rules.

## Part 2: Cloud staging environment and docs

Scope, to be planned after part 1 lands.

**Environment:**

- App cluster at one replica. Verification temporarily scales it to two.
- Worker cluster running `php artisan horizon` as a background process, one replica.
- Managed Valkey with `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis`.
- Managed Postgres, managed Reverb, and the two buckets from part 1.
- Scheduler enabled on exactly one instance.

**Build and deploy:**

- Build: `composer install`, `pnpm build` (Cloud injects `VITE_REVERB_*` first), and
  `php artisan scribe:generate`. Scribe is DB-free, so the spec is baked into the image.
- Deploy: `php artisan migrate --force`, `php artisan optimize`, `php artisan filament:optimize`.
- Passport keys come from `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`, never files.
- Auto-deploy on every push to `main`.

**Verification** (the done bar, at two web replicas):

1. Upload a record attachment, a rich-editor image, and a workspace logo; view each from a
   fresh session. Run a CRM export and download it.
2. Stage an upload through the MCP tools and attach it in a second call.
3. Send a chat message and see it stream through managed Reverb.
4. See `/horizon` process jobs on each lane.
5. See each scheduled command run once, not once per replica.

**Docs:** a Laravel Cloud section in
`packages/Documentation/resources/content/docs/guides/self-hosting.md`, beside Docker,
Coolify, and Dokploy. It lists the clusters, bucket disk names, env vars, build and deploy
commands, and states that imports need part 3.

## Part 3: import store off local SQLite

Scope, to be planned after part 2.

Port the remote-store layer from Tapix (`~/Herd/tapix-core`, v1.3.0 and later) into
`Relaticle\ImportWizard\Store\ImportStore`:

- `IMPORT_STORE_DISK` (null by default). Null keeps today's local file, so Forge is
  unchanged.
- `loadForExecution()` and `withWriteLock()`: take `Cache::lock("import:write:{id}")`,
  download the SQLite file to a temp directory, mutate, upload a `VACUUM INTO` snapshot.
- `loadForRead()`: an ETag-checked local read cache, so repeat reads cost a HEAD request.
- The three jobs and `WithImportStore` move to these APIs. Cleanup deletes the remote file.

Risks the part 3 plan must settle first:

- Tapix's lock TTL is 120 seconds and the `imports` lane allows 300. Check how Tapix
  bounds `ExecuteImportJob` so no job outlives its lock.
- Confirm whether Tapix's cleanup command dispatches the remote-file cleanup. If it does
  not, the port must, or buckets leak import files.

## Out of scope

- Moving production to Cloud, and copying existing files to buckets.
- Octane.
- Cloud queue clusters.
- Per-PR preview environments.
- Redirecting private media to signed bucket URLs.

## Open questions for review

1. **Staging `APP_ENV`.** Horizon only defines `production` and `local` supervisors.
   Staging either runs `APP_ENV=production` with sandbox credentials (Stripe sandbox, mail
   to a log or test inbox, analytics off), or `config/horizon.php` gains a `staging`
   entry. Horizon has no wildcard environment key. The first keeps staging closest to
   production. It needs an audit of everything keyed on `production` that talks to the
   outside world.
2. **Staging data.** Seeded demo data through `LocalSeeder`-style seeding, or an empty
   database used only by us.
