---
title: WP-CLI overview
description: Run offload, Bring Back and retention jobs from the terminal with the wp omnioffloader commands.
sidebar:
  order: 1
---

Every bulk job in OmniOffloader can also run from the terminal with [WP-CLI](https://wp-cli.org/). The admin screens and the terminal do the same work and follow the same settings.

| Command | What it does |
| --- | --- |
| [`wp omnioffloader offload`](/wp-cli/offload/) | Upload media to cloud storage. |
| [`wp omnioffloader restore`](/wp-cli/restore/) | Bring Back: download offloaded media to the server again. |
| [`wp omnioffloader retention`](/wp-cli/retention/) | Apply a retention policy to media that is already offloaded. |
| [`wp omnioffloader move`](/wp-cli/move/) | Move media offloaded under an earlier Path Prefix into the current folder. |
| [`wp omnioffloader status`](/wp-cli/status/) | Show the provider, connection, Safe mode, media counts and running jobs. |
| [`wp omnioffloader stop`](/wp-cli/stop/) | Stop the running job from any terminal. |
| [Long runs and log files](/wp-cli/long-runs/) | Run a big job in the background, watch its log, and work out the time left. |

The same reference, with copy buttons, is on the **OmniOffloader → Tools** screen.

![The WP-CLI reference on the Tools screen: how to run a long job in the background, then every command with examples and copy buttons](/images/screenshots/wp-cli-marked.png)

## When to use WP-CLI

- **Large libraries.** A terminal command is not limited by the browser or by short web request time limits. The Bulk Offload and Bring Back screens have a **Run this job from WP-CLI** panel: **View command** opens the matching command in the WP-CLI reference on the Tools screen. For libraries of 5,000 items or more the panel recommends it. For tens of thousands of items, add [`--concurrency`](/wp-cli/offload/) to upload many files at once.
- **SSH access.** You can run a job on the server directly and watch a progress bar.
- **Long jobs.** Start it with `nohup … &` and close the terminal; the job keeps going and writes its progress to a log file. See [Long runs and log files](/wp-cli/long-runs/).
- **Scheduled work.** Use `--limit` with a cron job to work through a big library in small chunks, for example 500 attachments each night.
- **Hosts that time out.** Some hosts stop long admin requests or block WordPress's internal background requests. WP-CLI does not depend on them.

## Running the commands

Run the commands from your WordPress folder:

```bash
cd /path/to/wordpress
wp omnioffloader status
```

Or add `--path` from anywhere:

```bash
wp omnioffloader status --path=/path/to/wordpress
```

## Shared behaviour

### Try it first with `--dry-run`

Every command that changes something supports `--dry-run`. It lists what would happen and changes nothing: no uploads, downloads or deletes.

```bash
wp omnioffloader offload --all --dry-run
wp omnioffloader restore --all --dry-run
wp omnioffloader retention --dry-run
```

### Pick your media with `--all`, `--ids` and `--limit`

- `--all` selects every attachment the command can work on. (`retention` selects them by default and has no `--all`.)
- `--ids=12,15,42` selects specific attachments. IDs that don't fit (not an attachment, or already in the wanted state) are skipped, and the command lists them in a warning.
- `--limit=<number>` stops after that many attachments.

The commands check these values before they start:

- `--limit` must be a positive whole number. A typo such as `--limit=5O` is an error. It never means "the whole library".
- `--ids` and `--all` can't be used together.
- `offload`, `restore` and `move` need either `--all` or `--ids`.

### Confirmations and `--yes`

Commands that delete files ask before they start:

- `restore --delete-cloud` asks before it deletes cloud copies.
- `retention` asks before it deletes local files.
- `move` asks before it deletes the old copies (not with `--keep-old`).

Add `--yes` to skip the question, for example in a script. Without a terminal to answer in (a cron job, `nohup … &`, or output sent to a file) the question can't be asked, so these commands stop with a message asking for `--yes` instead of waiting.

### One job at a time

Offload, Bring Back, retention and move jobs never run at the same time. They read and delete the same files, so the plugin allows one job at a time. This applies to jobs started from the admin screens, the Media Library and WP-CLI.

When another job is running, the command stops with a message like this:

```
Error: A bulk offload started from the admin screen is running. Wait for it to finish, cancel it there, or pass --force.
```

While a command runs, it holds a lock. The admin screens see the lock and will not start a second job: the Offload, Bring Back and Tools screens show which WP-CLI job is running, with its progress, and their Start buttons wait until it finishes. The lock is renewed every 30 seconds, and while a large file is still transferring. It is released when the command ends, also when you press **Ctrl-C** (when PHP's `pcntl` extension is available, which is common for the command line).

Closing the terminal also stops the command and releases its lock. To keep a job running after you close the terminal, start it with [`nohup … &`](/wp-cli/long-runs/). To stop a running job from another terminal, use [`wp omnioffloader stop`](/wp-cli/stop/).

If a command was killed in a way that left the lock behind (for example `kill -9`, or the server restarted), the next command on the same server sees the process is gone and clears the lock. Otherwise the lock expires within 10 minutes. Pass `--force` only if you are sure nothing else is running.

:::caution
`--force` skips the one-job-at-a-time check. Two jobs working on the same files can fail each other's uploads or downloads. Use it only when you know the other job is gone.
:::

### Safe mode

On a staging, development or local copy of your site, [Safe mode](/features/safe-mode/) is on. Then:

- `offload` and `move` refuse to run (except with `--dry-run`).
- `restore` refuses to run, unless **Allow Bring Back on a copy (download only)** is on in Settings. Even then, `--delete-cloud` is never allowed.
- `status` shows why Safe mode is on.

### Transfer Speed

The commands follow the **Transfer Speed** setting, just like the admin screens. A faster setting moves more files at the same time inside the same PHP process. `offload --concurrency` and `move --concurrency` override it for that run, up to 64 files at once. See [Transfer Speed](/features/transfer-speed/).

### Stopping on repeated failures

`offload`, `restore`, `retention` and `move` watch for failures in a row. After 10 failures in a row, they check the connection to your storage provider:

- If the provider does not answer, the command stops and shows the provider's error.
- If the provider answers, the failures come from the attachments themselves (for example files missing on disk). The command keeps going and the errors stay in each attachment's error log. It still stops after 100 failures in a row.

`restore`, `retention` and `move` also stop at once when the server runs out of disk space, with a message saying so.

Attachments that failed in an earlier run are processed last, so a restart is not held up by the same broken files.

## Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success. |
| `1` | An error, or some attachments failed. `status` also returns `1` when no provider is configured or the bucket can't be reached, so you can use it in monitoring. |
| `0` (with a warning) | The run was ended with `wp omnioffloader stop`. |
| `129` / `130` / `143` | The command was stopped by closing the terminal (`SIGHUP`), Ctrl-C (`SIGINT`) or `SIGTERM`, and its locks were released. Needs PHP's `pcntl` extension. |
