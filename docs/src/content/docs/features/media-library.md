---
title: Media Library
description: See the offload status of every attachment and offload or bring back media right from the Media Library.
sidebar:
  order: 7
---

OmniOffloader adds offload status and actions to the WordPress Media Library.

![OmniOffloader WordPress Media Library Integration](/images/screenshots/media-library-marked.png)

## Status column (list view)

The list view gets an **Offload status** column with a small icon:

- **Offloaded**: the attachment is in the cloud. Hover to see the provider.
- **Offloading…** / **Restoring…**: a transfer is running.
- **Queued…**: waiting for the background upload.
- **Offload failed**: hover to see the reason.
- **File missing**: the attachment's file isn't on this server, so it can't be offloaded. Restore the file; the next Bulk Offload picks it up. See [Files missing on this server](/features/bulk-offload/#files-missing-on-this-server).
- **Not offloaded**: the attachment is only on your server.

The status updates live while a job runs. You do not need to reload the page.

## Grid view badges

In grid view, the attachment details show the same live status.

## Filter by offload state

Use the filter dropdown above the list to show **Offloaded** or **Local only** attachments.

## OmniOffloader box on the edit screen

When you edit an attachment, an **OmniOffloader** box shows:

- **Provider:** where the attachment is stored,
- **Bucket:** the bucket it is in (administrators only),
- **Folder:** the folder in the bucket, for example `shop/2026/09/` (administrators only), with *Not in the current folder* when the [Path Prefix](/features/path-prefix-and-versioning/#changing-the-prefix-later) changed after it was offloaded,
- **Offloaded on:** the date and time,
- **Local Storage:** what is still on the server, for example *Preserved on server (All files)*, *Original kept (Thumbnails cleaned)* or *Removed from server (Cloud only)*,

plus one-click **Offload now**, **Retry Offload** or **Bring Back** buttons.

## Row actions

Under each attachment title in list view:

- **Offload now**: upload this attachment to the cloud.
- **Retry Offload**: try again after a failure.
- **Bring Back**: restore this attachment to your server.

While a transfer runs, the action shows **Offloading…**, **Restoring…** or **Queued…** instead.

## Bulk actions

Select several attachments, then choose from the **Bulk actions** menu:

- **Offload to Cloud**
- **Bring Back to Server**

You can select up to 1,000 attachments at once. For more, use [Bulk Offload](/features/bulk-offload/), [Bring Back](/features/bring-back/) or [WP-CLI](/wp-cli/).

:::note
If another OmniOffloader job is already running, the action is refused with a notice: *"Another OmniOffloader job is running. Wait for it to finish, then try again."*
:::

## Who can use these actions

Offload and Bring Back are **for administrators only** (users who can manage options). This applies to row actions, the edit screen buttons, bulk actions and the REST API.

Other users keep their normal WordPress rights. They upload and delete their own media. With [Mirror Delete](/features/mirror-delete/) on, deleting an attachment also removes its cloud copy.

## On a staging or local copy

In [Safe mode](/features/safe-mode/), the offload links and the **Offload to Cloud** bulk action are hidden. Bring Back is hidden too, unless you allowed it on a copy in Settings.
