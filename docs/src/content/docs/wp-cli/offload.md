---
title: wp omnioffloader offload
description: Upload media library attachments to your cloud storage from the terminal.
sidebar:
  order: 2
---

Uploads attachments to your cloud storage. It runs in the terminal with a progress bar. After each upload is verified in the bucket, the local files follow your saved [retention policy](/features/retention-policies/).

The admin screen that does the same job is **OmniOffloader → Offload**.

## Synopsis

```bash
wp omnioffloader offload [--all] [--ids=<ids>] [--limit=<number>] [--dry-run] [--force] [--concurrency=<number>]
```

## Options

| Option | Description |
| --- | --- |
| `--all` | Offload every attachment that is not offloaded yet. |
| `--ids=<ids>` | Comma-separated attachment IDs to offload. |
| `--limit=<number>` | Stop after this many attachments. Useful for working through a large library in scheduled chunks. |
| `--dry-run` | List what would be offloaded without uploading anything. |
| `--force` | Run even when a bulk job started from the admin screen is active. |
| `--concurrency=<number>` | Files uploaded at the same time for this run, from `1` to `64`, instead of the [Transfer Speed](/features/transfer-speed/) setting (at most 10). For a VPS or dedicated server. |

You must pass either `--all` or `--ids`, but not both.

## Examples

List what would be offloaded:

```bash
wp omnioffloader offload --all --dry-run
```

Offload every local attachment:

```bash
wp omnioffloader offload --all
```

Work through a large library in chunks of 500:

```bash
wp omnioffloader offload --all --limit=500
```

Offload the whole library, keep it running after you close the terminal, and write its progress to a log file (see [Long runs and log files](/wp-cli/long-runs/)):

```bash
nohup wp omnioffloader offload --all > ~/offload.log 2>&1 &
```

The same in the background on a VPS, 64 files at once:

```bash
nohup wp omnioffloader offload --all --concurrency=64 > ~/offload.log 2>&1 &
```

Follow it with `tail -f ~/offload.log` or `wp omnioffloader status`, and end it with [`wp omnioffloader stop`](/wp-cli/stop/).

Upload 32 files at the same time on a VPS; try a short run first and time it:

```bash
wp omnioffloader offload --all --concurrency=32 --limit=500
```

Offload specific attachments:

```bash
wp omnioffloader offload --ids=12,15,42
```

## Output

A dry run prints the ID and title of each attachment:

```
Would offload 3 attachment(s):
  12  Beach sunset
  15  Team photo
  42  Product video
```

A finished run prints a summary:

```
Success: 3 attachment(s) offloaded.
```

If some attachments failed, the command prints a warning and exits with code `1`:

```
Warning: 41 offloaded, 2 failed (see omnioffloader_error_log meta).
```

Each failed attachment keeps its error. You can see it in the Media Library (**Failed — …** with a **Retry Offload** link) and in the attachment's OmniOffloader box on its edit screen.

## Notes

- **Which attachments:** `--all` picks every attachment that has a file and is not in the trash or already offloaded. Attachments that failed before are processed last.
- **Skipped attachments:** attachments excluded by the [`omnioffloader_should_offload_attachment`](/developers/hooks/#omnioffloader_should_offload_attachment) filter are counted as skipped, not as failures.
- **Speed:** uploads follow the [Transfer Speed](/features/transfer-speed/) setting, or `--concurrency` for this run. Above **Low**, a few attachments share one batch and their files upload side by side; with a high `--concurrency`, more attachments share a batch (one per four files at once, up to 16) so every slot stays busy. A file above 64 MB uploads at most 10 parts at once.
- **When `--concurrency` helps:** with many small files (thumbnails) on a server with good bandwidth. It can't make one large file or a saturated connection faster. [`restore`](/wp-cli/restore/#speed), [`retention`](/wp-cli/retention/) and [`move`](/wp-cli/move/) take it too, but they work through one attachment at a time, so there it is capped by the number of files an attachment has.
- **Repeated failures:** after 10 failures in a row the command checks the connection. If the provider does not answer, it stops with the provider's error. See [Stopping on repeated failures](/wp-cli/#stopping-on-repeated-failures).
- **Safe mode:** on a staging, development or local copy, the command refuses to run (a dry run still works). See [Safe mode](/features/safe-mode/).
- **Shared buckets:** before it starts, the command checks that this site's bucket folder is not used by another site. See [Sharing one bucket](/features/shared-bucket/).
