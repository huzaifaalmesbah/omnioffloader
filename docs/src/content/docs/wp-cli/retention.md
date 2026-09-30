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
wp omnioffloader retention [--policy=<policy>] [--ids=<ids>] [--limit=<number>] [--dry-run] [--yes] [--force] [--format=<format>]
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

Download local copies of everything again:

```bash
wp omnioffloader retention --policy=0
```

Export the dry run for a spreadsheet or a script:

```bash
wp omnioffloader retention --dry-run --format=csv > retention-plan.csv
wp omnioffloader retention --dry-run --format=json
```

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
