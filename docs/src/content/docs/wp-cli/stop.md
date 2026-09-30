---
title: wp omnioffloader stop
description: Stop a running offload, Bring Back or retention job from any terminal, and run long jobs in the background.
sidebar:
  order: 6
---

Stops the offload, Bring Back or retention job that is running. It works from any terminal: a new SSH window, a script or a cron job.

- **A WP-CLI run** (in the foreground or with `--background`) finishes the file it is transferring, then exits and releases its lock. No file is left half uploaded or half downloaded.
- **A bulk job started from the admin screen** is cancelled, like its **Cancel** button does.

Attachments that were already done stay done. Run the same command again to continue where it stopped.

## Synopsis

```bash
wp omnioffloader stop [--wait=<seconds>]
```

## Options

| Option | Description |
| --- | --- |
| `--wait=<seconds>` | How long to wait for a WP-CLI run to finish its current file. Default `120`. `0` asks it to stop and returns right away. |

## Example

```bash
wp omnioffloader stop
```

```
Asked the offload run (process 843753, 3420 of 5000 done) to stop after the file it is transferring.
Success: Stopped. Run the same command again to continue where it left off.
```

If a large file is still transferring when the wait ends, the command says so. The run still stops on its own right after that file.

## Run in the background

`offload`, `restore` and `retention` take `--background`. The command starts the job, confirms it is running, and returns. You can then close the terminal; the job keeps going.

```bash
wp omnioffloader offload --all --background
```

```
Success: Started in the background (process 843753). You can close this window.
Log:      /tmp/omnioffloader-offload-20260930-110512.log
Progress: wp omnioffloader status
Stop:     wp omnioffloader stop
```

- **Progress:** [`wp omnioffloader status`](/wp-cli/status/) shows how far it got. The log file gets a line every 25 attachments: `tail -f` it to follow along.
- **Log file:** by default a new file in the server's temp folder. Choose another with `--log=<file>`.
- **Confirmations:** nobody can answer a question in the background, so a run that would ask one (`restore --delete-cloud`, or `retention` with a stricter policy) needs `--yes`.
- `--background` can't be combined with `--dry-run`.

`--background` needs PHP to be allowed to start processes (`proc_open`). Most hosts allow it on the command line. Where it is disabled, the command says so; run the job inside `screen` or `tmux` instead.

## Closing the terminal without `--background`

A run in the foreground stops when you close the terminal, like any command. Its lock is released right away, so you can start it again at once.

## A run that was killed

If a run was killed without cleaning up (for example `kill -9`, or the server restarted), its lock is left behind. On the same server, the next command sees that the process is gone, clears the lock and starts. `status` shows such a run as `NOT RUNNING`, and `stop` clears it too.
