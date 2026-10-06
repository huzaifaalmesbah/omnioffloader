---
title: Large libraries (rclone and similar)
description: Copy a big media library to your bucket with rclone, the AWS CLI or a similar tool, then link it with wp omnioffloader offload --link-existing.
sidebar:
  order: 7
---

A library with tens of thousands of attachments can take a day or more to offload, because every original and every thumbnail is uploaded through PHP. A dedicated copy tool such as **rclone** moves the same files many times faster.

The fastest way to offload a large library:

1. Copy your uploads folder to the bucket with a copy tool.
2. Run `wp omnioffloader offload --all --link-existing`. OmniOffloader checks each file in the bucket and marks the attachment as offloaded. It only uploads files that are missing or different.

## How the files must be laid out

OmniOffloader stores each file at the same path it has in your uploads folder, under your Path Prefix if you set one:

```
[path prefix/]2024/05/photo.jpg
[path prefix/]2024/05/photo-300x200.jpg
```

So copy the **contents** of `wp-content/uploads` into the bucket (or into the Path Prefix folder), keeping the year/month folders.

Before you start:

- **Turn Object Versioning off** (Settings → Behavior). With versioning on, each file goes to a new timestamped folder, so copied files can never match. The command refuses to run with it on.
- **Use the same Path Prefix** in the copy and in Settings.
- **Multisite:** each site's files are in `wp-content/uploads/sites/<id>/`. Copy that folder's contents, not `wp-content/uploads`.
- **Optional:** turn off [Serve Media from Cloud](/features/url-rewriting/#offload-now-serve-from-the-cdn-later) so your site keeps loading media from the server until you have checked everything.

:::danger[Copy only your media folders]
`wp-content/uploads` often holds more than media: WooCommerce logs, backup archives, form uploads, cache files. Everything you copy to a public bucket can be downloaded by anyone. Copy only the year/month folders (`2019/`, `2020/` …), as in the examples below.
:::

## Copy tools

Any tool that writes to S3-compatible storage works. These are the most common:

| Tool | Best for |
| --- | --- |
| [rclone](https://rclone.org/) | The usual choice: fast, resumable, works with every provider OmniOffloader supports. |
| [AWS CLI](https://aws.amazon.com/cli/) (`aws s3 sync`) | Already installed on many servers; works with any S3-compatible endpoint. |
| [s5cmd](https://github.com/peak/s5cmd) | Very fast for millions of small files. |
| [MinIO Client](https://min.io/docs/minio/linux/reference/minio-mc.html) (`mc mirror`) | Simple mirroring to any S3-compatible storage. |
| [s3cmd](https://s3tools.org/s3cmd) (`s3cmd sync`) | Older, but available in most Linux package managers. |
| Cyberduck, WinSCP, Mountain Duck | Desktop apps. Fine for small libraries, much slower for large ones. |

Not suitable: Cloudflare's **Wrangler** uploads one file per command, and Cloudflare's **Super Slurper** and **Sippy** only copy from another cloud bucket, not from your server.

### rclone

Set up a remote once with `rclone config` (choose S3, then your provider, for example Cloudflare R2). Use the same keys, endpoint and bucket as in OmniOffloader. Then copy, from your WordPress folder:

```bash
rclone copy wp-content/uploads r2:BUCKET/PREFIX \
  --include "/[0-9][0-9][0-9][0-9]/**" \
  --transfers 32 --checkers 32 --progress
```

- Leave out `/PREFIX` if you don't use a Path Prefix.
- You can run it again at any time: it only copies new or changed files.
- With an API token that can't create buckets, add `--s3-no-check-bucket`.
- On shared hosting without rclone, run it on your own computer with an SFTP remote as the source (`rclone copy sftp:path/to/wp-content/uploads r2:BUCKET …`). The files then pass through your computer.

### AWS CLI

```bash
aws s3 sync wp-content/uploads s3://BUCKET/PREFIX \
  --endpoint-url https://ACCOUNT_ID.r2.cloudflarestorage.com \
  --exclude "*" --include "20*/*" --include "19*/*"
```

Use your provider's endpoint, or leave out `--endpoint-url` for Amazon S3. If uploads fail with a checksum error on an S3-compatible service, set `AWS_REQUEST_CHECKSUM_CALCULATION=when_required` first.

## Link the copied files

First check what is already in the bucket. This only reads the bucket:

```bash
wp omnioffloader offload --all --link-existing --dry-run
```

```
Checked 62000 attachment(s) against the bucket:
  61850 already fully in the bucket: would be linked without uploading.
  150 need 420 file(s) uploaded (38.2 MB).
512000 file(s) found in the bucket with the same size as on this server.
```

Then link them. For a long run, start it with `nohup` so it keeps going after you close the terminal ([more](/wp-cli/stop/#keep-a-job-running-after-you-close-the-terminal)):

```bash
nohup wp omnioffloader offload --all --link-existing > link.log 2>&1 &
```

```
Success: 62000 attachment(s) offloaded (512000 file(s) already in the bucket, 420 uploaded).
```

For each attachment:

- A file counts as already there when an object exists at its key **with exactly the same size** as the file on your server. It is not uploaded again.
- A missing file, or one whose size differs (for example a thumbnail regenerated after the copy), is uploaded as usual.
- The attachment is only marked offloaded once every file is in the bucket and its main file is confirmed. Then your [retention policy](/features/retention-policies/) applies, as after a normal offload.

The bucket is read one folder at a time (about one request per 1,000 files), so linking mostly costs database writes and is far faster than uploading.

New uploads while you work are offloaded automatically as usual. When everything is linked, turn **Serve Media from Cloud** back on if you turned it off, and clear your page cache.
