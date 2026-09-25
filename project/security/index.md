# Security Notes

The full model is in [PLAN.md §10](../PLAN.md) and CLAUDE.md "Safety Invariants".

## Model

- Mutations via REST (api-fetch nonces) or nonced admin-post handlers.
- Settings, credentials, bulk jobs, retention: `manage_options`.
- Offload / Bring Back of any item: `manage_options` + `edit_post`; deleting the cloud copy also needs `delete_post`. The read-only Media Library status route is `upload_files`, filtered per item, bucket name admin-only.
- Secrets never returned raw, never logged, not autoloaded; wp-config constants lock fields.
- Settings whitelisted and schema-validated (`rest_validate_request_arg` on every arg).
- Paths: `..` rejected in collected files and restore targets; restores realpath-confined; deletes via `wp_delete_file_from_directory()`.
- `omnioffloader_*` attachment meta is protected (not editable as custom fields).
- Never delete local before a verified cloud copy; never delete cloud before a verified local restore.
- Uninstall cleanup is opt-in and keeps offload records while cloud-only media exists.

## Audit log

| Date | Event |
|---|---|
| 2026-08-12 | v0.1.0 skeleton written with the invariants baked into BaseController / ProviderController / OptionsHelper. |
| 2026-08-18 | Full audit of v0.1.0 — see [AUDIT.md](../AUDIT.md). |
| 2026-09-24 | 0.1.3 security audit: Authors could offload/restore any media and delete cloud copies via `delete_cloud` → cloud actions made admin-only with per-item caps; path confinement for deletes/restores; no absolute paths in error logs; escaped Media Library tooltips; REST schema validation; uninstall keeps cloud-only records. |
| 2026-09-24 | 0.1.3 re-audit: status route scoped per item (no enumeration), admin-only poll self-heal, `..` dropped from collected files. No critical/high/medium issues remaining. |
| 2026-09-24 | 0.1.3 full audit (all installed WordPress skills): DB worker mutex for bulk queues, per-site queues on multisite, CLI lock renewal per item + Ctrl-C release, strict `--limit`, protected meta, credentials kept out of autoload. |
