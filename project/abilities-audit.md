---
Last updated: 2026-09-24 12:00
---

# OmniOffloader Abilities — Phase 1 Audit

```yaml
plugin: omnioffloader
repo: huzaifaalmesbah/omnioffloader
branch_audited: main
audited_at: 2026-09-24
auditor: Claude (read-only audit subagent for Huzaifa Al Mesbah)
baseline_abilities: 0
plugin_family: media-offload
capability_gate:
  read: manage_options
  write: manage_options
  confirmed: true
  verified_at: "includes/Api/BaseController.php:188 permissions_check() -> current_user_can('manage_options'); per-item writes add AttachmentHelper::current_user_can_manage() (manage_options + edit_post) and current_user_can_delete_cloud() (+ delete_post). Only GET /offload/attachment-status uses the weaker upload_files gate (OffloadController.php:322)."

proposed_abilities:

  - name: omnioffloader/get-media-stats
    intent: "How many media items are offloaded vs still local, and is a provider configured?"
    backing:
      kind: rest_controller
      class: OmniOffloader\Api\CoreController
      file: includes/Api/CoreController.php
      method: GET
      route: /omnioffloader/v1/core/stats
      route_registration_line: 47
      callback: get_stats_response
      callback_line: 135
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "WP_REST_Response wrapping {total:int, offloaded:int, local:int, configured:bool, provider:string}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "Optional input `refresh` (boolean, default false) bypasses the 60s transient."
      - "Prefer calling Helpers::get_media_stats() + OptionsHelper::is_configured()/get_provider_key() directly rather than rest_do_request()."
    risks:
      - "Helpers::get_media_stats() writes the `omnioffloader_media_stats` transient (cache write). Mark with `// verify-ignore: readonly -- cache-only transient` so wp-abilities-verify does not flag it."
    use_case_fit: "Agent answers 'how much of my media library is in the cloud?' in one zero-arg call."
    side_effects:
      - "set_transient('omnioffloader_media_stats', …, 60s) on a cache miss"
    seed_data_needs: "at least one attachment; configured provider for a meaningful `configured: true`"
    reference_ability: true

  - name: omnioffloader/get-settings
    intent: "What provider, retention policy and offload options are currently in effect?"
    backing:
      kind: service
      class: OmniOffloader\Traits\OptionsHelper
      file: includes/Traits/OptionsHelper.php
      callback: get_settings
      callback_line: 48
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "array matching OptionsHelper::get_default_settings() (9 keys)"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "REST mirror: GET /omnioffloader/v1/core/settings (CoreController.php:32/67)."
      - "output_schema: retention_policy enum [0,1,2] with descriptions (retain local / smart cleanup / full migration)."
    risks: []
    use_case_fit: "Agent explains what will happen to local files on the next upload before the user changes anything."
    side_effects: []
    seed_data_needs: "no seed required"

  - name: omnioffloader/list-providers
    intent: "Which cloud storage providers does this plugin support and what credentials does each need?"
    backing:
      kind: rest_controller
      class: OmniOffloader\Api\CoreController
      file: includes/Api/CoreController.php
      method: GET
      route: /omnioffloader/v1/core/providers
      route_registration_line: 45
      callback: get_providers_response
      callback_line: 109
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "array of {key, name, fields: CredentialFieldDef[]}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "Field definitions only — no stored values. Safe to expose."
    risks: []
    use_case_fit: "Agent guides a site owner through choosing and configuring a provider."
    side_effects: []
    seed_data_needs: "no seed required"

  - name: omnioffloader/get-attachment-status
    intent: "Is this attachment (or these attachments) offloaded, local, queued, processing or failed?"
    backing:
      kind: helper
      class: OmniOffloader\Traits\AttachmentHelper
      file: includes/Traits/AttachmentHelper.php
      callback: get_attachment_status
      callback_line: 477
    permission:
      source: service
      callback: "AttachmentHelper::current_user_can_manage (per id)"
      resolves_to: "current_user_can('manage_options') && current_user_can('edit_post', $id)"
      confirmed: true
    return_type: "map id -> {status, is_offloaded, label, provider?, bucket?, offloaded_at?, date?, error?}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "input_schema: ids array of integer, minItems 1, maxItems 100."
      - "Do NOT delegate to GET /offload/attachment-status: that route drives the async/bulk queues (process_queue_now) and renders admin HTML snippets."
    risks:
      - "Silently skip ids the user may not edit (mirror the REST route) so the ability can't enumerate others' media."
    use_case_fit: "Agent answers 'is image #203 served from the CDN yet?' or checks a set after an offload run."
    side_effects: []
    seed_data_needs: "one offloaded and one local attachment"

  - name: omnioffloader/get-bulk-job-status
    intent: "Is a bulk offload, Bring Back or retention run in progress, and how far along is it?"
    backing:
      kind: service
      class: OmniOffloader\Services\AbstractBulkProcessor
      file: includes/Services/AbstractBulkProcessor.php
      callback: get_state
      callback_line: 138
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "BulkState (+ delete_cloud for restore; + policy, freed_bytes, repaired for retention)"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "input_schema: job enum ['offload','restore','retention'], optional; omitted = report whichever Plugin::get_active_bulk_job() returns (or idle)."
      - "Collapses three REST progress routes into one question."
    risks:
      - "Must call the static ::get_state() only. The REST /*/bulk/progress routes call process_queue_now() (network transfers, local deletes under cleanup retention) — delegating to them would make a readonly ability write."
    use_case_fit: "Agent reports progress of a long-running migration without the admin page open."
    side_effects: []
    seed_data_needs: "no seed required (idle state is a valid answer); a finished run gives richer output"

  - name: omnioffloader/get-bulk-preflight
    intent: "Before a Bring Back or retention run: how many items, how many bytes, and is there enough disk space?"
    backing:
      kind: helper
      class: OmniOffloader\Core\Helpers
      file: includes/Core/Helpers.php
      callback: "get_restore_preflight / get_retention_preflight"
      callback_line: 241
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "RestorePreflight | RetentionPreflight (oneOf, discriminated by operation)"
    effort: M
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "input_schema: operation enum ['restore','retention'] (required), refresh boolean."
      - "REST mirrors: GET /bring-back/preflight (BringBackController.php:34) and GET /retention/preflight (RetentionController.php:35)."
      - "If a single oneOf output_schema proves awkward for MCP clients, split into two abilities — the grouping escape hatch allows it for differing output shapes."
    risks:
      - "Writes 60s preflight transients (cache only) — add verify-ignore comment."
      - "Walks all offloaded attachment meta in chunks; keep refresh=false default."
    use_case_fit: "Agent checks disk space before recommending a Bring Back or a switch to Full Migration."
    side_effects:
      - "set_transient('omnioffloader_restore_preflight' | 'omnioffloader_retention_preflight', …, 60s) on cache miss"
    seed_data_needs: "at least one offloaded attachment"

  - name: omnioffloader/list-offload-errors
    intent: "Which media items failed to offload or restore, and why?"
    backing:
      kind: rest_controller
      class: OmniOffloader\Api\OffloadController
      file: includes/Api/OffloadController.php
      method: GET
      route: /omnioffloader/v1/offload/errors
      route_registration_line: 67
      callback: errors_response
      callback_line: 283
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "array of {id, title, errors: [{time, message}]}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "input_schema: per_page 1-100 (default 20). Add page + total once the REST route gains pagination."
    risks:
      - "No pagination/total today (no_found_rows) — agent cannot tell whether more errors exist."
    use_case_fit: "Agent diagnoses a failed bulk run and suggests retries."
    side_effects: []
    seed_data_needs: "one attachment with omnioffloader_error_log meta"

  - name: omnioffloader/test-connection
    intent: "Can the site reach the configured bucket with the saved credentials?"
    backing:
      kind: rest_controller
      class: OmniOffloader\Api\ProviderController
      file: includes/Api/ProviderController.php
      method: POST
      route: /omnioffloader/v1/provider/test-connection
      route_registration_line: 60
      callback: test_connection_response
      callback_line: 165
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "{connected: bool, message: string, checked_at: string}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "input_schema: provider enum from ProviderRegistry (optional; default active provider)."
      - "Backed by AbstractS3Provider::check_connection() -> headBucket only; no object writes."
      - "Use meta.show_in_rest with method POST/GET per Abilities REST rules — readonly abilities execute via GET."
    risks:
      - "Outbound network call to the live bucket; rate-limit or document it."
      - "Failure message comes from the SDK; must never include credentials (it does not today)."
    use_case_fit: "Agent verifies setup after the owner pastes credentials, before any offload."
    side_effects:
      - "remote HeadBucket request to the provider"
      - "error_log() line on failure"
    seed_data_needs: "saved credentials for a provider (a MockProvider-backed test can stub it)"

  - name: omnioffloader/offload-media
    intent: "Upload these attachments (or all local media, up to a limit) to the cloud bucket."
    backing:
      kind: service
      class: OmniOffloader\Services\BulkOffloadProcessor
      file: includes/Services/BulkOffloadProcessor.php
      callback: start
      callback_line: 66
    permission:
      source: service
      callback: "BaseController::permissions_check + AttachmentHelper::filter_manageable_ids"
      resolves_to: "current_user_can('manage_options') && current_user_can('edit_post', $id) per id"
      confirmed: true
    return_type: "{queued:int, state: BulkState}"
    effort: M
    annotations: { readonly: false, destructive: true, idempotent: false }
    notes:
      - "input_schema: ids array<int> (optional, maxItems 1000; omitted = all local media), limit int >=1 (optional), dry_run boolean (default false) that returns the would-be queue size without starting a run."
      - "Merges REST POST /offload/bulk (limit) and POST /bulk/action action=offload (ids). Single-item synchronous path (POST /offload/attachment/{id}) is a UI convenience; the ability should always queue."
      - "Must call Plugin::bulk_job_conflict('offload') first and return its 409 WP_Error (omnioffloader_bulk_running)."
      - "Must return omnioffloader_not_configured when Plugin::get_bulk_offload() is null."
    risks:
      - "destructive because retention_policy 1/2 deletes local files after verified upload; description must state the active policy's consequence."
      - "Must not start while a WP-CLI offload/restore holds the process lock (Safety Invariant 8) — start() already respects the lock; verify."
      - "Large runs incur provider egress/storage cost."
    use_case_fit: "Owner asks an agent to 'move my media library to R2' or offload a specific set of images."
    side_effects:
      - "creates omnioffloader_bulk_offload_batch_* options, updates omnioffloader_bulk_offload_state, schedules watchdog cron, dispatches loopback"
      - "per item: omnioffloader_before_offload / after_offload actions, attachment meta writes, cloud PutObject, possible local file deletion (policy 1/2)"
    seed_data_needs: "configured provider (MockProvider in tests) and at least one local attachment"

  - name: omnioffloader/bring-back-media
    intent: "Restore offloaded attachments from the cloud to the server, optionally deleting the cloud copies afterwards."
    backing:
      kind: service
      class: OmniOffloader\Services\BulkRestoreProcessor
      file: includes/Services/BulkRestoreProcessor.php
      callback: start
      callback_line: 69
    permission:
      source: service
      callback: "permissions_check + filter_manageable_ids + current_user_can_delete_cloud (when delete_cloud)"
      resolves_to: "manage_options && edit_post per id; + delete_post per id when delete_cloud=true"
      confirmed: true
    return_type: "{queued:int, state: RestoreBulkState}"
    effort: M
    annotations: { readonly: false, destructive: true, idempotent: false }
    notes:
      - "input_schema: ids array<int> optional, limit int optional, delete_cloud boolean default false."
      - "Merges POST /bring-back/bulk and POST /bulk/action action=restore."
      - "Call Helpers::get_restore_preflight() first and refuse when disk_ok is false unless the caller passes an explicit override."
    risks:
      - "delete_cloud=true is irreversible for object_versioning=false buckets — keep default false and require it be explicit (Safety Invariant 2)."
      - "Refuse during a retention run (BringBackController.php:160 pattern) and on any bulk_job_conflict('restore')."
      - "Appending ids to a running restore inherits its delete_cloud choice (OffloadController.php:394-406) — replicate the delete_post filter."
    use_case_fit: "Owner leaving a provider (or uninstalling) asks an agent to bring all media back safely."
    side_effects:
      - "queue options/state/cron as for offload"
      - "per item: omnioffloader_before_restore / after_restore actions, local file writes (temp + rename), meta cleanup, optional cloud DeleteObject"
    seed_data_needs: "configured provider and at least one offloaded attachment (MockProvider directory in tests)"

  - name: omnioffloader/apply-retention-policy
    intent: "Apply the saved retention policy to media that was offloaded under a different policy."
    backing:
      kind: service
      class: OmniOffloader\Services\BulkRetentionProcessor
      file: includes/Services/BulkRetentionProcessor.php
      callback: start
      callback_line: 77
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "{queued:int, state: RetentionBulkState}"
    effort: M
    annotations: { readonly: false, destructive: true, idempotent: false }
    notes:
      - "input_schema: limit int optional only. Policy is read from saved settings, never from input (RetentionController design)."
      - "Pair with get-bulk-preflight operation=retention so the agent can state to_clean / freed_bytes before invoking."
    risks:
      - "Deletes local files for policy 1/2 (after verifying cloud copies); may upload missing objects ('repaired')."
      - "Must honour bulk_job_conflict('retention')."
    use_case_fit: "After switching to Smart Cleanup, the owner asks the agent to reclaim disk space on existing media."
    side_effects:
      - "queue options/state/cron; local file deletions or downloads; meta updates; possible cloud PutObject"
    seed_data_needs: "offloaded attachments whose omnioffloader_retention differs from the saved policy"

  - name: omnioffloader/cancel-bulk-job
    intent: "Stop the running bulk offload, Bring Back or retention run."
    backing:
      kind: service
      class: OmniOffloader\Services\AbstractBulkProcessor
      file: includes/Services/AbstractBulkProcessor.php
      callback: cancel_now
      callback_line: 717
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "BulkState of the cancelled job"
    effort: S
    annotations: { readonly: false, destructive: false, idempotent: true }
    notes:
      - "input_schema: job enum ['offload','restore','retention'] (required)."
      - "Collapses the three POST */bulk/cancel routes."
    risks:
      - "An item already in flight completes; state says cancelled. Document that cancel is not rollback."
    use_case_fit: "Owner changes their mind mid-migration or a run is hammering the server."
    side_effects:
      - "clears batch rows, lock and watchdog cron; sets state status=cancelled"
    seed_data_needs: "a running job for a meaningful test; idle is a no-op"

  - name: omnioffloader/update-settings
    intent: "Change the active provider, retention policy or offload options."
    backing:
      kind: rest_controller
      class: OmniOffloader\Api\CoreController
      file: includes/Api/CoreController.php
      method: POST
      route: /omnioffloader/v1/core/settings
      route_registration_line: 34
      callback: update_settings_response
      callback_line: 77
    permission:
      source: rest_controller
      callback: permissions_check
      resolves_to: "current_user_can('manage_options')"
      confirmed: true
    return_type: "saved Settings object"
    effort: M
    annotations: { readonly: false, destructive: false, idempotent: true }
    notes:
      - "input_schema must be explicit (the REST arg is an untyped object): provider enum, retention_policy enum [0,1,2], booleans, path_prefix string; additionalProperties false; partial updates merge over current."
      - "Consider excluding remove_data_on_uninstall from the ability input."
    risks:
      - "Setting retention_policy 1/2 or mirror_delete=true makes future uploads/deletes remove files — no data loss at save time, but description must warn."
      - "Changing provider mid-run could strand a queue; refuse while get_active_bulk_job() is non-null."
    use_case_fit: "Owner asks the agent to 'switch to smart cleanup' or 'turn on a path prefix'."
    side_effects:
      - "update_option('omnioffloader_settings')"
    seed_data_needs: "no seed required"

  - name: omnioffloader/get-overview
    intent: "One call: configured provider, stats, active job + progress, and error count."
    backing: null
    permission: null
    return_type: "{configured, provider, stats, active_job, job_state, error_count}"
    effort: S
    annotations: { readonly: true, destructive: false, idempotent: true }
    notes:
      - "Compose from get_media_stats, get_active_bulk_job + get_state, and an errors COUNT query."
    risks: []
    use_case_fit: "'How is my offload going?' — highest-frequency owner question, zero input."
    side_effects: []
    seed_data_needs: null

excluded_from_mvp:
  - name: omnioffloader/update-provider-credentials
    reason: "Secrets would transit agent transcripts/MCP logs (Safety Invariant 6); keep credential entry in the admin UI or wp-config constants."
  - name: omnioffloader/get-provider-credentials
    reason: "Returns access-key IDs, endpoints and bucket names in clear; low agent value versus exposure — test-connection answers the useful question."
  - name: omnioffloader/offload-attachment-now
    reason: "Synchronous single-item upload (POST /offload/attachment/{id}) holds the request for the whole transfer and returns admin HTML; offload-media with ids=[id] covers it via the queue."
  - name: omnioffloader/process-queue-now
    reason: "Self-heal plumbing inside the progress/attachment-status polls — no human invocation point (fails the use-case-contract test)."

surfaced_gaps:
  - name: omnioffloader/get-overview
    one_line_rationale: "No single endpoint answers 'what is the state of my offload?' — the React Dashboard makes 3+ calls; a composite zero-arg read would be the highest-leverage ability."
  - name: omnioffloader/list-offload-errors (paginated)
    one_line_rationale: "GET /offload/errors has no page/total; adding found_rows + X-WP-Total lets the ability report completeness."
```

## Controller Inventory

| Class | File | REST Base | Routes |
|---|---|---|---|
| OmniOffloader\Api\BaseController (abstract) | includes/Api/BaseController.php | omnioffloader/v1 | — (permissions_check L188, media_permissions_check L207, register_get_route L227, register_post_route L248, anti-cache filters) |
| OmniOffloader\Api\CoreController | includes/Api/CoreController.php | omnioffloader/v1/core | GET/POST /core/settings, GET /core/providers, GET /core/stats |
| OmniOffloader\Api\ProviderController | includes/Api/ProviderController.php | omnioffloader/v1/provider | GET/POST /provider/credentials, POST /provider/test-connection |
| OmniOffloader\Api\OffloadController | includes/Api/OffloadController.php | omnioffloader/v1/offload (+ /bulk/action) | POST /offload/attachment/{id}, POST /offload/bulk, GET /offload/bulk/progress, POST /offload/bulk/cancel, GET /offload/errors, POST /bulk/action, GET /offload/attachment-status (upload_files gate) |
| OmniOffloader\Api\BringBackController | includes/Api/BringBackController.php | omnioffloader/v1/bring-back | GET /bring-back/preflight, POST /bring-back/attachment/{id}, POST /bring-back/bulk, GET /bring-back/bulk/progress, POST /bring-back/bulk/cancel |
| OmniOffloader\Api\RetentionController | includes/Api/RetentionController.php | omnioffloader/v1/retention | GET /retention/preflight, POST /retention/bulk, GET /retention/bulk/progress, POST /retention/bulk/cancel |

22 routes total (21 plus the namespace index), confirmed at runtime via `rest_get_server()->get_routes()` on WP 7.1.2.

## Notes and Surprises

### Environment
WordPress 7.1.2 locally; `wp_register_ability` exists; 18 abilities registered site-wide (core/*, ai/*), **zero** under `omnioffloader/`. wp-abilities-verify therefore returns "no abilities registered" — only the audit-doc validation step applies.

### Capability gate is uniform except one route
All routes use `BaseController::permissions_check` (`manage_options`) and per-item routes layer `edit_post` / `delete_post` checks through `AttachmentHelper`. The exception is `GET /offload/attachment-status` (`upload_files`), a Media Library poll. No route uses `__return_true`.

### GET routes that write
`/offload/bulk/progress`, `/bring-back/bulk/progress`, `/retention/bulk/progress` and `/offload/attachment-status` call `process_queue_now()` (the loopback-blocked self-heal). This is deliberate (CLAUDE.md), but it means **no readonly ability may delegate to these routes**. Readonly abilities must call the static `get_state()` / `get_attachment_status()` helpers directly. This is the main reason most backings are `service`/`helper`, not `rest_controller`.

### Transient caches in reads
Stats and both preflights write 60s transients. These are caches, not domain state; annotate readonly and add `// verify-ignore: readonly -- cache transient` at the call site so the adversarial check does not fail them.

### Grouping decisions
- Three progress routes → one `get-bulk-job-status` (job enum). Three cancel routes → one `cancel-bulk-job`.
- `/offload/bulk` + `/bulk/action` (offload) → one `offload-media` (ids optional). Same for restore.
- Preflights grouped by `operation` enum; split if the oneOf output is unfriendly to clients.
- Writes stay narrow: offload, bring back, apply retention and cancel are separate state transitions with different annotations.

### Destructive abilities: required safeguards
1. `bring-back-media`: `delete_cloud` default false, explicit opt-in; per-id `delete_post` check; refuse on `disk_ok=false`; refuse during retention/other bulk jobs.
2. `offload-media` / `apply-retention-policy`: description must name the active retention policy's consequence (local deletion for 1/2); honour `bulk_job_conflict()` and the CLI process lock (Safety Invariant 8).
3. All writes: `permission_callback` must be `manage_options` plus per-id checks inside execute, matching REST exactly; never broaden to `upload_files`.
4. Consider a `dry_run` input (mirrors the CLI's `--dry-run`, Safety Invariant 7) that returns the would-be `queued` count and preflight without starting a queue.

### Shared-service shape
Every write has side effects (hooks, meta, queue options, cron, remote calls), so abilities should call the service classes (the same ones `Cli\OffloadCommand`/`RestoreCommand`/`RetentionCommand` use) rather than `rest_do_request()`. Extracting the "start bulk job with guards" logic (engine null → not_configured, conflict check, delete_cloud filtering) out of the controllers into a shared method would keep REST, CLI and abilities in lockstep.
