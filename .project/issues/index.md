# Issues & Decisions Log

| Date | Item | Status |
|---|---|---|
| 2026-08-12 | AWS SDK bundled unscoped during 0.x development; PHP-Scoper prefixing (à la advanced-media-offloader's `WPFitter\` prefix) is a release task for 0.9.0 to avoid conflicts with other plugins bundling the SDK. | Open |
| 2026-08-12 | R2 region is fixed to `auto`; no ACLs sent to R2 (unsupported) — `smowp_object_acl` filter defaults to empty. | Decided |
