# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

**Version `0.1.7`.** The **public user docs** are an Astro Starlight site in `docs/` (its own pnpm workspace, deployed on Vercel, excluded from the plugin bundle — see `docs/README.md`); its changelog page is generated from `readme.txt`. When a user-visible feature, setting, CLI flag, provider field or hook changes, update the matching `docs/src/content/docs/` page in the same commit. Never link the private repo from it.

Released so far: `0.1.0` (initial release, Cloudflare R2 only), `0.1.1` (five more providers, bulk-queue reliability work), `0.1.2` (custom batch quantity limit for Bulk Offload & Bring Back, UI consistency, CLI `--limit` restore, plus LiteSpeed Cache, Breeze, and Object Cache real-time reliability fixes), `0.1.3` (apply a changed retention policy to existing media — Tools page + `wp omnioffloader retention` — an in-admin WP-CLI reference, and parallel uploads with a Transfer Speed setting), `0.1.4` (Serve Media from Cloud setting, `offload --concurrency` up to 64, `wp omnioffloader stop`, nohup-friendly long CLI runs), `0.1.5` (editors show offloaded media from the cloud; saves store local URLs — `ContentImageTagObserver`), `0.1.6` (Failed Items + retry, `wp omnioffloader verify`/`errors`, `status --format=json`, single-item actions refuse during any job, admin data preloaded with the page, opt-in Appsero insights). See `readme.txt` for the user-facing changelog — keep it updated in the same commit as any user-visible change.

### Releasing (Release Please)

Releases are automated with Release Please (`.github/workflows/release-please.yml`, `release-please-config.json`, `.release-please-manifest.json`). Never bump versions or publish releases by hand.

- **PR titles are Conventional Commits** (`feat:`, `fix:`, `perf:`, `docs:`, `chore:`…) — a squash merge uses the title, and `pr-title.yml` checks it. Before 1.0.0, `feat` bumps the patch (0.1.6 → 0.1.7) and a breaking change bumps the minor; Release Please never goes to 1.0.0 by itself. To choose a version, add a `Release-As: x.y.z` footer to a commit.
- **Every user-visible PR adds its readme lines under `= Unreleased =`** in both the `== Changelog ==` and `== Upgrade Notice ==` sections of `readme.txt` (plain, user-friendly wording; create the entry if it isn't there).
- On each push to main, Release Please updates one open `chore: release x.y.z` PR (CHANGELOG.md + manifest). The `sync-versions` job then runs `scripts/sync-versions.mjs` on it: the four version places (`Version:` header and `OMNIOFFLOADER_VERSION` in `omnioffloader.php`, `Stable tag` in `readme.txt` — the docs badge reads it — and `version` in `package.json`), `= Unreleased =` → `= x.y.z =`, and moving releases beyond the two latest from `readme.txt` into `changelog.txt`. It warns when the readme has no entry.
- **The public docs go live with the release**: Vercel's production branch is **`docs-live`** (the released plugin's docs); `main` only builds previews. The release's `publish-docs` job fast-forwards `docs-live` to the release commit, so feature docs merge with their feature and go live with it. **A quick fix to the live docs goes in a PR into `docs-live`** (live at once); `docs-live.yml` merges it back into `main` with a merge commit (never squash — the next fast-forward needs it), or opens a PR on a conflict. `publish-docs` never forces: if it fails, a docs-live fix is missing from main.
- **Merging the release PR is the release**: tag, GitHub Release, `deploy-on-pushing-plugin.yml` (called directly — a release made by GitHub Actions doesn't fire `release: published`) and the milestone named after the version is closed. A manual GitHub Release still deploys through `release: published`.
- Merged PRs without a milestone join the lowest open version milestone (`milestones.yml`).
- A migration for the new version goes in `Core\Migrator`, in the feature PR that needs it.
- Needs the repo setting "Allow GitHub Actions to create and approve pull requests".
- **Workflow rules** (checked by `workflow-lint.yml`: actionlint + zizmor at `min-severity: low`): pin every action to a commit SHA with a `# vX.Y.Z` comment (Dependabot, `.github/dependabot.yml`, bumps them weekly with a 7-day cooldown — but not the `docker://` actionlint digest or the zizmor `version` in `workflow-lint.yml`, which are bumped by hand); `permissions: {}` at the top and per-job permissions; `persist-credentials: false` on every checkout that doesn't `git push`; `timeout-minutes` on every job (not allowed on a job that calls a reusable workflow); never put `${{ … }}` inside a `run:` script — pass it through `env:`; no dependency cache in release/deploy jobs (no `cache:` input, and `package-manager-cache: false` on setup-node — it only caches on its own for npm, the explicit false keeps that off). A deliberate exception gets `# zizmor: ignore[<audit>]` plus a comment saying why. Run `actionlint` locally before pushing workflow changes.

## What This Plugin Does

Offloads WordPress media library files to cloud object storage and serves them from a CDN. Three retention policies control what stays on the server. The signature feature is **Bring Back**: restoring offloaded media from the cloud back to the server (per-item and bulk), safely reversing an offload.

Providers shipped (`Providers\ProviderRegistry::$providers`): `amazon_s3`, `cloudflare_r2`, `backblaze_b2`, `digitalocean_spaces`, `wasabi`, and `s3_compatible` (generic, user-supplied endpoint/region, custom display name). `ProviderRegistry::$aliases` maps legacy keys (`aws`, `aws_s3`, `dos`, `digitalocean`, `minio`) onto canonical ones.

## Build Commands

JS tooling uses **pnpm** (version pinned by `packageManager` in `package.json`; `corepack enable` provides it) — not npm.

```bash
pnpm start            # Development build with watch mode (wp-scripts)
pnpm build            # Production build
pnpm check-types      # tsc --noEmit — strict TypeScript check
pnpm lint:js          # ESLint (WordPress TS config)
pnpm screenshots      # Automated Puppeteer headless browser screenshot & annotation engine
pnpm --dir docs run dev    # Start Astro Starlight docs development server (localhost:4321)
pnpm --dir docs run build  # Build production Astro Starlight static site to docs/dist/
pnpm bundle           # Build + distribution zip (excludes dev files per .distignore)
pnpm bundle:org       # Same, shaped for the WordPress.org SVN layout
composer phpstan     # PHPStan level 8 (szepeviktor/phpstan-wordpress)
composer phpcs       # WordPress Coding Standards
```

Run `pnpm check-types`, `pnpm lint:js`, `composer phpstan` and `composer phpcs` before committing. `.github/workflows/tests.yml` runs them again on every PR and push to main, plus the integration tests on a **computed** PHP × WordPress matrix (`scripts/ci-matrix.mjs`: `Requires at least` / `Requires PHP` from `readme.txt`, WordPress versions from `api.wordpress.org/core/stable-check`, PHP versions from wordpress-develop's `.version-support-php.json` for the latest major — never hard-code versions in the workflow). PRs run a reduced matrix: lowest/highest PHP on the oldest (newest point release of `Requires at least`, on MySQL 5.7), previous-major and latest WordPress, plus one multisite job (main site **and** a sub-site), one Redis object-cache job (`redis-cache` drop-in, Predis) and one MariaDB 10.6 job; a PHP core lists only for its next major, and WordPress nightly (or a version given to a manual run), are experimental. The weekly run, a PR labelled **`full matrix`** and a manual run with `full` test every PHP × oldest/previous/latest × single/multisite. Database images are pinned by digest in `ci-matrix.mjs`. Translations come from translate.wordpress.org; there is no local i18n build step and no `languages/` folder.

### Integration tests

Not PHPUnit — `wp eval-file` scripts under `tests/`, run against a real WordPress install:

```bash
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-folder-claim.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-concurrency.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-unique-filename.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-bulk-queue.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-move.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-content-rewrite.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-verify.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-counters.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php 20 myprefix
wp --user=admin eval-file tests/integration-stale-status.php   # needs a configured provider; no bucket calls
wp eval-file tests/integration-bulk-rest.php   # needs real credentials; hits the live bucket
```

`scripts/integration-tests.sh [wp-path]` runs every `tests/integration-*.php` the way CI does (plugin must be **active** there — the tests rely on hooks `Core\Plugin` registers on `plugins_loaded`, e.g. `MediaCounters`; skips the two that need real credentials). It fails on a failed check, a missing `RESULT` line, or any PHP warning/notice/deprecation from `includes/` or `omnioffloader.php`. Tests must not call a trait's static methods directly (deprecated since PHP 8.1 — go through a class that uses the trait) or no-op functions PHP 8.5 deprecates (`imagedestroy()`, `ReflectionMethod::setAccessible()`).

`tests/MockProvider.php` implements the full `ProviderInterface` against a local directory, so the offload and restore pipelines run end-to-end without credentials. `OMNIOFFLOADER_DISABLE_ENGINE` is the kill switch that stops `Core\Plugin` booting the observers, so a test can wire its own provider.

## Identity & Conventions

- Namespace: `OmniOffloader\` → `includes/`, via **Composer PSR-4** (there is no hand-written autoloader). `composer.json` trims the AWS SDK to the S3 and Sts services through `Aws\Script\Composer\Composer::removeUnusedServices`.
- Prefix everything: options/meta/hooks `omnioffloader_`, constants `OMNIOFFLOADER_`, REST `omnioffloader/v1`, CLI `wp omnioffloader`, script handle `omnioffloader-admin-app`, localized object `window.omnioffloader`.
- Text domain: `omnioffloader`. All user-facing strings use `__( '…', 'omnioffloader' )` in PHP and `__( '…', 'omnioffloader' )` from `@wordpress/i18n` in TS.
- License: GPL-2.0-or-later (the "or later" is required — the bundled AWS SDK is Apache-2.0, compatible only via GPLv3).
- PHP 8.1+, WP 6.6+. WordPress Coding Standards for PHP (doc headers on every file/class/method).

## Architecture

### Backend (`includes/`)

```
includes/
├── Abstracts/
│   └── WP_Background_Processing/   # Vendored WP_Async_Request + WP_Background_Process
├── Core/               # Plugin (singleton, wires all on plugins_loaded), Activator,
│                       #   Deactivator, Uninstaller, Migrator (version-gated), Helpers,
│                       #   SafeMode, FolderClaim (shared-bucket folder markers)
├── Api/                # REST controllers extending BaseController (namespace omnioffloader/v1)
├── Providers/          # ProviderInterface, AbstractS3Provider, ProviderRegistry,
│                       #   AmazonS3, CloudflareR2, BackblazeB2, DigitalOceanSpaces,
│                       #   Wasabi, S3Compatible
├── Services/           # AttachmentUploader, AttachmentRestorer (Bring Back), AttachmentMover, LocalCleaner,
│                       #   RetentionApplier, AbstractBulkProcessor + BulkOffloadProcessor /
│                       #   BulkRestoreProcessor / BulkRetentionProcessor / BulkMoveProcessor
├── Offload/            # Offloader + Observers/ — one WordPress hook per observer class
├── Admin/              # Settings (menu + React app enqueue)
├── Cli/                # CommandRegistrar, OffloadCommand, RestoreCommand, RetentionCommand,
│                       #   StatusCommand, StopCommand, MoveCommand, ErrorsCommand, VerifyCommand;
│                       #   CliJobGuard (shared lock/stop/detached runs)
├── Traits/             # OptionsHelper, AttachmentHelper
└── templates/          # admin-app.php (#omnioffloader-admin-app mount point)
```

Key rules:
- **No side effects at file load.** Everything boots from `Core\Plugin::get_instance()->run()` on `plugins_loaded`. Activation/deactivation hooks registered at top level of the main file only.
- Offload engine (observers, bulk processors) loads **only when a provider is configured** (`OptionsHelper::is_configured()`) and the AWS SDK class exists. `OMNIOFFLOADER_DISABLE_ENGINE` skips it entirely.
- `Migrator::run()` on `init` priority 5 in every context (not just admin) so WP-CLI/cron updates migrate too. **A migration also runs inside the bulk queue's own worker requests** — guard anything destructive against a live run (see `migrate_to_0_1_1()`).

### Bulk queues — read before touching `Services/`

`AbstractBulkProcessor` holds all shared queue behaviour; `BulkOffloadProcessor` and `BulkRestoreProcessor` only supply `queue_ids()`, `process_item()` and their `STATE_OPTION`. It overrides the vendored `WP_Background_Process` because one task here is a **network transfer**, not a short CPU-bound job. Do not undo these — each fixes a specific production failure:

- **`$queue_lock_time = 600`, renewed after every item** (`renew_lock()`, which deliberately does not call `lock_process()` — that would reset `$start_time` and defeat `time_exceeded()`). The parent's 60s lock expires mid-transfer, letting the cron healthcheck and the admin poll start a second worker on the same batch.
- **`handle()` takes a MySQL `GET_LOCK()` worker mutex first** (`acquire_worker_mutex()`). The transient process lock is check-then-set, so the loopback worker, cron healthcheck and admin poll could all pass `is_processing()` together and run a batch twice (seen in production: `processed` 12 of `total` 6). The mutex is released right after `unlock_process()` so the next pass's loopback can take it; databases without `GET_LOCK` fall back to the transient.
- **Queue identifiers are per site on multisite** (`Helpers::queue_prefix()` → `omnioffloader_{blog_id}`), because `WP_Background_Process` stores batches/status/locks in network-wide site options. Single sites keep `omnioffloader_*`. The CLI job lock uses the same prefix (`Plugin::cli_job_lock_key()`).
- **Items are claimed out of the batch before they run.** The parent removes an item only after `task()` returns, so a task killed by `max_execution_time` is retried forever and one oversized file wedges the run.
- **Pass length follows the host** (`pass_time_limit()`: `max_execution_time − 20`, clamped 20–40 s; 30 s when unlimited), read before the first item because the uploader's `set_time_limit(0)` rewrites the ini value. The `{identifier}_default_time_limit` filter still wins, which keeps the inline poll pass at 8 s.
- **`current` / `current_ids` in the state record the in-flight group**, so `recover_lost_item()` can report every item of a killed worker's group instead of stalling the progress bar below `total`. `group_size()` (default 1) and `process_items()` let a queue claim and run several items together — only the offload queue does.
- **Circuit breaker** at `FAILURE_LIMIT` consecutive failures (filter `omnioffloader_bulk_failure_limit`) → status `failed` with a message. At the limit it first asks `connection_check()` (offload: the provider; restore: the item's provider; retention: `null` = stop): if the provider answers, the failures are the attachments' own (missing files) and the run continues, up to `FAILURE_LIMIT × UNEXPLAINED_FAILURE_FACTOR` failures since the last success. `streak` / `fail_run` live in the state so they survive passes. Queues order previously `failed` items last, so a restart is never held up by the same broken items. The CLI mirrors this.
- **Every run has an id (`run` in the state, a UUID from `start_queue()`)**; a pass remembers it (`pass_run`). Cancel + an immediate restart used to let the old worker, still holding its batch in memory, carry on inside the new run (re-creating deleted batches, counting into the new totals, with the new run's `delete_cloud`). A superseded pass now stops without purging (the queue is the new run's), records nothing, skips the breaker and dispatches the new run's worker.
- **Every claim first checks the state's `status` is still `running`** (`run_stopped()`): the queue's own cancel flag is cached for the whole request, so a worker never saw a cancel, and with a persistent object cache its claim re-created the deleted batch row.
- **One state write per group** (`record_results()`), plus the one that marks the group in flight — never per item.
- `purge_queue()` clears `queued` / `processing` statuses of the items it drops; `recover_lost_item()` marks lost items `failed` and calls `clean_up_lost_item()` (restore/retention: delete partial `*.tmp` downloads). `halt_run()` stops a run with a custom message (Bring Back's disk check).
- Out of disk / quota mid-run: `AbstractS3Provider::download_hit_disk_limit()` (curl 23 or PHP's "Disk quota exceeded" / "No space left" warning) → `AttachmentRestorer::ran_out_of_disk()` → Bring Back, retention and CLI restore stop via `halt_run()` instead of grinding into the breaker.
- `process_items()` may return `null` for an item — "skipped" (the should-offload filter): logged as `skipped`, not an error, breaker untouched.
- **`process_queue_now()` only works when the queue has been quiet for `STALL_SECONDS`.** It is the self-heal for hosts where the loopback is blocked; without the stall check every 2s poll would hold a PHP worker for 8s.
- **`cancel_now()` tears down in-request** rather than via the parent's loopback dispatch, which never arrives on loopback-blocked hosts.
- **`completed()` refuses to overwrite a non-`running` status**, so a late worker cannot report a cancelled run as done.
- **`purge_stored_data()` is static** so deactivation/uninstall can clean batch rows, run status, process lock and cron without booting the engine.

Queue state statuses: `idle` · `running` · `done` · `cancelled` · `failed`.

### Adding a cloud provider (the point of the design)

1. Create `includes/Providers/{Name}.php` extending `AbstractS3Provider` — supply `get_key()`, `get_name()`, `create_client()` and `credential_fields()`.
2. Add one line to `ProviderRegistry::$providers`.

That's it. `credential_fields()` is served as JSON to the React app, which renders the form generically — **zero JS changes per provider**.

`AbstractS3Provider` handles the rest (plus the optional `list_object_sizes()` that `RetentionApplier` uses to verify an attachment's files in one request): `upload_file()` switches to `MultipartUploader` above `MULTIPART_THRESHOLD` (64 MB, filter `omnioffloader_multipart_threshold`) and aborts the multipart upload on failure; the optional batch methods `upload_files()`, `download_files()`, `objects_exist()` and `copy_objects()` (server-side CopyObject inside the bucket, guarded like uploads; `AttachmentMover` uses it for same-bucket moves and falls back to Bring Back + offload) (all `method_exists`-checked by callers, which fall back to the single-file methods) run through one `Aws\CommandPool` at `OptionsHelper::files_at_once()` (setting `transfer_speed` low/balanced/fast → 1/3/6, custom → `transfer_concurrency`, filter `omnioffloader_transfer_concurrency`, clamped to 10; CLI `--concurrency` (offload, move, restore, retention — `CliJobGuard::apply_concurrency()`) sets `Helpers::set_transfer_concurrency_override()` up to 64, which wins over both, `batch_size()` grows to one attachment per 4 slots ≤16, multipart parts stay ≤ `MAX_PART_CONCURRENCY` 10) — one PHP process, never extra workers. `AttachmentUploader::upload_many()` pools several attachments (`batch_size()`: 3, or 1 on Low) and confirms their main keys together; `upload()` is `upload_many()` of one. `RetentionApplier::prefetch()` lists a group's prefixes in one `list_object_sizes_many()` pool before a stricter policy's deletes (CLI groups of `group_size()` = `files_at_once()`; `BulkRetentionProcessor` groups too, downloads `runs_alone()`); `AttachmentRestorer` checks existing local copies with one `remote_sizes()` listing per attachment and downloads the rest through `download_files()`; providers build their client through `client_config()`, which sets `request_checksum_calculation => when_required`; every call carries `connect_timeout`; ACL is omitted unless `omnioffloader_object_acl` returns one (R2 rejects the header, so never add it unconditionally).

### Frontend (`src/` — TypeScript React SPA)

```
src/
├── index.tsx           # Entry → renders into #omnioffloader-admin-app
├── App.tsx             # Router via view= query param
├── types/              # ★ Single source of truth for all REST payload shapes
│   └── global.d.ts     # window.omnioffloader declaration (in tsconfig "files")
├── api/                # Typed apiFetch wrappers (core, provider, offload, bringBack, retention, move)
├── context/            # CoreContext: settings, provider, stats, global dirty/save state
├── hooks/              # useBulkOffload, useBringBack, useRetentionSync, useMoveSync
├── pages/              # Dashboard, Settings, Offload, BringBack, Tools
├── components/
│   ├── layout/         # Sidebar (logo + 2-line nav), Header (icon badge + page title),
│   │                   #   SaveBar (sticky bottom save bar), MainLayout
│   ├── ui/             # Card, ProgressBar, StatCard, ActivityLog, BatchScopeSelector
│   ├── settings/       # CredentialFields, TestConnection
│   └── tools/          # RetentionSyncCard, MoveFolderCard, FailedItemsCard, CliReference (mirrors includes/Cli docblocks —
│                       #   update it and docs/…/wp-cli/ whenever a command or flag changes)
└── utils/              # constants.ts holds RouteMeta[] (icon/title/subtitle per page —
                        #   Sidebar and Header render from this one source)
```

UI design: full-page app, left sidebar with logo top + active-item accent highlight, content header showing current page icon/name/subtitle, **sticky bottom SaveBar** ("All changes saved" / "Save changes"), white rounded cards on light gray background, blue accent (`#135dbe` / `#0e4690`).

Progress polling (`useBulkOffload`, `useBringBack`, `useRetentionSync`, `useMoveSync`) uses a **chained `setTimeout` with backoff** (2s → 6s), never `setInterval` — a poll can make the server process a batch, so an interval would stack requests whenever one outlasts its period.

### TypeScript rules (Gutenberg-style)

- `tsc` is **type-check only** (`noEmit`); Babel via wp-scripts transpiles. `isolatedModules` must stay on.
- `strict: true` + `noImplicitReturns` + `noFallthroughCasesInSwitch`. No `any` — use `unknown` + narrowing; `@ts-expect-error` with reason over `@ts-ignore`.
- Every REST request/response shape lives in `src/types/` and must match the PHP controller's `args` schema / response. When you change one side, change the other in the same commit.
- `@wordpress/*` packages ship their own types; don't add `@types/wordpress__*` unless a package lacks them.

## REST API (`omnioffloader/v1`)

All mutations via REST; `@wordpress/api-fetch` sends the nonce. Every route: explicit `permission_callback` (`manage_options`; only the read-only `/offload/attachment-status` uses `upload_files`), `args` with type/sanitize callbacks **plus `'validate_callback' => 'rest_validate_request_arg'`** (core skips schema checks — type, enum, min/max — when only a sanitizer is given), `WP_REST_Server` method constants, `rest_ensure_response()` / `WP_Error` with status + stable `omnioffloader_*` error codes.

- `GET/POST /core/settings` · `GET /core/providers` · `GET /core/stats` (includes `safe_mode` and `storage`: `MediaCounters::storage()` totals for the Dashboard Storage card) · `GET /core/cli-job` (the WP-CLI job running now, from its live claim + `omnioffloader_cli_run`; the admin `useCliJob` checks on open, on tab return and after a 409 `omnioffloader_bulk_running`, and polls only while one runs) · `POST /core/safe-mode/live` · `POST /core/folder-claim/take-over`
- `GET/POST /provider/credentials` · `POST /provider/test-connection` — all take a provider arg
- `POST /offload/attachment/{id}` · `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel` · `GET /offload/errors` (`state` all/local/offloaded, `Helpers::failed_ids()`) · `POST /offload/errors/retry` (queues every failed offload as a bulk offload — Tools → Failed Items)
- `GET /bring-back/preflight` · `POST /bring-back/attachment/{id}` · `POST /bring-back/bulk` · `GET /bring-back/bulk/progress` · `POST /bring-back/bulk/cancel`
- `POST /bulk/action` (Media Library bulk offload/restore of selected IDs, `maxItems` 1000, `manage_options`) · `GET /offload/attachment-status` (Media Library live badges, `upload_files`, read-only)
- `GET /move/preflight` · `POST /move/bulk` · `GET /move/bulk/progress` · `POST /move/bulk/cancel` — move media offloaded under an earlier Path Prefix into the current (saved) folder (`BulkMoveProcessor` → `AttachmentMover`)
- `GET /retention/preflight` · `POST /retention/bulk` · `GET /retention/bulk/progress` · `POST /retention/bulk/cancel` — apply the **saved** retention policy to already-offloaded media (the client never sends a policy)

On load the admin page embeds the bodies of `core/settings`, `core/providers`, `core/stats`, `core/cli-job` and every provider's `provider/credentials` (`Admin\Settings::preload_data()` via `rest_preload_api_request()` → `window.omnioffloaderPreload`); `src/api/preload.ts` hands each out once, then requests go to the server. A route the first screen needs belongs there — but never one that scans the library or calls the bucket.

The four `bulk/progress` routes are `GET` but deliberately do work (the stalled-queue self-heal). Keep that in mind before adding caching.

Credentials: `GET` returns secrets masked (`is_set: true`); `POST` ignores masked placeholders so saves never wipe secrets. wp-config constants (`OMNIOFFLOADER_CLOUDFLARE_R2_KEY`, etc.) lock fields (`locked: true`).

## Data Reference

**Options:**
- `omnioffloader_settings` (autoload on) — provider, retention_policy (0/1/2), serve_from_cloud (default on; may only be off with retention_policy 0 — `update_settings()` forces it on otherwise; off keeps local URLs except files an attachment's stored `omnioffloader_retention` removed: all at 2, generated sizes at 1 — `AttachmentHelper::cloud_serving()` / `is_served_from_cloud()`), mirror_delete (default **on** since 0.1.3 — only affects new installs, because every save stores all keys), path_prefix(+active) (each attachment keeps the folder it was offloaded to, so a change only affects later offloads — Settings warns and SaveBar confirms; `wp omnioffloader move` / `Services\AttachmentMover` moves old media into the current folder), object_versioning, async_upload, transfer_speed (low/balanced/fast/custom), transfer_concurrency (1–10, used by custom), bring_back_delete_cloud, remove_data_on_uninstall, protect_copies (Safe mode, default on), safe_mode_bring_back (download-only Bring Back on a copy, default off)
- `omnioffloader_version` (autoload on) — for Migrator
- `omnioffloader_credentials` (autoload **off** — secrets stay out of the shared alloptions cache; `is_configured()` pays one small query per request for that) — per-provider credential sub-arrays. Never returned raw via REST.
- `omnioffloader_first_activated_version` (autoload off)
- `omnioffloader_live_site` (autoload on) — `{hash, label}` of the address recorded as live (`SafeMode::maybe_record_live_site()` on first configured boot; the "This is the live site" button re-records). `label` is base64 so search-replace skips it.
- `omnioffloader_folders_used` (autoload on) — every folder this site claimed, per md5(provider|bucket); `FolderClaim::blocks( $provider, $keys )` checks the folder each key is really in (`folder_of()`), so a folder the site moved away from but still has media in stays guarded; `lost_folders()` feeds the status warning
- `omnioffloader_folder_claims` (autoload on) — `Core\FolderClaim` cache, md5(provider|bucket|prefix) → `{status: ours|other, owner, checked_at}`, max 10 entries, re-read after a day. Safe mode reads it (never a remote call).
- `omnioffloader_bulk_offload_state` / `omnioffloader_bulk_restore_state` / `omnioffloader_bulk_retention_state` / `omnioffloader_bulk_move_state` (autoload off) — progress heartbeats
- `omnioffloader_cnt_{0,1,2}_{count,main_bytes,size_bytes,main_unknown,sizes_unknown,any_unknown}` + `omnioffloader_counters_version` (autoload off) — `Core\MediaCounters` running totals; removed on uninstall
- `omnioffloader_bulk_{offload,restore,retention}_batch_*` / `_status` — queue internals, written by `WP_Background_Process` via `update_site_option` (autoload off on single site). Never leave these behind; `purge_stored_data()` owns their cleanup.

**Attachment meta:** `omnioffloader_offloaded`, `omnioffloader_offloaded_at`, `omnioffloader_provider` (provider at offload time — Bring Back resolves this, not the active provider), `omnioffloader_bucket`, `omnioffloader_path`, `omnioffloader_object_version`, `omnioffloader_async_attempts` (background-upload tries; the async queue gives up after 3 and marks the attachment failed), `omnioffloader_retention` (policy the attachment's local files currently follow — missing means 0; only advances once every file was handled), `omnioffloader_error_log`.

**Retention policy values:** `0` retain local (default) · `1` smart cleanup (delete thumbs, keep original) · `2` full migration (delete all local).

The policy is applied once at offload time (`LocalCleaner`). Changing the setting later does nothing to existing media until `RetentionApplier` runs (Tools page, `wp omnioffloader retention`). Stricter: every local file is verified in the bucket **by size** before deletion; missing objects are uploaded first; mismatches are kept and logged. Looser: `AttachmentRestorer::restore_local_copies()` downloads files while the attachment stays offloaded.

**Transients:** `omnioffloader_media_stats`, `omnioffloader_retention_preflight` (only the filtered scan), `omnioffloader_counters_checked`, `omnioffloader_move_preflight` (count of media outside the current folder for the admin screens, keyed by provider/bucket/folder, cleared by `flush_media_stats()`), `omnioffloader_activation_redirect`.

**Cron:** watchdog event only while a bulk job runs; unschedule when idle.

**Constants:** `OMNIOFFLOADER_SAFE_MODE` (true/false — force Safe mode), `OMNIOFFLOADER_VERSION`, `OMNIOFFLOADER_DIR`, `OMNIOFFLOADER_URL`, `OMNIOFFLOADER_ASSETS_URL`, `OMNIOFFLOADER_BASENAME`, `OMNIOFFLOADER_FILE`, `OMNIOFFLOADER_DOCS_URL` (the only place the docs domain is written — PHP uses it directly, the admin app via `docsUrl()` in `src/utils/docs.ts`), plus `OMNIOFFLOADER_DISABLE_ENGINE` (kill switch).

## Public Hooks

Filters: `omnioffloader_free_disk_space` (bytes free for restores — `Helpers::free_disk_space()`, the one place free space is read: min of `disk_free_space()` and the cPanel quota from `Helpers::hosting_quota()`, which parses `~/.cpanel/datastore/_Cpanel::Quota.pm__{user}` JSON `{"data":[used, limit, remaining, …]}` — no exec; the page trusts it up to a day old, the running disk check up to an hour), `omnioffloader_cpanel_quota_file` (path override), `omnioffloader_cloud_providers`, `omnioffloader_object_acl`, `omnioffloader_attachment_key`, `omnioffloader_attachment_delete_keys`, `omnioffloader_should_offload_attachment`, `omnioffloader_use_async_upload`, `omnioffloader_should_apply_retention`, `omnioffloader_media_block_names`, `omnioffloader_log_provider_errors`, `omnioffloader_multipart_threshold`, `omnioffloader_transfer_concurrency`, `omnioffloader_bulk_failure_limit`, `omnioffloader_check_folder_claim` (default true).
Actions: `omnioffloader_before_offload`, `omnioffloader_after_offload`, `omnioffloader_before_restore`, `omnioffloader_after_restore`, `omnioffloader_transfer_heartbeat` (every 30 s while bytes move — `AbstractS3Provider::transfer_http_options()` progress callback; bulk passes and `CliJobGuard` renew their locks on it).

## Safety Invariants (never violate)

1. **Never delete a local file before the cloud upload is verified** (`object_exists` on the main key).
2. **Never delete a cloud object before the local restore is verified** (all expected files present; delete-cloud is opt-in, default OFF).
3. Restores write atomically: temp file + `rename()`; destination confined to `wp_upload_dir()` basedir (realpath check).
4. On any failure, an attachment must always have at least one valid copy and truthful meta; errors go to `omnioffloader_error_log`.
5. Guard `dirname()` returning `'.'` when building object keys (produces broken `./file.jpg` keys — known bug class in the reference plugin).
6. Never log or echo credentials; REST never returns raw secrets.
7. CLI destructive paths (`--delete-cloud`) require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.
8. Only one bulk job at a time — offload, restore, retention and move runs (admin queue or CLI) all read or delete the same local files. REST start routes check `Plugin::bulk_job_conflict()`; CLI commands refuse while `Plugin::get_active_bulk_job()` is non-empty (`--force` overrides), hold their queue's process lock, and announce themselves with `Plugin::claim_cli_job()` (site transient, 10 min). All of this lives in the `Cli\CliJobGuard` trait: locks are renewed by time (every 30 s from `keep_job_alive()`, plus the transfer heartbeat — never every N items), the run record is written at most every 5 s and with the final counts by `end_job()`, released on shutdown and on Ctrl-C (pcntl), and `--limit`/`--ids` are validated (a typo must never mean "whole library") so admin jobs of every type see them. Each run records itself in option `omnioffloader_cli_run` ({job, pid, host, progress, log, files_at_once, updated, in_flight}) so `wp omnioffloader stop` (sets `omnioffloader_cli_stop`, read straight from the DB between items) can end it, `status` can show it (with speed and time left), `Plugin::clear_cli_run()` can reset the in-flight items' status and keep the record as `omnioffloader_cli_last_run` for `status`, and a lock whose process is gone on this host is cleared instead of refused. A run without a TTY on stdout (`nohup … &`, cron, redirected output) is "detached": it leaves SIGHUP alone, writes progress lines instead of a bar, and refuses confirmation prompts without `--yes`. **No `proc_open`/`exec`** — Plugin Check forbids them, which is why a `--background` relaunch option was dropped before 0.1.4. Single-item offload and Bring Back are refused while any job runs (`bulk_job_conflict( 'single' )`); `verify --repair` claims the job too (job type `verify`).
9. **Offload / Bring Back is administrator-only.** Every path that moves media between server and cloud — row actions, detail buttons, Media Library bulk actions, `/offload/attachment/{id}`, `/bring-back/attachment/{id}`, `/bulk/action` — needs `manage_options` (plus `edit_post`; `delete_post` to delete the cloud copy), via `AttachmentHelper::current_user_can_manage()` / `current_user_can_delete_cloud()` / `filter_manageable_ids()`. Non-admins only upload and delete their own media through core; Mirror Delete handles the cloud side of a delete. `/offload/attachment-status` (read-only badges) stays `upload_files`.
10. **A copy of the site never writes to or deletes from the bucket** (`Core\SafeMode`). A copy is detected by a changed site address (read from the stored `home` option, never `home_url()` — multilingual/domain-mapping plugins filter that per request; sha256 fingerprint in `omnioffloader_live_site` — never a plain URL, which migration search-replace would rewrite), `wp_get_environment_type()` ≠ production, or a local host (`localhost`, `*.local`, `*.test`…); `OMNIOFFLOADER_SAFE_MODE` overrides, setting `protect_copies` (default on) disables. Enforced at the provider (`upload_file`/`upload_files`/`delete_objects` refuse) plus every entry point: upload observers and async queue skip, Mirror Delete skips, restores force `delete_cloud` off, offload REST routes return 409 `omnioffloader_safe_mode`, Media Library offload links/bulk option hidden, CLI `offload` and `restore --delete-cloud` refuse. URL rewriting keeps working. Bring Back is refused and hidden too (`SafeMode::blocks_bring_back()`: row/editor/meta-box buttons, bulk option, `/bring-back/*` and `/bulk/action` restore → 409, queue halts, CLI `restore` refuses) unless setting `safe_mode_bring_back` allows it — then download-only. Local test sites (`*.test`) are in Safe mode — the integration tests define `OMNIOFFLOADER_SAFE_MODE` false.
11. **A site never writes to or deletes from a bucket folder another site uses** (`Core\FolderClaim`). Separate sites may share one bucket + CDN, each under its own path prefix; each claims its folder with a `{prefix}.omnioffloader-site.json` marker naming `SafeMode::current_address()`. Another site's marker → Safe mode reason `folder` (not a copy: `SafeMode::is_copy()` stays false, the constant and `protect_copies` don't switch it off), so every Safe mode guard applies. Enforced at the provider too (`upload_file`/`upload_files`/`delete_objects` call `FolderClaim::blocks()`, active provider only); `POST /core/settings` returns 409 `omnioffloader_folder_claimed` for a taken prefix and claims a new one on save; offload REST routes and CLI `offload` call `FolderClaim::refresh()` first; offload/retention queues `halt_run()`. Markers are read via the optional `read_small_object()` / `write_small_object()` provider methods (`method_exists`-checked; `write_small_object()` refuses on a copy). A copy never inspects or claims — it would only find the live site's marker. Uninstall never deletes the marker.

## Performance Rules

- Frontend (non-admin) footprint is URL-rewrite filters only — no queries, writes, or HTTP. `render_block` only looks up IDs for `ContentImageTagObserver::MEDIA_BLOCKS` (filter `omnioffloader_media_block_names`), and `prime_block_attachments()` on `the_content` priority 8 primes those IDs in one batch before `do_blocks` (9) — core only primes at 12; `<video>/<audio>/<source>` URLs are resolved in one batched `IN (…)` query per content and memoized per request — never `attachment_url_to_postid()` per src.
- Preflights (`get_restore_preflight`, `get_retention_preflight`) read **running counters** (`Core\MediaCounters`), never scan the library: per stored retention policy, the count, main-file and size bytes (from `_wp_attachment_metadata`), and how many have no recorded size. Kept current by hooks on the three meta keys they depend on (`omnioffloader_offloaded`, `omnioffloader_retention`, `_wp_attachment_metadata`, via the `add/update/delete_post_metadata` pre-filters + `added/updated/deleted_post_meta`) and `delete_attachment`: the contribution before an attachment's first change is remembered, the difference applied after (every 50 attachments and at shutdown) — writers need no changes, but **anything that edits those keys with direct SQL must call `MediaCounters::rebuild()`**. Each figure is its own option row (`omnioffloader_cnt_{policy}_{field}`, autoload off) changed by atomic SQL increments. Missing/outdated (`omnioffloader_counters_version`) → chunked rebuild on first read; an hourly grouped count (`omnioffloader_counters_checked`) rebuilds on drift. The restore preflight counts only bytes the stored policy removed from the server (`RetentionApplier::local_roles()`), reports `unsized`, and reads free space live. Only when an `omnioffloader_should_apply_retention` filter is attached does the retention preflight fall back to its per-attachment scan (1-minute transient).
- No remote calls outside explicit user actions (Test Connection, offload/restore jobs).
- Stats via two indexed count queries (offloaded; local counted like the offload queue), short-transient cached.
- **Never bust the stats cache per attachment.** `Helpers::flush_media_stats()` is deferrable — bulk passes and CLI loops wrap their work in `Helpers::defer_media_stats_flush( true/false )` so it fires once. Per-item flushing costs ~2 queries per attachment for a value nothing reads mid-run.
- **Never read attachment metadata in an unbounded loop.** Prime with `update_meta_cache( 'post', $chunk )` in chunks and drop the cache between them (see `Helpers::get_retention_preflight()`), or select just the meta rows you need 500 IDs at a time (`MediaCounters::rebuild()`).
- Bulk state and batch options are `autoload => false`.

## Reference Plugin (read-only, for patterns)

- `../advanced-media-offloader/` — offload engine concepts (observers, retention, key collection, bulk processing), and the source of the `dirname()` bug class in Safety Invariant #5.

This plugin's own skeleton, admin UX and conventions are settled — follow what is already in `includes/` and `src/`, not an outside plugin.

## Documentation & Screenshots

### Public User Docs (`docs/` — Astro Starlight)
The public documentation is built with Astro Starlight in `docs/` and deployed to Vercel (see `docs/README.md`).
- Run `pnpm --dir docs run dev` to test documentation locally at `http://localhost:4321`.
- Run `pnpm --dir docs run build` to verify the static site builds without broken links or syntax errors.
- The changelog page is generated automatically from `readme.txt` and `changelog.txt` by `docs/scripts/changelog.mjs`. Always update `readme.txt`, never `changelog.md`.
- WordPress.org truncates a readme Changelog over **5,000 words**. `readme.txt` keeps only the two latest releases; `scripts/sync-versions.mjs` moves older ones into the top of `changelog.txt` on the release PR (same `= x.y.z =` format, ships in the bundle). Upgrade notices all stay in `readme.txt`. The docs page skips `= Unreleased =`. Root `CHANGELOG.md` is Release Please's commit log — never edit it, and it is excluded from the plugin.
- Keep docs in sync with user-facing features, settings, CLI flags, provider fields, and hooks in the same commit.

### Screenshot Automation (`pnpm screenshots`)
Run `pnpm screenshots` to generate high-resolution screenshots using Puppeteer Core against your local WordPress install:
- Generates clean 2x retina captures (`docs/public/images/screenshots/<view>.png`).
- Generates callout-annotated diagrams (`docs/public/images/screenshots/<view>-marked.png`) for the user documentation.
- Crops and exports `.wordpress-org/screenshot-{1..N}.png` matching official WordPress.org specifications.

The WordPress skills (`wordpress-router`, `wp-plugin-development`, `wp-rest-api`, `wp-block-development`, `wp-phpstan`, `wp-performance`, `wp-plugin-directory-guidelines`, `wp-wpcli-and-ops`, `wp-abilities-*`, `wp-interactivity-api`) are installed locally in `.claude/skills/`.

