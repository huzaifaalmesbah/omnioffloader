# Smart Media Offloader — Development Plan

> **Status:** Planning only — no code yet.
> **References:**
> - `advanced-media-offloader` (v4.4.4) — offload engine concepts: provider abstraction, retention policy, bulk background processing.
> - `smart-password-protect` (v1.2.6) — plugin skeleton: custom autoloader, `Core\Plugin` bootstrap, REST API controllers, **React admin app built with `@wordpress/scripts`**, i18n workflow, docs (`CLAUDE.md`, `.project/`).

---

## 1. Vision

Offload WordPress media library files to cloud object storage to save server disk space and serve media from a CDN.

- **v1.0:** Cloudflare R2 only — one provider, done really well.
- **Future:** Amazon S3, Backblaze B2, DigitalOcean Spaces, Wasabi, MinIO/any S3-compatible — added **one by one** without touching core code (provider registry + abstract base class make each new provider a single new file).
- **Key differentiator:** **Bring Back** — a first-class restore feature that downloads offloaded media from the cloud back to the server (per-file and bulk), safely reversing an offload.
- **Modern admin:** single-page React app (like smart-password-protect) — no PHP settings templates, everything through the REST API.

---

## 2. Plugin Identity

| Item | Value |
|---|---|
| Plugin name | Smart Media Offloader |
| Folder / slug | `smart-media-offloader` |
| Main file | `smart-media-offloader.php` |
| **Starting version** | **`0.1.0` (pre-release)** — see §11 version map. ⚠️ **All planned work ends at `0.9.0`. Version `1.0.0` is NOT part of this plan and must never be tagged/released without the owner's explicit permission.** |
| License | `GPL-2.0-or-later` + License URI header (the "or later" also makes bundling the Apache-2.0-licensed AWS SDK compatible — Apache-2.0 is GPLv3-compatible) |
| Text domain | `smart-media-offloader` |
| PHP namespace | `SmartMediaOffloader\` |
| Prefix (options, meta, hooks) | `smowp_` / constants `SMOWP_` |
| REST namespace | `smowp/v1` |
| Requires | WP 6.6+, PHP 8.1+ |
| PHP autoloading | Custom `includes/Autoloader.php` (PSR-4 style, like SPPWP) for plugin classes; Composer **only** for the scoped AWS SDK in `vendor/` |
| Admin UI | React SPA in **TypeScript** (`.tsx`/`.ts`) via `@wordpress/scripts`, `@wordpress/components`, `@wordpress/element`, `@wordpress/api-fetch`, `@wordpress/i18n` |
| SDK | AWS SDK for PHP (S3Client works for R2 & every future S3-compatible provider), prefixed with PHP-Scoper |

---

## 3. Folder & File Structure (target — mirrors smart-password-protect)

```
smart-media-offloader/
├── smart-media-offloader.php        # Bootstrap: constants, autoloader, hooks
├── uninstall.php                    # Delegates to Core\Uninstaller (opt-in cleanup)
├── readme.txt                       # WP.org readme
├── CLAUDE.md                        # ★ Dev docs for AI/contributors (like SPPWP)
├── PLAN.md                          # This file
├── package.json                     # @wordpress/scripts build + i18n + bundle scripts
├── tsconfig.json                    # ★ Strict TS config (Gutenberg-style, type-check only)
├── phpstan.neon.dist                # ★ PHPStan level 6+ w/ szepeviktor/phpstan-wordpress
├── phpcs.xml.dist                   # ★ WordPress Coding Standards ruleset
├── composer.json                    # Scoped AWS SDK + dev tools (phpstan, wpcs)
├── .gitignore / .distignore / .gitattributes
├── .project/                        # ★ Living project docs (like SPPWP)
│   ├── index.md                     # Project overview & doc index
│   ├── roadmap/
│   │   ├── index.md
│   │   └── v0.1.0.md                # Phase checklists (this plan, broken down)
│   ├── issues/index.md
│   └── security/index.md            # Security notes & audit log
├── .wordpress-org/                  # Screenshots, banners for the plugin directory
├── .github/                         # CI: build check, PHPCS, deploy workflow
├── bin/
│   └── merge-json-translations.js   # i18n helper (copy from SPPWP)
├── assets/
│   └── icons/icon.svg               # Menu icon
├── languages/                       # POT/PO/MO/JSON translations
├── build/                           # wp-scripts output (index.js, index.css, index.asset.php) — not committed
├── src/                             # ★ React admin app in TypeScript (entry: src/index.tsx → #smowp-admin-app)
│   ├── index.tsx
│   ├── index.scss
│   ├── App.tsx                      # Router: view= query param → page
│   ├── types/
│   │   ├── settings.ts              # Settings, RetentionPolicy enum, credential field defs
│   │   ├── provider.ts              # Provider registry entry, connection status
│   │   ├── media.ts                 # Stats, attachment offload state
│   │   ├── progress.ts              # Bulk offload/restore progress payloads
│   │   └── global.d.ts              # window.smowp (localized data) declaration
│   ├── api/                         # Typed apiFetch wrappers per feature
│   │   ├── core.ts                  # settings, provider list, stats
│   │   ├── provider.ts              # credentials, test connection
│   │   ├── offload.ts               # bulk offload start/progress/cancel, per-item
│   │   └── bringBack.ts             # ★ restore start/progress/cancel, per-item
│   ├── context/
│   │   └── CoreContext.tsx          # Global state: settings, provider, stats, dirty/save state
│   ├── hooks/
│   │   ├── useSettings.ts
│   │   ├── useProvider.ts           # credentials + connection status
│   │   ├── useBulkOffload.ts        # polling progress state machine
│   │   ├── useBringBack.ts          # ★ same for restore
│   │   └── useMediaStats.ts
│   ├── pages/
│   │   ├── Dashboard.tsx            # Stats cards, quick status, helpful links
│   │   ├── Settings.tsx             # Provider + credentials + policies
│   │   ├── Offload.tsx              # Bulk offload with progress
│   │   └── BringBack.tsx            # ★ Bulk restore with progress
│   ├── components/
│   │   ├── layout/                  # Sidebar (logo + 2-line nav), Header (icon badge
│   │   │                            #   + current page title/subtitle), SaveBar
│   │   │                            #   (★ sticky bottom save bar), MainLayout, PageLayout
│   │   ├── ui/                      # Button, Card, Input, Select, Toggle, Modal,
│   │   │                            #   ProgressBar, Skeleton, CopyButton
│   │   ├── common/SaveButton.tsx
│   │   ├── dashboard/               # StatsCard, StorageChart, HelpfulLinks
│   │   ├── settings/
│   │   │   ├── ProviderSelect.js    # Dropdown from provider registry
│   │   │   ├── CredentialFields.js  # Renders provider's field defs; masks
│   │   │   │                        #   wp-config-constant-locked fields
│   │   │   ├── TestConnection.js
│   │   │   ├── RetentionPolicy.js   # 3 radio options
│   │   │   ├── MirrorDelete.js
│   │   │   └── PathSettings.js      # Prefix + object versioning
│   │   ├── offload/                 # OffloadProgress, ErrorList, ConfirmModal
│   │   └── bring-back/              # ★ RestoreProgress, DiskSpaceWarning,
│   │                                #   DeleteCloudToggle, ConfirmModal
│   └── utils/                       # constants.ts (route meta), apiHandler.ts,
│                                    #   notificationHelper.ts, formatBytes.ts, errorHelper.ts
├── includes/                        # ★ PHP backend (SPPWP layout)
│   ├── Autoloader.php               # SmartMediaOffloader\ → includes/
│   ├── Core/
│   │   ├── Plugin.php               # Singleton; wires everything on plugins_loaded
│   │   ├── Activator.php            # First-version option, activation redirect transient
│   │   ├── Deactivator.php          # Clear crons + bulk state (keep settings)
│   │   ├── Uninstaller.php          # Opt-in full cleanup (options + meta)
│   │   ├── Migrator.php             # Version-gated migrations
│   │   ├── Cron.php                 # Stalled-process watchdog
│   │   └── Helpers.php              # Path sanitize, URL normalize, stats queries
│   ├── Api/                         # REST controllers (extend BaseController)
│   │   ├── BaseController.php       # register_*_route helpers, permissions_check
│   │   │                            #   (manage_options), param helpers, pagination
│   │   ├── CoreController.php       # GET/POST settings, GET providers, GET stats
│   │   ├── ProviderController.php   # GET/POST credentials, POST test-connection
│   │   ├── OffloadController.php    # POST offload/{id}, POST bulk, GET bulk/progress,
│   │   │                            #   POST bulk/cancel, GET errors
│   │   └── BringBackController.php  # ★ POST restore/{id}, POST bulk, GET progress,
│   │                                #   POST cancel, GET preflight (disk space)
│   ├── Providers/
│   │   ├── ProviderInterface.php    # Contract (see §4.2)
│   │   ├── AbstractS3Provider.php   # Shared S3Client logic: upload, download,
│   │   │                            #   batch delete, exists, checkConnection
│   │   ├── ProviderRegistry.php     # key => [name, class]; smowp_cloud_providers filter
│   │   └── CloudflareR2.php         # v1.0 — the only concrete provider
│   │   # future, one file each: AmazonS3.php, BackblazeB2.php, …
│   ├── Services/
│   │   ├── AttachmentUploader.php   # Offload one attachment (+ all sizes/sources)
│   │   ├── AttachmentRestorer.php   # ★ Bring Back: cloud → server, atomic + verified
│   │   ├── LocalCleaner.php         # Applies retention policy after verified offload
│   │   ├── BulkOffloadProcessor.php # Background queue (WP_Background_Process)
│   │   └── BulkRestoreProcessor.php # ★ Background queue for Bring Back
│   ├── Offload/                     # Hook integration (observer pattern, one hook per class)
│   │   ├── ObserverInterface.php    # register(): void
│   │   ├── Offloader.php            # Attaches + registers all observers
│   │   └── Observers/
│   │       ├── UploadObserver.php           # wp_generate_attachment_metadata
│   │       ├── UrlRewriteObserver.php       # wp_get_attachment_url
│   │       ├── SrcsetObserver.php           # wp_calculate_image_srcset
│   │       ├── SrcsetMetaObserver.php       # wp_calculate_image_srcset_meta
│   │       ├── ContentImageTagObserver.php  # wp_content_img_tag
│   │       ├── AttachedFileObserver.php     # get_attached_file fallback
│   │       ├── DeleteObserver.php           # delete_attachment → mirror delete
│   │       ├── UpdateObserver.php           # image edits / regenerated thumbs
│   │       ├── UniqueFilenameObserver.php   # avoid overwrites when local removed
│   │       ├── MediaLibraryFilterObserver.php  # Offloaded/Local dropdown filter
│   │       ├── OffloadStatusObserver.php    # Status in attachment details
│   │       └── RowActionsObserver.php       # ★ "Offload" / "Bring Back" row actions
│   ├── Admin/
│   │   ├── Settings.php             # Menu page + enqueue build/index.js (asset.php
│   │   │                            #   deps), wp_localize_script('smowp', …),
│   │   │                            #   wp_set_script_translations, redirects
│   │   └── AdminBar.php             # Optional status shortcut
│   ├── Cli/
│   │   ├── CommandRegistrar.php     # Registers all commands under `wp smowp`
│   │   ├── AbstractCommand.php
│   │   ├── OffloadCommand.php       # wp smowp offload [--all|--ids=…] [--batch-size=…]
│   │   ├── RestoreCommand.php       # ★ wp smowp restore [--all|--ids=…] [--delete-cloud]
│   │   └── StatusCommand.php        # wp smowp status — provider, counts, connection
│   ├── Traits/
│   │   ├── OptionsHelper.php        # get/update smowp_settings, smowp_credentials,
│   │   │                            #   sanitize helpers, is_configured()
│   │   └── AttachmentHelper.php     # Key collection (sizes/original/sources),
│   │                                #   subdir/version logic, offload meta read/write
│   └── templates/
│       └── admin-app.php            # <div id="smowp-admin-app"></div> mount point
└── vendor/                          # Scoped AWS SDK (composer, PHP-Scoper)
```

---

## 4. Backend Architecture

### 4.1 Bootstrap flow (SPPWP pattern)

`smart-media-offloader.php`:

1. Define constants: `SMOWP_VERSION`, `SMOWP_DIR`, `SMOWP_URL`, `SMOWP_ASSETS_URL`, `SMOWP_BASENAME`, `SMOWP_FILE`.
2. `require includes/Autoloader.php` → `SmartMediaOffloader\Autoloader::run()`.
3. Load scoped AWS SDK autoloader from `vendor/` (admin notice if missing).
4. `register_activation_hook` → `Core\Activator::activate()`; deactivation → `Core\Deactivator::deactivate()`.
5. `add_action('plugins_loaded', …)` → `Core\Plugin::get_instance()->run()`.
6. `WP_CLI` check → `Cli\CommandRegistrar::register()`.

`Core\Plugin::run()`:
- `initialize_classes()`:
  - Always: `CoreController`, `ProviderController`, `Admin\Settings`.
  - Only when a provider is configured (`OptionsHelper::is_configured()`): `Offload\Offloader` (registers all observers), `OffloadController`, `BringBackController`, `BulkOffloadProcessor`, `BulkRestoreProcessor`, `Cron`.
- `maybe_upgrade()` on `init` priority 5 → `Migrator::run()` (version-gated, runs in every context).
- Plugin action links (Settings) + row meta.

### 4.2 Provider abstraction (easy to extend)

```
ProviderInterface
   └── AbstractS3Provider (abstract)          ← 95% of logic lives here
          └── CloudflareR2 (v1.0)
          └── AmazonS3, BackblazeB2, …         ← future: ~80-line classes
```

**`ProviderInterface`:**

| Method | Purpose |
|---|---|
| `get_key(): string` | e.g. `cloudflare_r2` |
| `get_name(): string` | e.g. "Cloudflare R2" |
| `get_client()` | Configured S3Client |
| `get_bucket(): ?string` | |
| `get_domain(): string` | Public/CDN base URL for rewrites |
| `upload_file(string $file, string $key): string\|false` | |
| `download_file(string $key, string $dest): bool` | ★ Bring Back (getObject + SaveAs) |
| `delete_objects(array $keys): bool` | Batched (1000/req), per-object fallback |
| `object_exists(string $key): bool` | |
| `check_connection(): bool` | headBucket, 5s timeout |
| `credential_fields(): array` | Field definitions **served as JSON to the React UI** |

`credential_fields()` returns machine-readable defs (`name`, `label`, `type`, `placeholder`, `description`, `locked` when a wp-config constant defines it) — the React `CredentialFields` component renders them generically. **A new provider needs zero JS changes.**

**`ProviderRegistry`:** static map `'cloudflare_r2' => ['name' => 'Cloudflare R2', 'class' => CloudflareR2::class]`, exposed via `smowp_cloud_providers` filter and `GET /smowp/v1/core/providers`. Shipping a new provider = one class + one registry line.

### 4.3 Credentials

1. **wp-config constants win:** `SMOWP_CLOUDFLARE_R2_KEY`, `…_SECRET`, `…_ENDPOINT`, `…_BUCKET`, `…_DOMAIN` — reported as `locked: true` to the UI (fields disabled, secrets masked, **never returned raw**).
2. Otherwise stored in `smowp_credentials` option (per-provider sub-array).
3. REST: `GET` returns fields with secrets masked (`••••`, `is_set: true`); `POST` ignores masked placeholder values so saving other fields never wipes a secret.

Cloudflare R2 fields (v1.0): Access Key ID, Secret Access Key, Endpoint URL (`https://<account-id>.r2.cloudflarestorage.com`), Bucket Name, Custom Domain / public bucket URL (required — R2 has no default public URL). Region fixed `auto`/`us-east-1`. No ACLs on R2 (filterable `smowp_object_acl` for future S3).

### 4.4 Attachment meta (state model)

| Meta key | Meaning |
|---|---|
| `smowp_offloaded` | `1` when file(s) live in cloud |
| `smowp_offloaded_at` | timestamp |
| `smowp_provider` | provider key at offload time (critical for Bring Back after switching providers) |
| `smowp_bucket` | bucket at offload time |
| `smowp_path` | object key prefix (subdir) in bucket |
| `smowp_object_version` | version segment when object versioning enabled |
| `smowp_retention` | retention policy applied (0/1/2) — tells Bring Back what's missing locally |
| `smowp_error_log` | last offload/restore errors (array) |

### 4.5 Options

- `smowp_settings` — provider key, retention_policy, mirror_delete, path_prefix(+active), object_versioning, bring_back defaults, remove_data_on_uninstall.
- `smowp_credentials` — per-provider credentials.
- `smowp_version` — for `Migrator`.
- `smowp_bulk_offload_state` / `smowp_bulk_restore_state` — progress heartbeat (total, processed, errors, cancelled).

---

## 5. REST API (`smowp/v1`) — consumed by the React app

All routes: `permission_callback` → `manage_options` (BaseController), nonce via `@wordpress/api-fetch` automatically (cookie auth + `X-WP-Nonce`).

**Controller conventions (WP REST best practice):**
- Register on `rest_api_init`; every route declares an explicit `permission_callback` (never omitted).
- Methods via `WP_REST_Server::READABLE/CREATABLE/EDITABLE/DELETABLE` constants.
- Every route defines `args` with `type`, `required`, `default`, `validate_callback`, `sanitize_callback` (JSON Schema style) — no reading `$_GET`/`$_POST`; always `WP_REST_Request` typed params.
- Success via `rest_ensure_response()`; errors via `WP_Error` with explicit `status` (400/403/404/409/500) and stable error codes (`smowp_invalid_provider`, `smowp_not_offloaded`, `smowp_disk_full`, …) that the React app maps to user-friendly notices.
- Response shapes documented per endpoint and mirrored 1:1 in `src/types/` (TS).

**Core (`CoreController`)**
- `GET  /core/settings` · `POST /core/settings`
- `GET  /core/providers` — registry list + each provider's credential field defs
- `GET  /core/stats` — total / offloaded / local counts, estimated cloud size

**Provider (`ProviderController`)**
- `GET  /provider/credentials?provider=…` (masked) · `POST /provider/credentials`
- `POST /provider/test-connection` — returns status + timestamp

**Offload (`OffloadController`)**
- `POST /offload/attachment/{id}` — single item (media library row action)
- `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel`
- `GET  /offload/errors` — paginated attachments with `smowp_error_log`

**★ Bring Back (`BringBackController`)**
- `GET  /bring-back/preflight` — count, estimated bytes, `disk_free_space()` check
- `POST /bring-back/attachment/{id}` — single restore (`delete_cloud` param)
- `POST /bring-back/bulk` · `GET /bring-back/bulk/progress` · `POST /bring-back/bulk/cancel`

Progress endpoints are polled by `useBulkOffload` / `useBringBack` hooks (2s interval while running — same pattern as SPPWP settings flow: optimistic local state, save via API, notices via `notificationHelper`).

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
   - Nav items: **icon + bold title + small gray subtitle** (two-line items), e.g. "Dashboard / Overview & quick actions", "Settings / Provider & policies", "Offload / Move media to cloud", "Bring Back / Restore to server".
   - **Active item**: soft accent background (light tint), colored left border bar, accent-colored icon/title.
2. **Top header bar** (content area, sticky):
   - Rounded **icon badge** (light accent tint) + **current menu name** as large title + subtitle underneath — mirrors the selected sidebar item.
3. **Sticky bottom bar** (content area, always visible):
   - Right-aligned: dirty-state text (**"All changes saved"** / "Unsaved changes") + primary **"Save changes"** button (accent color, disabled/dimmed when nothing to save).
   - Save state is global (context-driven): pages register their dirty state; Save persists via REST, then shows saved confirmation.
4. **Content area**: light gray background; content in **white rounded cards** with soft shadows; feature cards can carry an icon badge + title + "Active" pill + description + right-aligned toggle (like the reference's module card); stat cards in a grid on Dashboard (icon, big number, label); hero/summary banner card allowed on Dashboard (accent gradient).
5. **Style tokens**: blue accent (`#135dbe` base, `#0e4690` dark), rounded corners (~12–16px), pill-shaped toggles/badges, generous spacing. Implemented in `src/index.scss` with CSS variables; components from our `ui/` kit wrap `@wordpress/components` but restyled to this design.
6. WP admin notices are hidden on our page; the app supplies its own snackbar/notice system so the full-page layout stays clean.

Layout components: `layout/Sidebar.tsx` (logo + nav), `layout/Header.tsx` (icon badge + title + subtitle from route meta), `layout/SaveBar.tsx` (★ new — sticky bottom save bar), `layout/MainLayout.tsx` (grid shell wiring all three around the page outlet). Route meta (icon, title, subtitle) lives in `utils/constants.ts` (typed `RouteMeta[]`) so Sidebar and Header render from one source.

### 6.2 Integration

- Menu: top-level "Media Offloader", `manage_options`, renders `templates/admin-app.php` (`#smowp-admin-app`).
- `Admin\Settings::enqueue_scripts()` — only on our hook: `build/index.asset.php` deps/version, `wp_enqueue_script` + `wp_enqueue_style` (`wp-components` dep), `wp_localize_script('smowp', ['settings_url' => …, 'upload_max' => …])`, `wp_set_script_translations('smowp-admin-app', 'smart-media-offloader', languages/)`.
- Routing via `view=` query param (SPPWP style): `dashboard`, `settings`, `offload`, `bring-back`.
- Pages:
  - **Dashboard** — stat cards (Total media / Offloaded / Local / Space saved), provider connection badge, links to Offload & Bring Back.
  - **Settings** — provider select, generic `CredentialFields`, Test Connection, Retention Policy radios, Mirror Delete, Path Prefix, Object Versioning, uninstall cleanup toggle.
  - **Offload** — pre-count, Start/Cancel, `ProgressBar`, live processed/error counters, error list.
  - **★ Bring Back** — preflight summary (items, bytes, free disk) with `DiskSpaceWarning`, "also delete from cloud" toggle (default OFF), Start/Cancel + progress, per-item results.
- Reusable `ui/` kit wraps `@wordpress/components` (Card, Button, Toggle, Modal, ProgressBar…) for consistent styling, like SPPWP.

---

## 7. Feature Behavior

### 7.1 Auto-offload on upload
`UploadObserver` on `wp_generate_attachment_metadata` (late): `AttachmentUploader` uploads original + all sizes (+ `original_image`, modern-format `sources` WebP/AVIF), writes meta, then `LocalCleaner` applies retention policy.

### 7.2 URL rewriting
When `smowp_offloaded`: rewrite via `UrlRewriteObserver`, `SrcsetObserver`, `SrcsetMetaObserver`, `ContentImageTagObserver`; `AttachedFileObserver` keeps `get_attached_file` usable when local copy is gone.

### 7.3 Retention Policy (same 3 modes as advanced-media-offloader)

Radio setting `smowp_settings[retention_policy]`:

| Value | Label | Behavior after successful offload |
|---|---|---|
| `0` | **Retain Local Files** (default) | Nothing deleted; cloud is a mirror. |
| `1` | **Smart Local Cleanup** | Delete generated thumbnail sizes locally; **keep original**. |
| `2` | **Full Cloud Migration** | Delete original + all sizes locally. |

`LocalCleaner` runs only after upload verified (`object_exists` on main key). Separate **Mirror Delete** checkbox: deleting an attachment in WP also deletes its cloud objects (batched, incl. backup sizes + modern-format sources, `smowp_attachment_delete_keys` filter).

### 7.4 ★ Bring Back (Restore cloud → server)

**Per-attachment** (row action / attachment details / REST):
`AttachmentRestorer::restore($id, $delete_cloud = false)`:
1. Verify `smowp_offloaded`; resolve provider **from `smowp_provider` meta** (works after switching active provider while credentials remain).
2. Build full key list (main, sizes, `original_image`, sources) — same collection logic as delete.
3. Download each to its `wp_upload_dir()` path: temp file + `rename()` (atomic, never half-written), create directories, path-traversal guard (`realpath` inside uploads basedir).
4. Skip files already present locally with matching size (retention 0/1 — no wasted bandwidth).
5. Verify all expected files exist locally.
6. Clear offload meta → URLs serve locally again.
7. If `$delete_cloud` (default OFF): delete cloud objects only after local verification.
- Any failure: keep cloud copy + meta, log to `smowp_error_log`, report. **Never leave an attachment where neither copy is valid.**

**Bulk:** `BulkRestoreProcessor` (WP_Background_Process): queue, batches, heartbeat progress option, stalled watchdog via `Cron`, cancel support, disk-space preflight. CLI: `wp smowp restore --all|--ids=1,2,3 [--delete-cloud] [--batch-size=…]`.

### 7.5 Bulk offload
Same machinery for offload direction: `BulkOffloadProcessor`, Offload page UI, `wp smowp offload`.

### 7.6 Media library integration
Filter dropdown (All/Offloaded/Local), status in attachment details (provider + date), row actions "Offload" / "Bring Back".

---

## 8. Build, i18n & Release Tooling (copied from SPPWP)

**package.json scripts:**

| Script | Purpose |
|---|---|
| `start` | `wp-scripts start` — dev watch (TypeScript compiled by wp-scripts natively) |
| `build` | `wp-scripts build` — production |
| `build:release` | build + `i18n:build` |
| `check-types` | `tsc --noEmit` — strict type check (run in CI alongside build) |
| `lint:js` | `wp-scripts lint-js` — ESLint incl. TS rules |
| `i18n:pot/po/mo/json/merge` | `wp i18n make-pot/…` chain + `bin/merge-json-translations.js` |
| `bundle` | build + zip excluding dev files (src, node_modules, .git*, .project, CLAUDE.md, …) |

- Dependencies: `@wordpress/api-fetch`, `@wordpress/components`, `@wordpress/element`, `@wordpress/i18n`, `@wordpress/url`, `@wordpress/compose`; dev: `@wordpress/scripts`, `typescript`, `@types/wordpress__*` where needed.
- **TypeScript setup (Gutenberg-grade — patterns verified against the `gutenberg` repo's `tsconfig.base.json` and TS-first packages like `dataviews`):**
  - `@wordpress/scripts` compiles `.ts`/`.tsx` out of the box (entry `src/index.tsx`, no webpack override). Babel does the transpiling; **`tsc` is type-check only**, exactly like Gutenberg.
  - `tsconfig.json`:
    ```jsonc
    {
      "$schema": "https://json.schemastore.org/tsconfig.json",
      "compilerOptions": {
        "target": "esnext",
        "module": "preserve",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "lib": ["DOM", "DOM.Iterable", "ESNext"],
        "strict": true,
        "noImplicitReturns": true,
        "noFallthroughCasesInSwitch": true,
        "isolatedModules": true,          // required — Babel transpiles file-by-file
        "resolveJsonModule": true,
        "allowSyntheticDefaultImports": true,
        "skipLibCheck": true,             // don't type-check node_modules
        "noEmit": true                    // app, not a library — check only
      },
      "include": ["src"],
      "files": ["src/types/global.d.ts"]
    }
    ```
  - `src/types/` mirrors Gutenberg's per-package `src/types/` convention: one file per domain (`settings.ts`, `provider.ts`, `media.ts`, `progress.ts`) with an `index.ts` barrel. These are the **single source of truth** for REST payload shapes, shared by `api/` wrappers, hooks, and components — and they must match the PHP controllers' `args`/response schemas (documented in `CLAUDE.md`).
  - `global.d.ts` declares `window.smowp` (localized data) and is referenced via tsconfig `files` (Gutenberg's pattern for guaranteed-included globals).
  - `@wordpress/*` packages ship their own types — no `@types/wordpress__*` needed for the core ones; add DefinitelyTyped packages only where a package lacks bundled types.
  - No `any` escapes: prefer `unknown` + narrowing; `@ts-expect-error` (with a reason comment) over `@ts-ignore`. ESLint via `wp-scripts lint-js` extends the WordPress TS config.
- i18n: PHP `__( '…', 'smart-media-offloader' )`; JS `__( '…', 'smart-media-offloader' )` from `@wordpress/i18n`; `wp_set_script_translations` loads JSON translations.
- `.distignore` mirrors the bundle excludes; `.github/` CI runs build + PHPCS (WordPress Coding Standards, like SPPWP file headers/comment style).

---

## 9. Documentation Deliverables (★ required)

1. **`CLAUDE.md`** — written in Phase 1, kept current every phase (SPPWP format):
   - Build commands (`npm run build`, `npm start`, `npm run bundle`, `npm run i18n`).
   - Architecture: backend structure tree, REST namespace + endpoint table, React `src/` structure + entry point (`#smowp-admin-app`), TypeScript conventions (strict mode, `src/types/` as the single source of payload shapes, `npm run check-types`).
   - Key patterns: `OptionsHelper` / `AttachmentHelper` traits, `BaseController` helpers, provider contract + "how to add a provider" recipe, settings flow (hook → optimistic update → API save → notice).
   - Data reference: options, attachment meta keys, transients, cron events, constants (`SMOWP_*`), wp-config credential constants.
   - Hooks table (actions/filters, §10).
   - Security considerations.
2. **`.project/`** — living project docs:
   - `index.md` (overview + doc index), `roadmap/v1.0.0.md` (phases below as checklists), `issues/index.md`, `security/index.md`.
3. **`readme.txt`** — WP.org: description, FAQ (R2 setup guide, custom-domain requirement, Bring Back explanation), screenshots, changelog.

---

## 10. Security & Extensibility

**Security (day 1):**
- REST-only mutations; `permission_callback` `manage_options`; api-fetch nonces.
- Secrets never returned raw; masked responses; constants lock fields; password-hash-grade care in logs (never log credentials).
- Sanitize/whitelist all settings server-side (retention ∈ {0,1,2} etc.); escape all PHP template output.
- Object keys sanitized; `dirname() === '.'` guard (real bug class in the reference plugin); restore destination confined to uploads basedir.
- Never delete local before verified cloud upload; never delete cloud before verified local restore.
- Uninstall cleanup only when user opted in (`Core\Uninstaller`).

**Public hooks:**

| Hook | Type | Purpose |
|---|---|---|
| `smowp_cloud_providers` | filter | Register/modify providers |
| `smowp_object_acl` | filter | Per-object ACL (empty for R2) |
| `smowp_attachment_key` | filter | Customize object key |
| `smowp_before_offload` / `smowp_after_offload` | action | Offload lifecycle |
| `smowp_before_restore` / `smowp_after_restore` | action | ★ Bring Back lifecycle |
| `smowp_attachment_delete_keys` | filter | Extra keys on mirror delete (sidecars) |
| `smowp_should_offload_attachment` | filter | Skip rules (mime/size/…) |

---

## 10a. Code Quality & Static Analysis (from wp-phpstan / wp-plugin-development skills)

- **PHPStan** from day 1 (not retrofitted — no baseline needed for a greenfield project):
  - `composer require --dev szepeviktor/phpstan-wordpress` (bundles WP core stubs + WP-specific rules).
  - `phpstan.neon.dist`: `level: 6` to start (ratchet up toward `8`), `paths: [includes, smart-media-offloader.php, uninstall.php]`, `excludePaths: [vendor, node_modules, build]`, includes the phpstan-wordpress extension.
  - WP-friendly typing: `WP_REST_Request<array{…}>` generics on controller callbacks, array shapes for settings/meta payloads, accurate `@param` docs on hook callbacks. Prefer fixing types over `ignoreErrors`.
  - `composer scripts`: `"phpstan": "phpstan analyse"` — run in CI next to `check-types` and `lint:js`.
- **PHPCS** with WordPress Coding Standards (`wp-coding-standards/wpcs` via Composer, `phpcs.xml.dist`) — same conventions as SPPWP (file/class doc headers, Yoda conditions per WPCS, escaping sniffs).
- **Plugin architecture guardrails:** no side effects at file load (everything on hooks via `Core\Plugin`); activation/deactivation hooks registered at top level of the main file; admin-only code behind `is_admin()`; `uninstall.php` guards `WP_UNINSTALL_PLUGIN` and only deletes data when the user opted in.

## 10b. Performance Rules (from wp-performance skill)

- **Options autoloading:** `smowp_settings`/`smowp_version` may autoload (tiny); `smowp_credentials`, bulk progress state (`smowp_bulk_offload_state`, `smowp_bulk_restore_state`) and any error logs are stored with **`autoload => false`** — they're only needed on our admin page/REST calls.
- **No remote calls on normal requests:** connection checks run only on explicit "Test Connection" or scheduled health check — never during page loads. All S3 client calls have explicit timeouts.
- **Cheap stats:** media counts via a single indexed query on `postmeta` key (cached in a short transient), not `WP_Query` meta_query loops; Dashboard stats endpoint is the only consumer.
- **Frontend footprint = filters only:** on non-admin requests the plugin adds URL-rewrite filters and nothing else — no queries, no option writes, no HTTP.
- **Cron hygiene:** single watchdog event while a bulk job runs, unscheduled when idle; background processing batches sized to stay under PHP time limits; idempotent tasks (safe to re-run).

## 10c. WordPress.org Directory Compliance (from wp-plugin-directory-guidelines skill)

- License header: `License: GPL-2.0-or-later` + `License URI: https://www.gnu.org/licenses/gpl-2.0.html` in the main file; same in `readme.txt`.
- **Bundled AWS SDK is Apache-2.0** — GPL-compatible only via GPLv3, which our "or later" covers; document this in readme licensing note. PHP-Scoper prefixing also avoids class collisions (guideline: don't break other plugins).
- Name/slug rules: "Smart Media Offloader" ≥5 alphanumeric chars ✓, no trademarks ✓ ("Cloudflare" appears only descriptively in feature lists, never in the plugin name/slug); header name and readme name must match exactly.
- No trialware/locked features: everything in the repo works; no upsell nags, no obfuscated code, no external tracking without opt-in consent. No "powered by" links injected into sites.
- `readme.txt` `Stable Tag` matches the released version; `Tested up to` kept current.

## 10d. WP-CLI Conventions (from wp-wpcli-and-ops skill)

- All commands under `wp smowp …`, registered only when `WP_CLI` is defined, via `CommandRegistrar`.
- **Destructive safety:** `wp smowp restore --delete-cloud` and any cloud-delete path require `--yes` or interactive `WP_CLI::confirm()`; every write command supports `--dry-run` (prints what would happen: counts, keys, bytes).
- Progress via `WP_CLI\Utils\make_progress_bar()`; structured summaries at the end (processed / skipped / failed); non-zero exit code on failure so CI scripts can gate on it.
- Docblock-documented synopses (`@synopsis` / PHPDoc args) so `wp help smowp offload` is complete.

## 11. Implementation Phases & Version Map

Phases 1–4 are **development phases, not release versions** — they all ship together as **`0.1.0`**, the pre-release line. Each was still built and verified in order so progress stayed testable at every step.

> ⚠️ **Version ceiling: this plan ends at `0.9.0`.** `1.0.0` (first stable / WP.org submission) requires the owner's **explicit permission** — it is listed below only for context and must not be started, tagged, or released as part of executing this plan.

| Version | Milestone |
|---|---|
| `0.1.0` | Phases 1–4 — skeleton + R2 provider + settings UI, offload pipeline + retention, bulk offload + library UX + CLI, ★ Bring Back |
| `0.9.0` | Phase 5 — polish, i18n, compat (release candidate). **← End of planned work.** |
| `1.0.0` | 🔒 First stable — **only with owner's explicit permission** |
| `1.x` | 🔒 Phase 6+ — new providers (after 1.0.0 is approved) |

### Phase 1 — Skeleton, Provider Core & Docs
Bootstrap + Autoloader + `Core\Plugin`/`Activator`/`Deactivator`/`Migrator`, scoped AWS SDK, `ProviderInterface` + `AbstractS3Provider` + `CloudflareR2` + `ProviderRegistry`, `BaseController` + `CoreController` + `ProviderController`, wp-scripts + TypeScript toolchain (`tsconfig.json`, `src/types/`, `check-types` in CI), PHPStan (`phpstan.neon.dist`, level 6) + PHPCS (WPCS) wired into composer scripts + CI, React shell (layout, router, Dashboard skeleton), Settings page with provider select + `CredentialFields` + Test Connection. Write `CLAUDE.md` + `.project/` skeleton.
**Done when:** credentials save via REST, Test Connection passes against a real R2 bucket, React app builds & renders.

### Phase 2 — Offload Pipeline
`AttachmentUploader`, meta model, all URL-rewrite observers, Retention Policy + `LocalCleaner`, Mirror Delete, Settings page policy sections.
**Done when:** new upload lands in R2 and serves from custom domain; all 3 retention modes verified; attachment delete cleans bucket.

### Phase 3 — Bulk Offload + Media Library UX
`BulkOffloadProcessor` + `OffloadController` bulk endpoints + Offload page (progress/cancel/errors), library filter, status field, row actions, `wp smowp offload`, `wp smowp status`.
**Done when:** 1k+ item library offloads reliably with live progress and cancel.

### Phase 4 — ★ Bring Back
`download_file()`, `AttachmentRestorer`, `BringBackController` (+ preflight), Bring Back page (disk warning, delete-cloud toggle, progress), `BulkRestoreProcessor`, `wp smowp restore`.
**Done when:** full round-trip proven — offload with Full Cloud Migration → Bring Back → files identical (checksums), URLs local again.

### Phase 5 (v0.9.0) — Polish & Release Candidate (end of planned work — 1.0.0 requires owner permission)
Error surfacing UI, full i18n pass (`npm run i18n`), `readme.txt` + `.wordpress-org` assets, `uninstall.php`, compat testing (Elementor, EWWW/Imagify, WebP-uploads), edge cases (scaled images, image editor saves, thumbnail regeneration), finalize `CLAUDE.md`, CI + `npm run bundle` release zip.

### Phase 6+ — Future Providers (🔒 locked — starts only after the owner approves 1.0.0)
AmazonS3 → BackblazeB2 → DigitalOceanSpaces → Wasabi → MinIO/generic S3. Each: one class in `includes/Providers/`, one registry line, credential field defs (UI renders automatically), docs update.

---

## 12. What Comes From Where

| Concern | Borrowed from |
|---|---|
| Provider abstraction, retention policy (3 modes), observer-per-hook offload engine, bulk background processing, key collection (sizes/original/modern sources), wp-config credential constants | advanced-media-offloader |
| Autoloader, `Core\Plugin` singleton + `Migrator`, REST `BaseController` + controllers, React SPA via @wordpress/scripts, `src/` layout (api/hooks/pages/components/context/utils), ui component kit, i18n + bundle scripts, `CLAUDE.md` + `.project/` docs, `.distignore`/CI | smart-password-protect |
| ★ Bring Back (restore to server), R2-first single-provider focus, provider meta per attachment, credential fields as JSON-driven generic UI, **TypeScript admin app** | new in this plugin |
