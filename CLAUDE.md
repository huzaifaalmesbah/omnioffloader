# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Status

**Pre-release, version `0.1.0`.** The full design lives in `PLAN.md` — read it before making architectural decisions. The phase map in PLAN.md §11 (skeleton → offload → bulk → Bring Back → release candidate) describes development phases, not ship versions: phases 1–4 are complete and all ship together as `0.1.0`. The next release version is `0.9.0` (release candidate).

> ⚠️ **VERSION CEILING: `0.9.0`.** Never bump, tag, or release version `1.0.0` (or anything ≥ 1.0.0) without the owner's explicit permission in that conversation. All planned work ends at 0.9.0.

## What This Plugin Does

Offloads WordPress media library files to cloud object storage (v1: **Cloudflare R2 only**; more S3-compatible providers added one per release) and serves them from a CDN. Three retention policies control what stays on the server. The signature feature is **Bring Back**: restoring offloaded media from the cloud back to the server (per-item and bulk), safely reversing an offload.

## Build Commands

```bash
npm start            # Development build with watch mode (wp-scripts)
npm run build        # Production build
npm run check-types  # tsc --noEmit — strict TypeScript check
npm run lint:js      # ESLint (WordPress TS config)
npm run i18n         # Regenerate POT/PO/MO/JSON translation files
npm run bundle       # Build + distribution zip (excludes dev files per .distignore)
composer phpstan     # PHPStan level 6+ (szepeviktor/phpstan-wordpress)
composer phpcs       # WordPress Coding Standards
```

CI must pass: `build`, `check-types`, `lint:js`, `phpstan`, `phpcs`.

## Identity & Conventions

- Namespace: `SmartMediaOffloader\` → `includes/` (custom `includes/Autoloader.php`; Composer `vendor/` holds only the PHP-Scoper-prefixed AWS SDK).
- Prefix everything: options/meta/hooks `smowp_`, constants `SMOWP_`, REST `smowp/v1`, CLI `wp smowp`, script handle/localized object `smowp`.
- Text domain: `smart-media-offloader`. All user-facing strings use `__( '…', 'smart-media-offloader' )` in PHP and `__( '…', 'smart-media-offloader' )` from `@wordpress/i18n` in TS.
- License: GPL-2.0-or-later (the "or later" is required — the bundled AWS SDK is Apache-2.0, compatible only via GPLv3).
- PHP 8.1+, WP 6.6+. WordPress Coding Standards for PHP (doc headers on every file/class/method).

## Architecture

### Backend (`includes/`)

```
includes/
├── Autoloader.php      # SmartMediaOffloader\ → includes/
├── Core/               # Plugin (singleton, wires all on plugins_loaded), Activator,
│                       #   Deactivator, Uninstaller, Migrator (version-gated), Helpers
│                       #   (no Cron class — WP_Background_Process ships its own healthcheck)
├── Api/                # REST controllers extending BaseController (namespace smowp/v1)
├── Providers/          # ProviderInterface, AbstractS3Provider, ProviderRegistry, CloudflareR2
├── Services/           # AttachmentUploader, AttachmentRestorer (Bring Back), LocalCleaner,
│                       #   BulkOffloadProcessor, BulkRestoreProcessor (WP_Background_Process)
├── Offload/            # Offloader + Observers/ — one WordPress hook per observer class
├── Admin/              # Settings (menu + React app enqueue), AdminBar
├── Cli/                # CommandRegistrar, OffloadCommand, RestoreCommand, StatusCommand
├── Traits/             # OptionsHelper, AttachmentHelper
└── templates/          # admin-app.php (#smowp-admin-app mount point)
```

Key rules:
- **No side effects at file load.** Everything boots from `Core\Plugin::get_instance()->run()` on `plugins_loaded`. Activation/deactivation hooks registered at top level of the main file only.
- Offload engine (observers, offload/bring-back controllers, processors) loads **only when a provider is configured** (`OptionsHelper::is_configured()`).
- `Migrator::run()` on `init` priority 5 in every context (not just admin) so WP-CLI/cron updates migrate too.

### Adding a cloud provider (the point of the design)

1. Create `includes/Providers/{Name}.php` extending `AbstractS3Provider` — supply client config + `credential_fields()` definitions.
2. Add one line to `ProviderRegistry::$providers`.
That's it. `credential_fields()` is served as JSON to the React app, which renders the form generically — **zero JS changes per provider**.

### Frontend (`src/` — TypeScript React SPA)

```
src/
├── index.tsx           # Entry → renders into #smowp-admin-app
├── App.tsx             # Router via view= query param
├── types/              # ★ Single source of truth for all REST payload shapes
│   └── global.d.ts     # window.smowp declaration (in tsconfig "files")
├── api/                # Typed apiFetch wrappers (core, provider, offload, bringBack)
├── context/            # CoreContext: settings, provider, stats, global dirty/save state
├── hooks/              # useSettings, useProvider, useBulkOffload, useBringBack, useMediaStats
├── pages/              # Dashboard, Settings, Offload, BringBack
├── components/
│   ├── layout/         # Sidebar (logo + 2-line nav), Header (icon badge + page title),
│   │                   #   SaveBar (sticky bottom save bar), MainLayout, PageLayout
│   ├── ui/             # Restyled wrappers around @wordpress/components
│   └── …/              # settings/, offload/, bring-back/, dashboard/ feature components
└── utils/              # constants.ts holds RouteMeta[] (icon/title/subtitle per page —
                        #   Sidebar and Header render from this one source)
```

UI design (PLAN.md §6.1): full-page app, left sidebar with logo top + active-item accent highlight, content header showing current page icon/name/subtitle, **sticky bottom SaveBar** ("All changes saved" / "Save changes"), white rounded cards on light gray background, blue accent (`#135dbe` / `#0e4690`).

### TypeScript rules (Gutenberg-style)

- `tsc` is **type-check only** (`noEmit`); Babel via wp-scripts transpiles. `isolatedModules` must stay on.
- `strict: true` + `noImplicitReturns` + `noFallthroughCasesInSwitch`. No `any` — use `unknown` + narrowing; `@ts-expect-error` with reason over `@ts-ignore`.
- Every REST request/response shape lives in `src/types/` and must match the PHP controller's `args` schema / response. When you change one side, change the other in the same commit.
- `@wordpress/*` packages ship their own types; don't add `@types/wordpress__*` unless a package lacks them.

## REST API (`smowp/v1`)

All mutations via REST; `@wordpress/api-fetch` sends the nonce. Every route: explicit `permission_callback` (`manage_options`), `args` with type/validate/sanitize callbacks, `WP_REST_Server` method constants, `rest_ensure_response()` / `WP_Error` with status + stable `smowp_*` error codes.

- `GET/POST /core/settings` · `GET /core/providers` · `GET /core/stats`
- `GET/POST /provider/credentials` · `POST /provider/test-connection`
- `POST /offload/attachment/{id}` · `POST /offload/bulk` · `GET /offload/bulk/progress` · `POST /offload/bulk/cancel` · `GET /offload/errors`
- `GET /bring-back/preflight` · `POST /bring-back/attachment/{id}` · `POST /bring-back/bulk` · `GET /bring-back/bulk/progress` · `POST /bring-back/bulk/cancel`

Credentials: `GET` returns secrets masked (`is_set: true`); `POST` ignores masked placeholders so saves never wipe secrets. wp-config constants (`SMOWP_CLOUDFLARE_R2_KEY` etc.) lock fields (`locked: true`).

## Data Reference

**Options** (`autoload => false` except settings/version):
- `smowp_settings` — provider key, retention_policy (0/1/2), mirror_delete, path_prefix(+active), object_versioning, bring-back defaults, remove_data_on_uninstall
- `smowp_credentials` — per-provider credential sub-arrays
- `smowp_version` — for Migrator
- `smowp_bulk_offload_state` / `smowp_bulk_restore_state` — progress heartbeats

**Attachment meta:** `smowp_offloaded`, `smowp_offloaded_at`, `smowp_provider` (provider at offload time — Bring Back resolves this, not the active provider), `smowp_bucket`, `smowp_path`, `smowp_object_version`, `smowp_retention`, `smowp_error_log`.

**Retention policy values:** `0` retain local (default) · `1` smart cleanup (delete thumbs, keep original) · `2` full migration (delete all local).

**Cron:** watchdog event only while a bulk job runs; unschedule when idle.

**Constants:** `SMOWP_VERSION`, `SMOWP_DIR`, `SMOWP_URL`, `SMOWP_ASSETS_URL`, `SMOWP_BASENAME`, `SMOWP_FILE`.

## Public Hooks

Filters: `smowp_cloud_providers`, `smowp_object_acl`, `smowp_attachment_key`, `smowp_attachment_delete_keys`, `smowp_should_offload_attachment`.
Actions: `smowp_before_offload`, `smowp_after_offload`, `smowp_before_restore`, `smowp_after_restore`.

## Safety Invariants (never violate)

1. **Never delete a local file before the cloud upload is verified** (`object_exists` on the main key).
2. **Never delete a cloud object before the local restore is verified** (all expected files present; delete-cloud is opt-in, default OFF).
3. Restores write atomically: temp file + `rename()`; destination confined to `wp_upload_dir()` basedir (realpath check).
4. On any failure, an attachment must always have at least one valid copy and truthful meta; errors go to `smowp_error_log`.
5. Guard `dirname()` returning `'.'` when building object keys (produces broken `./file.jpg` keys — known bug class in the reference plugin).
6. Never log or echo credentials; REST never returns raw secrets.
7. CLI destructive paths (`--delete-cloud`) require `WP_CLI::confirm()` or `--yes`; every write command supports `--dry-run`.

## Performance Rules

- Frontend (non-admin) footprint is URL-rewrite filters only — no queries, writes, or HTTP.
- No remote calls outside explicit user actions (Test Connection, offload/restore jobs).
- Stats via one indexed postmeta count query, short-transient cached.
- Bulk state options are `autoload => false`.

## Reference Plugins (read-only, for patterns)

- `../advanced-media-offloader/` — offload engine concepts (observers, retention, key collection, bulk processing).
- `../smart-password-protect/` — skeleton patterns (Autoloader, Core\Plugin, BaseController, React admin app, i18n scripts). Its `.claude/skills/` (wp-plugin-development, wp-rest-api, wp-block-development, wp-phpstan, wp-performance, wp-plugin-directory-guidelines, wp-wpcli-and-ops) apply to WordPress work here too.
- `../gutenberg/` — TypeScript conventions source (`tsconfig.base.json`, `packages/dataviews` as TS-first example).
