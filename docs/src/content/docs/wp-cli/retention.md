---
title: wp omnioffloader retention
description: Apply a retention policy to media you already offloaded, from the terminal.
sidebar:
  order: 4
---

Applies a [retention policy](/features/retention-policies/) to media that is already offloaded.

A new retention policy only affects new uploads at first. This command updates the media you offloaded before:

- **A stricter policy** deletes local files. Each file is checked in cloud storage by size first. A file missing from the bucket is uploaded before its local copy is deleted.
  It is refused while [Serve Media from Cloud](/features/url-rewriting/#offload-now-serve-from-the-cdn-later) is off, because your site still loads those files from the server. `--dry-run` still works.
- **A looser policy** downloads local copies again.

The attachments stay offloaded either way. The admin screen that does the same job is **OmniOffloader → Tools → Apply Retention Policy to Existing Media**.

## Synopsis

```bash
wp omnioffloader retention [--policy=<policy>] [--ids=<ids>] [--limit=<number>] [--dry-run] [--yes] [--force] [--concurrency=<number>] [--format=<format>]
```

## Options

| Option | Description |
| --- | --- |
| `--policy=<policy>` | Target policy: `0`, `1` or `2` (see below). Defaults to the policy saved in Settings. |
| `--ids=<ids>` | Comma-separated attachment IDs. Defaults to every offloaded attachment that follows a different policy. |
| `--limit=<number>` | Stop after this many attachments. |
| `--dry-run` | List what would change without deleting or downloading anything. |
| `--yes` | Skip the confirmation prompt before local files are deleted. |
| `--force` | Run even when a bulk job started from the admin screen is active. |
| `--concurrency=<number>` | 1 to 64, instead of the [Transfer Speed](/features/transfer-speed/) setting (at most 10). A stricter policy checks that many attachments in cloud storage at once; a looser policy downloads that many files of an attachment at once. For a VPS or dedicated server. See [Speed](#speed). |
| `--format=<format>` | Output format for the `--dry-run` table: `table` (default), `json` or `csv`. |

### Policy values

| Value | Policy | Local files |
| --- | --- | --- |
| `0` | Retain Local Files | Keep everything on the server. |
| `1` | Smart Local Cleanup | Delete generated thumbnails, keep originals. |
| `2` | Full Cloud Migration | Remove all local copies. |

## Examples

Review what the saved policy would change:

```bash
wp omnioffloader retention --dry-run
```

Apply the saved policy (asks before deleting):

```bash
wp omnioffloader retention
```

Smart Local Cleanup, 500 attachments at a time:

```bash
wp omnioffloader retention --policy=1 --limit=500
```

Full Cloud Migration without the prompt:

```bash
wp omnioffloader retention --policy=2 --yes
```

Apply the saved policy faster on a VPS or dedicated server, checking 32 attachments at once:

```bash
wp omnioffloader retention --concurrency=32 --yes
```

Download local copies of everything again:

```bash
wp omnioffloader retention --policy=0
```

The same, downloading up to 32 files at once (a VPS or dedicated server):

```bash
wp omnioffloader retention --policy=0 --concurrency=32
```

Apply the saved policy in the background, keep it running after you close the terminal, and write its progress to a log file (see [Long runs and log files](/wp-cli/long-runs/)). Add `--yes`: when the policy deletes local files the command asks first, and a background job can't answer:

```bash
nohup wp omnioffloader retention --yes > ~/retention.log 2>&1 &
```

The same, faster, on a VPS or dedicated server:

```bash
nohup wp omnioffloader retention --concurrency=32 --yes > ~/retention.log 2>&1 &
```

Export the dry run for a spreadsheet or a script:

```bash
wp omnioffloader retention --dry-run --format=csv > retention-plan.csv
wp omnioffloader retention --dry-run --format=json
```

## Speed

**A stricter policy** (for example Retain Local Files → Smart Local Cleanup) uploads and downloads nothing in the usual case. For each attachment it asks your storage provider for the sizes of its files (one listing request), then deletes the verified local copies. Nearly all the time goes into waiting for those answers, so the command asks about several attachments at once: as many as the [Transfer Speed](/features/transfer-speed/) setting allows (3 on Balanced), or `--concurrency` for this run.

- `--concurrency=32` checks 32 attachments per round trip instead of 3, which makes a large library many times faster. Every file is still checked by size before its local copy is deleted.
- A file missing from the bucket, or with a different size there, is still uploaded first, one at a time. That is rare and doesn't slow a normal run.

**A looser policy** downloads files. Each attachment's files download side by side, as many at once as the setting or `--concurrency` allows. As with [`restore`](/wp-cli/restore/#speed), an attachment never downloads more files at once than it has.

The **Apply Retention Policy** card on the Tools screen checks attachments in groups too, following the Transfer Speed setting (up to 10).

## Dry run output

The dry run shows one row per attachment with the columns `ID`, `title`, `from`, `to`, `action` and `detail`:

- `from` and `to` are policy values (`0`, `1`, `2`).
- `action` is `delete local` or `download`.
- `detail` shows the number and size of files to delete, or which files would be downloaded (`original` or `original + sizes`).

In `table` format, a summary line follows, for example:

```
Would apply "Smart Local Cleanup" to 250 attachment(s), freeing about 1.2 GB.
```

The `json` and `csv` formats print only the rows, so the output stays machine-readable.

## Confirmation

When local files would be deleted, the command asks first:

```
Apply "Full Cloud Migration" to 250 attachment(s)? Local files of 250 attachment(s) will be deleted after each is verified in cloud storage. [y/n]
```

Add `--yes` to skip the question. Moving to a looser policy only downloads, so it doesn't ask.

## Output

```
3 file(s) were missing from cloud storage and were uploaded before cleanup.
Freed 1.2 GB of local disk space.
Success: 250 attachment(s) now follow "Smart Local Cleanup".
```

If some attachments failed, the command prints a warning and exits with code `1`. Failed attachments keep their local files and are retried on the next run.

## Notes

- **A different policy than Settings:** when `--policy` differs from the saved setting, the command warns you. New uploads keep following the setting, so update it too if the change should be permanent.
- **Size check:** a local file whose cloud copy has a different size is not deleted. It is uploaded again and checked (for example after an image optimizer shrank your thumbnails).
- **Excluding attachments:** use the [`omnioffloader_should_apply_retention`](/developers/hooks/#omnioffloader_should_apply_retention) filter to keep specific attachments' local files, such as downloadable products or PDFs another plugin reads from disk.
- **Checking what needs updating:** `wp omnioffloader status` tells you how many offloaded attachments follow an older policy.
