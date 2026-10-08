---
title: Transfer Speed
description: Choose how many files move between your server and the cloud at the same time.
sidebar:
  order: 5
---

**Transfer Speed** (in **Settings → Behavior**) sets how many files are uploaded or downloaded at the same time.

![OmniOffloader Transfer Speed and Concurrency Settings](/images/screenshots/transfer-speed-marked.png)

| Setting | Files at the same time | Best for |
|---|---|---|
| **Low** | 1 | Small shared hosting plans; lowest resource use |
| **Balanced** (default) | 3 | Most sites |
| **Fast** | 6 | VPS and dedicated servers |
| **Custom** | 1 to 10 (you choose) | Fine-tuning for your server |

## Always one PHP process

Every setting runs inside a **single PHP process**. A faster setting does not start extra workers. Instead, it overlaps the time spent waiting on the network. Each extra file only adds a little memory and bandwidth.

At any speed above **Low**, a few attachments also share one batch, and their main files are confirmed in the bucket together.

## What it affects

The setting matters most when you move media that is already in your library:

- [Bulk Offload](/features/bulk-offload/)
- Media Library bulk actions
- [Bring Back](/features/bring-back/)
- Applying a [retention policy](/features/retention-policies/) to existing media
- [Moving media to the current folder](/features/path-prefix-and-versioning/#move-existing-media-to-the-new-folder)
- WP-CLI commands such as [`wp omnioffloader offload`](/wp-cli/offload/)
- Large multipart uploads (files above 64 MB)

New uploads also follow it, but they are offloaded in the background as they arrive, so you rarely notice their speed.

## Which one should I choose?

- Start with **Balanced**.
- On a small shared plan, or if your host reports high resource use, choose **Low**.
- On a VPS or dedicated server, try **Fast**.
- Use **Custom** if you want a number between these.
- For a one-off WP-CLI run on a VPS, `--concurrency=<1–64>` goes beyond 10 for that run only. `wp omnioffloader offload`, `move`, `restore` and `retention` take it. With `retention`, a stricter policy checks that many attachments in cloud storage at once. See [`wp omnioffloader offload`](/wp-cli/offload/).

:::note
Speed also depends on how quickly your server reaches your storage provider, how large your files are, and how many thumbnail sizes your theme creates.
:::

## For developers

Override the number of files with the `omnioffloader_transfer_concurrency` filter. See [Hooks](/developers/hooks/).
