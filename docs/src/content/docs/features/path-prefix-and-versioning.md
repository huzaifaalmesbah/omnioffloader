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

Nothing breaks, but your media is then split across two folders. When media is offloaded, Settings says so under the field, and **Save changes** asks you to confirm the change. Once saved, Settings shows how many attachments are outside the current folder, with the WP-CLI commands to move them (recommended) and a **Move media in Tools** button.

![Settings after a Path Prefix change: media outside the current folder, the recommended WP-CLI move commands with copy buttons, and a Move media in Tools button](/images/screenshots/path-prefix-move-marked.png)

[`wp omnioffloader status`](/wp-cli/status/) shows the current folder and how many offloaded attachments are in other folders.

#### Move existing media to the new folder

Go to **OmniOffloader → Tools → Move Media to the Current Folder**, or run [`wp omnioffloader move`](/wp-cli/move/). The card only appears when some media is outside the current folder. It shows how many attachments are in other folders, and **Move Media** starts the move with a progress bar and a Cancel button, like Bulk Offload.

![Tools → Move Media to the Current Folder: the current folder, media in other folders, a custom batch option and the Move Media button](/images/screenshots/move-media-marked.png)

Within the same bucket, your storage provider copies each attachment's files into the new folder itself: nothing is downloaded, and the files on your server are not touched. Each old copy is deleted once its new copy is confirmed. A file that can't be copied that way (larger than 5 GB) is moved through your server instead.

From WP-CLI:

```bash
wp omnioffloader move --all --dry-run   # see what would move
wp omnioffloader move --all             # move it
```

For a large library, run it in the background so it keeps going after you close the terminal: see [`wp omnioffloader move`](/wp-cli/move/) and [Long runs and log files](/wp-cli/long-runs/).

Within the same bucket it is quick and needs no disk space on your server, whatever your [retention policy](/features/retention-policies/).

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
