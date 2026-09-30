---
title: Serving Media from Your CDN
description: How OmniOffloader rewrites media URLs as pages render, without editing your content or database.
sidebar:
  order: 6
---

After an attachment is offloaded, your site loads it from the cloud: from your **custom domain or CDN**, or directly from the bucket. You do not need to change any links yourself.

## How it works

URLs are rewritten **as pages render**. Nothing in your post content is edited, and no database search-replace is needed. That means:

- Posts, pages and page builders keep working exactly as before.
- Turning the plugin off, or using [Bring Back](/features/bring-back/), returns URLs to local paths without any cleanup in the database.

## What is rewritten

- **Attachment URLs** everywhere WordPress builds them: featured images, galleries, theme templates and plugins that use the standard WordPress functions.
- **Responsive images**: every URL in `srcset`.
- **Block editor media blocks**: Image, Gallery, Video, Audio, File, Cover and Media & Text.
- **Classic Editor media**: `<img>`, `<video>`, `<audio>` and `<source>` tags in post content.
- **Audio and video shortcodes**: `[audio]` and `[video]`.

Only offloaded attachments are rewritten. Media that is still local keeps its local URL.

## Offload now, serve from the CDN later

**Serve Media from Cloud** (in **Settings → Retention Policy**) is on by default. With the **Retain Local Files** policy you can turn it off: media is still offloaded to your bucket, but your site keeps loading every file from your own server, and the cloud is a backup copy. This is useful while your CDN or custom domain is not ready yet.

When you are ready, turn it on and save. Every offloaded file switches to your cloud storage or CDN right away. Nothing is uploaded again and no content is edited.

- **Smart Local Cleanup** and **Full Cloud Migration** delete files from your server, so with those policies the setting is hidden and media always loads from the cloud.
- Switched to Retain Local Files after media was cleaned up? Files that are still missing from your server keep loading from the cloud, so nothing breaks. Apply the policy from **Tools** to download them again, and they load from your server too.
- Clear your page cache after changing the setting, so cached pages pick up the new URLs.

## Custom domain / CDN URL

Each provider has a **Custom Domain / CDN URL** field in Settings. Enter the address that serves your bucket, for example `https://media.yourdomain.com`.

Why it matters:

- Most buckets do not serve files publicly by default. **Cloudflare R2** has no public URL at all until you connect a custom domain or turn on its `r2.dev` URL.
- A CDN hostname lets files be cached at the edge, closer to your visitors.

For some providers the field is optional. If you leave it empty, media is served directly from the bucket's own address. See the setup guide for [your provider](/providers/).

:::tip
Changed your CDN domain? Update the Custom Domain field and save. Because URLs are rewritten on the fly, every page uses the new domain right away.
:::

## Front-end performance

On the front end, OmniOffloader only rewrites URLs. It does not make remote calls, and it does not write to the database.

- The attachments used by media blocks are loaded **in one batch** before the blocks render, so a page costs no extra queries per image.
- Only media blocks are looked up. Other blocks, such as navigation menus, cost nothing.
- Video and audio links in content are resolved with **one query per post** and cached for the rest of the page.

## Stale files after re-uploads

If you replace files and your CDN keeps serving the old version, turn on **Object Versioning**. It adds a timestamp segment to object keys, so a new upload always has a new URL. See [Path Prefix & Versioning](/features/path-prefix-and-versioning/).

## For developers

Register third-party media blocks with the `omnioffloader_media_block_names` filter so their URLs are rewritten too. See [Hooks](/developers/hooks/).
