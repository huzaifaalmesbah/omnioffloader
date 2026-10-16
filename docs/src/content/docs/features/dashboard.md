---
title: Dashboard
description: See your cloud provider, how much media is offloaded, and how much space is in the cloud, on your server and freed.
sidebar:
  order: 0
---

**OmniOffloader → Dashboard** is the first screen you see. It shows whether offloading is active, your media counts, and where your storage goes.

![The OmniOffloader Dashboard: provider status, media counts and quick actions](/images/screenshots/dashboard-marked.png)

## Status and media counts

- **Active / Safe mode / Setup Required:** whether new uploads are being offloaded, and to which provider.
- **Total media items**, **Offloaded to cloud** (with the share) and **Local only** (media not offloaded yet). Click a count to open the **Offload** screen.

## Storage

![The Storage card on the Dashboard: totals in the cloud, still on the server and freed (1), and a table by retention policy (2)](/images/screenshots/dashboard-storage-marked.png)

The **Storage** card shows, for your offloaded media:

- **In the cloud:** every offloaded file, the original and every image size.
- **Still on this server:** the files your [retention policy](/features/retention-policies/) keeps on your server. With **Retain Local Files** that is everything; with **Smart Local Cleanup**, the originals; with **Full Cloud Migration**, nothing.
- **Space freed:** what the retention policy removed from your server.

Below that, a table **by retention policy**: how many items follow each policy, and their bytes in the cloud, on the server and freed. Media keeps the policy it was offloaded (or last updated) with, so after you change the policy the table can show more than one row. [Apply the new policy to existing media](/features/retention-policies/) from the Tools screen.

The totals are kept up to date as your media changes, so the card shows at once, however large your library is.

:::note
Media uploaded before WordPress 6.0 has no recorded file size. It is counted as an item but not in the byte totals, and the card says how many such items there are.
:::
