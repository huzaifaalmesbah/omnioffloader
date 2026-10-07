---
title: wp omnioffloader stop
description: Stop a running offload, Bring Back or retention job from any terminal, and keep long jobs running after you close the terminal.
sidebar:
  order: 7
---

Stops the offload, Bring Back or retention job that is running. It works from any terminal: a new SSH window, a script or a cron job.

- **A WP-CLI run** (in a terminal or started with `nohup … &`) finishes the file it is transferring, then exits and releases its lock. No file is left half uploaded or half downloaded.
- **A bulk job started from the admin screen** is cancelled, like its **Cancel** button does.

Attachments that were already done stay done. Run the same command again to continue where it stopped.

## Synopsis

```bash
wp omnioffloader stop [--wait=<seconds>]
```

## Options

| Option | Description |
| --- | --- |
| `--wait=<seconds>` | How long to wait for a WP-CLI run to finish its current file, as a whole number. Default `120`. `0` asks it to stop and returns right away. |

## Example

```bash
wp omnioffloader stop
```

```
Asked the offload run (process 843753, 3420 of 5000 done) to stop after the file it is transferring.
Success: Stopped. Run the same command again to continue where it left off.
```

If a large file is still transferring when the wait ends, the command says so. The run still stops on its own right after that file.

## Keep a job running after you close the terminal

Start the command with `nohup` and `&`, and send its output to a log file in your home folder:

```bash
nohup wp omnioffloader offload --all > ~/offload.log 2>&1 &
```

The same works for the other jobs. Give each its own log file, and add `--yes` to a job that would ask a question, because nobody can answer it in the background:

```bash
nohup wp omnioffloader restore --all > ~/restore.log 2>&1 &
nohup wp omnioffloader retention --yes > ~/retention.log 2>&1 &
```

You can then close the terminal; the job keeps going. Follow it with `tail -f ~/offload.log` or `wp omnioffloader status`, and end it with `wp omnioffloader stop`. [Long runs and log files](/wp-cli/long-runs/) explains every step: checking that it started, reading the log, working out the time left, and what to do when it ends.

`screen` and `tmux` work too: start the command inside one, detach, and come back to it later.

## Closing the terminal

A run started without `nohup` stops when you close the terminal, like any command. Its lock is released right away, so you can start it again at once.

## A run that was killed

If a run was killed without cleaning up (for example `kill -9`, or the server restarted), its lock is left behind. On the same server, the next command sees that the process is gone, clears the lock and starts. `status` shows such a run as `NOT RUNNING`, and `stop` clears it too.

## Media Library after a stop

While a run works, the attachments it is transferring show **Offloading...** or **Restoring...** in **Media → Library**. When the run ends without finishing them (Ctrl+C, a closed window, `kill`), they go back to their real status, such as **Local**, and the next run picks them up. After a run killed without cleaning up, this happens when the next command clears its lock.
