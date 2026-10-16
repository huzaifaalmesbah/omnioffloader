# Future plan (0.1.4 → 0.9.0)

What to build next, what to speed up, and what to clean up. Written after the
0.1.3 audits (security, performance, REST, WP-CLI, directory guidelines,
accessibility), so the backlog below is grounded in the current code.

> ⚠️ All planned work ends at **0.9.0**. **1.0.0 needs the owner's explicit
> permission** — see [CLAUDE.md](../../CLAUDE.md).

Versioning: releases continue as patch versions — **0.1.4, 0.1.5, …** — one
theme per release, until the **0.9.0** release candidate. Items can move
between releases; the order below is the intended sequence.

Priorities: **P1** = do next · **P2** = important · **P3** = nice to have.
Effort: **S** ≤ 1 day · **M** 2–4 days · **L** 1–2 weeks.

---

## Done in 0.1.6

Carried over from the 0.1.4 plan: integrity checker as `wp omnioffloader verify [--repair]` (1.1 — the admin Verify screen and orphan-object scan are still open), failed items list/filter/retry with `wp omnioffloader errors` (1.3), pinned `composer.lock` (3.1), single-item actions refuse during any job (3.5), `media-status.js` strings (3.8), `status --format=json` (3.9), CSS prefix rename (3.7). Also opt-in Appsero usage insights.

Still open from the original 0.1.5 plan: O(1) preflights + storage dashboard (2.2, 1.2), batch deletes (2.5), lighter Media Library polling (2.4), shared bulk hook/panel (3.2). The 0.1.6 "More providers" theme (1.4) and folding `/bulk/action` into the bulk routes (3.4) move to a later release.

## Where 0.1.3 stands

- Six providers on one `AbstractS3Provider` base: Amazon S3, Cloudflare R2,
  Backblaze B2, DigitalOcean Spaces, Wasabi, generic S3-compatible.
- Offload (live + background + bulk), three retention policies, Bring Back,
  and — new in 0.1.3 — applying a changed retention policy to existing media.
- Hardened bulk queues (DB mutex, per-item claim, circuit breaker, self-heal),
  WP-CLI for every job, admin-only cloud actions, PHPStan level 8.

---

## Version map

| Version | Theme | Headline items |
|---|---|---|
| **0.1.4** | Trust & visibility | Integrity checker, failed-item retry, async-queue mutex, pinned dependencies |
| **0.1.5** | Faster at scale | Concurrent transfers, O(1) preflights + storage dashboard, lighter polling |
| **0.1.6** | More providers | Google Cloud Storage, Bunny Storage, Akamai/Linode, Hetzner, Scaleway, MinIO preset |
| **0.1.7** | Images & CDN | Optimizer integration, CDN cache purge, separate CDN vs bucket URL |
| **0.1.8** | Private media | Private buckets + signed URLs, WooCommerce downloads |
| **0.1.9** | Moving data | Provider-to-provider migration, import an existing bucket, content URL rewrite tool |
| **0.1.10** | Automation & AI | Abilities API, scheduled jobs, notifications, multisite network admin |
| **0.9.0** | Release candidate | Test suite + CI, compatibility matrix, polish, docs, screenshots |

---

## 1. New features

### 1.1 Integrity checker — "Verify" (P1 · M · 0.1.4)
Compare what the database says with what is really in the bucket and on disk.
- Find: offloaded attachments missing objects in the bucket; attachments whose
  local files are missing while the policy says they should be there; orphan
  objects in the bucket with no attachment.
- Repair actions: re-upload from local, download from cloud, delete orphans
  (opt-in, confirmed), reset stuck `processing` / `queued` statuses.
- Runs as a fourth bulk queue on `AbstractBulkProcessor`; uses
  `list_object_sizes()` per prefix so it costs one request per attachment.
- `wp omnioffloader verify [--repair] [--format=json]`.

### 1.2 Storage dashboard (P1 · S–M · 0.1.5)
- Bytes in the cloud, bytes still on the server, bytes freed so far.
- Breakdown by retention policy and by provider (media can live on more than
  one provider after a switch).
- Optional cost estimate per provider (price per GB-month as a filterable
  table — never fetched remotely).
- Kept cheap: counters updated when items change, not a library scan
  (see 2.2).

### 1.3 Failed items: list, filter, retry (P1 · S · 0.1.4)
- Media Library filter "Offload failed" and a "Retry failed" bulk action.
- Tools card listing failures with the last error, paged
  (`/offload/errors` already supports paging).
- `wp omnioffloader errors [--retry] [--format=json]`.

### 1.4 More providers (P2 · S each · 0.1.6)
Mostly one class each thanks to `AbstractS3Provider`:
- **S3-compatible presets:** Google Cloud Storage (interoperability mode),
  Bunny.net Storage (S3 API), Akamai/Linode Object Storage, Hetzner Object
  Storage, Scaleway, Vultr, IDrive e2, Storj, MinIO (self-hosted preset).
- **Native, non-S3:** Azure Blob Storage — needs a direct `ProviderInterface`
  implementation (P3 · L).
- Region pickers and endpoint validation in `credential_fields()`.

### 1.5 Image optimizer integration (P2 · M · 0.1.7)
Smush, ShortPixel, EWWW, Imagify, Optimole and core's WebP/AVIF generation
change files *after* the offload, so the cloud copy goes stale.
- Hook their "optimized" actions (and `wp_update_attachment_metadata`) to
  re-upload changed files — reuse `RetentionApplier`'s size-compare +
  re-upload logic.
- Offload newly generated modern-format sources (`sources` in metadata).
- When the policy removes local files, keep them until the optimizer is done
  (short grace period or optimizer-complete hook).

### 1.6 CDN features (P2 · M · 0.1.7)
- Separate **bucket URL** and **CDN URL** (e.g. R2 bucket behind a custom
  Cloudflare domain, or BunnyCDN pull zone in front of any bucket).
- Cache purge on re-upload / delete: Cloudflare, BunnyCDN, CloudFront
  invalidations (explicit user action or on change, never on page views).
- Optional cache-busting query string instead of object versioning.

### 1.7 Private media & signed URLs (P2 · L · 0.1.8)
- Mark attachments (or MIME types / folders) as private: uploaded without
  public access, served through short-lived presigned URLs.
- WooCommerce downloadable products and Easy Digital Downloads: protected
  files offloaded but only reachable through the store's download handler.
- Per-request signing is cached per page render; never an API call per URL.

### 1.8 Provider migration (P2 · L · 0.1.9)
Move media from provider A to provider B without downloading everything to
the server.
- Copy object-by-object (download stream → upload), update
  `omnioffloader_provider` / `_bucket` / `_path` per attachment only after
  the copy is verified, then optionally delete from A.
- Runs as a bulk queue with the same safety invariants as Bring Back.
- `wp omnioffloader migrate --to=<provider> [--delete-source] [--dry-run]`.

### 1.9 Import an existing bucket (P3 · M · 0.1.9)
For sites that uploaded media to a bucket with another tool: match objects to
attachments by path and write offload meta without re-uploading.

### 1.10 Content URL rewrite tool (P3 · M · 0.1.9)
Optional one-time search-replace of local upload URLs in post content with
cloud URLs (and the reverse on Bring Back), so pages no longer depend on the
runtime rewrite. Dry run, per-post undo log, and never on by default.

### 1.11 Abilities API (P2 · M · 0.1.10)
WordPress 7.1 ships the Abilities API. Register the 14 abilities proposed in
[`../abilities-audit.md`](../abilities-audit.md): 10 read-only
(`get-media-stats` first), one settings write, and three destructive
(offload, Bring Back, apply retention) that require `manage_options`,
per-item caps, `dry_run`, and the one-job-at-a-time check. Lets AI agents
and MCP clients report on and operate the plugin safely.

### 1.12 Scheduling & notifications (P3 · M · 0.1.10)
- Scheduled jobs: nightly "offload anything missed", scheduled retention.
- Email / webhook when a bulk run fails or the circuit breaker trips.
- Weekly summary (optional).

### 1.13 Multisite network admin (P3 · L · 0.1.10)
Queues are already per site. Next: network-level provider credentials with
per-site overrides, network activation, and a network uninstall loop.

### 1.14 Settings import/export (P3 · S)
Export settings (never secrets) as JSON; import on another site. Useful for
agencies and staging → production.

---

## 2. Performance

| # | Item | Why | P · Effort · Release |
|---|---|---|---|
| 2.1 | **Concurrent transfers per attachment** — upload/download an attachment's size files in parallel with the SDK's `CommandPool`/promises | A photo has 10–30 files; sequential transfers dominate bulk time. Expect 3–5× faster bulk offload/Bring Back | P1 · M · 0.1.5 |
| 2.2 | **O(1) preflights** — keep running counters (offloaded, bytes, per-policy counts) updated on offload/restore/retention instead of scanning every attachment's metadata | Bring Back / retention preflights read every offloaded item's metadata; at 100k items that is seconds per call | P1 · M · 0.1.5 |
| 2.3 | **Mutex for the async upload queue** — reuse `acquire_worker_mutex()` in `AsyncUploadProcessor` | Two workers can still upload the same new file twice (idempotent but wasteful) | P1 · S · 0.1.4 |
| 2.4 | **Lighter Media Library polling** — use the Heartbeat API or conditional responses (ETag/304) for `/offload/attachment-status` | Every open Media tab polls every ~2 s during a run | P2 · M · 0.1.5 |
| 2.5 | **Batch deletes for retention cleanup and mirror delete** across attachments (`deleteObjects`, 1,000 keys) | One request per attachment today | P2 · S · 0.1.5 |
| 2.6 | **Front-end: cache the cloud base URL per attachment** in the object cache when a persistent cache exists | Removes repeated path building on image-heavy pages | P3 · S · 0.1.7 |
| 2.7 | **Server-Timing headers** (debug only) for rewrite filters and REST routes | Makes regressions measurable without Query Monitor | P3 · S · 0.1.5 |
| 2.8 | **Load test**: 50k-attachment fixture + scripted bulk offload/restore/retention with query and memory budgets | Guards the performance rules in CLAUDE.md | P2 · M · 0.9.0 |

---

## 3. Enhancements & tech debt

From the 0.1.3 audits, left for later on purpose:

| # | Item | P · Effort · Release |
|---|---|---|
| 3.1 | **Pin dependencies**: commit `composer.lock` resolved for PHP 8.1 (`config.platform.php`) so releases don't pull a new AWS SDK each time | P1 · S · 0.1.4 |
| 3.2 | **One bulk hook + panel**: merge `useBulkOffload` / `useBringBack` / `useRetentionSync` into `useBulkQueue()` and a shared `<BulkRunPanel>` | P2 · M · 0.1.5 |
| 3.3 | **REST response schemas** for all routes (also the future abilities' output schemas) | P2 · M · 0.1.10 |
| 3.4 | Media Library routes: per-item response types in `src/types/`, fold `/bulk/action` into `/offload/bulk` and `/bring-back/bulk` with an `ids` arg | P3 · S · 0.1.6 |
| 3.5 | Single-item offload / restore: check `get_active_bulk_job()` fully (today only restore-vs-retention is blocked) | P3 · S · 0.1.4 |
| 3.6 | PHPStan level 9: typed accessors for meta and options (≈390 `mixed` casts) | P3 · L · 0.9.0 |
| 3.7 | Rename the CSS prefix `smowp-` → `omnioffloader-` (prefix rule) | P3 · M · 0.1.7 |
| 3.8 | `media-status.js`: move hardcoded English fallbacks to the localized strings; "…" instead of "..." | P3 · S · 0.1.4 |
| 3.9 | `wp omnioffloader status --format=json` for monitoring | P3 · S · 0.1.4 |
| 3.10 | Unify local bundle (`scripts/bundle.js`) and CI deploy on `.distignore` as the single file list | P3 · S · 0.1.4 |

---

## 4. Quality & release readiness (0.9.0)

- **Tests in CI:** run PHPStan, PHPCS, `check-types`, ESLint and the
  `tests/integration-*.php` suites on every push (wp-env or a MySQL service
  container). Add PHPUnit for pure units (key building, retention roles,
  arg validation).
- **End-to-end:** Playwright for the admin app — settings save, bulk runs
  with a mock provider, keyboard navigation of the card radios.
- **Compatibility matrix:** PHP 8.1–8.4 × WP 6.6–7.x; popular cache and
  optimizer plugins; multisite.
- **Directory listing:** 3–5 screenshots (Dashboard, Settings, Offload,
  Bring Back, Tools), shorter readme changelog with older entries in
  `changelog.txt`.
- **Docs:** provider setup guides for every preset, troubleshooting
  (loopback-blocked hosts, `DISABLE_WP_CRON`, object caches), and a hooks
  reference for developers.
- **Live-provider pass:** Bring Back and retention against real R2, S3 and B2
  buckets (so far verified with the mock provider plus R2 offload).

---

## 0.1.4 scope

The next release, "Trust & visibility":

| Item | Section | Effort |
|---|---|---|
| Integrity checker — `Verify` screen + `wp omnioffloader verify` | 1.1 | M |
| Failed items: filter, list, retry (admin + `wp omnioffloader errors`) | 1.3 | S |
| Worker mutex for the async upload queue | 2.3 | S |
| Pin dependencies: `composer.lock` resolved for PHP 8.1 | 3.1 | S |
| Single-item offload / restore respect any running bulk job | 3.5 | S |
| `media-status.js` strings moved to localized i18n | 3.8 | S |
| `wp omnioffloader status --format=json` | 3.9 | S |
| One file list for local bundle and CI deploy | 3.10 | S |

Then **0.1.5** ("Faster at scale"): concurrent transfers (2.1), O(1)
preflights (2.2) and the storage dashboard built on them (1.2), lighter
Media Library polling (2.4), batch deletes (2.5), shared bulk hook/panel (3.2).
