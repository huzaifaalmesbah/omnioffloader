---
title: Troubleshooting
description: Fix common problems with connections, broken images, slow or stopped jobs, disk space and Safe mode.
sidebar:
  order: 2
---

## Where to find error messages

Start here. OmniOffloader records every failure with the reason:

- **Media Library:** a failed attachment shows **Failed — …** (or **Offload failed: …** in the status column) with the reason, and a **Retry Offload** link.
- **Attachment edit screen:** the **OmniOffloader** box shows the status, provider, bucket and the last error.
- **Offload, Bring Back and Tools screens:** the activity log lists each attachment of the current run. When a run stops, the screen shows why.
- **WP-CLI:** failed runs print the reason and exit with code `1`. Run [`wp omnioffloader status`](/wp-cli/status/) for a quick health check.
- **PHP error log:** provider errors are also written there when `WP_DEBUG` is on. To log them on a live site without `WP_DEBUG`, use the [`omnioffloader_log_provider_errors`](/developers/hooks/#omnioffloader_log_provider_errors) filter.

## Test Connection fails

**Test Connection** asks the bucket for a response. When it fails, the message after **Connection failed:** comes from your provider. Check:

1. **Bucket name.** It must match exactly, including upper and lower case.
2. **Keys.** Copy the Access Key ID and Secret Access Key again. Look for spaces at the start or end.
3. **Permissions.** The key needs read **and** write access to the bucket. For example: an R2 token with **Object Read & Write**, a Backblaze key with **Read and Write**.
4. **Endpoint and region.** For R2, Backblaze B2, DigitalOcean Spaces and S3-compatible storage, the endpoint must be the S3 API endpoint of your bucket's region. The region must match it (for example `us-east-005` with `https://s3.us-east-005.backblazeb2.com`).
5. **Path-style.** Some S3-compatible services (for example Storj or MinIO) need **Use Path-Style Endpoint** turned on.
6. **Firewall.** Your server must be able to make outgoing HTTPS requests to the provider. Some hosts block this; ask your host.

If fields are locked, their values come from `wp-config.php` constants. Fix them there. See [Credentials in wp-config.php](/providers/wp-config-credentials/).

Test Connection can also show **Connected** with a warning below it. Read the warning. It usually means the bucket folder is used by another site. See [Folder used by another site](#folder-used-by-another-site).

## Images don't load after offloading

The upload worked, but visitors can't reach the file. The bucket or the CDN URL is not public:

1. Open an image's address (right-click → open image in a new tab) and look at the error.
2. Make sure you entered a **Custom Domain / CDN URL** that points at the bucket. Cloudflare R2 has no public URL until you connect a custom domain or turn on the `r2.dev` URL.
3. Make sure the files are publicly readable: a public bucket (Backblaze B2 "Public"), a bucket policy that allows public reads (Amazon S3, Wasabi), or a CDN in front of the bucket.
4. If you use a [Custom Path Prefix](/features/shared-bucket/), the URL includes that folder. Check that your CDN serves it.

Your provider's page under [Storage providers](/providers/) has the exact steps.

:::tip
While you test, keep the retention policy on **Retain Local Files**. All files stay on your server, so nothing is lost if the public URL is not right yet.
:::

## A bulk job is slow or seems stuck

- **Loopback requests blocked.** Bulk jobs run in the background through WordPress's internal requests. Some hosts block them. Then the open admin screen keeps the job moving instead: leave the Offload (or Bring Back) page open until it finishes.
- **Use WP-CLI.** For large libraries, or on hosts that time out long requests, [WP-CLI](/wp-cli/) is the most reliable option.
- **Transfer Speed.** On a VPS or dedicated server, a faster [Transfer Speed](/features/transfer-speed/) finishes sooner. On small shared plans, **Low** is the safest choice.
- **Large files.** One very large video can take a while. The job keeps its lock while bytes are moving, so it is not stuck.
- **A file cut short.** If your host's time limit stops a transfer, that attachment is reported and skipped, and the job moves on. It is not retried forever.

Only one job (offload, Bring Back, retention or move) runs at a time. If a job will not start, check whether another one is running, possibly from WP-CLI.

## A bulk job stopped after several failures

After 10 failures in a row, the job checks the connection to your storage provider:

- **The provider doesn't answer:** the job stops and shows the provider's error. Fix the connection (see [Test Connection fails](#test-connection-fails)), then start the job again.
- **The provider answers:** the failures come from the attachments themselves, for example files missing on the server. The job keeps going and the errors stay on those attachments. It still stops after 100 failures in a row.

When you start again, attachments that failed before are processed last, so they don't block the rest.

## Bring Back stops with "out of disk space"

Bring Back checks free space before it downloads. It stops with a clear message instead of filling the disk. The attachments that were not brought back stay in the cloud, and the next run continues where it stopped.

On shared hosting with a storage quota (for example cPanel or CloudLinux), PHP sees the whole server disk, not your plan's limit. OmniOffloader reads cPanel's own quota record and shows the space left in your plan on the Bring Back screen. When the server refuses to save a file ("Disk quota exceeded", "No space left on device"), the job stops right away.

To fix it: free up space or raise your plan's quota, then run Bring Back again. You can also restore in smaller batches with a quantity limit. On other hosts, a developer can report the real free space with the [`omnioffloader_free_disk_space`](/developers/hooks/#omnioffloader_free_disk_space) filter.

## Safe mode is on, but this is my live site

A banner explains why Safe mode is on. Common reasons:

- **The site address changed** (for example you moved to a new domain). If this really is the live site, click **This is the live site (it moved to a new address)** in the banner.
- **`WP_ENVIRONMENT_TYPE` is not `production`.** Some hosts set it on the live site too. Remove or change it, or define `OMNIOFFLOADER_SAFE_MODE` as `false`.
- **The site runs on a local address** such as `localhost`, `*.local` or `*.test`.

You can also turn off **Protect the live site when this is a copy** in Settings. See [Safe mode](/features/safe-mode/) and [wp-config.php constants](/developers/constants/#omnioffloader_safe_mode).

## Folder used by another site

Another site already stores its media in the same bucket folder. To protect both sites, this site switches to Safe mode and doesn't upload or delete anything.

- **It's a different site:** click **Choose a Path Prefix** and give this site its own folder.
- **It's really this site** (for example after a domain change): click **This folder belongs to this site**.

See [Sharing one bucket](/features/shared-bucket/).

## Media Library badges stay on "Queued"

A badge that stays on **Queued** or **Offloading…** after a job was cancelled or stopped is cleared automatically. Reload the Media Library. If an item shows **Failed — …**, click **Retry Offload**.

## Still stuck?

Collect this information and ask on the [plugin's support forum](https://wordpress.org/support/plugin/omnioffloader/):

- The output of `wp omnioffloader status` (it never shows your keys), or a screenshot of the Dashboard.
- The exact error message from the Media Library, the activity log or WP-CLI.
- Your provider, your hosting type (shared, VPS, managed) and your WordPress and PHP versions.

:::danger
Never post your Access Key ID or Secret Access Key in a support request.
:::
