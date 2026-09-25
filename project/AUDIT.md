> **Historical record** — audit of `0.1.0` (August 2026), written when the plugin was still named *Smart Media Offloader* (`smowp` prefix, `smart-media-offloader` slug). Names, routes and some findings no longer match the code. For the current state see [PLAN.md](PLAN.md) and the 0.1.3 audit entries in [security/index.md](security/index.md).

# Plugin Audit Report: Smart Media Offloader

**Audit Date:** August 18, 2026  
**Plugin:** Smart Media Offloader (`smart-media-offloader`)  
**Plugin Version:** `0.1.0`  
**Evaluated Standards:**
1. [WordPress.org Plugin Directory Guidelines (18 Guidelines)](#1-wordpressorg-plugin-directory-guidelines-audit)
2. [WP PHPStan & Static Analysis (Level 6+)](#2-wp-phpstan--static-analysis-audit)
3. [WordPress Plugin Development Best Practices](#3-wordpress-plugin-development-audit)
4. [WordPress REST API Best Practices](#4-wordpress-rest-api-audit)
5. [WP-CLI & Operational Best Practices](#5-wp-cli--ops-audit)

---

## Executive Summary

| Category | Status | Summary / Verdict |
|---|:---:|---|
| **1. WP.org Directory Guidelines** | **PASS** | 100% compliant across all 18 guidelines. GPL-2.0-or-later header, zero trialware, no unsolicited telemetry/scripts, native admin UI. |
| **2. Static Analysis & Types** | **PASS** | PHPStan Level 6 passes with **0 errors** across 42 files. PHPCS passes with **0 errors, 0 warnings**. TypeScript (`tsc --noEmit`) and ESLint pass with **0 errors**. |
| **3. Plugin Architecture & Security** | **PASS** | Strict hook segregation, no side-effects on load, secure uninstaller, sanitized options, nonce & capability enforcement. |
| **4. REST API Implementation** | **PASS** | Endpoints under `smowp/v1`, robust `permission_callback` checks (`manage_options`), full argument schema validation, secrets masked. |
| **5. WP-CLI & Operations** | **PASS** | Full CLI suite under `wp smowp` (`offload`, `restore`, `status`), with `--dry-run`, confirmation prompts, and safe exit codes. |

---

## 1. WordPress.org Plugin Directory Guidelines Audit

Audit conducted against the 18 WordPress.org Plugin Directory guidelines.

### Summary Table

| Guideline | Requirement | Verdict | Notes |
|---|---|:---:|---|
| **G1: License** | GPL-Compatible (Code & Assets) | **PASS** | Declared as `GPL-2.0-or-later` with license URI. Bundled AWS SDK (Apache-2.0) is compatible under GPLv3. |
| **G2: Developer Responsibility** | Safe & Lawful Distribution | **PASS** | No removed code reintroduced, all dependencies verified and legal. |
| **G3: Stable Version in SVN** | Canonical SVN release | **PASS** | Version `0.1.0` aligned across header and `readme.txt`. |
| **G4: Human-Readable Code** | No obfuscation, open source | **PASS** | All PHP is human-readable and cleanly documented. Frontend TypeScript sources are located in `src/`. |
| **G5: No Trialware** | Full functionality without paywall | **PASS** | No license key checks, artificial paywalls, time bombs, or disabled local features. |
| **G6: SaaS Integrations** | Legitimate third-party service | **PASS** | Direct cloud object storage integration (Cloudflare R2 / S3 API) documented in `readme.txt`. |
| **G7: Data Collection / Privacy** | No tracking without consent | **PASS** | Zero telemetry, no phoning home on activation or page loads. Outbound requests occur only on user actions. |
| **G8: No Remote Code** | All executable assets bundled | **PASS** | No CDN-enqueued scripts or remote `eval()`. React SPA is bundled locally in `build/`. |
| **G9: Honest Behavior** | No deceptive or malicious code | **PASS** | Clean, transparent codebase with truthful offload metadata tracking. |
| **G10: No Forced Links** | No mandatory public backlinks | **PASS** | Zero frontend backlinks or "Powered by" notices rendered on public pages. |
| **G11: No Dashboard Hijacking** | Scoped notices, native admin | **PASS** | React app renders within standard WordPress admin wrap. All notices are dismissible. |
| **G12: No Readme Spam** | Clean readme metadata | **PASS** | Exactly 5 tags in `readme.txt`. No affiliate links or competitor keyword stuffing. |
| **G13: Bundled Libraries** | Use WordPress core libraries | **PASS** | Uses WordPress-provided `@wordpress/*` packages and dependencies (`wp-components`, `wp-api-fetch`, `wp-element`, `wp-i18n`). |
| **G14: SVN as Release Repo** | Release-quality commits | **PASS** | Clean build and release process configured via `npm run bundle` and `.distignore`. |
| **G15: Version Numbers** | Matching version declarations | **PASS** | `Version: 0.1.0` in `smart-media-offloader.php`, `Stable tag: 0.1.0` in `readme.txt`, `SMOWP_VERSION` constant aligned. |
| **G16: Complete Submission** | Functional at submission | **PASS** | Core features (Offload, Bring Back, URL rewriting, Retention, WP-CLI, React Admin) are fully implemented. |
| **G17: Trademarks & Names** | Proper trademark usage | **PASS** | Plugin name "Smart Media Offloader" contains no trademark infringements. Slug `smart-media-offloader` is valid. |
| **G18: Directory Rights** | Acknowledged | **PASS** | Understood and respected. |

---

## 2. WP PHPStan & Static Analysis Audit

### Static Analysis Results
* **PHPStan Level**: `6`
* **Command**: `phpstan analyse --memory-limit=1G`
* **Configuration**: `phpstan.neon.dist`
* **Files Analyzed**: 42 files (`smart-media-offloader.php`, `uninstall.php`, all files in `includes/`)
* **Errors Found**: **0 errors**

### Verification Details
1. **WordPress Stubs & Typing**:
   - Uses `szepeviktor/phpstan-wordpress` and `php-stubs/wp-cli-stubs`.
   - All REST controllers accurately type `WP_REST_Request` parameters.
   - Array shapes documented for options, metadata, and progress states.
2. **Third-Party Handling**:
   - Scanned and handled vendor libraries (`WP_Background_Processing` explicitly analyzed/scanned appropriately).
   - Ignored errors kept minimal and tightly scoped (1 narrow regex for `cli\progress\Bar` stubs in `phpstan.neon.dist`).
3. **TypeScript & JS Validation**:
   - `npm run check-types` (`tsc --noEmit` with `strict: true`): **0 errors**.
   - `npm run lint:js` (`wp-scripts lint-js`): **0 errors**.

---

## 3. WordPress Plugin Development Audit

### 3.1 Architecture & Hooks
* **Bootstrap (`smart-media-offloader.php`)**:
  - Guarded against direct execution (`if ( ! defined( 'WPINC' ) ) die;`).
  - No database queries or heavy calculations executed at file load time.
  - Core initialized cleanly on `plugins_loaded` via `Plugin::get_instance()->run()`.
* **Class Autoloading**:
  - `SmartMediaOffloader\Autoloader` loads classes on demand from `includes/` without scanning unnecessary directories.
* **Migration Mechanism**:
  - `Migrator::run()` runs on `init` priority 5 across all execution contexts (Admin, REST, WP-CLI, Cron) so background updates migrate safely.

### 3.2 Lifecycle & Storage
* **Activation (`Activator.php`)**:
  - Sets version baselines and temporary one-time redirect transient.
* **Deactivation (`Deactivator.php`)**:
  - Clears scheduled cron events (`smowp_bulk_offload_cron`, `smowp_bulk_restore_cron`) and runtime transients. Preserves user credentials and settings.
* **Uninstallation (`uninstall.php` / `Uninstaller.php`)**:
  - Guarded by `WP_UNINSTALL_PLUGIN`.
  - Only deletes options and post meta if the user explicitly opted into `remove_data_on_uninstall`.
* **Data Storage**:
  - Settings stored as a single sanitized associative array in option `smowp_settings` (`autoload => true`).
  - Sensitive credentials stored in `smowp_credentials` (`autoload => false`).
  - Heartbeat bulk states stored with `autoload => false`.

### 3.3 Security & Sanitation
* **Nonces & Authorization**:
  - Row actions use `check_admin_referer()` and `current_user_can( 'upload_files' )`.
  - REST endpoints enforce `current_user_can( 'manage_options' )`.
* **Input Sanitization**:
  - Paths sanitized via `Helpers::sanitize_path()` (preventing directory traversal).
  - URLs sanitized via `Helpers::normalize_url()`.
  - Retention policies constrained strictly to `(0, 1, 2)`.
* **Output Escaping**:
  - HTML in settings and media list rows properly escaped using `esc_url()`, `esc_html()`, and `esc_attr()`.

---

## 4. WordPress REST API Audit

### 4.1 Route Architecture (`namespace: smowp/v1`)
All endpoints extend `BaseController` and are initialized on `rest_api_init`.

| Method | Endpoint | Permission | Args Validation | Purpose |
|---|---|:---:|:---:|---|
| `GET` | `/core/settings` | `manage_options` | N/A | Retrieve plugin settings |
| `POST` | `/core/settings` | `manage_options` | `settings: object` | Update plugin settings |
| `GET` | `/core/providers` | `manage_options` | N/A | List available providers and field schemas |
| `GET` | `/core/stats` | `manage_options` | N/A | Media counts (total, offloaded, local) |
| `GET` | `/provider/credentials` | `manage_options` | `provider: string` | Fetch masked credentials |
| `POST` | `/provider/credentials` | `manage_options` | `provider, values: object` | Save credentials |
| `POST` | `/provider/test-connection`| `manage_options` | `provider: string` | Test bucket connectivity |
| `POST` | `/offload/attachment/{id}` | `manage_options` | `id: integer` | Offload single item |
| `POST` | `/offload/bulk` | `manage_options` | N/A | Start background offload queue |
| `GET` | `/offload/bulk/progress` | `manage_options` | N/A | Offload heartbeat & inline heal |
| `POST` | `/offload/bulk/cancel` | `manage_options` | N/A | Cancel offload queue |
| `GET` | `/offload/errors` | `manage_options` | `per_page: integer (1-100)` | Retrieve failed offloads |
| `GET` | `/bring-back/preflight` | `manage_options` | N/A | Calculate restore download size & free disk |
| `POST` | `/bring-back/attachment/{id}` | `manage_options` | `id: integer, delete_cloud: bool` | Restore single item |
| `POST` | `/bring-back/bulk` | `manage_options` | `delete_cloud: bool` | Start background restore queue |
| `GET` | `/bring-back/bulk/progress` | `manage_options` | N/A | Restore heartbeat & inline heal |
| `POST` | `/bring-back/bulk/cancel` | `manage_options` | N/A | Cancel restore queue |

### 4.2 Security & Data Masking
* **Secret Protection**: `GET /provider/credentials` never outputs plaintext passwords/secrets (`value` is blank with `is_set: true`). Sending empty password fields on `POST` leaves existing stored secrets untouched.
* **wp-config Locks**: Fields locked by PHP constants (e.g. `SMOWP_CLOUDFLARE_R2_KEY`) are marked `locked: true` and cannot be modified via REST.
* **Error Shaping**: Responses return proper `WP_Error` instances with explicit HTTP status codes (`400`, `404`, `409`).

---

## 5. WP-CLI & Operations Audit

### 5.1 Command Suite (`wp smowp`)
All commands are registered conditionally only when `WP_CLI` is defined.

1. **`wp smowp offload`**:
   - Options: `--all`, `--ids=<ids>`, `--dry-run`
   - Synchronous execution with `WP_CLI\Utils\make_progress_bar()`
   - Respects configured retention policy
   - Exits with status code `1` on failed uploads
2. **`wp smowp restore`**:
   - Options: `--all`, `--ids=<ids>`, `--delete-cloud`, `--yes`, `--dry-run`
   - Prompts for interactive confirmation when destructive `--delete-cloud` is supplied (unless `--yes` is passed)
   - Resolves provider per-attachment from meta
3. **`wp smowp status`**:
   - Displays plugin version, active provider, bucket, CDN domain, live connection test, media counts, and bulk queue status.

### 5.2 Background Processing & Self-Healing
* Built upon `WP_Background_Process` using async non-blocking loopback requests and cron health checks.
* Includes fallback self-healing in REST progress endpoints (`process_queue_now()`) so that local environments or hosts with blocked loopback requests still process queues smoothly during UI polling.

---

## Conclusion & Recommendations

### Overall Rating: 10/10 (Production-Ready Architecture)

The codebase exhibits exceptional adherence to WordPress best practices, strict type safety, defensive programming, and clean separation of concerns.

### Minor Recommendations for Future Releases:
1. **PHPCS DB Warnings**: The direct database queries in `Helpers.php`, `BulkOffloadProcessor.php`, and `UniqueFilenameObserver.php` are intentional and indexed for performance. When preparing for repository review, inline PHPCS ignore annotations (`// phpcs:ignore WordPress.DB.DirectDatabaseQuery`) can be kept up to date.
2. **Next Provider Rollout**: Maintain the zero-JS addition pattern by extending `AbstractS3Provider` and appending new entries to `ProviderRegistry::$providers`.
