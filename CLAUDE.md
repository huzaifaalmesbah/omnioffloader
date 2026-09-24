# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

**Pre-release, version `0.1.3`.** The full design lives in `PLAN.md` — read it before making architectural decisions. The phase map in PLAN.md §11 (skeleton → offload → bulk → Bring Back → release candidate) describes development phases, not ship versions.

Released so far: `0.1.0` (initial release, Cloudflare R2 only), `0.1.1` (five more providers, bulk-queue reliability work), `0.1.2` (custom batch quantity limit for Bulk Offload & Bring Back, UI consistency, CLI `--limit` restore, plus LiteSpeed Cache, Breeze, and Object Cache real-time reliability fixes), `0.1.3` (apply a changed retention policy to existing media — Tools page + `wp omnioffloader retention` — and an in-admin WP-CLI reference). See `readme.txt` for the user-facing changelog — keep it updated in the same commit as any user-visible change.

> ⚠️ **VERSION CEILING: `0.9.0`.** Never bump, tag, or release version `1.0.0` (or anything ≥ 1.0.0) without the owner's explicit permission in that conversation. All planned work ends at 0.9.0.

When bumping the version, three places must move together: the `Version:` header and `OMNIOFFLOADER_VERSION` in `omnioffloader.php`, and `Stable tag` in `readme.txt`. A migration for the new version goes in `Core\Migrator`.

## What This Plugin Does

Offloads WordPress media library files to cloud object storage and serves them from a CDN. Three retention policies control what stays on the server. The signature feature is **Bring Back**: restoring offloaded media from the cloud back to the server (per-item and bulk), safely reversing an offload.

Providers shipped (`Providers\ProviderRegistry::$providers`): `amazon_s3`, `cloudflare_r2`, `backblaze_b2`, `digitalocean_spaces`, `wasabi`, and `s3_compatible` (generic, user-supplied endpoint/region, custom display name). `ProviderRegistry::$aliases` maps legacy keys (`aws`, `aws_s3`, `dos`, `digitalocean`, `minio`) onto canonical ones.

## Build Commands

```bash
npm start            # Development build with watch mode (wp-scripts)
npm run build        # Production build
npm run check-types  # tsc --noEmit — strict TypeScript check
npm run lint:js      # ESLint (WordPress TS config)
npm run bundle       # Build + distribution zip (excludes dev files per .distignore)
npm run bundle:org   # Same, shaped for the WordPress.org SVN layout
composer phpstan     # PHPStan level 6+ (szepeviktor/phpstan-wordpress)
composer phpcs       # WordPress Coding Standards
```

Run `check-types`, `lint:js`, `phpstan` and `phpcs` before committing — GitHub Actions only builds and deploys, so nothing else catches a regression. Translations come from translate.wordpress.org; there is no local i18n build step and no `languages/` folder.

### Integration tests

Not PHPUnit — `wp eval-file` scripts under `tests/`, run against a real WordPress install:

```bash
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php 20 myprefix
wp eval-file tests/integration-bulk-rest.php   # needs real credentials; hits the live bucket
```

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
│                       #   Deactivator, Uninstaller, Migrator (version-gated), Helpers
├── Api/                # REST controllers extending BaseController (namespace omnioffloader/v1)
├── Providers/          # ProviderInterface, AbstractS3Provider, ProviderRegistry,
│                       #   AmazonS3, CloudflareR2, BackblazeB2, DigitalOceanSpaces,
│                       #   Wasabi, S3Compatible
├── Services/           # AttachmentUploader, AttachmentRestorer (Bring Back), LocalCleaner,
│                       #   RetentionApplier, AbstractBulkProcessor + BulkOffloadProcessor /
│                       #   BulkRestoreProcessor / BulkRetentionProcessor
├── Offload/            # Offloader + Observers/ — one WordPress hook per observer class
├── Admin/              # Settings (menu + React app enqueue)
├── Cli/                # CommandRegistrar, OffloadCommand, RestoreCommand, RetentionCommand,
│                       #   StatusCommand
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
- **Items are claimed out of the batch before they run.** The parent removes an item only after `task()` returns, so a task killed by `max_execution_time` is retried forever and one oversized file wedges the run.
- **`current` in the state records the in-flight ID**, so `recover_lost_item()` can report a killed worker instead of stalling the progress bar below `total`.
- **Circuit breaker** at `FAILURE_LIMIT` consecutive failures (filter `omnioffloader_bulk_failure_limit`) → status `failed` with a message.
- **`process_queue_now()` only works when the queue has been quiet for `STALL_SECONDS`.** It is the self-heal for hosts where the loopback is blocked; without the stall check every 2s poll would hold a PHP worker for 8s.
- **`cancel_now()` tears down in-request** rather than via the parent's loopback dispatch, which never arrives on loopback-blocked hosts.
- **`completed()` refuses to overwrite a non-`running` status**, so a late worker cannot report a cancelled run as done.
- **`purge_stored_data()` is static** so deactivation/uninstall can clean batch rows, run status, process lock and cron without booting the engine.

Queue state statuses: `idle` · `running` · `done` · `cancelled` · `failed`.

### Adding a cloud provider (the point of the design)

1. Create `includes/Providers/{Name}.php` extending `AbstractS3Provider` — supply `get_key()`, `get_name()`, `create_client()` and `credential_fields()`.
2. Add one line to `ProviderRegistry::$providers`.

That's it. `credential_fields()` is served as JSON to the React app, which renders the form generically — **zero JS changes per provider**.

`AbstractS3Provider` handles the rest (plus the optional `list_object_sizes()` that `RetentionApplier` uses to verify an attachment's files in one request): `upload_file()` switches to `MultipartUploader` above `MULTIPART_THRESHOLD` (64 MB, filter `omnioffloader_multipart_threshold`) and aborts the multipart upload on failure; every call carries `connect_timeout`; ACL is omitted unless `omnioffloader_object_acl` returns one (R2 rejects the header, so never add it unconditionally).

### Frontend (`src/` — TypeScript React SPA)

```
src/
├── index.tsx           # Entry → renders into #omnioffloader-admin-app
├── App.tsx             # Router via view= query param
├── types/              # ★ Single source of truth for all REST payload shapes
│   └── global.d.ts     # window.omnioffloader declaration (in tsconfig "files")
├── api/                # Typed apiFetch wrappers (core, provider, offload, bringBack, retention)
├── context/            # CoreContext: settings, provider, stats, global dirty/save state
├── hooks/              # useBulkOffload, useBringBack, useRetentionSync
├── pages/              # Dashboard, Settings, Offload, BringBack, Tools
├── components/
│   ├── layout/         # Sidebar (logo + 2-line nav), Header (icon badge + page title),
│   │                   #   SaveBar (sticky bottom save bar), MainLayout
│   ├── ui/             # Card, ProgressBar, StatCard, ActivityLog, BatchScopeSelector
│   ├── settings/       # CredentialFields, TestConnection
│   └── tools/          # RetentionSyncCard, CliReference (mirrors includes/Cli docblocks —
│                       #   update it whenever a command or flag changes)
└── utils/              # constants.ts holds RouteMeta[] (icon/title/subtitle per page —
                        #   Sidebar and Header render from this one source)
```

UI design (PLAN.md §6.1): full-page app, left sidebar with logo top + active-item accent highlight, content header showing current page icon/name/subtitle, **sticky bottom SaveBar** ("All changes saved" / "Save changes"), white rounded cards on light gray background, blue accent (`#135dbe` / `#0e4690`).

Progress polling (`useBulkOffload`, `useBringBack`, `useRetentionSync`) uses a **chained `setTimeout` with backoff** (2s → 6s), never `setInterval` — a poll can make the server process a batch, so an interval would stack requests whenever one outlasts its period.

### TypeScript rules (Gutenberg-style)

- `tsc` is **type-check only** (`noEmit`); Babel via wp-scripts transpiles. `isolatedModules` must stay on.
- `strict: true` + `noImplicitReturns` + `noFallthroughCasesInSwitch`. No `any` — use `unknown` + narrowing; `@ts-expect-error` with reason over `@ts-ignore`.
- Every REST request/response shape lives in `src/types/` and must match the PHP controller's `args` schema / response. When you change one side, change the other in the same commit.
- `@wordpress/*` packages ship their own types; don't add `@types/wordpress__*` unless a package lacks them.

## REST API (`omnioffloader/v1`)

All mutations via REST; `@wordpress/api-fetch` sends the nonce. Every route: explicit `permission_callback` (`manage_options`, or `upload_files` for the per-item media routes), `args` with type/sanitize callbacks **plus `'validate_callback' => 'rest_validate_request_arg'`** (core skips schema checks — type, enum, min/max — when only a sanitizer is given), `WP_REST_Server` method constants, `rest_ensure_response()` / `WP_Error` with status + stable `omnioffloader_*` error codes.

- `GET/POST /core/settings` · `GET /core/providers` · `GET /core/stats`
- `GET/POST /provider/credentials` · `POST /provider/test-connection` — all take a provider arg
- `POST /offload/attachment/{id}` · `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel` · `GET /offload/errors`
- `GET /bring-back/preflight` · `POST /bring-back/attachment/{id}` · `POST /bring-back/bulk` · `GET /bring-back/bulk/progress` · `POST /bring-back/bulk/cancel`
- `POST /bulk/action` (Media Library bulk offload/restore of selected IDs, `maxItems` 1000) · `GET /offload/attachment-status` (Media Library live badges) — both `upload_files`, filtered per item
- `GET /retention/preflight` · `POST /retention/bulk` · `GET /retention/bulk/progress` · `POST /retention/bulk/cancel` — apply the **saved** retention policy to already-offloaded media (the client never sends a policy)

The three `bulk/progress` routes are `GET` but deliberately do work (the stalled-queue self-heal). Keep that in mind before adding caching.

Credentials: `GET` returns secrets masked (`is_set: true`); `POST` ignores masked placeholders so saves never wipe secrets. wp-config constants (`OMNIOFFLOADER_CLOUDFLARE_R2_KEY`, etc.) lock fields (`locked: true`).

## Data Reference

**Options:**
- `omnioffloader_settings` (autoload on) — provider, retention_policy (0/1/2), mirror_delete, path_prefix(+active), object_versioning, bring_back_delete_cloud, remove_data_on_uninstall
- `omnioffloader_version` (autoload on) — for Migrator
- `omnioffloader_credentials` (autoload **on** since 0.1.3 — `is_configured()` reads it on every request) — per-provider credential sub-arrays. Never returned raw via REST.
- `omnioffloader_first_activated_version` (autoload off)
- `omnioffloader_bulk_offload_state` / `omnioffloader_bulk_restore_state` / `omnioffloader_bulk_retention_state` (autoload off) — progress heartbeats
- `omnioffloader_bulk_{offload,restore,retention}_batch_*` / `_status` — queue internals, written by `WP_Background_Process` via `update_site_option` (autoload off on single site). Never leave these behind; `purge_stored_data()` owns their cleanup.

**Attachment meta:** `omnioffloader_offloaded`, `omnioffloader_offloaded_at`, `omnioffloader_provider` (provider at offload time — Bring Back resolves this, not the active provider), `omnioffloader_bucket`, `omnioffloader_path`, `omnioffloader_object_version`, `omnioffloader_retention` (policy the attachment's local files currently follow — missing means 0; only advances once every file was handled), `omnioffloader_error_log`.

**Retention policy values:** `0` retain local (default) · `1` smart cleanup (delete thumbs, keep original) · `2` full migration (delete all local).

The policy is applied once at offload time (`LocalCleaner`). Changing the setting later does nothing to existing media until `RetentionApplier` runs (Tools page, `wp omnioffloader retention`). Stricter: every local file is verified in the bucket **by size** before deletion; missing objects are uploaded first; mismatches are kept and logged. Looser: `AttachmentRestorer::restore_local_copies()` downloads files while the attachment stays offloaded.

**Transients:** `omnioffloader_media_stats`, `omnioffloader_restore_preflight`, `omnioffloader_retention_preflight`, `omnioffloader_activation_redirect`.

**Cron:** watchdog event only while a bulk job runs; unschedule when idle.

**Constants:** `OMNIOFFLOADER_VERSION`, `OMNIOFFLOADER_DIR`, `OMNIOFFLOADER_URL`, `OMNIOFFLOADER_ASSETS_URL`, `OMNIOFFLOADER_BASENAME`, `OMNIOFFLOADER_FILE`, plus `OMNIOFFLOADER_DISABLE_ENGINE` (kill switch).

## Public Hooks

Filters: `omnioffloader_cloud_providers`, `omnioffloader_object_acl`, `omnioffloader_attachment_key`, `omnioffloader_attachment_delete_keys`, `omnioffloader_should_offload_attachment`, `omnioffloader_should_apply_retention`, `omnioffloader_media_block_names`, `omnioffloader_multipart_threshold`, `omnioffloader_bulk_failure_limit`.
Actions: `omnioffloader_before_offload`, `omnioffloader_after_offload`, `omnioffloader_before_restore`, `omnioffloader_after_restore`.

## Safety Invariants (never violate)

1. **Never delete a local file before the cloud upload is verified** (`object_exists` on the main key).
2. **Never delete a cloud object before the local restore is verified** (all expected files present; delete-cloud is opt-in, default OFF).
3. Restores write atomically: temp file + `rename()`; destination confined to `wp_upload_dir()` basedir (realpath check).
4. On any failure, an attachment must always have at least one valid copy and truthful meta; errors go to `omnioffloader_error_log`.
5. Guard `dirname()` returning `'.'` when building object keys (produces broken `./file.jpg` keys — known bug class in the reference plugin).
6. Never log or echo credentials; REST never returns raw secrets.
7. CLI destructive paths (`--delete-cloud`) require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.
8. Only one bulk job at a time — offload, restore and retention runs (admin queue or CLI) all read or delete the same local files. REST start routes check `Plugin::bulk_job_conflict()`; CLI commands refuse while `Plugin::get_active_bulk_job()` is non-empty (`--force` overrides), hold their queue's process lock, and announce themselves with `Plugin::claim_cli_job()` (site transient `omnioffloader_cli_job`, 10 min, renewed) so admin jobs of every type see them. Single-item Bring Back is refused while a retention run is active.
9. **Per-item permissions mirror WordPress.** Offload / Bring Back of one attachment needs `edit_post` on it; deleting its cloud copy needs `delete_post` (`AttachmentHelper::current_user_can_manage()` / `current_user_can_delete_cloud()` / `filter_manageable_ids()`). `upload_files` alone is never enough to act on someone else's media. Whole-library jobs and settings stay `manage_options`.

## Performance Rules

- Frontend (non-admin) footprint is URL-rewrite filters only — no queries, writes, or HTTP. `render_block` only looks up IDs for `ContentImageTagObserver::MEDIA_BLOCKS` (filter `omnioffloader_media_block_names`); `<video>/<audio>/<source>` URLs are resolved in one batched `IN (…)` query per content and memoized per request — never `attachment_url_to_postid()` per src.
- Preflights (`get_restore_preflight`, `get_retention_preflight`) scan every offloaded attachment's metadata. The UI reads the transient by default and forces a refresh only when a run ends — never on a polling interval.
- No remote calls outside explicit user actions (Test Connection, offload/restore jobs).
- Stats via one indexed postmeta count query, short-transient cached.
- **Never bust the stats cache per attachment.** `Helpers::flush_media_stats()` is deferrable — bulk passes and CLI loops wrap their work in `Helpers::defer_media_stats_flush( true/false )` so it fires once. Per-item flushing costs ~2 queries per attachment for a value nothing reads mid-run.
- **Never read attachment metadata in an unbounded loop.** Prime with `update_meta_cache( 'post', $chunk )` in chunks and drop the cache between them (see `Helpers::get_restore_preflight()`).
- Bulk state and batch options are `autoload => false`.

## Reference Plugin (read-only, for patterns)

- `../advanced-media-offloader/` — offload engine concepts (observers, retention, key collection, bulk processing), and the source of the `dirname()` bug class in Safety Invariant #5.

This plugin's own skeleton, admin UX and conventions are settled — follow what is already in `includes/` and `src/`, not an outside plugin.

The WordPress skills (`wordpress-router`, `wp-plugin-development`, `wp-rest-api`, `wp-block-development`, `wp-phpstan`, `wp-performance`, `wp-plugin-directory-guidelines`, `wp-wpcli-and-ops`, `wp-abilities-*`, `wp-interactivity-api`) are installed locally in `.claude/skills/`.
