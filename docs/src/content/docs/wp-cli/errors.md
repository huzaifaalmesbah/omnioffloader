---
title: wp omnioffloader errors
description: List media with errors, retry failed offloads, and clear error logs from the terminal.
sidebar:
  order: 7
---

Lists the media that has an error: the attachment, whether it is offloaded, when the last error happened and what it said. It also retries failed offloads, and clears error logs once you have fixed the cause.

An item that is **not offloaded** is a failed offload: it is still on your server only. An **offloaded** item logged its error during a Bring Back, retention update or move. The admin screen with the same list is the **Failed Items** card on **OmniOffloader → Tools** (see [Failed Items](/features/failed-items/)).

## Synopsis

```bash
wp omnioffloader errors [--state=<state>] [--retry] [--clear] [--limit=<number>] [--format=<format>] [--dry-run] [--force] [--concurrency=<number>] [--yes]
```

| Option | Description |
| --- | --- |
| `--state=<state>` | `all` (default), `local` (failed offloads) or `offloaded` (in the cloud, with errors from another job). |
| `--retry` | Offload the failed offloads again. Runs [`wp omnioffloader offload`](/wp-cli/offload/) with their IDs, so it takes offload's `--dry-run`, `--force` and `--concurrency`. |
| `--clear` | Delete the error logs of the listed items. Changes no files. Asks first, unless you pass `--yes`. |
| `--limit=<number>` | At most this many items, newest first. |
| `--format=<format>` | `table` (default), `json`, `csv`, `ids` or `count`. |
| `--dry-run` | With `--retry` or `--clear`: show what would happen without changing anything. |
| `--force` | With `--retry`: run even when a bulk job started from the admin screen is active. |
| `--concurrency=<number>` | With `--retry`: files uploaded at the same time, 1 to 64. |
| `--yes` | With `--clear`: skip the confirmation. |

## Examples

List everything with an error:

```bash
wp omnioffloader errors
```

```
id	title	offloaded	time	error	errors
3220	team-photo	no	2026-10-16 15:49	Upload failed for "2026/10/team-photo.jpg"	2
2928	brochure	yes	2026-10-16 15:12	Bring Back download failed	1
1 failed offload(s). Retry them with: wp omnioffloader errors --retry
```

Retry the failed offloads, after a dry run:

```bash
wp omnioffloader errors --retry --dry-run
wp omnioffloader errors --retry
```

Failed offloads as JSON, for a script:

```bash
wp omnioffloader errors --state=local --format=json
```

Clear the logs of offloaded items once you have fixed the cause:

```bash
wp omnioffloader errors --state=offloaded --clear --yes
```

## Notes

- `--retry` follows the same rules as `offload`: it refuses in [Safe mode](/features/safe-mode/) and while another job runs (unless `--force`), and failed items go last in line.
- An attachment whose file is missing on the server is not a failed offload here. `status` reports those separately; they are picked up again once the file is back.
- `--clear` refuses while a job runs, since that job may be writing to the same logs.
- A successful offload or Bring Back clears the attachment's error log on its own.
