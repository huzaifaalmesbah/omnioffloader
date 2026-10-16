---
title: wp omnioffloader verify
description: Check that every offloaded file is really in your cloud storage and on the server where its retention policy keeps it, and repair what differs.
sidebar:
  order: 8
---

Compares what WordPress records with what is really in your bucket and on your server, file by file: the original, the scaled original and every generated size of each offloaded attachment. With `--repair` it fixes what can be fixed. A repair never deletes a file.

## Synopsis

```bash
wp omnioffloader verify [--ids=<ids>] [--limit=<number>] [--repair] [--dry-run] [--format=<format>] [--force]
```

| Option | Description |
| --- | --- |
| `--ids=<ids>` | Comma-separated attachment IDs. Default: every offloaded attachment. |
| `--limit=<number>` | Check at most this many attachments. |
| `--repair` | Fix what can be fixed (see below). Never deletes a file. |
| `--dry-run` | With `--repair`: list the repairs without making them. |
| `--format=<format>` | `table` (default), `json`, `csv`, or `count` (issues left). |
| `--force` | With `--repair`: run even when a bulk job started from the admin screen is active. |

## What it finds

| Issue | Meaning | `--repair` |
| --- | --- | --- |
| `cloud_missing` | The file is not in the bucket. | Uploads it from the server. |
| `cloud_differs` | The bucket copy has another size than the server's, usually because an image optimizer changed the file after the offload. | Uploads the server's copy. |
| `local_missing` | The attachment's [retention policy](/features/retention-policies/) keeps this file on the server, but it is gone. | Downloads it from the bucket. |
| `lost` | The file is neither in the bucket nor on the server. | Nothing it can do. Restore it from a backup. Also written to the attachment's error log. |
| `stuck` | The attachment shows **Offloading…** or **Queued** in the Media Library while no job runs. | Clears the status. |
| `unchecked` | The bucket could not be read for this attachment (a network error). | Nothing. Run verify again. |
| `no_provider` | The attachment was offloaded to a provider that is no longer configured. | Nothing. |

A file the retention policy removed from the server (sizes under Smart Local Cleanup, everything under Full Cloud Migration) is fine as long as it is in the bucket.

Downloads work like [Bring Back](/features/bring-back/): each file goes to a temporary file first and is renamed into place only when complete, inside the uploads folder.

## Examples

Check everything:

```bash
wp omnioffloader verify
```

```
Verifying  100% [====================================] 0:12 / 0:12
id	title	issue	file	repair	result
2928	brochure	local_missing	brochure-300x196.png	download
3011	team	cloud_missing	team-1024x683.jpg	upload
Checked 24 attachment(s), 192 file(s): 2 issue(s).
Fix what can be fixed with: wp omnioffloader verify --repair (try --dry-run first).
```

See the repairs first, then make them:

```bash
wp omnioffloader verify --repair --dry-run
wp omnioffloader verify --repair
```

A big library in the background, like the other long jobs (see [Long runs and log files](/wp-cli/long-runs/)):

```bash
nohup wp omnioffloader verify --repair > ~/verify.log 2>&1 &
```

## Notes

- Verify reads one bucket listing per attachment. Without `--repair` it changes nothing, so you can run it any time. Files a running job is working on may show as issues; run it again once the job ends.
- `--repair` counts as a job: it refuses while another job runs (unless `--force`), the admin screens wait for it, and [`wp omnioffloader stop`](/wp-cli/stop/) ends it. It refuses in [Safe mode](/features/safe-mode/), since it uploads to your cloud storage.
- It stops at once if a storage provider can't be reached, rather than report every file as unchecked.
- A file counts as missing from the bucket only when a listing of the bucket succeeded and does not include it, so a network error never causes an upload.
- Orphan objects (files in the bucket that no attachment uses) are not checked.

## Use it in monitoring

The command exits with code `1` while any issue is left unrepaired, and `0` otherwise:

```bash
wp omnioffloader verify --format=count --path=/var/www/html || echo "OmniOffloader: media needs attention"
```
