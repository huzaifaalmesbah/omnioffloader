---
title: FAQ
description: Short answers to the most common questions about OmniOffloader.
sidebar:
  order: 1
---

## Getting started

### Which cloud storage providers are supported?

Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and Wasabi each have a dedicated setup. Anything else that speaks the S3 API (MinIO, Storj, OVHcloud, Linode Object Storage, Scaleway and others) works through **Any S3-Compatible Storage**, where you enter the endpoint and region yourself. See [Storage providers](/providers/).

### Why do I need a custom domain or CDN URL?

Most buckets don't serve files publicly by default, and Cloudflare R2 has no public URL at all until you connect one. A custom domain or CDN hostname pointed at your bucket makes your media reachable, and lets it be cached at the edge.

### Can I offload now and switch to the CDN later?

Yes. With **Retain Local Files**, turn off **Serve Media from Cloud** in **Settings → Retention Policy**. Media is still offloaded, but your site keeps loading it from your own server. Turn it on when your CDN is ready and every offloaded file switches over at once. See [Offload now, serve from the CDN later](/features/url-rewriting/#offload-now-serve-from-the-cdn-later).

### Can I keep my access keys out of the database?

Yes. Define them as constants in `wp-config.php`. The settings screen then shows those fields as locked. See [Credentials in wp-config.php](/providers/wp-config-credentials/).

## Offloading

### Will offloading break my existing image URLs?

No. URLs are rewritten as pages render, so posts, pages, featured images and responsive `srcset` attributes keep working. Nothing in your post content is edited, and no database search-replace is needed.

### Do I have to delete my local files?

No. The default policy, **Retain Local Files**, deletes nothing. The cloud becomes a second copy. See [Retention policies](/features/retention-policies/).

### I changed the retention policy. Why are old files still on my server?

A new policy only affects new uploads at first. Apply it to media you offloaded earlier from **OmniOffloader → Tools → Apply Retention Policy to Existing Media**, or with [`wp omnioffloader retention`](/wp-cli/retention/).

### How long does a bulk offload take? Can I close the browser tab?

It depends on how quickly your server reaches your storage provider, how large your files are, and how many thumbnail sizes your theme creates. A higher [Transfer Speed](/features/transfer-speed/) finishes sooner on a server that can handle it.

Jobs run in the background and keep going after you close the tab. On hosts that block WordPress's internal loopback requests, the open admin screen drives the job instead. There, leave the Offload page open until it finishes, or use [WP-CLI](/wp-cli/).

### What about very large videos or files?

Files above 64 MB are uploaded in parts, so a dropped connection costs one part instead of the whole transfer. Files larger than 5 GB are supported. If your host's time limit still cuts a transfer short, that attachment is reported with an explanation and the job moves on.

### Who can offload or bring back media?

Only administrators (users with the `manage_options` capability). Other users keep their normal WordPress rights: they upload and delete their own media. With [Mirror Delete](/features/mirror-delete/) on, deleting an attachment also removes its cloud copy.

## Bring Back and removal

### Can I move my media back to my server later?

Yes, that is what [Bring Back](/features/bring-back/) is for. It downloads your media to its original paths, checks that every file arrived, and then serves everything locally again. The cloud copy is kept unless you choose to delete it.

### What happens to my images if I deactivate the plugin?

It depends on the retention policy you used:

- **Retain Local Files:** every file is still on your server. Nothing changes.
- **Smart Local Cleanup** or **Full Cloud Migration:** some or all local files were deleted after upload. Without the plugin, URLs point at files that are no longer on disk.

:::caution
If you used a cleanup policy, **run Bring Back before you deactivate** the plugin.
:::

## Staging and multiple sites

### I copied my live site to staging or a local dev site. Can it damage the live site's media?

No. OmniOffloader recognises a copy and switches to Safe mode: nothing is uploaded to or deleted from your cloud storage. Media already in the cloud keeps loading. See [Safe mode](/features/safe-mode/).

### Can several separate sites share one bucket and one CDN address?

Yes. Give each site its own folder with **Settings → Custom Path Prefix**. A site never uploads into or deletes from a folder another site already uses. See [Sharing one bucket](/features/shared-bucket/).

### Does it work on multisite?

Yes, per site. Each site in a network has its own settings, credentials and background queues. It has not had a dedicated multisite test pass yet, so treat large networks as unverified for now. See [Compatibility](/help/compatibility/).

## Privacy

### Does the plugin send any data to its author?

No. OmniOffloader only connects to the storage provider you configure. There is no analytics or tracking. See [How your media stays safe](/help/safety/#privacy-and-external-services).
