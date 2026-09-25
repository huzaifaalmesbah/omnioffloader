---
title: Automatic Offload
description: Every new upload is copied to your cloud storage in the background, with all of its sizes.
sidebar:
  order: 1
---

Once a storage provider is connected, OmniOffloader offloads every new upload for you. There is nothing to click.

## What gets uploaded

For each attachment, OmniOffloader uploads every file that belongs to it:

- the **original** file,
- every **thumbnail** and other generated image size,
- the **`-scaled`** version WordPress creates for very large images,
- modern **WebP / AVIF** sources created by image-format plugins.

An attachment only counts as offloaded when **every** one of its files uploaded and its main file is confirmed in your bucket.

## Background uploads

The **Background Cloud Offload** setting (in **Settings → Behavior**) is on by default. Your upload finishes right away, and the cloud transfer happens in the background a moment later. The upload screen never waits for the cloud.

While it waits, the Media Library shows the attachment as **Queued for background offload**. Once it is in the cloud, the status changes to **Offloaded**. See [Media Library](/features/media-library/).

If you turn Background Cloud Offload off, the file is uploaded to the cloud during the upload request itself. This makes each upload slower, so keep it on unless you have a reason not to.

## What happens to the local files

After the upload is confirmed in the bucket, your [retention policy](/features/retention-policies/) decides which local files stay on the server:

- **Retain Local Files** (default) keeps everything.
- **Smart Local Cleanup** deletes the generated thumbnails and keeps the original.
- **Full Cloud Migration** deletes all local copies.

:::note
A local file is **never** deleted before its upload has been confirmed in your bucket.
:::

## Large files

Files above **64 MB** are uploaded in parts (multipart upload). If the connection drops, only one part is lost, not the whole transfer. Files larger than **5 GB** are supported.

If your host's time limit still cuts a transfer short, the attachment is reported with an explanation and the job moves on. It is not retried forever.

## Existing media

Automatic offload only handles **new** uploads. To move media that is already in your library, use [Bulk Offload](/features/bulk-offload/) or [`wp omnioffloader offload`](/wp-cli/offload/).

## Staging and local copies

On a copy of your site (staging, development or local), nothing is uploaded. New uploads stay on that copy's own server. See [Safe mode](/features/safe-mode/).

## For developers

- Skip specific attachments with the `omnioffloader_should_offload_attachment` filter.
- Turn background uploads on or off per attachment with `omnioffloader_use_async_upload`.
- Change the multipart threshold with `omnioffloader_multipart_threshold`.

See [Hooks](/developers/hooks/) for details.
