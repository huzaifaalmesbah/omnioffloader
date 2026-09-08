# OmniOffloader — Development Plan

> **Status:** Built and shipped through **`0.1.1`**. This started as a pre-code design document; it is now a living record of the design *and* where the implementation diverged from it. Where the two disagree, **the code wins** — fix this file.
> **Reference:** `advanced-media-offloader` (v4.4.4) — offload engine concepts: provider abstraction, retention policy, bulk background processing, key collection. Read-only; the source of the `dirname()` bug class guarded in §10.
>
> The plugin's own skeleton, admin UX and conventions are settled. Follow what is in `includes/` and `src/` rather than an outside plugin.

**Divergences from the original plan, at a glance** (detail in the sections named):

| Planned | Built | Why |
|---|---|---|
| Custom `includes/Autoloader.php`; Composer only for the SDK (§2, §4.1) | Composer PSR-4 for everything | `770dde6`. Reason not recorded in the commit. |
| PHP-Scoper-prefixed AWS SDK (§2) | Unprefixed; trimmed to S3 + Sts via `removeUnusedServices` | Scoping was never applied. Trimming came in `0ff768e`, motivated by zip size for WP.org review — it does not give the collision safety scoping would have. |
| Cloudflare R2 only until `1.0.0` (§1, §11) | Six providers shipped in `0.1.1` | `AbstractS3Provider` made each new provider a ~90-line file, so the gate was unnecessary. |
| `Core\Cron` watchdog class (§3) | Nothing — `WP_Background_Process` ships its own healthcheck | Redundant. |
| Local i18n build (`npm run i18n`, `languages/`, `bin/`) (§8, §9) | Translations via translate.wordpress.org | Removed in `2538735`; WP.org generates them. |
| `WP_Background_Process` used as-is (§7.5) | Subclassed via `AbstractBulkProcessor` | Its defaults assume short CPU-bound tasks; ours are network transfers. See §7.5. |

---

## 1. Vision

Offload WordPress media library files to cloud object storage to save server disk space and serve media from a CDN.

- **Providers:** Amazon S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces, Wasabi, and a generic S3-compatible option. The provider registry + abstract base class make each one a single new file with **zero JS changes**.
- **Key differentiator:** **Bring Back** — a first-class restore feature that downloads offloaded media from the cloud back to the server (per-file and bulk), safely reversing an offload.
- **Secondary backup storage:** optionally mirror every offloaded file to a second, independent provider.
- **Modern admin:** single-page React app in TypeScript — no PHP settings templates, everything through the REST API.
- **Works on any host:** bulk jobs are built for a 1-CPU shared plan as much as a 16-core box. See §7.5.

---

## 2. Plugin Identity

| Item | Value |
|---|---|
| Plugin name | OmniOffloader — Multi-Cloud Media Offload & CDN |
| Folder / slug | `omnioffloader` |
| Main file | `omnioffloader.php` |
| **Current version** | **`0.1.1` (pre-release)** — see the §11 version map, including the `0.9.0` ceiling |
| License | `GPL-2.0-or-later` + License URI header (the "or later" also makes bundling the Apache-2.0-licensed AWS SDK compatible — Apache-2.0 is GPLv3-compatible) |
| Text domain | `omnioffloader` |
| PHP namespace | `OmniOffloader\` |
| Prefix (options, meta, hooks) | `omnioffloader_` / constants `OMNIOFFLOADER_` |
| REST namespace | `omnioffloader/v1` |
| Requires | WP 6.6+, PHP 8.1+ |
| PHP autoloading | **Composer PSR-4** (`OmniOffloader\` → `includes/`) — plugin classes and the AWS SDK share one autoloader |
| Admin UI | React SPA in **TypeScript** (`.tsx`/`.ts`) via `@wordpress/scripts`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n` |
| SDK | AWS SDK for PHP (`S3Client` works for every S3-compatible provider), trimmed to the **S3 + Sts** services via `Aws\Script\Composer\Composer::removeUnusedServices` |

---

## 3. Folder & File Structure (as built)

```
omnioffloader/
├── omnioffloader.php                # Bootstrap: constants, Composer autoload, hooks
├── uninstall.php                    # Delegates to Core\Uninstaller (opt-in cleanup)
├── readme.txt                       # WP.org readme — update with every user-visible change
├── CLAUDE.md                        # Dev docs for AI/contributors
├── PLAN.md                          # This file
├── package.json                     # @wordpress/scripts build + bundle scripts
├── tsconfig.json                    # Strict TS config (type-check only)
├── phpstan.neon.dist                # PHPStan level 6 w/ szepeviktor/phpstan-wordpress
├── phpcs.xml.dist                   # WordPress Coding Standards ruleset
├── composer.json                    # AWS SDK (S3+Sts) + dev tools (phpstan, wpcs)
├── .gitignore / .distignore / .gitattributes
├── .claude/skills/                  # WordPress skills, installed locally
├── .project/                        # Living project docs
│   ├── index.md · roadmap/{index,v0.1.0}.md · issues/index.md · security/index.md
├── .wordpress-org/                  # Banners, icons, blueprints, sources
├── .github/workflows/               # Build + deploy to WP.org SVN; SVN tag delete
├── scripts/bundle.js                # Release zip (npm run bundle / bundle:org)
├── tests/                           # wp eval-file integration scripts — see §10e
├── build/                           # wp-scripts output — not committed
├── src/                             # React admin app in TypeScript
│   │                                #   entry: src/index.tsx → #omnioffloader-admin-app
│   ├── index.tsx · index.scss · App.tsx
│   ├── types/                       # settings.ts, provider.ts, media.ts, progress.ts,
│   │                                #   index.ts (barrel), global.d.ts (window.omnioffloader)
│   ├── api/                         # core.ts, provider.ts, offload.ts, bringBack.ts
│   ├── context/CoreContext.tsx      # settings, provider, stats, dirty/save state
│   ├── hooks/                       # useBulkOffload.ts, useBringBack.ts
│   ├── pages/                       # Dashboard, Settings, Offload, BringBack
│   ├── components/
│   │   ├── layout/                  # Sidebar, Header, SaveBar, MainLayout
│   │   ├── ui/                      # Card, ProgressBar, StatCard, ActivityLog
│   │   └── settings/                # CredentialFields, TestConnection
│   └── utils/                       # constants.ts (RouteMeta[]), formatBytes.ts, errorHelper.ts
├── includes/                        # PHP backend
│   ├── Abstracts/
│   │   └── WP_Background_Processing/  # Vendored WP_Async_Request + WP_Background_Process
│   │                                  #   (Delicious Brains, GPL) — excluded from PHPCS docblock rules
│   ├── Core/
│   │   ├── Plugin.php               # Singleton; wires everything on plugins_loaded
│   │   ├── Activator.php            # First-version option, activation redirect transient
│   │   ├── Deactivator.php          # Purge queue data + transients (keep settings)
│   │   ├── Uninstaller.php          # Opt-in full cleanup (options + meta)
│   │   ├── Migrator.php             # Version-gated migrations
│   │   └── Helpers.php              # Path sanitize, URL normalize, stats, restore preflight
│   ├── Api/                         # REST controllers (extend BaseController)
│   │   ├── BaseController.php       # register_*_route helpers, permissions_check (manage_options)
│   │   ├── CoreController.php       # GET/POST settings, GET providers, GET stats
│   │   ├── ProviderController.php   # GET/POST credentials, POST test-connection (primary+secondary)
│   │   ├── OffloadController.php    # POST offload/{id}, POST bulk, GET bulk/progress,
│   │   │                            #   POST bulk/cancel, GET errors
│   │   └── BringBackController.php  # POST restore/{id}, POST bulk, GET progress,
│   │                                #   POST cancel, GET preflight (disk space)
│   ├── Providers/
│   │   ├── ProviderInterface.php    # Contract (see §4.2)
│   │   ├── AbstractS3Provider.php   # Shared S3Client logic: upload (single + multipart),
│   │   │                            #   download, batch delete, exists, size, check_connection
│   │   ├── ProviderRegistry.php     # key => class, aliases; omnioffloader_cloud_providers filter
│   │   └── AmazonS3 · CloudflareR2 · BackblazeB2 · DigitalOceanSpaces · Wasabi · S3Compatible
│   ├── Services/
│   │   ├── AttachmentUploader.php   # Offload one attachment (+ all sizes/sources, + secondary)
│   │   ├── AttachmentRestorer.php   # Bring Back: cloud → server, atomic + verified
│   │   ├── LocalCleaner.php         # Applies retention policy after verified offload
│   │   ├── AbstractBulkProcessor.php  # ★ Hardened queue base — see §7.5
│   │   ├── BulkOffloadProcessor.php   # queue_ids() + process_item() only
│   │   └── BulkRestoreProcessor.php   # queue_ids() + process_item() + delete_cloud state
│   ├── Offload/                     # Hook integration (observer pattern, one hook per class)
│   │   ├── ObserverInterface.php    # register(): void
│   │   ├── Offloader.php            # Attaches + registers all observers
│   │   └── Observers/               # 9 classes:
│   │       ├── UploadObserver.php            # wp_generate_attachment_metadata
│   │       ├── UrlRewriteObserver.php        # wp_get_attachment_url
│   │       ├── SrcsetObserver.php            # wp_calculate_image_srcset
│   │       ├── ContentImageTagObserver.php   # wp_content_img_tag
│   │       ├── DeleteObserver.php            # delete_attachment → mirror delete
│   │       ├── UniqueFilenameObserver.php    # avoid overwrites when local removed
│   │       ├── MediaLibraryFilterObserver.php # Offloaded/Local dropdown filter
│   │       ├── OffloadStatusObserver.php     # Status in attachment details
│   │       └── RowActionsObserver.php        # "Offload" / "Bring Back" row actions
│   ├── Admin/Settings.php           # Menu page + enqueue build/index.js, localize, translations
│   ├── Cli/
│   │   ├── CommandRegistrar.php     # Registers all commands under `wp omnioffloader`
│   │   ├── OffloadCommand.php       # offload [--all|--ids=…] [--limit=…] [--dry-run] [--force]
│   │   ├── RestoreCommand.php       # restore [--all|--ids=…] [--delete-cloud] [--yes]
│   │   │                            #   [--dry-run] [--force]
│   │   └── StatusCommand.php        # status — provider, counts, connection, queue state
│   ├── Traits/
│   │   ├── OptionsHelper.php        # settings/credentials get+sanitize, is_configured(),
│   │   │                            #   is_secondary_configured()
│   │   └── AttachmentHelper.php     # Key collection (sizes/original/sources),
│   │                                #   subdir/version logic, offload meta read/write
│   └── templates/admin-app.php      # <div id="omnioffloader-admin-app"></div> mount point
└── vendor/                          # Composer: AWS SDK (S3+Sts) + dev tools
```

**Planned but never built** (do not resurrect without a reason): `Core\Cron` (the queue ships its own healthcheck), `Admin\AdminBar`, `Cli\AbstractCommand`, `SrcsetMetaObserver`, `AttachedFileObserver`, `UpdateObserver`, `bin/merge-json-translations.js`, `languages/`, `assets/`, `components/{common,dashboard,offload,bring-back}/`, `hooks/{useSettings,useProvider,useMediaStats}.ts`, `PageLayout`. Settings sub-components (`ProviderSelect`, `RetentionPolicy`, `MirrorDelete`, `PathSettings`) live inline in `pages/Settings.tsx`.

---

## 4. Backend Architecture

### 4.1 Bootstrap flow

`omnioffloader.php`:

1. Define constants: `OMNIOFFLOADER_VERSION`, `_DIR`, `_URL`, `_ASSETS_URL`, `_BASENAME`, `_FILE`.
2. `require vendor/autoload.php` (guarded by `file_exists`) — Composer PSR-4 covers both plugin classes and the AWS SDK.
3. `register_activation_hook` → `Core\Activator::activate()`; deactivation → `Core\Deactivator::deactivate()`.
4. `add_action('plugins_loaded', …)` → `Core\Plugin::get_instance()->run()`.
5. `WP_CLI` check → `Cli\CommandRegistrar::register()`.

`Core\Plugin::run()`:
- `initialize_classes()`:
  - Always: `CoreController`, `ProviderController`, `Admin\Settings`, `OffloadController`, `BringBackController`.
  - Only when a provider is configured (`OptionsHelper::is_configured()`) **and** `Aws\S3\S3Client` exists: the primary provider, the secondary provider when configured, `Offload\Offloader` (registers all observers), `BulkOffloadProcessor`, `BulkRestoreProcessor`. The two processors must be constructed on every request — their constructors register the async loopback and cron-healthcheck hooks that drive the queues.
  - `OMNIOFFLOADER_DISABLE_ENGINE` (wp-config constant) skips all of it — the kill switch used by the integration tests.
- `maybe_upgrade()` on `init` priority 5 → `Migrator::run()` (version-gated, runs in every context). **This also executes inside the bulk queue's own worker requests** — guard destructive migrations against a live run.
- Plugin action link (Settings).

### 4.2 Provider abstraction (easy to extend)

```
ProviderInterface
   └── AbstractS3Provider (abstract)          ← 95% of logic lives here
          └── AmazonS3 · CloudflareR2 · BackblazeB2
              DigitalOceanSpaces · Wasabi · S3Compatible
```

**`ProviderInterface`:**

| Method | Purpose |
|---|---|
| `get_key(): string` | e.g. `cloudflare_r2` |
| `get_name(): string` | e.g. "Cloudflare R2" (`S3Compatible` returns the user's custom name) |
| `get_role(): string` | `primary` or `secondary` — selects the credential store |
| `get_bucket(): ?string` | |
| `get_domain(): string` | Public/CDN base URL for rewrites |
| `upload_file(string $file, string $key): string\|false` | Single PUT, or multipart above the threshold |
| `download_file(string $key, string $dest): bool` | Bring Back (getObject + SaveAs, temp file + rename) |
| `delete_objects(array $keys): bool` | Batched (1000/req), per-object fallback |
| `object_exists(string $key): bool` | |
| `object_size(string $key): int\|false` | |
| `check_connection(): true\|WP_Error` | headBucket, 5s timeout |
| `credential_fields(): array` | Field definitions **served as JSON to the React UI** |

`credential_fields()` returns machine-readable defs (`name`, `label`, `type`, `placeholder`, `description`; `locked` added by the controller when a wp-config constant defines it) — the React `CredentialFields` component renders them generically. **A new provider needs zero JS changes.**

**`ProviderRegistry`:** static `key => class` map exposed via the `omnioffloader_cloud_providers` filter and `GET /omnioffloader/v1/core/providers`, plus an alias map (`aws`, `aws_s3` → `amazon_s3`; `dos`, `digitalocean` → `digitalocean_spaces`; `minio` → `s3_compatible`) resolved by `canonical_key()`. Shipping a new provider = one class + one registry line.

**Transfer behaviour in `AbstractS3Provider`** (all of it applies to every provider):
- `upload_file()` uses `MultipartUploader` at or above `MULTIPART_THRESHOLD` (64 MB, filter `omnioffloader_multipart_threshold`, floored at the 5 MB S3 part minimum) and aborts the multipart upload on failure so no half-assembled upload accrues storage. Below the threshold it is a single `putObject` with `SourceFile` (streamed, so memory is flat regardless of file size).
- Every call carries `connect_timeout`; head requests also carry a request `timeout`. The SDK sets none by default, so an unreachable endpoint would otherwise pin a PHP worker — and the queue's process lock — indefinitely.
- **ACL is omitted unless `omnioffloader_object_acl` returns one.** R2 rejects the header. This is why `MultipartUploader` is used directly rather than `ObjectUploader`, which always sends `ACL`.

### 4.3 Credentials

1. **wp-config constants win:** `OMNIOFFLOADER_CLOUDFLARE_R2_KEY`, `…_SECRET`, `…_ENDPOINT`, `…_BUCKET`, `…_DOMAIN` (and `OMNIOFFLOADER_SECONDARY_*` for the backup provider) — reported as `locked: true` to the UI (fields disabled, secrets masked, **never returned raw**).
2. Otherwise stored in the `omnioffloader_credentials` / `omnioffloader_secondary_credentials` options (per-provider sub-arrays).
3. REST: `GET` returns fields with secrets masked (`is_set: true`); `POST` ignores masked placeholder values so saving other fields never wipes a secret.

R2 fields: Access Key ID, Secret Access Key, Endpoint URL (`https://<account-id>.r2.cloudflarestorage.com`), Bucket Name, Custom Domain / public bucket URL (required — R2 has no default public URL). Region fixed `auto`. Other providers add region and, for `S3Compatible`, endpoint, path-style toggle and a display name.

### 4.4 Attachment meta (state model)

| Meta key | Meaning |
|---|---|
| `omnioffloader_offloaded` | `1` when file(s) live in cloud |
| `omnioffloader_offloaded_at` | timestamp |
| `omnioffloader_provider` | provider key at offload time (critical for Bring Back after switching providers) |
| `omnioffloader_bucket` | bucket at offload time |
| `omnioffloader_path` | object key prefix (subdir) in bucket |
| `omnioffloader_object_version` | version segment when object versioning enabled |
| `omnioffloader_retention` | retention policy applied (0/1/2) — tells Bring Back what's missing locally |
| `omnioffloader_error_log` | last offload/restore errors (array of `{time, message}`, capped at 10) |
| `omnioffloader_secondary_{offloaded,offloaded_at,provider,bucket,path}` | same state for the backup provider |

### 4.5 Options

| Option | Autoload | Contents |
|---|---|---|
| `omnioffloader_settings` | **on** | provider, secondary_active, secondary_provider, retention_policy, mirror_delete, path_prefix(+active), object_versioning, bring_back_delete_cloud, remove_data_on_uninstall |
| `omnioffloader_version` | **on** | for `Migrator` |
| `omnioffloader_credentials` · `omnioffloader_secondary_credentials` | off | per-provider credentials |
| `omnioffloader_first_activated_version` | off | |
| `omnioffloader_bulk_offload_state` · `omnioffloader_bulk_restore_state` | off | progress heartbeat (status, total, processed, errors, message, current, recent[]) |
| `omnioffloader_bulk_{offload,restore}_batch_*` · `_status` | off | queue internals, written by `WP_Background_Process`. **Never leave these behind** — `AbstractBulkProcessor::purge_stored_data()` owns their cleanup on deactivate, uninstall and upgrade. |

Transients: `omnioffloader_media_stats`, `omnioffloader_restore_preflight`, `omnioffloader_activation_redirect`.

---

## 5. REST API (`omnioffloader/v1`) — consumed by the React app

All routes: `permission_callback` → `manage_options` (BaseController), nonce via `@wordpress/api-fetch` automatically (cookie auth + `X-WP-Nonce`).

**Controller conventions (WP REST best practice):**
- Register on `rest_api_init`; every route declares an explicit `permission_callback` (never omitted).
- Methods via `WP_REST_Server::READABLE/CREATABLE/EDITABLE/DELETABLE` constants.
- Every route defines `args` with `type`, `required`, `default`, `validate_callback`, `sanitize_callback` (JSON Schema style) — no reading `$_GET`/`$_POST`; always `WP_REST_Request` typed params.
- Success via `rest_ensure_response()`; errors via `WP_Error` with explicit `status` (400/403/404/409/500) and stable error codes (`omnioffloader_invalid_provider`, `omnioffloader_not_offloaded`, `omnioffloader_bulk_running`, …) that the React app maps to user-friendly notices.
- Response shapes documented per endpoint and mirrored 1:1 in `src/types/` (TS).

**Core (`CoreController`)**
- `GET  /core/settings` · `POST /core/settings`
- `GET  /core/providers` — registry list + each provider's credential field defs
- `GET  /core/stats` — total / offloaded / local counts, configured/provider flags, secondary status

**Provider (`ProviderController`)**
- `GET  /provider/credentials?provider=…&role=…` (masked) · `POST /provider/credentials`
- `POST /provider/test-connection` — primary or secondary role

**Offload (`OffloadController`)**
- `POST /offload/attachment/{id}` — single item (media library row action)
- `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel`
- `GET  /offload/errors` — attachments carrying `omnioffloader_error_log`

**★ Bring Back (`BringBackController`)**
- `GET  /bring-back/preflight` — count, estimated bytes, `disk_free_space()` check
- `POST /bring-back/attachment/{id}` — single restore (`delete_cloud` param)
- `POST /bring-back/bulk` · `GET /bring-back/bulk/progress` · `POST /bring-back/bulk/cancel`

⚠️ **The two `bulk/progress` routes are `GET` but deliberately do work** — they are the self-heal for hosts where the loopback is blocked (§7.5). Do not add caching, and be careful what else calls them.

`POST /offload/bulk` and `POST /bring-back/bulk` each refuse (409 `omnioffloader_bulk_running`) if *either* queue is active — a simultaneous offload and restore would race over the same files.

---

## 6. React Admin App

### 6.1 ★ UI Design (agreed 2026-08-12 — based on reference screenshots, "Optimazz"-style layout)

The admin app is a full-page SPA with its own chrome (not the classic WP settings look). Three fixed regions:

```
┌────────────┬──────────────────────────────────────────────┐
│  LOGO      │  [icon]  Page Title                          │ ← Top header
│ ─────────  │          Page subtitle                       │
│ ▍Dashboard │──────────────────────────────────────────────│
│  Overview… │                                              │
│  Settings  │            Page content (cards)              │
│  Provider… │                                              │
│  Offload   │                                              │
│  Bring Back│                                              │
│            │──────────────────────────────────────────────│
│            │            All changes saved   [Save changes]│ ← Sticky bottom bar
└────────────┴──────────────────────────────────────────────┘
```

1. **Left sidebar** (fixed, white, full height):
   - **Plugin logo + name at the top**, divider below it.
   - Nav items: **icon + bold title + small gray subtitle** (two-line items), e.g. "Dashboard / Overview & quick status", "Settings / Provider & policies", "Offload / Move media to cloud", "Bring Back / Restore to server".
   - **Active item**: soft accent background (light tint), colored left border bar, accent-colored icon/title.
2. **Top header bar** (content area, sticky):
   - Rounded **icon badge** (light accent tint) + **current menu name** as large title + subtitle underneath — mirrors the selected sidebar item.
3. **Sticky bottom bar** (content area, always visible):
   - Right-aligned: dirty-state text (**"All changes saved"** / "Unsaved changes") + primary **"Save changes"** button (accent color, disabled/dimmed when nothing to save).
   - Save state is global (context-driven): pages register their dirty state; Save persists via REST, then shows saved confirmation.
4. **Content area**: light gray background; content in **white rounded cards** with soft shadows; feature cards can carry an icon badge + title + "Active" pill + description + right-aligned toggle; stat cards in a grid on Dashboard (icon, big number, label); hero/summary banner card allowed on Dashboard (accent gradient).
5. **Style tokens**: blue accent (`#135dbe` base, `#0e4690` dark), rounded corners (~12–16px), pill-shaped toggles/badges, generous spacing. Implemented in `src/index.scss` with CSS variables; components in `ui/` wrap `@wordpress/components` but restyled to this design.
6. WP admin notices are hidden on our page; the app supplies its own notice system so the full-page layout stays clean.

Layout components: `layout/Sidebar.tsx` (logo + nav), `layout/Header.tsx` (icon badge + title + subtitle from route meta), `layout/SaveBar.tsx` (sticky bottom save bar), `layout/MainLayout.tsx` (grid shell wiring all three around the page outlet). Route meta (icon, title, subtitle) lives in `utils/constants.ts` (typed `RouteMeta[]`) so Sidebar and Header render from one source.

### 6.2 Integration

- Menu: top-level "OmniOffloader", `manage_options`, renders `templates/admin-app.php` (`#omnioffloader-admin-app`).
- `Admin\Settings::enqueue_scripts()` — only on our hook: `build/index.asset.php` deps/version, script handle `omnioffloader-admin-app`, `wp_localize_script(…, 'omnioffloader', ['version', 'sdk_loaded', 'upload_url', 'media_url'])`, `wp_set_script_translations(…, 'omnioffloader')`. Admin notice if `build/` is missing.
- Routing via `view=` query param: `dashboard`, `settings`, `offload`, `bring-back`.
- Pages:
  - **Dashboard** — stat cards (Total / Offloaded / Local), provider connection badge, links to Offload & Bring Back.
  - **Settings** — provider select, generic `CredentialFields`, Test Connection, secondary backup storage section, Retention Policy radios, Mirror Delete, Path Prefix, Object Versioning, uninstall cleanup toggle.
  - **Offload** — pre-count, Start/Cancel, `ProgressBar`, live processed/error counters, `ActivityLog` of recent items.
  - **★ Bring Back** — preflight summary (items, bytes, free disk) with disk warning, "also delete from cloud" toggle (default OFF), Start/Cancel + progress, per-item results.

**Progress polling** (`useBulkOffload`, `useBringBack`): a **chained `setTimeout` with backoff** (2s → 6s, +250ms per tick), never `setInterval`. A poll can make the server process a batch, so an interval would stack requests whenever one outlasts its own period. An in-flight guard drops overlapping ticks. Queue statuses the UI renders: `idle` · `running` · `done` · `cancelled` · `failed` (the last carries a `message`).

---

## 7. Feature Behavior

### 7.1 Auto-offload on upload
`UploadObserver` on `wp_generate_attachment_metadata` (late): `AttachmentUploader` uploads original + all sizes (+ `original_image`, modern-format `sources` WebP/AVIF), verifies the main object exists, writes meta, mirrors to the secondary provider when configured, then `LocalCleaner` applies the retention policy.

### 7.2 URL rewriting
When `omnioffloader_offloaded`: rewrite via `UrlRewriteObserver`, `SrcsetObserver`, `ContentImageTagObserver`.

### 7.3 Retention Policy (same 3 modes as advanced-media-offloader)

Radio setting `omnioffloader_settings[retention_policy]`:

| Value | Label | Behavior after successful offload |
|---|---|---|
| `0` | **Retain Local Files** (default) | Nothing deleted; cloud is a mirror. |
| `1` | **Smart Local Cleanup** | Delete generated thumbnail sizes locally; **keep original**. |
| `2` | **Full Cloud Migration** | Delete original + all sizes locally. |

`LocalCleaner` runs only after upload verified (`object_exists` on main key) and re-checks `is_offloaded()` itself. Separate **Mirror Delete** checkbox: deleting an attachment in WP also deletes its cloud objects (batched, incl. backup sizes + modern-format sources, `omnioffloader_attachment_delete_keys` filter).

### 7.4 ★ Bring Back (Restore cloud → server)

**Per-attachment** (row action / attachment details / REST):
`AttachmentRestorer::restore($id, $delete_cloud = false)`:
1. Verify `omnioffloader_offloaded`; resolve provider **from `omnioffloader_provider` meta** (works after switching active provider while credentials remain).
2. Build full key list (main, sizes, `original_image`, sources) — same collection logic as delete.
3. Download each to its `wp_upload_dir()` path: temp file + `rename()` (atomic, never half-written), create directories, path-traversal guard (`realpath` inside uploads basedir).
4. Skip files already present locally with matching size (retention 0/1 — no wasted bandwidth).
5. Verify all expected files exist locally.
6. Clear offload meta (primary + secondary) → URLs serve locally again.
7. If `$delete_cloud` (default OFF): delete cloud objects — from both providers — only after local verification.
- Any failure: keep cloud copy + meta, log to `omnioffloader_error_log`, report. **Never leave an attachment where neither copy is valid.**

### 7.5 ★ Bulk processing (offload and Bring Back)

Both queues extend `Services\AbstractBulkProcessor`; the subclasses supply only `queue_ids()`, `process_item()` and their `STATE_OPTION`. The base **overrides the vendored `WP_Background_Process`** because one task here is a *network transfer*, not a short CPU-bound job. Each override fixes a specific production failure — do not undo them:

| Behaviour | Why |
|---|---|
| `$queue_lock_time = 600`, renewed after every item (`renew_lock()`, which deliberately does **not** call `lock_process()` — that resets `$start_time` and defeats `time_exceeded()`) | The parent's 60 s lock expires mid-transfer, letting the cron healthcheck and the admin poll start a second worker on the same batch. On a small PHP-FPM pool that fans out into duplicate uploads and can exhaust the pool. |
| Items are **claimed out of the batch before they run** | The parent removes an item only *after* `task()` returns, so a task killed by `max_execution_time` stays queued and is retried forever — one oversized file wedges the whole run. |
| `current` in the state records the in-flight ID; `recover_lost_item()` reports it on the next pass | Otherwise a killed worker leaves `processed` permanently short of `total` with no explanation. |
| Circuit breaker at `FAILURE_LIMIT` (10) consecutive failures → status `failed` + message (filter `omnioffloader_bulk_failure_limit`) | Bad credentials fail identically for every attachment; without it the user waits out the whole library to be told so, leaving an error-log meta row per item. |
| `process_queue_now()` only works once the queue has been quiet for `STALL_SECONDS` (20) | It is the self-heal for blocked loopbacks (local SSL, HTTP auth, Cloudflare Access, `DISABLE_WP_CRON` with no system cron). Without the stall check every 2 s poll would hold a PHP worker for 8 s and starve small pools. |
| `cancel_now()` tears down in-request | The parent's `cancel()` relies on a loopback dispatch that never arrives on blocked hosts, leaving the job flagged active so no new run can start. |
| `completed()` refuses to overwrite a non-`running` status | A late worker finding an emptied queue must not report a cancelled run as done. |
| `purge_stored_data()` is **static** | Deactivation, uninstall and the `0.1.1` migration must clean batch rows, run status, process lock and cron *without* booting the engine (which only loads when a provider is configured). |

Queue population skips trashed attachments and rows with no `_wp_attached_file` — each would otherwise burn a task and land in the error log. State is published *before* the queue is written, so a request that dies while queueing a large library still leaves a coherent, cancellable state.

**Throughput is deliberately serial** — one attachment at a time, ~7 round trips each (6 PUT + 1 verifying HEAD; double with a secondary provider). Hardware does not change it; a 16-core box finishes a 15k library in the same time as a 1-core box. Any future parallelism must preserve the safety invariants in §10.

**CLI:** `wp omnioffloader offload --all [--limit=…]` and `restore --all` run the same services synchronously. They refuse to start against an active queue and hold its process lock while they work (see §10 invariant 8).

### 7.6 Media library integration
Filter dropdown (All/Offloaded/Local), status in attachment details (provider + date), row actions "Offload" / "Bring Back".

---

## 8. Build & Release Tooling

**package.json scripts:**

| Script | Purpose |
|---|---|
| `start` | `wp-scripts start` — dev watch (TypeScript compiled by wp-scripts natively) |
| `build` | `wp-scripts build` — production |
| `check-types` | `tsc --noEmit` — strict type check |
| `lint:js` | `wp-scripts lint-js src` — ESLint incl. TS rules |
| `bundle` / `bundle:org` | `scripts/bundle.js` — release zip / WP.org SVN layout, excluding dev files per `.distignore` |

`composer phpstan` and `composer phpcs` complete the local gate. **GitHub Actions only builds and deploys** — nothing in CI catches a type, lint or standards regression, so run all five before committing.

- **TypeScript setup (type-check only):** `@wordpress/scripts` compiles `.ts`/`.tsx` out of the box (entry `src/index.tsx`, no webpack override). Babel does the transpiling; **`tsc` is type-check only**.
  - `tsconfig.json`: `strict`, `noImplicitReturns`, `noFallthroughCasesInSwitch`, `isolatedModules` (**required** — Babel transpiles file-by-file), `resolveJsonModule`, `allowSyntheticDefaultImports`, `skipLibCheck`, `noEmit`; `include: ["src"]`, `files: ["src/types/global.d.ts"]`.
  - `src/types/` is one file per domain (`settings.ts`, `provider.ts`, `media.ts`, `progress.ts`) with an `index.ts` barrel. These are the **single source of truth** for REST payload shapes, shared by `api/` wrappers, hooks and components — and they must match the PHP controllers' `args`/response schemas. Change both sides in the same commit.
  - `global.d.ts` declares `window.omnioffloader` and is referenced via tsconfig `files` so it is always included.
  - `@wordpress/*` packages ship their own types — no `@types/wordpress__*` for the core ones.
  - No `any` escapes: prefer `unknown` + narrowing; `@ts-expect-error` (with a reason comment) over `@ts-ignore`.
- **i18n:** PHP `__( '…', 'omnioffloader' )`; JS `__( '…', 'omnioffloader' )` from `@wordpress/i18n`; `wp_set_script_translations` loads JSON translations. There is **no local i18n build step and no `languages/` folder** — translations come from translate.wordpress.org.
- `.distignore` mirrors the bundle excludes; `.github/workflows/` builds and deploys to the WP.org SVN repo.

---

## 9. Documentation Deliverables

1. **`CLAUDE.md`** — the working doc for AI/contributors; must be kept current with the code. Covers build/test commands, architecture trees, the bulk-queue rationale, REST endpoints, data reference (options, meta, transients, constants), public hooks, safety invariants and performance rules.
2. **`.project/`** — living project docs: `index.md`, `roadmap/{index,v0.1.0}.md`, `issues/index.md`, `security/index.md`.
3. **`readme.txt`** — WP.org: description, FAQ (provider setup, custom-domain requirement, Bring Back explanation, bulk-job expectations), changelog, `Upgrade Notice`. **Update it in the same commit as any user-visible change**, and keep `Stable tag` in step with the version in `omnioffloader.php` (both the header and `OMNIOFFLOADER_VERSION`).

---

## 10. Security & Extensibility

**Security:**
- REST-only mutations; `permission_callback` `manage_options`; api-fetch nonces.
- Secrets never returned raw; masked responses; constants lock fields; never log credentials (`AbstractS3Provider::log_error` logs keys and messages only).
- Sanitize/whitelist all settings server-side (retention ∈ {0,1,2} etc.); escape all PHP template output.
- Object keys sanitized; `dirname() === '.'` guard (real bug class in the reference plugin); restore destination confined to uploads basedir.
- Uninstall cleanup only when the user opted in (`Core\Uninstaller`).

**Safety invariants — never violate:**
1. Never delete a local file before the cloud upload is verified (`object_exists` on the main key).
2. Never delete a cloud object before the local restore is verified; delete-cloud is opt-in, default OFF.
3. Restores write atomically: temp file + `rename()`, destination realpath-confined to the uploads basedir.
4. On any failure an attachment keeps at least one valid copy and truthful meta; errors go to `omnioffloader_error_log`.
5. Guard `dirname()` returning `'.'` when building object keys.
6. Never log or echo credentials; REST never returns raw secrets.
7. CLI destructive paths (`--delete-cloud`) require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.
8. **The CLI and the admin bulk queue must never process the same attachments concurrently** — with a cleanup retention policy one side deletes files the other is reading. The commands refuse to start against an active queue (`--force` overrides) and hold its process lock while they work.

**Public hooks:**

| Hook | Type | Purpose |
|---|---|---|
| `omnioffloader_cloud_providers` | filter | Register/modify providers |
| `omnioffloader_object_acl` | filter | Per-object ACL (empty by default — R2 rejects the header) |
| `omnioffloader_attachment_key` | filter | Customize object key |
| `omnioffloader_attachment_delete_keys` | filter | Extra keys on mirror delete (sidecars) |
| `omnioffloader_should_offload_attachment` | filter | Skip rules (mime/size/…) |
| `omnioffloader_multipart_threshold` | filter | Bytes at which uploads go multipart |
| `omnioffloader_bulk_failure_limit` | filter | Consecutive failures that stop a bulk run (0 disables) |
| `omnioffloader_before_offload` / `omnioffloader_after_offload` | action | Offload lifecycle |
| `omnioffloader_before_restore` / `omnioffloader_after_restore` | action | ★ Bring Back lifecycle |

---

## 10a. Code Quality & Static Analysis

- **PHPStan** `level: 6`, `paths: [omnioffloader.php, uninstall.php, includes/]`, `excludePaths.analyse: includes/Abstracts/WP_Background_Processing/` (vendored third-party code — its symbols are still scanned, but it is not held to this project's docblock standards). WP-CLI stubs scanned; `treatPhpDocTypesAsCertain: false`. Prefer fixing types over `ignoreErrors`.
- **PHPCS** with WordPress Coding Standards (`phpcs.xml.dist`) — file/class/method doc headers, Yoda conditions, escaping sniffs.
- **Architecture guardrails:** no side effects at file load (everything on hooks via `Core\Plugin`); activation/deactivation hooks registered at top level of the main file; `uninstall.php` guards `WP_UNINSTALL_PLUGIN` and only deletes data when the user opted in.

## 10b. Performance Rules

- **Options autoloading:** only `omnioffloader_settings` and `omnioffloader_version` autoload (both tiny). Credentials, bulk progress state and all queue batch rows are `autoload => false`.
- **No remote calls on normal requests:** connection checks run only on explicit "Test Connection" or an offload/restore job — never during page loads. All S3 client calls have explicit connect timeouts.
- **Cheap stats:** media counts via a single indexed query on `postmeta`, cached in a short transient.
- **Never bust the stats cache per attachment.** `Helpers::flush_media_stats()` is deferrable; bulk passes and CLI loops wrap their work in `Helpers::defer_media_stats_flush(true/false)` so it fires once. Per-item flushing measured ~2 queries per attachment — 30,000 on a 15k library — for a value nothing reads mid-run.
- **Never read attachment metadata in an unbounded loop.** Prime with `update_meta_cache('post', $chunk)` in chunks and drop the cache between them (see `Helpers::get_restore_preflight()`).
- **Frontend footprint = filters only:** on non-admin requests the plugin adds URL-rewrite filters and nothing else — no queries, no option writes, no HTTP.
- **Cron hygiene:** the queue's healthcheck event exists only while a bulk job runs and is unscheduled when idle.

## 10c. WordPress.org Directory Compliance

- License header: `License: GPL-2.0-or-later` + `License URI` in the main file; same in `readme.txt`.
- **Bundled AWS SDK is Apache-2.0** — GPL-compatible only via GPLv3, which our "or later" covers; documented in the readme licensing note.
- ⚠️ **Open risk: the bundled SDK is not namespace-prefixed.** The original plan called for PHP-Scoper; it was never applied. Another plugin bundling a different `aws/aws-sdk-php` version loads whichever autoloader wins, which can break one of the two. Revisit before `1.0.0` — either scope it, or verify the version floor is wide enough to be safe.
- Name/slug rules: "OmniOffloader" ≥5 alphanumeric chars ✓, no trademarks in the name/slug ✓ (provider names appear only descriptively in feature lists); header name and readme name must match exactly.
- No trialware/locked features, no upsell nags, no obfuscated code, no external tracking, no injected "powered by" links.
- `readme.txt` `Stable tag` matches the released version; `Tested up to` kept current.

## 10d. WP-CLI Conventions

- All commands under `wp omnioffloader …`, registered only when `WP_CLI` is defined, via `CommandRegistrar`.
- **Destructive safety:** `restore --delete-cloud` requires `--yes` or interactive `WP_CLI::confirm()`; every write command supports `--dry-run`.
- Progress via `WP_CLI\Utils\make_progress_bar()`; structured summaries at the end; non-zero exit code on failure so scripts can gate on it.
- Docblock-documented synopses so `wp help omnioffloader offload` is complete.

## 10e. Testing

Not PHPUnit — `wp eval-file` integration scripts under `tests/`, run against a real WordPress install. `tests/MockProvider.php` implements the full `ProviderInterface` against a local directory so the pipelines run end-to-end without credentials; `OMNIOFFLOADER_DISABLE_ENGINE` stops `Core\Plugin` booting the observers so a test can wire its own provider.

```bash
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php 20 myprefix
wp eval-file tests/integration-bulk-rest.php   # needs real credentials; hits the live bucket
```

---

## 11. Implementation Phases & Version Map

Phases 1–4 were **development phases, not release versions** — they shipped together as **`0.1.0`**. Each was still built and verified in order so progress stayed testable at every step.

> ⚠️ **Version ceiling: this plan ends at `0.9.0`.** `1.0.0` (first stable / WP.org submission) requires the owner's **explicit permission** — it is listed below only for context and must not be started, tagged, or released as part of executing this plan.

| Version | Status | Milestone |
|---|---|---|
| `0.1.0` | ✅ released | Phases 1–4 — skeleton + R2 provider + settings UI, offload pipeline + retention, bulk offload + library UX + CLI, ★ Bring Back |
| `0.1.1` | ✅ released | Five more providers + generic S3-compatible, secondary backup storage, multipart uploads, bulk-queue reliability & performance work (§7.5) |
| `0.9.0` | planned | Phase 5 — polish, compat, release candidate. **← End of planned work.** |
| `1.0.0` | 🔒 | First stable — **only with owner's explicit permission** |

### Phase 1 — Skeleton, Provider Core & Docs ✅
Bootstrap + Composer autoload + `Core\Plugin`/`Activator`/`Deactivator`/`Migrator`, AWS SDK, `ProviderInterface` + `AbstractS3Provider` + `CloudflareR2` + `ProviderRegistry`, `BaseController` + `CoreController` + `ProviderController`, wp-scripts + TypeScript toolchain, PHPStan + PHPCS, React shell, Settings page with provider select + `CredentialFields` + Test Connection, `CLAUDE.md` + `.project/`.

### Phase 2 — Offload Pipeline ✅
`AttachmentUploader`, meta model, URL-rewrite observers, Retention Policy + `LocalCleaner`, Mirror Delete, Settings page policy sections.

### Phase 3 — Bulk Offload + Media Library UX ✅
`BulkOffloadProcessor` + `OffloadController` bulk endpoints + Offload page (progress/cancel/errors), library filter, status field, row actions, `wp omnioffloader offload`, `wp omnioffloader status`.

### Phase 4 — ★ Bring Back ✅
`download_file()`, `AttachmentRestorer`, `BringBackController` (+ preflight), Bring Back page, `BulkRestoreProcessor`, `wp omnioffloader restore`. Verified by full round-trip: offload with Full Cloud Migration → Bring Back → files byte-identical (md5), URLs local again.

### Phase 4a — Providers & Reliability (`0.1.1`) ✅
Originally scoped as "Phase 6+, locked until `1.0.0`" — **that gate was dropped**, because `AbstractS3Provider` made each provider a ~90-line file with no core changes, exactly as §4.2 intended. Shipped: `AmazonS3`, `BackblazeB2`, `DigitalOceanSpaces`, `Wasabi`, `S3Compatible`; secondary backup storage; multipart uploads; and the bulk-queue hardening in §7.5.

### Phase 5 (v0.9.0) — Polish & Release Candidate
Error surfacing UI, `readme.txt` + `.wordpress-org` assets polish, compat testing (Elementor, EWWW/Imagify, WebP-uploads), edge cases (scaled images, image editor saves, thumbnail regeneration), finalize `CLAUDE.md`, release zip. **End of planned work — `1.0.0` requires owner permission.**

### Future (🔒 after `1.0.0` is approved)
Google Cloud Storage; scheduled Zip archives / safety backups to Google Drive; multi-CDN routing and cache-purge integrations.

---

## 12. What Comes From Where

| Concern | Origin |
|---|---|
| Provider abstraction, retention policy (3 modes), observer-per-hook offload engine, key collection (sizes/original/modern sources), wp-config credential constants | advanced-media-offloader |
| Background queue primitives (`WP_Async_Request`, `WP_Background_Process`) | Delicious Brains' WP Background Processing (GPL), vendored in `includes/Abstracts/` and subclassed — see §7.5 |
| ★ Bring Back (restore to server), secondary backup storage, provider meta per attachment, credential fields as JSON-driven generic UI, **TypeScript admin app**, the hardened bulk queue, full-page admin design (§6.1) | new in this plugin |
