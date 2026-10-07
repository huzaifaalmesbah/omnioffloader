---
title: wp omnioffloader restore
description: Bring Back offloaded media from the cloud to your server from the terminal.
sidebar:
  order: 3
---

Bring Back from the terminal. Downloads offloaded attachments to the server and serves them locally again. It runs with a progress bar.

- Each attachment is restored from the provider it was offloaded to, so restores still work after you switched to another provider.
- Downloads go to a temporary file first and are moved into place only when complete. Every file is checked before the attachment counts as restored.
- Cloud copies are **kept**, unless you pass `--delete-cloud`.

The admin screen that does the same job is **OmniOffloader → Bring Back**. See [Bring Back](/features/bring-back/) for the full picture.

## Synopsis

```bash
wp omnioffloader restore [--all] [--ids=<ids>] [--limit=<number>] [--delete-cloud] [--yes] [--dry-run] [--force]
```

## Options

| Option | Description |
| --- | --- |
| `--all` | Restore every offloaded attachment. |
| `--ids=<ids>` | Comma-separated attachment IDs to restore. |
| `--limit=<number>` | Stop after this many attachments. Useful for working through a large library in scheduled chunks. |
| `--delete-cloud` | Also delete the cloud objects after each verified restore. Destructive: asks for confirmation unless `--yes` is passed. |
| `--yes` | Answer yes to the `--delete-cloud` confirmation prompt. |
| `--dry-run` | List what would be restored without downloading anything. |
| `--force` | Run even when a bulk job started from the admin screen is active. |

You must pass either `--all` or `--ids`, but not both.

## Examples

List what would be restored:

```bash
wp omnioffloader restore --all --dry-run
```

Restore everything and keep the cloud copies:

```bash
wp omnioffloader restore --all
```

Restore in chunks of 500:

```bash
wp omnioffloader restore --all --limit=500
```

Restore specific attachments:

```bash
wp omnioffloader restore --ids=12,15,42
```

Restore, then delete the cloud copies, without the prompt:

```bash
wp omnioffloader restore --all --delete-cloud --yes
```

Restore everything in the background, keep it running after you close the terminal, and write its progress to a log file (see [Long runs and log files](/wp-cli/long-runs/)):

```bash
nohup wp omnioffloader restore --all > ~/restore.log 2>&1 &
```

With `--delete-cloud` in the background, add `--yes`: nobody can answer the question, so without it the command stops and asks for it.

```bash
nohup wp omnioffloader restore --all --delete-cloud --yes > ~/restore.log 2>&1 &
```

## Deleting cloud copies

:::danger
`--delete-cloud` removes files from your bucket. A cloud file is only deleted after its attachment was fully restored and checked on the server, and only when that file is now on the server (an image's pre-edit original, for example, stays in the cloud), but the delete itself can't be undone. Turn on versioning in your bucket if you want a safety net.
:::

Without `--yes`, the command asks first:

```
Delete the cloud objects of 120 attachment(s) after restoring them? [y/n]
```

In [Safe mode](/features/safe-mode/), `--delete-cloud` is never allowed. It would delete files the live site still serves.

## Output

```
Success: 120 attachment(s) restored.
```

If some attachments failed, the command prints a warning and exits with code `1`:

```
Warning: 118 restored, 2 failed (see omnioffloader_error_log meta).
```

## Disk space

Before each attachment, the command checks that the server has room for it. When it doesn't, the command stops with a clear message instead of failing download after download:

```
Error: Stopped: the server is running out of disk space (24 MB more is needed for the next attachment, 80 restored first). Free up space, then run the command again.
```

On shared hosting with a storage quota (for example cPanel or CloudLinux), PHP may see more free space than your plan allows. When the server refuses to save a file ("Disk quota exceeded", "No space left on device"), the command stops right away:

```
Error: Stopped: the server ran out of disk space, or the hosting plan's storage quota is used up (80 restored first). Free up space, then run the command again.
```

Nothing is lost. Attachments that were not brought back stay in the cloud, and the next run picks them up.

## Notes

- **Which attachments:** `--all` picks every offloaded attachment. Attachments that failed before are processed last.
- **Speed:** downloads follow the [Transfer Speed](/features/transfer-speed/) setting.
- **Repeated failures:** after 10 failures in a row the command checks the connection. See [Stopping on repeated failures](/wp-cli/#stopping-on-repeated-failures).
- **Safe mode:** on a staging, development or local copy, the command refuses to run unless **Allow Bring Back on a copy (download only)** is on in Settings.
