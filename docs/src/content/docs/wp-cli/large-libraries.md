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

Can't install a copy tool? Let WP-CLI upload more files at once instead. It still uploads every file through PHP, so it is slower than copying and linking, but on a VPS with good bandwidth it is usually faster than the default:

```bash
nohup wp omnioffloader offload --all --concurrency=32 > offload.log 2>&1 &
```

See [`--concurrency`](/wp-cli/offload/).

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

#### 1. Install it

On a VPS with root or sudo:

```bash
curl https://rclone.org/install.sh | sudo bash
rclone version
```

This installs the latest version for your server's CPU. Package managers (`apt install rclone`) often ship an old one.

Without root (shared or cPanel hosting with SSH), put the program in your home folder:

```bash
cd ~
curl -O https://downloads.rclone.org/rclone-current-linux-amd64.zip
unzip rclone-current-linux-amd64.zip
mkdir -p ~/bin
cp rclone-*-linux-amd64/rclone ~/bin/
chmod 755 ~/bin/rclone
~/bin/rclone version
```

If `uname -m` shows `aarch64`, download `rclone-current-linux-arm64.zip` instead. Some shared hosts don't allow running your own programs or long jobs. There, use [`--concurrency`](/wp-cli/offload/) instead, or run rclone on your own computer with an SFTP remote as the source (`rclone copy sftp:path/to/wp-content/uploads r2:BUCKET …`); the files then pass through your computer.

#### 2. Connect it to your bucket

The quickest way: let OmniOffloader write the remote from its own settings, with your keys, endpoint and this site's folder:

```bash
wp omnioffloader rclone --write-config
```

It saves a remote named `omnioffloader` to rclone's config file and prints the copy and link commands for this site. See [`wp omnioffloader rclone`](/wp-cli/rclone/). Then skip to step 3, using `omnioffloader:` where the examples below say `r2:`.

To set it up by hand instead, use the same keys, endpoint and bucket as in OmniOffloader. Either run `rclone config` and answer the questions (new remote named `r2`, storage **Amazon S3 compatible**, then your provider, for example **Cloudflare R2**, your keys and endpoint, defaults for the rest), or write the config file `~/.config/rclone/rclone.conf` yourself. For Cloudflare R2:

```ini
[r2]
type = s3
provider = Cloudflare
access_key_id = YOUR_ACCESS_KEY_ID
secret_access_key = YOUR_SECRET_ACCESS_KEY
endpoint = https://ACCOUNT_ID.r2.cloudflarestorage.com
no_check_bucket = true
```

For another provider, set `provider` to `AWS`, `DigitalOcean`, `Wasabi` or `Other`, and add `region` where your provider needs one. The file holds your keys, so protect it:

```bash
chmod 600 ~/.config/rclone/rclone.conf
```

Check the connection:

```bash
rclone ls r2:BUCKET | head
```

`rclone lsd r2:` lists your buckets, but answers "Access Denied" when your API token is limited to one bucket. That is normal.

#### 3. Copy your media

From your WordPress folder (the one that contains `wp-content`), first do a dry run, which copies nothing:

```bash
rclone copy wp-content/uploads r2:BUCKET/PREFIX \
  --include "/[0-9][0-9][0-9][0-9]/**" \
  --transfers 32 --checkers 32 --dry-run
```

Then run it for real. Started with `nohup`, it keeps going after you close the terminal:

```bash
nohup rclone copy wp-content/uploads r2:BUCKET/PREFIX \
  --include "/[0-9][0-9][0-9][0-9]/**" \
  --transfers 32 --checkers 32 \
  --stats 1m --log-file rclone.log > /dev/null 2>&1 &

tail -f rclone.log
```

- Leave out `/PREFIX` if you don't use a Path Prefix.
- The `--include` line copies only the year/month folders, so logs and backups stay out of the bucket.
- If the copy stops, run the same command again: it skips files that are already copied.
- Always use `rclone copy`, never `rclone sync`. `sync` deletes files in the bucket that are not on your server, including the folder's ownership marker and, in a shared bucket, other sites' files.
- `tail -f` only shows progress; Ctrl+C stops watching, not the copy.

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
