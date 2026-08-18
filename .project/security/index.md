# Security Notes

## Invariants (see CLAUDE.md "Safety Invariants")

- REST-only mutations; `manage_options` permission_callback on every route; api-fetch nonces.
- Secrets never returned raw by REST (masked, `is_set` flag); wp-config constants lock fields; never logged.
- Settings whitelisted/sanitized server-side; object keys sanitized; restore paths confined to uploads basedir.
- Never delete local before verified cloud upload; never delete cloud before verified local restore.
- Uninstall cleanup is opt-in.

## Audit log

| Date | Event |
|---|---|
| 2026-08-12 | v0.1.0 skeleton written with the above invariants baked into BaseController / ProviderController / OptionsHelper. |
