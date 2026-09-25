# Issues & Decisions Log

| Date | Item | Status |
|---|---|---|
| 2026-08-12 | AWS SDK bundled unscoped; PHP-Scoper prefixing (à la advanced-media-offloader's `WPFitter\` prefix) would avoid conflicts with other plugins bundling the SDK. Revisit before `1.0.0`. | Open |
| 2026-08-12 | R2 region fixed to `auto`; no ACLs sent to R2 (unsupported) — `omnioffloader_object_acl` defaults to empty. | Decided |
| 2026-09-24 | Offload / Bring Back limited to administrators (`manage_options` + per-item `edit_post` / `delete_post`). Other users keep core rights: upload and delete their own media (Mirror Delete removes the cloud copy). | Decided |
| 2026-09-24 | Credentials option stays **autoload off** — secrets out of the shared alloptions cache, at the cost of one small query per request. | Decided |
| 2026-09-24 | Release ships **build files only** — `.distignore` excludes `src/`, `package.json`, `tsconfig.json`, `composer.json`, `docs/` and `project/`. The plugin is already approved on WordPress.org. | Decided |
| 2026-09-24 | `composer.lock` not committed; CI resolves dependencies at release time. Commit it resolved for PHP 8.1 (`config.platform.php`) to pin the SDK — see future plan 3.1. | Open |
| 2026-09-24 | Bulk items processed twice (`processed 12 of 6`) — fixed with a MySQL `GET_LOCK()` worker mutex in `AbstractBulkProcessor`. | Fixed (0.1.3) |
| 2026-09-24 | Single-item offload doesn't check a running bulk job (only Bring Back vs retention is blocked). No data-loss path found; see future plan 3.5. | Open (low) |
| 2026-09-24 | Multisite: queues and CLI lock now per site; network activation / network uninstall loop still untested. | Open |
