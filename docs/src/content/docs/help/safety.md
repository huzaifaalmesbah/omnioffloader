---
title: How your media stays safe
description: The safety rules OmniOffloader follows, what deactivating and uninstalling do, and what data leaves your site.
sidebar:
  order: 4
---

Moving media between your server and the cloud should never lose a file. OmniOffloader follows these rules everywhere: in the admin screens, the Media Library, background jobs and WP-CLI.

## Safety rules

### A local file is deleted only after its upload is confirmed

When a [retention policy](/features/retention-policies/) removes local files, it does so only after the upload was confirmed in your bucket. When you apply a stricter policy to media you offloaded earlier, every local file is first checked in the cloud **by size**. A missing file is uploaded first. A file whose cloud copy differs is uploaded again and checked, and kept on the server if it still doesn't match.

### A cloud file is deleted only after Bring Back confirmed the local copy

[Bring Back](/features/bring-back/) keeps the cloud copy by default. Deleting it after the restore is an option you must turn on. Even then, the cloud file is only deleted after every expected file is back on the server.

### Restores never leave half-written files

Each download goes to a temporary file first and is moved into place only when it is complete. Restores never write outside your uploads folder, and paths containing `..` are refused. Partial downloads left behind by a stopped job are cleaned up.

### Every attachment always has a working copy

If something fails, the attachment keeps at least one valid copy, on the server or in the cloud, and its status stays truthful. The error is recorded on the attachment, so you can see what happened and retry.

### One bulk job at a time

Offload, Bring Back, retention and move jobs read and delete the same files, so only one runs at a time. This applies whether the job was started from the admin screens, the Media Library or [WP-CLI](/wp-cli/).

### Only administrators move media

Offloading and bringing back media is limited to administrators. Other users keep their normal WordPress rights: they upload and delete their own media.

### Access keys stay secret

Access keys are never shown in the browser or written to logs. Stored credentials are kept out of WordPress's autoloaded options. You can also keep them out of the database entirely with [wp-config.php constants](/providers/wp-config-credentials/).

### Copies of your site can't touch the live bucket

A staging, development or local copy of your site is recognised automatically and switches to [Safe mode](/features/safe-mode/). It never uploads to or deletes from the live site's cloud storage.

### Sites sharing a bucket stay in their own folder

Several sites can share one bucket and one CDN address, each in its own folder. A site never uploads to or deletes from a folder another site already uses. See [Sharing one bucket](/features/shared-bucket/).

### Deletes in WP-CLI need confirmation

Destructive WP-CLI options ask before they run, unless you pass `--yes`. Every command that changes something supports `--dry-run`.

## Deactivating the plugin

Deactivating stops any running bulk job and clears its queue, scheduled events and temporary data. Your settings and credentials are kept, so you can activate the plugin again later.

:::caution
While the plugin is inactive, media URLs are no longer rewritten to the cloud. If you used **Smart Local Cleanup** or **Full Cloud Migration**, some files exist only in the cloud, and they will show as broken images. **Run Bring Back before you deactivate** if you want to stop using the plugin.
:::

## Deleting (uninstalling) the plugin

By default, deleting the plugin removes nothing. Your settings, credentials and offload records stay in the database, in case you install it again.

To clean up, turn on **Settings → Uninstall → Remove all data on uninstall** before you delete the plugin. Then deleting the plugin removes:

- the plugin's settings and credentials,
- its background queues, temporary data and error logs,
- the offload records of your media, **unless** some media exists only in the cloud.

When any attachment's files exist only in the cloud (after Smart Local Cleanup or Full Cloud Migration), the offload records are kept. That way, you can install the plugin again and still bring that media back.

:::note
Uninstalling never touches your cloud files. Files in your bucket stay there until you delete them with your provider.
:::

## Privacy and external services

OmniOffloader connects only to the storage provider **you** choose and configure. Nothing is sent anywhere until you enter credentials.

- **Uploads:** your media files (originals, thumbnails and other generated sizes) and their file paths are uploaded when media is offloaded.
- **Downloads:** files are downloaded again when you use Bring Back or apply a looser retention policy.
- **Deletes:** files are deleted from the bucket when you delete an attachment with [Mirror Delete](/features/mirror-delete/) on, or restore with "delete from cloud" on.
- **Test Connection** sends one request to check that your bucket can be reached.

Every request is signed with your access key. The secret key itself is never transmitted. Visitors load offloaded files from the storage or CDN address you configured.

No analytics, tracking or other data is sent to the plugin author or anyone else.
