---
title: wp omnioffloader rclone
description: Print or save the rclone setup for this site, built from its settings, to copy a large library before linking it.
sidebar:
  order: 8
---

Builds the rclone setup for this site from its settings: an rclone remote for your bucket, the command that copies this site's media into its folder, and the command that links the copied files afterwards. It is the first step of offloading a [large library](/wp-cli/large-libraries/) with rclone.

The same setup, with copy buttons, is on **OmniOffloader → Tools → Copy a Large Library with rclone**.

## Synopsis

```bash
wp omnioffloader rclone [--write-config] [--config-file=<file>] [--force] [--file-list=<file>]
```

## Options

| Option | Description |
| --- | --- |
| `--write-config` | Save the remote, with your secret key, to rclone's config file instead of printing it. The file is made readable only by your user. |
| `--config-file=<file>` | rclone config file for `--write-config`. Default: `$RCLONE_CONFIG`, or `~/.config/rclone/rclone.conf`. |
| `--force` | Replace a remote named `omnioffloader` that is already in the config file. Other remotes in the file are kept. |
| `--file-list=<file>` | Write the exact list of media files not offloaded yet, and copy with rclone's `--files-from` instead of the year/month folders. |

## Example

```bash
wp omnioffloader rclone --write-config
```

```
Success: Saved the rclone remote "omnioffloader" to /home/example/.config/rclone/rclone.conf, with your secret key. Only your user can read the file.

2. Check the connection, then copy (first with --dry-run, which copies nothing):

rclone ls omnioffloader:my-bucket/shop | head

rclone copy '/var/www/example/wp-content/uploads' 'omnioffloader:my-bucket/shop' \
  --include '/[0-9][0-9][0-9][0-9]/**' \
  --transfers 32 --checkers 32 --progress --dry-run
…
3. Link the copied files (only missing or different files are uploaded):

wp omnioffloader offload --all --link-existing --dry-run
wp omnioffloader offload --all --link-existing
```

Without `--write-config`, the remote is printed with `PASTE_YOUR_SECRET_ACCESS_KEY_HERE` in place of the secret key, for you to paste into your rclone config yourself.

## What it uses from Settings

| rclone | From |
| --- | --- |
| Remote | Your provider, Access Key ID, endpoint and region, mapped to rclone's provider type (`AWS`, `Cloudflare`, `DigitalOcean`, `Wasabi`, or `Other` for Backblaze B2 and S3-compatible storage). Path-style addressing follows your S3-compatible setting. |
| Destination | `omnioffloader:<bucket>/<Path Prefix>`: only this site's folder. |
| Source | This site's uploads folder on the server (`wp-content/uploads/sites/<id>` on multisite). |

Keys defined in `wp-config.php` are used the same way as keys saved in Settings.

## Sites sharing one bucket

Each site prints its own destination, so `shop.example.com` copies into `my-bucket/shop` and `blog.example.com` into `my-bucket/blog`. The remote itself is the same for every site that uses the same bucket and keys, so it only needs to be saved once per server.

## Safety

- **The secret key is never printed**, and never sent to the browser on the Tools screen. Only `--write-config` writes it, into a file only your user can read.
- **On a copy of the site, or when another site uses this folder** ([Safe mode](/features/safe-mode/)), the command refuses: copying from here would write into someone else's files.
- **The copy command only copies the year/month folders** (or, with `--file-list`, only your media files), so logs and backups in the uploads folder never reach the bucket.
- **Use `rclone copy`, never `rclone sync`.** `sync` deletes files in the bucket that are not on this server: the folder's [ownership marker](/features/shared-bucket/) and, in a shared bucket, other sites' files.
- With **Object Versioning** on, copied files can never be linked; the command warns you to turn it off first.
