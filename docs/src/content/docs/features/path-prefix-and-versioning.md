---
title: Path Prefix & Versioning
description: Store objects in a folder of your choice, and add a timestamp to object keys so CDN caches never serve stale files.
sidebar:
  order: 9
---

Two settings in **Settings → Behavior** control where your files are stored in the bucket.

![OmniOffloader Custom Path Prefix and Object Versioning Settings](/images/screenshots/shared-bucket-marked.png)

## Custom Path Prefix

By default, objects use the same path as in your WordPress uploads folder:

```
2026/09/photo.jpg
```

Turn on **Custom Path Prefix** and enter a folder name, for example `wp-media`, to store everything under that folder:

```
wp-media/2026/09/photo.jpg
```

With a custom domain, the file is then served from an address like `https://media.yourdomain.com/wp-media/2026/09/photo.jpg`.

Use a path prefix when:

- your bucket also holds other files, and you want to keep WordPress media in its own folder, or
- **several sites share one bucket**. Each site needs its own prefix. See [Sharing one bucket](/features/shared-bucket/).

:::note[Changing the prefix later]
Each attachment remembers the path it was offloaded to, so a new prefix would only apply to media offloaded after the change, and your media would end up split across two folders.

To prevent that, the prefix can't be changed while media is offloaded. Settings shows the steps instead:

1. Bring all media back to the server with [Bring Back](/features/bring-back/).
2. Change the Path Prefix and save.
3. Offload your media again. It goes to the new folder.

In [Safe mode](/features/safe-mode/) the prefix can still be changed, because a new folder is often the fix there: another site uses this folder, or a staging copy needs a folder of its own. Settings then shows that existing media stays in its current folder.

[`wp omnioffloader status`](/wp-cli/status/) shows the current folder and how many offloaded attachments are in other folders.
:::

## Object Versioning

**Object Versioning** adds a timestamp segment to object keys:

```
wp-media/2026/09/20260925143012/photo.jpg
```

Why this helps: CDNs cache files for a long time. If a file is replaced with a new version under the same URL, some visitors can keep seeing the old one. With versioning, a new upload gets a new key, and therefore a new URL. CDN caches never serve a stale file.

Object Versioning is **off** by default.

:::caution
OmniOffloader's **Object Versioning** is not the same as **bucket versioning** at your storage provider. Bucket versioning keeps older copies of overwritten or deleted objects, and is a good safety net for [Mirror Delete](/features/mirror-delete/). You turn it on in your provider's dashboard.
:::

## For developers

Change the object key for an attachment's main file with the `omnioffloader_attachment_key` filter. See [Hooks](/developers/hooks/).
