---
title: wp omnioffloader status
description: Check the provider, the bucket connection, Safe mode, media counts and running jobs from the terminal.
sidebar:
  order: 6
---

Shows what is running right now, then the provider settings, the bucket connection, Safe mode and media counts. It changes nothing, so you can run it as often as you like, from any terminal.

The admin screen with the same information is **OmniOffloader → Dashboard**.

## Synopsis

```bash
wp omnioffloader status [--format=<format>]
```

| Option | Description |
| --- | --- |
| `--format=<format>` | `text` (default) or `json`. See [JSON output](#json-output). |

## While a job runs

The first lines show the running job. The **Offload**, **Bring Back** and **Tools** screens show the same figures for a running WP-CLI job (see [In the admin screens](/wp-cli/long-runs/#in-the-admin-screens)).

For a WP-CLI job, for example one started with [`nohup … &`](/wp-cli/long-runs/):

```
OmniOffloader 0.1.5

Running now: WP-CLI offload (in the background, process 11030)
  Progress:   3,420 of 59,177 (5.8%), 2 failed
  Started:    2026-10-07 04:45 (25 mins ago)
  Speed:      137 attachments per minute
  Time left:  about 6 hours 48 mins (finishes around 2026-10-07 11:33, site time)
  Transfers:  64 files at once
  Log:        /home/example/offload.log (watch it: tail -f /home/example/offload.log)
  Stop:       wp omnioffloader stop

Provider:   Cloudflare R2 (cloudflare_r2)
Bucket:     my-media
CDN domain: https://media.example.com
Connection: OK
Safe mode:  off (live site)
Folder:     shop/ (Path Prefix; new offloads go here)

Media: 59,177 total — 3,420 offloaded (5.8%), 55,757 local
```

| Line | Meaning |
| --- | --- |
| **Running now** | The job, whether it runs in the background or in a terminal, and its process number. |
| **Progress** | Attachments done out of the total, and how many failed. |
| **Started** | When the job started, in the site's time zone. |
| **Speed** | The average since the start. Shown after the first minute. |
| **Time left** | Worked out from that speed, with the expected finish time. It gets more accurate the longer the job runs. |
| **Transfers** | How many files move at the same time: `--concurrency`, or the [Transfer Speed](/features/transfer-speed/) setting. |
| **Note** | Only when there was no progress for two minutes: a large file is probably still transferring. |
| **Log** | The log file of a background job, on Linux servers. |
| **Stop** | The command that stops the job. See [`wp omnioffloader stop`](/wp-cli/stop/). |

After `wp omnioffloader stop`, the first line says **Stopping**: the job finishes the files it is transferring, then exits.

A bulk job started from the admin screen shows its progress and error count:

```
Running now: Bulk Offload started from the admin screen
  Progress:   120 of 500 (24.0%), 0 errors
  Stop:       wp omnioffloader stop (or Cancel on the admin screen)
```

## When nothing runs

`status` shows how the last WP-CLI job ended:

```
Running now: nothing
Last WP-CLI run: offload, finished
  Result:     59,177 of 59,177 done, 3 failed
  When:       2026-10-07 04:45 to 11:31 (6 hours 46 mins), ended 2 hours ago
  Log:        /home/example/offload.log
```

The job ended one of these ways:

- **finished**: it worked through every attachment.
- **stopped with `wp omnioffloader stop`**.
- **ended early**: Ctrl+C, a closed terminal window, a server restart, or too many failures in a row. Run the same command again to continue; attachments already done are skipped.

If attachments are still local and no job runs, `status` ends with the command that offloads them in the background.

### A job that was killed

If the job's process is gone without cleaning up (a server restart, or `kill -9`), `status` says so and shows how far it got:

```
Running now: nothing. The WP-CLI offload run (process 11030) ended without cleaning up, for example a server restart or kill -9.
  Got to:     3,420 of 59,177 (5.8%), 2 failed, last progress 3 hours ago
  Continue:   run the same command again. It clears the old lock and skips attachments already done.
```

## The other lines

- **Provider, Bucket, CDN domain:** the active provider and where media is served from.
- **Connection:** `OK` when the bucket can be reached. Otherwise a warning with the provider's error.
- **Safe mode:** `off (live site)`, or `ON` with the reason, for example `WP_ENVIRONMENT_TYPE is "staging"` or `the site address changed from … to …`. See [Safe mode](/features/safe-mode/).
- **Folder:** the bucket folder new offloads go to, set by the [Path Prefix](/features/path-prefix-and-versioning/), or `bucket root`. When some offloaded media is in another folder, bucket or provider, because a setting changed after it was offloaded, a second line says how many. That media keeps working from where it is. [`wp omnioffloader move`](/wp-cli/move/) moves it into the current folder.
- **Warning: Folder … is now used by another site:** a folder this site moved away from was taken over by another site sharing the bucket. Its older media there still loads, but this site no longer writes to or deletes from that folder. Move it with `wp omnioffloader move`. See [Sharing one bucket](/features/shared-bucket/#folders-a-site-moved-away-from).
- **Media:** total attachments, how many are offloaded (and what share), and how many are only local. Counted fresh each time.
- **File missing:** shown when some attachments have no file on this server. They are skipped until the files are back. See [Files missing on this server](/features/bulk-offload/#files-missing-on-this-server).
- **Last Bulk Offload / Bring Back / retention update / folder move from the admin screen:** the result of the last bulk job started from the admin screen, and how long ago it ran.
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

## JSON output

`--format=json` prints the same information as one JSON object, for monitoring scripts and dashboards. The exit code works the same way.

```bash
wp omnioffloader status --format=json
```

```json
{
    "version": "0.1.6",
    "healthy": true,
    "engine": true,
    "provider": { "key": "cloudflare_r2", "name": "Cloudflare R2", "bucket": "my-media", "domain": "https://media.example.com" },
    "connection": { "ok": true, "error": null },
    "safe_mode": { "active": false, "reason": null },
    "folder": { "prefix": "shop/", "offloaded_elsewhere": 0, "lost_folders": [] },
    "media": { "total": 59177, "offloaded": 3420, "local": 55757, "missing": 0, "outdated_retention": 0 },
    "running": { "job": "offload", "source": "cli" },
    "admin_jobs": {
        "offload": { "status": "idle", "processed": 0, "total": 0, "errors": 0, "updated_at": null },
        "restore": { "...": "..." },
        "retention": { "...": "..." },
        "move": { "...": "..." }
    },
    "cli_run": { "job": "offload", "pid": 11030, "host": "web1", "alive": true, "stopping": false, "processed": 3420, "total": 59177, "failed": 2, "started": 1791348300, "updated": 1791349800, "log": "/home/example/offload.log" },
    "cli_last": null
}
```

| Key | Meaning |
| --- | --- |
| `healthy` | `false` when no provider is configured or the bucket can't be reached (the command then exits with `1`). |
| `provider`, `connection`, `folder` | `null` when no provider is configured. |
| `safe_mode.reason` | `constant`, `environment`, `local`, `address` or `folder`. See [Safe mode](/features/safe-mode/). |
| `media.outdated_retention` | Offloaded attachments that still follow an older retention policy. |
| `running` | The job running now, from the admin screen (`admin`) or WP-CLI (`cli`), or `null`. |
| `cli_run` | The WP-CLI run record while one exists. `alive` is `false` when its process is gone, and `null` when it runs on another server. |
| `cli_last` | How the last WP-CLI run ended, when nothing runs. Times are Unix timestamps. |
