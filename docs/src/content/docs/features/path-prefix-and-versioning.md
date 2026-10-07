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

### Changing the prefix later

You can change the prefix at any time. Each attachment remembers the folder it was offloaded to, so:

- media already offloaded **stays in its folder and keeps loading** from there, with Bring Back, Mirror Delete and retention updates working as before, and
- only media offloaded **from now on** goes to the new folder.

Nothing breaks, but your media is then split across two folders. When media is offloaded, Settings says so under the field, and **Save changes** asks you to confirm the change.

[`wp omnioffloader status`](/wp-cli/status/) shows the current folder and how many offloaded attachments are in other folders.

#### Move existing media to the new folder

Run [`wp omnioffloader move`](/wp-cli/move/). It moves each attachment that is outside the current folder: it brings it back, offloads it again into the new folder, and then deletes the old copies. One attachment at a time, so even with Full Cloud Migration only one attachment's files need room on the server.

```bash
wp omnioffloader move --all --dry-run   # see what would move
wp omnioffloader move --all             # move it
```

For a large library, run it in the background:

```bash
nohup wp omnioffloader move --all --yes > ~/move.log 2>&1 &
```

How long it takes depends on your [retention policy](/features/retention-policies/): with **Retain Local Files** the files are already on the server, so only the upload to the new folder takes time. With **Smart Local Cleanup** or **Full Cloud Migration**, each attachment is downloaded first.

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
