---
title: Compatibility
description: Requirements, hosting, caching plugins, multisite, page builders and multilingual sites.
sidebar:
  order: 3
---

## Requirements

| | Minimum |
| --- | --- |
| WordPress | 6.6 (tested up to 7.1) |
| PHP | 8.1 |
| Storage | An Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 or Wasabi bucket, or any S3-compatible storage |

Your server must be able to make outgoing HTTPS requests to your storage provider. WP-CLI is optional.

## Hosting

OmniOffloader is built to run on a small shared hosting plan as well as on a VPS or dedicated server.

- **One PHP process, low memory.** Bulk jobs run in the background, one short pass at a time. A faster Transfer Speed moves several files side by side in the same process instead of starting extra workers.
- **You choose the pace.** **Low** for small shared plans, **Balanced** (the default) for most sites, **Fast** or **Custom** for a VPS or dedicated server. See [Transfer Speed](/features/transfer-speed/).
- **Time limits.** Background passes adjust to your host's `max_execution_time`. A file cut short by a time limit is reported and skipped instead of retried forever.
- **Blocked loopback requests.** If your host blocks WordPress's internal background requests, the open admin screen keeps the job moving.
- **Storage quotas.** On cPanel, Bring Back reads your plan's real space left. See [Troubleshooting](/help/troubleshooting/#bring-back-stops-with-out-of-disk-space).

## Caching plugins and object caches

- **Page caches:** the plugin's own REST requests tell page caches not to store them, including **LiteSpeed Cache** and **Breeze**. Progress bars stay live.
- **Object caches:** bulk progress stays live under **Redis** and **Memcached**.
- **Front end:** the plugin only rewrites URLs. It adds no database queries per image. Media blocks on a page are loaded in one batch before they render.

## Content and page builders

Media URLs are rewritten as pages render, without editing your content:

- Image, gallery, video, audio, file, cover and media-text blocks.
- Classic Editor media tags (`<img>`, `<video>`, `<audio>`, `<source>`) and audio/video shortcodes.
- Featured images, attachment URLs and responsive `srcset` attributes.
- Modern image formats: WebP/AVIF sources and the `-scaled` version are offloaded with the original.

Page builders and themes that use WordPress's standard image functions get cloud URLs automatically. A third-party block that stores an attachment ID in an `id` or `mediaId` attribute can be added with the [`omnioffloader_media_block_names`](/developers/hooks/#omnioffloader_media_block_names) filter.

## Multilingual sites

Safe mode and the shared-bucket check identify your site by its saved **Site Address**. Multilingual plugins that use a different domain per language, such as **WPML** and **Polylang**, are never mistaken for a copy of the site or for another site.

## Multisite

Supported per site. Each site in a network has its own settings, credentials and background queues.

Only a network administrator can point a provider at a private or local endpoint address (for example a MinIO server on the internal network). Site administrators must use a public endpoint, so a sub-site can't make the server send requests into its own network.

:::note
Multisite has not had a dedicated test pass yet. Treat large networks as unverified for now, and test on a staging network first.
:::

## Migration and backup plugins

When a backup or migration plugin, or your host's staging tool, makes a copy of your site, the copy is recognised automatically and runs in [Safe mode](/features/safe-mode/). It never uploads to or deletes from the live site's cloud storage.
