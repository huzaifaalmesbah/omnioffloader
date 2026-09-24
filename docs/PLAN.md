# OmniOffloader — Design & Architecture

> **Status:** built through **`0.1.3`** (pre-release; `0.1.3` not yet published). This began as a pre-code design document and is now the living record of the design *as built*. Where this file and the code disagree, **the code wins** — fix this file.
> **Upcoming work:** [`roadmap/future-plan.md`](roadmap/future-plan.md). **Dev conventions:** [`../CLAUDE.md`](../CLAUDE.md).
> **Reference:** `advanced-media-offloader` (v4.4.4) — offload engine concepts: provider abstraction, retention policy, bulk background processing, key collection. Read-only; the source of the `dirname()` bug class guarded in §10.

**Divergences from the original plan, at a glance** (detail in the sections named):

| Planned | Built | Why |
|---|---|---|
| Custom `includes/Autoloader.php`; Composer only for the SDK (§2, §4.1) | Composer PSR-4 for everything | `770dde6`. |
| PHP-Scoper-prefixed AWS SDK (§2) | Unprefixed; trimmed to S3 + Sts via `removeUnusedServices` | Scoping never applied; trimming is for zip size, not collision safety. Open risk, §10c. |
| Cloudflare R2 only until `1.0.0` (§11) | Six providers since `0.1.1` | `AbstractS3Provider` made each provider a ~90-line file. |
| `Core\Cron` watchdog class | Nothing — `WP_Background_Process` ships its own healthcheck | Redundant. |
| Local i18n build (`languages/`, `bin/`) | Translations via translate.wordpress.org | Removed in `2538735`. |
| `WP_Background_Process` used as-is | Subclassed via `AbstractBulkProcessor`, plus a DB mutex | Network transfers, not short CPU tasks; §7.5. |
| Retention applied once, at offload | Also applied later to existing media (`0.1.3`) | Changing the setting left old media untouched; §7.3. |
| Media actions for anyone with `upload_files` | Offload / Bring Back are **administrator-only** (`0.1.3`) | Authors could act on others' media and delete cloud copies; §10. |
| WP admin notices hidden on the plugin page | Not implemented — core notices still show; the app adds its own snackbars | Never needed in practice. |
| No front-end assets besides the app | `assets/` holds the Media Library status script and styles | Live status badges and row actions in the Media Library. |

---

## 1. Vision

Offload WordPress media library files to cloud object storage to save server disk space and serve media from a CDN.

- **Providers:** Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, and a generic S3-compatible option. The provider registry + abstract base make each one a single new file with **zero JS changes**.
- **Key differentiator:** **Bring Back** — restore offloaded media from the cloud back to the server (per item and bulk), safely reversing an offload.
- **Three retention policies**, which can be changed later and **applied to media that is already offloaded** (both directions).
- **Modern admin:** single-page React app in TypeScript — everything through the REST API.
- **Works on any host:** bulk jobs are built for a 1-CPU shared plan as much as a 16-core box (§7.5).
- **Every job also runs from WP-CLI.**

---

## 2. Plugin Identity

| Item | Value |
|---|---|
| Plugin name | OmniOffloader — Multi-Cloud Media Offload & CDN |
| Folder / slug | `omnioffloader` |
| Main file | `omnioffloader.php` |
| **Current version** | **`0.1.3` (pre-release)** — version ceiling `0.9.0`, §11 |
| License | `GPL-2.0-or-later` (the "or later" makes bundling the Apache-2.0 AWS SDK compatible) |
| Text domain | `omnioffloader` |
| PHP namespace | `OmniOffloader\` → `includes/` (Composer PSR-4) |
| Prefix | options/meta/hooks `omnioffloader_`, constants `OMNIOFFLOADER_` |
| REST namespace | `omnioffloader/v1` |
| Requires | WP 6.6+, PHP 8.1+ (tested up to WP 7.1) |
| Admin UI | React SPA in TypeScript via `@wordpress/scripts`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n`, `@wordpress/icons`, `@wordpress/url` |
| SDK | AWS SDK for PHP (`S3Client` speaks to every S3-compatible provider), trimmed to **S3 + Sts** |

---

## 3. Folder & File Structure (as built)

```
omnioffloader/
├── omnioffloader.php                # Bootstrap: constants, Composer autoload (notice if missing), hooks
├── uninstall.php                    # Delegates to Core\Uninstaller (opt-in cleanup)
├── readme.txt                       # WP.org readme — update with every user-visible change
├── CLAUDE.md                        # Dev docs for AI/contributors
├── docs/                            # Project docs — excluded from bundle + WP.org deploy
│   ├── README.md · PLAN.md (this) · AUDIT.md · abilities-audit.md
│   ├── roadmap/{index, v0.1.0, future-plan}.md · issues/index.md · security/index.md
├── package.json · tsconfig.json     # wp-scripts build, strict TS (type-check only)
├── composer.json                    # AWS SDK (S3+Sts) + dev tools (phpstan, wpcs)
├── phpstan.neon.dist                # PHPStan level 8
├── phpcs.xml.dist                   # WordPress Coding Standards
├── .distignore / .gitattributes     # Release excludes (dev files, src/, docs/)
├── .wordpress-org/                  # Banners, icons, blueprint
├── .github/workflows/               # Build + deploy to WP.org SVN (10up action, uses .distignore)
├── scripts/bundle.js                # Local release zip (whitelist: main file, readme, uninstall,
│                                    #   includes, assets, build, vendor)
├── tests/                           # wp eval-file integration scripts — §10e
├── build/                           # wp-scripts output (index.js/css, index-rtl.css) — not committed
├── assets/                          # Media Library status UI (plain JS/CSS, not built)
│   ├── js/media-status.js           #   live status badges, row actions, bulk actions, polling
│   └── css/media-status.css
├── src/                             # React admin app (TypeScript) → #omnioffloader-admin-app
│   ├── index.tsx · index.scss · App.tsx      # router via ?view=
│   ├── types/      # settings, provider, media, progress (+ retention), global.d.ts
│   ├── api/        # core, provider, offload, bringBack, retention
│   ├── context/CoreContext.tsx      # draft + saved settings, providers, stats, notices, save
│   ├── hooks/      # useBulkOffload, useBringBack, useRetentionSync
│   ├── pages/      # Dashboard, Settings, Offload, BringBack, Tools
│   ├── components/
│   │   ├── layout/   # Sidebar, Header, SaveBar, MainLayout
│   │   ├── ui/       # Card, ProgressBar, StatCard, ActivityLog, BatchScopeSelector, RunAnnouncer
│   │   ├── settings/ # CredentialFields, TestConnection
│   │   └── tools/    # RetentionSyncCard, CliReference
│   └── utils/      # constants (RouteMeta[], RETENTION_LABELS), radioGroup, formatBytes, errorHelper
├── includes/
│   ├── Abstracts/WP_Background_Processing/   # Vendored (Delicious Brains, GPL-2.0+), credited
│   ├── Core/       # Plugin, Activator, Deactivator, Uninstaller, Migrator, Helpers
│   ├── Api/        # BaseController + Core, Provider, Offload, BringBack, Retention controllers
│   ├── Providers/  # ProviderInterface, AbstractS3Provider, ProviderRegistry,
│   │               #   AmazonS3, CloudflareR2, BackblazeB2, DigitalOceanSpaces, Wasabi, S3Compatible
│   ├── Services/   # AttachmentUploader, AttachmentRestorer, LocalCleaner, RetentionApplier,
│   │               #   AsyncUploadProcessor, AbstractBulkProcessor +
│   │               #   BulkOffloadProcessor / BulkRestoreProcessor / BulkRetentionProcessor
│   ├── Offload/    # Offloader + Observers/ (one hook family per class):
│   │               #   Upload, UrlRewrite, Srcset, ContentImageTag, Delete, UniqueFilename,
│   │               #   MediaLibraryFilter, OffloadStatus, RowActions
│   ├── Admin/Settings.php           # Menu + app enqueue (+ RTL), localize, translations
│   ├── Cli/        # CommandRegistrar, CliJobGuard (trait), Offload/Restore/Retention/Status commands
│   ├── Traits/     # OptionsHelper, AttachmentHelper
│   └── templates/admin-app.php
└── vendor/                          # Composer
```

**Planned but never built** (do not resurrect without a reason): `Core\Cron`, `Admin\AdminBar`, `Cli\AbstractCommand` (replaced by the `CliJobGuard` trait), `SrcsetMetaObserver`, `AttachedFileObserver`, `UpdateObserver`, `languages/`, `hooks/{useSettings,useProvider,useMediaStats}`, `PageLayout`.

---

## 4. Backend Architecture

### 4.1 Bootstrap flow

`omnioffloader.php`:
1. Define constants `OMNIOFFLOADER_VERSION`, `_DIR`, `_URL`, `_ASSETS_URL`, `_BASENAME`, `_FILE`.
2. If `vendor/autoload.php` is missing → admin notice and **return** (no fatal). Otherwise require it.
3. `register_activation_hook` → `Core\Activator` (first-version option, version option, 30 s activation-redirect transient); deactivation → `Core\Deactivator`.
4. `plugins_loaded` → `Core\Plugin::get_instance()->run()`.
5. Under `WP_CLI` → `Cli\CommandRegistrar::register()`.

`Core\Plugin::run()`:
- Always: `CoreController`, `ProviderController`, `Admin\Settings`, `OffloadController`, `BringBackController`, `RetentionController`.
- **Engine** — only when a provider is configured (`is_configured()`) **and** `Aws\S3\S3Client` exists, and `OMNIOFFLOADER_DISABLE_ENGINE` is not set: the active provider, `AsyncUploadProcessor`, `BulkOffloadProcessor`, `BulkRestoreProcessor`, `BulkRetentionProcessor` (constructed on every request — their constructors register the loopback and cron-healthcheck hooks), and `Offloader` (registers all observers).
- `maybe_upgrade()` on `init` priority 5 → `Migrator::run()` in every context, **including the queues' own worker requests** — destructive migrations must skip a live run.
- `is_protected_meta` filter: all `omnioffloader_*` meta is protected (never editable as a custom field).
- Job coordination helpers: `get_active_bulk_job()`, `bulk_job_conflict()`, `claim_cli_job()` / `release_cli_job()` (§7.5).

### 4.2 Provider abstraction

```
ProviderInterface
   └── AbstractS3Provider (abstract)          ← ~95% of logic
          └── AmazonS3 · CloudflareR2 · BackblazeB2 · DigitalOceanSpaces · Wasabi · S3Compatible
```

| `ProviderInterface` method | Purpose |
|---|---|
| `get_key()` / `get_name()` | `cloudflare_r2` / "Cloudflare R2" (`S3Compatible` returns a custom name) |
| `get_bucket(): ?string` · `get_domain(): string` | Bucket; public/CDN base URL for rewrites |
| `upload_file($file, $key): string\|false` | Single PUT, or multipart ≥ threshold |
| `download_file($key, $dest): bool` | Temp file in the destination dir + `rename()` |
| `delete_objects(array $keys): bool` | Batched 1,000/request, per-object fallback |
| `object_exists($key)` · `object_size($key)` | HEAD |
| `check_connection(): true\|WP_Error` | `headBucket`, 5 s timeouts |
| `credential_fields(): array` | Field defs served as JSON; the React form renders them generically |

`AbstractS3Provider` extras: `list_object_sizes($prefix)` (one `ListObjectsV2` for all of an attachment's files — used by retention; not part of the interface, callers check `method_exists`), `MultipartUploader` at `MULTIPART_THRESHOLD` (64 MB, filter `omnioffloader_multipart_threshold`, floor 5 MB) with abort on failure, `connect_timeout` on every call, ACL only when `omnioffloader_object_acl` returns one (R2 rejects the header). `log_error()` writes to the PHP log only when `WP_DEBUG` (filter `omnioffloader_log_provider_errors`); failures are always recorded on the attachment.

`ProviderRegistry`: `key => class` map (filter `omnioffloader_cloud_providers`), aliases `aws`, `aws_s3` → `amazon_s3`; `dos`, `digitalocean` → `digitalocean_spaces`; `minio` → `s3_compatible`. New provider = one class + one registry line.

### 4.3 Credentials

1. **wp-config constants win** (`OMNIOFFLOADER_{PROVIDER}_{FIELD}`, e.g. `OMNIOFFLOADER_CLOUDFLARE_R2_SECRET`) → `locked: true` in the UI.
2. Otherwise the `omnioffloader_credentials` option (per-provider sub-arrays, **autoload off** — secrets stay out of the shared alloptions cache).
3. REST `GET` masks secrets (`is_set: true`, password values empty); `POST` ignores masked placeholders so saving never wipes a secret; `values` must be a string map.

### 4.4 Attachment meta (state model — all protected)

| Meta key | Meaning |
|---|---|
| `omnioffloader_offloaded` | `1` when files live in the cloud |
| `omnioffloader_offloaded_at` | timestamp |
| `omnioffloader_provider` / `_bucket` / `_path` | where the files are (provider at offload time — Bring Back resolves this, not the active provider) |
| `omnioffloader_object_version` | version segment when object versioning is on |
| `omnioffloader_retention` | policy the attachment's **local files currently follow** (missing = 0); only advances once every file was handled |
| `omnioffloader_status` | transient UI state: `queued` / `processing` / `failed` |
| `omnioffloader_error_log` | last errors (`{time, message}`, capped at 10; no absolute server paths) |

### 4.5 Options & transients

| Option | Autoload | Contents |
|---|---|---|
| `omnioffloader_settings` | on | provider, retention_policy, mirror_delete, path_prefix(+active), object_versioning, async_upload, bring_back_delete_cloud, remove_data_on_uninstall |
| `omnioffloader_version` | on | for `Migrator` |
| `omnioffloader_credentials` | off | per-provider credentials |
| `omnioffloader_first_activated_version` | off | |
| `omnioffloader_bulk_{offload,restore,retention}_state` | off | progress heartbeat (status, total, processed, errors, message, current, current_item, recent[]; retention adds policy, freed_bytes, repaired) |
| `{prefix}_bulk_*_batch_*` / `_status`, `{prefix}_async_upload_*` | off | queue internals (site options). `{prefix}` = `omnioffloader`, or `omnioffloader_{blog_id}` on multisite. `purge_stored_data()` owns cleanup. |

Transients: `omnioffloader_media_stats`, `omnioffloader_restore_preflight`, `omnioffloader_retention_preflight` (60 s), `omnioffloader_activation_redirect` (30 s), site transients `{prefix}_cli_job` (10 min, renewed) and `{prefix}_async_upload_has_queue`.

---

## 5. REST API (`omnioffloader/v1`)

Conventions: registered on `rest_api_init`; explicit `permission_callback` on every route; `WP_REST_Server` constants; every arg has `type` + `sanitize_callback` **and** `'validate_callback' => 'rest_validate_request_arg'` (core skips schema checks otherwise); `rest_ensure_response()`; `WP_Error` with status and stable `omnioffloader_*` codes; shapes mirrored in `src/types/`. All `omnioffloader/v1` responses send no-cache headers (LiteSpeed, Breeze, proxies).

| Route | Permission | Notes |
|---|---|---|
| `GET/POST /core/settings` | admin | POST validates every key against the defaults (types, `retention_policy` enum, no unknown keys); GET returns known keys only |
| `GET /core/providers` · `GET /core/stats` | admin | stats: total / offloaded / local, `?refresh=1` bypasses cache |
| `GET/POST /provider/credentials` · `POST /provider/test-connection` | admin | unknown provider → `omnioffloader_invalid_provider` (400) |
| `POST /offload/attachment/{id}` | admin + `edit_post` | synchronous single offload |
| `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel` | admin | `limit` optional |
| `GET /offload/errors` | admin | `per_page` ≤ 100, `page`, newest first, `X-WP-Total` / `X-WP-TotalPages` |
| `POST /bulk/action` | admin + per-item `edit_post` | Media Library bulk offload/restore of selected IDs (`maxItems` 1000); can append to a running queue of the same kind |
| `GET /offload/attachment-status` | `upload_files` + per-item `edit_post` | read-only badges; bucket name only for admins; only admins' polls drive stalled queues |
| `GET /bring-back/preflight` · `POST /bring-back/attachment/{id}` | admin (+ `edit_post`; `delete_post` for `delete_cloud`) | omitted `delete_cloud` follows the setting; refused while a retention run is active |
| `POST /bring-back/bulk` · `GET …/progress` · `POST …/cancel` | admin | |
| `GET /retention/preflight` · `POST /retention/bulk` · `GET …/progress` · `POST …/cancel` | admin | always applies the **saved** policy — the client never sends one |

⚠️ The three `bulk/progress` routes and `attachment-status` are `GET` but **do work**: they are the self-heal for loopback-blocked hosts (only after the queue is quiet for `STALL_SECONDS`). Do not add caching.

Starting any bulk job is refused (409 `omnioffloader_bulk_running`) while another job — admin queue **or WP-CLI** — is running (`Plugin::bulk_job_conflict()`).

---

## 6. React Admin App

### 6.1 UI design (agreed 2026-08-12, "Optimazz"-style layout)

```
┌────────────┬──────────────────────────────────────────────┐
│  LOGO      │  [icon]  Page Title / subtitle               │ ← header
│ ▍Dashboard │──────────────────────────────────────────────│
│  Settings  │            Page content (cards)              │
│  Offload   │                                              │
│  Bring Back│                                              │
│  Tools     │──────────────────────────────────────────────│
│            │            All changes saved   [Save changes]│ ← sticky SaveBar
└────────────┴──────────────────────────────────────────────┘
```

Left sidebar (logo, two-line nav items, accent active state, `aria-current`), header from the same `RouteMeta[]`, sticky SaveBar driven by global dirty state, white rounded cards on a light-gray canvas, accent `#135dbe` / `#0e4690`, tokens as CSS variables in `src/index.scss`. Notices are `@wordpress/components` snackbars (success auto-dismiss after 5 s; errors stay until dismissed).

### 6.2 Integration & pages

- Menu: top-level "OmniOffloader" (`manage_options`), `templates/admin-app.php`. Assets load only on this hook: `build/index.js` + `index.css` (swapped for `index-rtl.css` in RTL locales), `wp_localize_script(…, 'omnioffloader', { version, sdk_loaded, upload_url, media_url })`, `wp_set_script_translations`. Notice if `build/` is missing. One-time redirect here after activation (checked on `load-plugins.php` only).
- Routing via `?view=`: `dashboard`, `settings`, `offload`, `bring-back`, `tools`.
- **Dashboard** — stat cards, provider status (from **saved** settings), quick actions.
- **Settings** — provider cards (keyboard radio group), generic `CredentialFields`, Test Connection, retention policy (with a notice when existing media follows an older policy), behaviour toggles (async upload, mirror delete, object versioning, path prefix), uninstall cleanup. Warns before leaving with unsaved changes.
- **Offload** — counts, batch scope ("all" or a custom amount), start/cancel, progress, activity log.
- **★ Bring Back** — disk-space preflight, batch scope, keep-or-delete-cloud choice (default keep), progress.
- **Tools** — *Apply Retention Policy to Existing Media* (preflight, batch scope, Full Migration confirmation, progress, freed bytes) and a **WP-CLI reference** (every command, options, copy buttons, link to the matching screen). `CliReference.tsx` mirrors the CLI docblocks.

**Polling** (`useBulkOffload`, `useBringBack`, `useRetentionSync`): chained `setTimeout` with backoff (2 s → 6 s, +250 ms/tick; ≥12 s when the tab is hidden), never `setInterval`; one timer chain at a time; a generation counter drops responses that land after cancel/stop; nothing is scheduled after unmount. Preflights are read from the server cache and forced only when a run ends. Accessibility: card radio groups follow the WAI-ARIA pattern (`src/utils/radioGroup.ts`), progress bars are labelled, `RunAnnouncer` announces start/finish/stop in a polite live region, `prefers-reduced-motion` disables animations.

### 6.3 Media Library integration (`assets/js/media-status.js` + observers)

Status column and grid badges, attachment-details badge and meta box, "Offloaded / Local" filter, row actions and bulk actions ("Offload to Cloud", "Bring Back to Server") — **actions shown to administrators only**. Live updates poll `/offload/attachment-status` (up to 100 visible IDs, `known` map to skip unchanged HTML); forced checks only on the Media Library screen or during a known bulk run.

---

## 7. Feature Behavior

### 7.1 Offload on upload

`UploadObserver` on `wp_generate_attachment_metadata` (priority 999; `add_attachment` for non-images). With **Background Cloud Offload** on (default), the attachment is queued in `AsyncUploadProcessor` so the upload request returns immediately; otherwise (or in a WP-CLI terminal) it offloads inline. Filter `omnioffloader_use_async_upload`; `omnioffloader_should_offload_attachment` can skip items. `AttachmentUploader` uploads main + `original_image` + modern-format `sources` + all sizes, verifies the main object exists, writes meta, then `LocalCleaner` applies the retention policy. Collected paths containing `..` are dropped (never uploaded, deleted or restored).

### 7.2 URL rewriting (front end = filters only)

`UrlRewriteObserver` (`wp_get_attachment_url`), `SrcsetObserver`, `ContentImageTagObserver`:
- `wp_content_img_tag` for `<img>` in content.
- `render_block` only for media blocks (`MEDIA_BLOCKS`: image, video, audio, file, cover, media-text, gallery — filter `omnioffloader_media_block_names`); their attachment IDs are primed in one batch on `the_content` priority 8, before `do_blocks` (9).
- `<video>/<audio>/<source>` URLs in content and media shortcodes are resolved with **one batched query per content**, memoized per request (key ignores the shortcode's `?_=N`, which is preserved on output).

### 7.3 Retention policy

| Value | Label | Local files after offload |
|---|---|---|
| `0` | **Retain Local Files** (default) | Everything kept; cloud is a mirror |
| `1` | **Smart Local Cleanup** | Thumbnails deleted, originals kept |
| `2` | **Full Cloud Migration** | Everything deleted locally |

Applied once at offload by `LocalCleaner` (after the upload is verified). **Mirror Delete** (separate toggle): deleting an attachment in WordPress also deletes its cloud objects (incl. editor backups and modern-format sources; filter `omnioffloader_attachment_delete_keys`). Deletes use `wp_delete_file_from_directory()` (uploads-confined).

**Applying a changed policy to existing media (`0.1.3`)** — `RetentionApplier`, run from the Tools page (`BulkRetentionProcessor`) or `wp omnioffloader retention`:
- Eligible = offloaded attachments whose stored `omnioffloader_retention` ≠ the target (missing counts as 0); filter `omnioffloader_should_apply_retention` excludes items (they are not counted as eligible).
- **Stricter** (e.g. 0→1, 0→2, 1→2): each local file is checked in the bucket **by size** before deletion (one `list_object_sizes()` per attachment, HEAD fallback). Missing or size-mismatched cloud copies (e.g. after an image optimizer) are **re-uploaded from the server and verified** first; if that fails, the local file is kept and logged.
- **Looser** (e.g. 2→0): `AttachmentRestorer::restore_local_copies()` downloads the files the policy keeps; the attachment stays offloaded.
- The stored policy only advances when every file was handled, so failures retry on the next run. Preflight: counts to clean/download and estimated bytes (from metadata), free disk space.

### 7.4 ★ Bring Back

`AttachmentRestorer::restore($id, $delete_cloud = false)`:
1. Must be offloaded; provider resolved **from the attachment's meta** (cached per request).
2. Collect all keys (main, sizes, `original_image`, sources).
3. Download each to its uploads path: reject `..` before creating directories, realpath-confine to the uploads basedir, temp file + `rename()`; skip files already present with the same size.
4. Verify every main file exists locally, then clear offload meta (URLs serve locally again).
5. `delete_cloud` (default OFF): delete cloud objects only after verification.
- On failure: cloud copy + meta kept, error logged. **Never leave an attachment with no valid copy.**

### 7.5 ★ Bulk processing (offload, Bring Back, retention)

Three queues extend `AbstractBulkProcessor` (subclasses supply `queue_ids()`, `process_item()`, `STATE_OPTION`). It overrides the vendored `WP_Background_Process` because one task is a **network transfer**. Each behaviour fixes a real production failure — do not undo:

| Behaviour | Why |
|---|---|
| **MySQL `GET_LOCK()` worker mutex** around each pass (released right after `unlock_process()`, before `dispatch()`; falls back to the transient lock without `GET_LOCK`) | The transient process lock is check-then-set; the loopback worker, cron healthcheck and admin poll ran the same batch twice (`processed 12 of 6`). |
| `$queue_lock_time = 600`, renewed after every item (`renew_lock()` does **not** call `lock_process()`) | The parent's 60 s lock expired mid-transfer, starting a second worker. |
| Items **claimed out of the batch before they run** | Otherwise a task killed by `max_execution_time` is retried forever. |
| `current` records the in-flight ID; `recover_lost_item()` reports it | Otherwise `processed` stays short of `total` with no explanation. |
| Circuit breaker at `FAILURE_LIMIT` (10) consecutive failures (filter `omnioffloader_bulk_failure_limit`) | Bad credentials fail identically for every item. |
| `process_queue_now()` only after `STALL_SECONDS` (2) of quiet, capped at `INLINE_TIME_LIMIT` (8 s) | Self-heal for blocked loopbacks without holding a PHP worker on every poll. |
| `cancel_now()` tears down in-request | The parent's cancel relies on a loopback that may never arrive. |
| `completed()` never overwrites a non-`running` status | A late worker must not report a cancelled run as done. |
| `purge_stored_data()` is static | Deactivate / uninstall / migrations clean up without booting the engine. |
| **Per-site identifiers on multisite** (`Helpers::queue_prefix()`) | `WP_Background_Process` stores batches and locks in network-wide site options. |

Queue population skips trashed attachments and rows without `_wp_attached_file` (offload); Bring Back includes trash so cloud objects are not stranded. State is published before the queue is written. Stats flushes are deferred to once per pass. The retention run's target policy is fixed in its state when it starts.

**One job at a time (invariant 8):** `Plugin::get_active_bulk_job()` sees admin queues and WP-CLI runs (`claim_cli_job()`); REST start routes, Media Library bulk actions and CLI commands all check it. Single-item Bring Back is refused during a retention run.

**Throughput is serial** — one attachment at a time; concurrency is planned (`future-plan.md` 2.1) and must keep these invariants.

`AsyncUploadProcessor` (new uploads) extends `WP_Background_Process` directly: one stats flush per pass, 8 s poll-driven passes for admins only.

### 7.6 WP-CLI

`wp omnioffloader offload | restore | retention | status` — see §10d.

---

## 8. Build & Release

| Script | Purpose |
|---|---|
| `pnpm start` / `pnpm build` | wp-scripts dev / production (TypeScript via Babel) |
| `pnpm check-types` | `tsc --noEmit` (strict) |
| `pnpm lint:js` | ESLint (WordPress TS config) |
| `pnpm bundle` / `pnpm bundle:org` | local release zip / WP.org SVN layout |
| `composer phpstan` / `composer phpcs` | PHPStan level 8 / WPCS |

**CI only builds and deploys** — run all checks locally before committing. The release ships **build files only**: `.distignore` excludes `src/`, `package.json`, `tsconfig.json`, `composer.json`, `docs/`, tests and dev config; `vendor/` is installed with `--no-dev`. TypeScript rules: strict, `isolatedModules`, no `any`, `src/types/` is the single source of truth for REST shapes (change PHP and TS together). i18n: PHP and JS `__()` with `omnioffloader`, `_n()` for counts, translator comments; translations come from translate.wordpress.org.

---

## 9. Documentation

1. **`CLAUDE.md`** — working doc for contributors/AI; must match the code.
2. **`docs/`** — this plan, roadmap + [future plan](roadmap/future-plan.md), issues, security notes, audits.
3. **`readme.txt`** — WP.org readme; update in the same commit as any user-visible change; `Stable tag` = `Version:` header = `OMNIOFFLOADER_VERSION`.

---

## 10. Security & Extensibility

**Security model:**
- All mutations via REST (api-fetch nonces) or nonced admin-post handlers.
- **Settings, credentials, bulk jobs and retention: `manage_options`.** **Offload / Bring Back of any item: `manage_options` + `edit_post`** (`delete_post` to delete the cloud copy). Other users keep core rights only (upload; delete own media — Mirror Delete removes the cloud copy). The read-only status route is `upload_files` and filtered per item.
- Secrets never returned raw, never logged, not autoloaded; wp-config constants lock fields.
- Settings whitelisted and schema-validated; output escaped (PHP and the Media Library JS).
- Paths: `dirname() === '.'` guard for keys; `..` rejected in collected files and restore targets; restores realpath-confined; deletes via `wp_delete_file_from_directory()`.
- Offload meta protected from custom-field editing. Uninstall cleanup is opt-in and keeps offload records while cloud-only media exists.

**Safety invariants — never violate:**
1. Never delete a local file before the cloud copy is verified (upload: main key exists; retention: every file by size).
2. Never delete a cloud object before the local restore is verified; delete-cloud is opt-in, default OFF.
3. Restores write atomically (temp + `rename()`), confined to the uploads basedir.
4. On any failure an attachment keeps at least one valid copy and truthful meta; errors go to `omnioffloader_error_log`.
5. Guard `dirname()` returning `'.'` when building object keys.
6. Never log or echo credentials; REST never returns raw secrets.
7. CLI destructive paths require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.
8. Only one bulk job at a time — admin queues and WP-CLI alike (§7.5).
9. Offload / Bring Back is administrator-only (above).

**Public hooks:**

| Hook | Type | Purpose |
|---|---|---|
| `omnioffloader_cloud_providers` | filter | Register/modify providers |
| `omnioffloader_object_acl` | filter | Per-object ACL (empty by default) |
| `omnioffloader_attachment_key` | filter | Customize the main object key |
| `omnioffloader_attachment_delete_keys` | filter | Extra keys on mirror delete |
| `omnioffloader_should_offload_attachment` | filter | Skip rules |
| `omnioffloader_use_async_upload` | filter | Background vs inline offload of a new upload |
| `omnioffloader_should_apply_retention` | filter | Exclude attachments from retention changes |
| `omnioffloader_media_block_names` | filter | Blocks whose `id`/`mediaId` is an attachment |
| `omnioffloader_multipart_threshold` | filter | Multipart size threshold |
| `omnioffloader_bulk_failure_limit` | filter | Consecutive failures that stop a bulk run |
| `omnioffloader_log_provider_errors` | filter | Write provider errors to the PHP log (default `WP_DEBUG`) |
| `omnioffloader_before_offload` / `_after_offload` | action | Offload lifecycle |
| `omnioffloader_before_restore` / `_after_restore` | action | Bring Back lifecycle |

## 10a. Code Quality

- **PHPStan level 8** (`paths`: main file, `uninstall.php`, `includes/`; vendored library scanned but not analysed; WP-CLI stubs; `dynamicConstantNames` for `OMNIOFFLOADER_VERSION` / `_DISABLE_ENGINE`; `earlyTerminatingMethodCalls` for `WP_CLI::error/halt`). Level 9 needs typed meta/option accessors (future plan 3.6).
- **PHPCS** (WordPress Coding Standards). **TypeScript** strict + ESLint.
- No side effects at file load; activation hooks at top level; `uninstall.php` guards `WP_UNINSTALL_PLUGIN`.

## 10b. Performance Rules

- Autoload only `omnioffloader_settings` and `omnioffloader_version`.
- No remote calls on normal requests; every S3 call has timeouts.
- Front end = rewrite filters only; media-block IDs primed in one batch; content media URLs resolved in one query per content.
- Stats: one indexed count, 60 s transient; never flushed per attachment (`defer_media_stats_flush()`).
- Never read attachment metadata in an unbounded loop — prime in chunks of 200 and drop the cache (preflights, CLI dry runs).
- Preflights are forced only when a run ends, never on a polling interval.
- The Media Library status poll reads each job state once (2 queries when idle).
- Cron events exist only while a job runs.

## 10c. WordPress.org Compliance

GPL-2.0-or-later headers; Apache-2.0 SDK via GPLv3 compatibility; bundled background-processing library credited; no trialware, tracking, external assets or obfuscation; names/slug/text domain `omnioffloader`; provider names only descriptive. ⚠️ **Open risk:** the bundled AWS SDK is not namespace-prefixed — another plugin bundling a different SDK version can conflict. Revisit before `1.0.0`.

## 10d. WP-CLI Conventions

- `wp omnioffloader offload [--all|--ids=…] [--limit=…] [--dry-run] [--force]`
- `wp omnioffloader restore [--all|--ids=…] [--limit=…] [--delete-cloud] [--yes] [--dry-run] [--force]`
- `wp omnioffloader retention [--policy=0|1|2] [--ids=…] [--limit=…] [--dry-run] [--format=table|json|csv] [--yes] [--force]`
- `wp omnioffloader status` — exits 1 when the engine is off or the bucket is unreachable.
- `Cli\CliJobGuard`: `--limit` must be a positive integer, `--ids` and `--all` are exclusive, skipped IDs are reported; refuses while another job runs (message names an interrupted CLI run); queue lock + CLI claim renewed after **every** item and released on shutdown and Ctrl-C; object cache cleared every 100 items; offload and restore stop after 10 consecutive failures.

## 10e. Testing

`wp eval-file` integration scripts against a real install; `tests/MockProvider.php` implements `ProviderInterface` on a local directory; `OMNIOFFLOADER_DISABLE_ENGINE` lets a test wire its own provider.

```bash
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-retention.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-async-upload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php 20 myprefix
wp eval-file tests/integration-bulk-rest.php   # needs real credentials; hits the live bucket
```

---

## 11. Version Map

> ⚠️ **Version ceiling `0.9.0`.** `1.0.0` requires the owner's explicit permission.

| Version | Status | Milestone |
|---|---|---|
| `0.1.0` | ✅ released | Skeleton, R2, settings UI, offload + retention, bulk offload + library UX + CLI, ★ Bring Back |
| `0.1.1` | ✅ released | Five more providers, multipart uploads, bulk-queue hardening |
| `0.1.2` | ✅ released | Custom batch sizes, CLI `--limit` restore, caching-plugin fixes |
| `0.1.3` | ✅ built, unreleased | Apply retention to existing media (Tools page + CLI), WP-CLI reference, admin-only cloud actions, DB worker mutex, per-site queues, security/performance/a11y audit fixes, PHPStan level 8 |
| `0.1.4` – `0.1.10` | planned | See [`roadmap/future-plan.md`](roadmap/future-plan.md) |
| `0.9.0` | planned | Release candidate — tests in CI, compatibility matrix, polish. **End of planned work.** |
| `1.0.0` | 🔒 | Only with the owner's explicit permission |

Phases 1–4 of the original plan (skeleton → offload → bulk → Bring Back) were development phases that shipped together as `0.1.0`; their checklists are in [`roadmap/v0.1.0.md`](roadmap/v0.1.0.md).

---

## 12. What Comes From Where

| Concern | Origin |
|---|---|
| Provider abstraction, three retention modes, observer-per-hook engine, key collection, wp-config credential constants | advanced-media-offloader |
| Background queue primitives (`WP_Async_Request`, `WP_Background_Process`) | Delicious Brains' WP Background Processing (GPL), vendored and subclassed |
| ★ Bring Back, provider meta per attachment, JSON-driven credential UI, TypeScript admin app, hardened bulk queue + DB mutex, retention changes on existing media, WP-CLI reference page, full-page admin design | new in this plugin |
