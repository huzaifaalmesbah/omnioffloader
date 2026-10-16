---
title: Failed Items
description: See which media failed to offload, bring back or move, read the error, and retry failed offloads in one click.
sidebar:
  order: 12
---

When an offload, Bring Back, retention update or move fails for an attachment, OmniOffloader keeps the error with that attachment. Every failed item still has a working copy: nothing is deleted from the server before its cloud copy is confirmed, and nothing is deleted from the cloud before its local copy is.

## On the Tools screen

**OmniOffloader → Tools** shows a **Failed Items** card while any media has an error:

- **Failed Offloads:** attachments whose last offload failed. They are still on your server only.
- **Other Errors:** attachments that are in the cloud, with an error from a Bring Back, retention update or move.
- The list, newest first, with each attachment's last error and when it happened. Click a title to open the attachment.

**Retry Failed Offloads** queues every failed offload again as a [Bulk Offload](/features/bulk-offload/) of just those attachments. Follow it on the **Offload** screen. Fix the cause first (for example the connection, or a file that is missing on the server), or the same items fail again.

The button is off while another job runs, from the admin screen or WP-CLI, since only one job runs at a time. It is also off in [Safe mode](/features/safe-mode/), since a retry uploads to your cloud storage.

## In the Media Library

In **Media → Library** (list view), choose **Offload failed** in the filter dropdown above the list. It shows the same failed offloads. Select them and choose **Offload** in the **Bulk actions** menu to retry only some of them.

An attachment whose file is missing on the server is not in this list: it shows **File missing on this server** instead, and is picked up again once the file is back.

## From WP-CLI

```bash
wp omnioffloader errors
wp omnioffloader errors --retry
```

[`wp omnioffloader errors`](/wp-cli/errors/) lists the same items, retries failed offloads, and clears error logs once you have fixed the cause.

## Common errors

| Error | Meaning |
| --- | --- |
| **Main file is missing locally.** | The attachment's file is no longer on the server, so there is nothing to upload. Restore the file from a backup, or delete the attachment if nothing uses it. |
| **Upload failed for "…"** | The upload itself failed (network, storage provider). Retry once the connection works. |

A successful offload or Bring Back clears the attachment's error log.
