---
title: wp omnioffloader status
description: Check the provider, the bucket connection, Safe mode, media counts and running jobs from the terminal.
sidebar:
  order: 5
---

Shows the provider settings, tests the connection to your bucket, and reports Safe mode, media counts and any running bulk job. It changes nothing.

The admin screen with the same information is **OmniOffloader → Dashboard**.

## Synopsis

```bash
wp omnioffloader status
```

This command has no options.

## Example output

```
OmniOffloader 0.1.3

Provider:   Cloudflare R2 (cloudflare_r2)
Bucket:     my-media
CDN domain: https://media.example.com
Connection: OK
Safe mode:  off (live site)

Media: 1250 total — 1200 offloaded, 50 local
Bulk offload: running (120/500, 0 errors)

40 offloaded attachment(s) follow an older retention policy. Run `wp omnioffloader retention --dry-run` to review.
```

What each part means:

- **Provider, Bucket, CDN domain:** the active provider and where media is served from.
- **Connection:** `OK` when the bucket can be reached. Otherwise a warning with the provider's error.
- **Safe mode:** `off (live site)`, or `ON` with the reason, for example `WP_ENVIRONMENT_TYPE is "staging"` or `the site address changed from … to …`. See [Safe mode](/features/safe-mode/).
- **Media:** total attachments, how many are offloaded and how many are only local.
- **Bulk offload / Bulk restore / Retention update:** shown only when that job is not idle, with its status (`running`, `done`, `cancelled` or `failed`), progress and error count.
- **WP-CLI offload / restore / retention:** shown while a WP-CLI run is going, in a terminal or started with `nohup … &`: its progress, failures and process number, plus the log file on Linux. `NOT RUNNING` means the run was killed without cleaning up; the next command clears its lock. Stop a run with [`wp omnioffloader stop`](/wp-cli/stop/).
- **Older retention policy:** shown when some offloaded media still follows a policy you changed later. See [`wp omnioffloader retention`](/wp-cli/retention/).

When a site's bucket folder is used by another site, the Safe mode line names that site and asks you to give this site its own Path Prefix. See [Sharing one bucket](/features/shared-bucket/).

## Use it in monitoring

The command exits with code `1` when:

- no cloud provider is configured (the offload engine is off), or
- the bucket can't be reached.

Otherwise it exits with `0`. That makes it easy to use in a health check:

```bash
wp omnioffloader status --path=/var/www/html > /dev/null || echo "OmniOffloader: storage unreachable"
```
