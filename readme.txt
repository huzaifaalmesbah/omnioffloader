=== OmniOffloader — Multi-Cloud Media Offload & CDN ===
Contributors: huzaifaalmesbah
Tags: offload, cloud storage, cdn, media library, s3
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload media to Amazon S3, Cloudflare R2, Wasabi or any S3 storage, serve it from a CDN, and restore it anytime with Bring Back.

== Description ==

Your media library is probably the biggest thing on your server. OmniOffloader moves it to cloud object storage, serves it from your own domain or CDN, and frees the disk space it was using — without changing a single URL in your content.

Every upload is copied to the cloud automatically: the original, every generated thumbnail, the `-scaled` version and modern WebP/AVIF sources. Image URLs and `srcset` attributes are rewritten as pages render, so your posts, pages and page builders keep working exactly as before, and nothing in your database is search-replaced.

And because offloading should never be a one-way door, **Bring Back** downloads everything from the cloud to your server again — one file or the whole library — and hands your URLs back to local paths.

**Documentation:** step-by-step provider guides, every feature, WP-CLI and developer hooks at [omnioffloader.vercel.app](https://omnioffloader.vercel.app/).

= Supported cloud storage providers =

* **Amazon S3**
* **Cloudflare R2**
* **DigitalOcean Spaces**
* **Backblaze B2**
* **Wasabi**
* **Any S3-compatible storage** — MinIO, Storj, OVHcloud, Linode, Scaleway and others, through the generic option where you supply your own endpoint and region, and give it your own display name.

= Key features =

* **Automatic offloading** — new uploads go to the cloud in the background, so the upload screen never waits on a cloud transfer.
* **Bulk offload for existing media** — process the whole library or a set number of attachments (5, 10, 25, 50, 100 or any number), with a live progress bar, activity log and a Cancel button.
* **Large libraries, much faster** — `wp omnioffloader offload --all --concurrency=32` (up to 64) uploads many files at the same time from WP-CLI on a VPS or dedicated server.
* **Adjustable transfer speed** — offload your existing, not-yet-offloaded media library and bring it back faster by moving 1, 3 or 6 files at the same time, or your own number from 1 to 10. Every setting runs inside a single PHP process, so faster never means more server processes.
* **Content URL rewriting** — images, galleries, video, audio, file, cover and media-text blocks, Classic Editor media tags and audio/video shortcodes are all served from the cloud, without editing post content.
* **Serve from your CDN** — media is delivered from your custom domain or CDN edge, not your origin server. Not ready yet? Turn off **Serve Media from Cloud** to offload now and keep loading media from your server, then switch over with one click.
* **Three retention policies** — keep every local copy, delete only generated thumbnails (Smart Local Cleanup), or move everything to the cloud (Full Cloud Migration).
* **Apply a new policy to old media** — change the retention policy later and apply it to media you already offloaded from the Tools screen. Every file is checked in the cloud before its local copy is deleted.
* **Bring Back** — restore media from the cloud to your server, per file or in bulk, with a disk-space preflight so you know what it needs before it starts. Deleting the cloud copy afterwards is optional and off by default.
* **Media Library integration** — a status column, grid view badges, a meta box on the edit screen, row actions and bulk actions for Offload and Bring Back.
* **Mirror Delete** — deleting an attachment in WordPress removes its cloud copies too (on by default for new installs; never on a staging or dev copy).
* **Object versioning and path prefix** — add a timestamp segment to object keys so CDN caches never serve a stale file, and store objects under a folder of your choice.
* **WP-CLI** — offload, restore, apply retention and check status from the terminal. Long jobs keep running after you close the terminal when started with `nohup`, and `wp omnioffloader stop` ends them from anywhere. A full command reference is on the Tools screen.
* **Credentials in wp-config.php** — define keys as PHP constants instead of storing them in the database; the settings screen locks those fields.

= Built for every host — shared plans to dedicated servers =

OmniOffloader is designed to run on a small shared hosting plan just as well as on a VPS.

* **One PHP process, low memory.** Bulk jobs run in the background, one short pass at a time. Faster transfer speeds upload several files side by side over the same connection instead of starting extra workers.
* **You choose the pace.** Choose **Low** on a small shared plan to upload one file at a time, **Balanced** (the default) for most sites, or **Fast** / **Custom** on a VPS or dedicated server. WP-CLI uses the same setting.
* **Large files in parts.** Files above 64 MB are uploaded in chunks, so a dropped connection costs one chunk instead of the whole transfer, and files beyond 5 GB are supported.
* **No stuck jobs.** A file that your host's time limit cuts short is reported and skipped instead of retried forever, and a broken connection stops the run after 10 failures in a row with an explanation.
* **Works where loopback requests are blocked.** If your host blocks WordPress's internal background requests, the open admin page keeps the job moving instead.
* **Caching-friendly.** Progress stays live under LiteSpeed Cache, Breeze, Redis and Memcached.

= Safe by design =

* A local file is never deleted until its upload is confirmed in your bucket.
* A cloud file is never deleted until Bring Back has confirmed the local copy — and only if you turned that option on.
* Restores write to a temporary file first and move it into place, and never write outside your uploads folder.
* Only one bulk job (offload, Bring Back or retention) runs at a time, whether it was started from the admin screens, the Media Library or WP-CLI.
* Moving media between your server and the cloud is limited to administrators. Other users keep their normal rights: they upload and delete their own media.
* Access keys are never shown in the browser or written to logs.
* Safe mode for staging, development and local copies: a copy of your site never uploads to or deletes from the live site's cloud storage.
* Several sites can share one bucket and one CDN address, each in its own folder. A site never uploads to or deletes from a folder another site already uses.

= For developers =

Filters: `omnioffloader_cloud_providers`, `omnioffloader_object_acl`, `omnioffloader_attachment_key`, `omnioffloader_attachment_delete_keys`, `omnioffloader_should_offload_attachment`, `omnioffloader_use_async_upload`, `omnioffloader_should_apply_retention`, `omnioffloader_media_block_names`, `omnioffloader_log_provider_errors`, `omnioffloader_multipart_threshold`, `omnioffloader_transfer_concurrency`, `omnioffloader_bulk_failure_limit`, `omnioffloader_free_disk_space`, `omnioffloader_cpanel_quota_file`, `omnioffloader_check_folder_claim`.

Actions: `omnioffloader_before_offload`, `omnioffloader_after_offload`, `omnioffloader_before_restore`, `omnioffloader_after_restore`, `omnioffloader_transfer_heartbeat`.

REST API namespace: `omnioffloader/v1`. WP-CLI: `wp omnioffloader`. Full reference with examples: [Hooks](https://omnioffloader.vercel.app/developers/hooks/) and [WP-CLI](https://omnioffloader.vercel.app/wp-cli/).

= Why offload media to cloud storage? =

* **Reclaim disk space** — media is usually the bulk of a WordPress install, and object storage costs a fraction of hosting disk.
* **Faster media delivery** — files served from a CDN edge load closer to your visitors.
* **Lighter backups and migrations** — a site without gigabytes of uploads is far quicker to back up and move.
* **No lock-in** — Bring Back reverses the whole thing whenever you want.

= Licensing note =

This plugin bundles the AWS SDK for PHP (Apache-2.0), which is GPL-compatible under GPLv3 — covered by this plugin's "GPL-2.0-or-later" license.

== Installation ==

1. Install the plugin from **Plugins → Add New**, or upload the `omnioffloader` folder to `/wp-content/plugins/`.
2. Activate it through the **Plugins** screen.
3. Open **OmniOffloader → Settings**.
4. Pick your storage provider, enter its credentials and your Custom Domain / CDN URL, then click **Test Connection**.
5. Choose a retention policy. Start with **Retain Local Files** — it keeps every local copy while you confirm everything serves correctly.
6. Choose a **Transfer Speed** that suits your hosting (Balanced is a good start).
7. Open **OmniOffloader → Offload** and start a bulk offload for your existing media. New uploads are offloaded automatically from now on.

== Frequently Asked Questions ==

= Which cloud storage providers are supported? =

Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and Wasabi each have a dedicated setup. Anything else that speaks the S3 API — MinIO, Storj, OVHcloud, Linode Object Storage, Scaleway and so on — works through **Any S3-Compatible Storage**, where you supply the endpoint and region yourself.

= How do I configure Cloudflare R2? =

1. In the Cloudflare dashboard, go to **R2** and create a bucket.
2. In the bucket's **Settings**, connect a **Custom Domain** (for example `https://media.yourdomain.com`) or enable the public `r2.dev` URL, so your files can be served publicly.
3. Back on the R2 overview page, open **Manage R2 API Tokens**, create a token with **Object Read & Write** permission, and copy the Access Key ID and Secret Access Key.
4. Copy the bucket's **S3 API** endpoint (`https://<account-id>.r2.cloudflarestorage.com`) from the bucket settings.
5. In OmniOffloader Settings, select **Cloudflare R2** and enter the Access Key ID, Secret Access Key, Endpoint URL, Bucket Name and your custom domain, then click **Test Connection**.

Full guide: [omnioffloader.vercel.app/providers/cloudflare-r2/](https://omnioffloader.vercel.app/providers/cloudflare-r2/)

= How do I configure Amazon S3? =

1. In the AWS console, create an S3 bucket in your preferred region.
2. In IAM, create a user with programmatic access and a policy that allows reading and writing objects in that bucket. Create an access key and copy the Access Key ID and Secret Access Key.
3. To serve media publicly, allow public `s3:GetObject` on the bucket with a bucket policy, or put an **Amazon CloudFront** distribution in front of it.
4. In OmniOffloader Settings, select **Amazon S3** and enter the keys, Bucket Name, Region (for example `us-east-1`) and your CloudFront or CDN URL (or leave it blank to serve directly from S3).

Full guide: [omnioffloader.vercel.app/providers/amazon-s3/](https://omnioffloader.vercel.app/providers/amazon-s3/)

= How do I configure DigitalOcean Spaces? =

1. In the DigitalOcean control panel, create a Space and, if you want, enable its built-in CDN.
2. Create a **Spaces access key** and copy the key and secret.
3. Note the region endpoint (for example `https://nyc3.digitaloceanspaces.com`) and region (`nyc3`).
4. In OmniOffloader Settings, select **DigitalOcean Spaces** and enter the keys, Endpoint URL, Region, Space name and your CDN or custom domain.

Full guide: [omnioffloader.vercel.app/providers/digitalocean-spaces/](https://omnioffloader.vercel.app/providers/digitalocean-spaces/)

= How do I configure Backblaze B2? =

1. In the Backblaze B2 console, create a bucket and set **Files in Bucket are** to **Public** so media can be viewed in browsers.
2. Note the bucket's S3 Endpoint (for example `https://s3.us-east-005.backblazeb2.com`) and its region (`us-east-005`).
3. Under **Application Keys**, add a key with **Read and Write** access to the bucket, and copy the `keyID` and `applicationKey`.
4. In OmniOffloader Settings, select **Backblaze B2** and enter the Application Key ID, Application Key, Endpoint URL, Region and Bucket Name. Leave the CDN URL blank to serve directly from Backblaze, or enter your CDN domain.

Full guide: [omnioffloader.vercel.app/providers/backblaze-b2/](https://omnioffloader.vercel.app/providers/backblaze-b2/)

= How do I configure Wasabi? =

1. In the Wasabi console, create a bucket and note its region (for example `us-east-1`).
2. Create an access key and copy the Access Key ID and Secret Access Key.
3. Make the files publicly readable with a bucket policy, or serve them through a CDN in front of the bucket.
4. In OmniOffloader Settings, select **Wasabi** and enter the keys, Bucket Name, Region and your CDN or custom domain.

Full guide: [omnioffloader.vercel.app/providers/wasabi/](https://omnioffloader.vercel.app/providers/wasabi/)

= How do I configure Storj or other S3-compatible storage? =

1. In the Storj console, create a bucket and open **Access → Create S3 Credentials**. Copy the Access Key and Secret Key.
2. Share the bucket publicly (**Objects → your bucket → Share**) and copy the link-sharing URL, for example `https://link.storjshare.io/s/<KEY>/<BUCKET>`. Replace `/s/` with `/raw/`.
3. In OmniOffloader Settings, select **Any S3-Compatible Storage**.
4. Set the S3 Endpoint URL to `https://gateway.storjshare.io`, Region to `us-east-1`, turn on **Use Path-Style Endpoint**, enter your keys and Bucket Name, and paste the `https://link.storjshare.io/raw/...` URL as the Custom Domain / CDN URL.

The same fields work for MinIO, OVHcloud, Linode, Scaleway and other S3-compatible services. The optional **Provider Label** lets you name the provider as it appears in the plugin. Full guide: [omnioffloader.vercel.app/providers/s3-compatible/](https://omnioffloader.vercel.app/providers/s3-compatible/)

= Which Transfer Speed should I choose? =

It matters most when you offload media that is already in your library but not in the cloud yet — with Bulk Offload, Media Library bulk actions or WP-CLI — and when you use Bring Back. New uploads are offloaded in the background as they arrive, so you rarely notice their speed. The setting chooses how many files are uploaded or downloaded at the same time:

* **Low** — one file at a time. The lightest option for small shared hosting plans.
* **Balanced** — 3 files at a time. The default, and right for most sites.
* **Fast** — 6 files at a time, for a VPS or dedicated server.
* **Custom** — any number from 1 to 10.

All four run in the same single PHP process; a faster setting overlaps the time spent waiting on the network instead of starting more processes. Each extra file only adds a little memory and bandwidth. Bulk Offload, Bring Back, new uploads and WP-CLI all follow this setting. For a one-off WP-CLI offload on a VPS, `wp omnioffloader offload --concurrency=32` (up to 64) goes beyond it for that run only.

= How long does a bulk offload take, and can I close the browser tab? =

It depends mostly on how quickly your server reaches your storage provider, how large your files are, and how many thumbnail sizes your theme creates. A higher Transfer Speed finishes sooner on a server that can handle it.

Jobs run in the background and keep going after you close the tab. On hosts that block WordPress's internal loopback requests, the admin screen drives the job instead — there, leave the Offload page open until it finishes, or use WP-CLI.

= Will offloading break my existing image URLs? =

No. URLs are rewritten as pages render, so posts, pages, featured images and responsive `srcset` attributes all keep working. Nothing in your post content is edited, and no database search-replace is needed.

= Can I move my media back to my server later? =

Yes — that is what **Bring Back** is for. It downloads your media from the cloud to its original paths, checks that every file arrived, and then stops rewriting URLs so everything serves locally again. You can restore a single attachment, a set number, or the whole library. The cloud copy is kept unless you turn on deleting it after restore.

= What happens to my images if I deactivate the plugin? =

That depends on the retention policy you used. With **Retain Local Files**, every file is still on your server and nothing changes. With **Smart Local Cleanup** or **Full Cloud Migration**, some or all local files were deleted after upload, so URLs would point at files that are no longer on disk. **Run Bring Back before deactivating** if you used either cleanup policy.

= I copied my live site to staging or a local dev site. Can it damage the live site's media? =

No. A copy carries the live site's settings and access keys, so without protection it would upload into the live bucket (a new file with the same name could overwrite one the live site added later) and, with Mirror Delete on, delete files the live site still uses. OmniOffloader recognises a copy automatically and switches to **Safe mode**:

* the site address changed from the one recorded as live (stored as a fingerprint, so migration tools' search-and-replace can't hide the change),
* WordPress reports a staging, development or local environment (`WP_ENVIRONMENT_TYPE`, set by many managed hosts and local tools), or
* the site runs on a local address such as `localhost`, `*.local` or `*.test`.

In Safe mode nothing is uploaded to or deleted from your cloud storage. Media already in the cloud keeps loading from it, and new uploads stay on the copy's own server. Bring Back is turned off too; to download media from the cloud to the copy, turn on **Allow Bring Back on a copy (download only)** in Settings — the cloud copy is then always kept. A banner explains why Safe mode is on. If your live site really moved to a new domain, click **This is the live site**. To decide yourself, add `define( 'OMNIOFFLOADER_SAFE_MODE', true );` (or `false`) to `wp-config.php`, or turn off **Protect the live site when this is a copy** in Settings.

= Can several separate sites share one bucket and one CDN address? =

Yes, for example `example.com`, `shop.example.com` and `blog.example.com`. Install OmniOffloader on each site, enter the same bucket, keys and custom domain, and give each site its own folder under **Settings → Custom Path Prefix** (for example `www`, `shop` and `blog`). Each site's files then live under their own folder, such as `https://cdn.example.com/shop/2026/09/photo.jpg`.

Separate sites share no database, so without a folder of their own two sites would store files under the same names. One site's upload would then overwrite the other's file, and Mirror Delete on one site would delete files the other still uses. To prevent this, each site marks its folder with a small `.omnioffloader-site.json` file naming the site. Settings refuses a folder another site already uses. If a site finds another site's marker in its folder, it switches to Safe mode and a banner explains why. Nothing is uploaded or deleted until you choose a different Path Prefix. If the folder really belongs to this site (for example after a domain change), click **This folder belongs to this site**.

For a hard guarantee on Amazon S3, give each site its own access key, limited to its own folder.

= Do I have to delete my local files? =

No. The default policy, **Retain Local Files**, deletes nothing — the cloud simply becomes a second copy. Choose **Smart Local Cleanup** to delete only generated thumbnails while keeping every original, or **Full Cloud Migration** to reclaim the most space. Local files are only removed after the upload has been confirmed in your bucket.

= I changed the retention policy. Why are old files still on my server? =

A retention policy is applied when a file is offloaded, so a new policy only affects new uploads at first. Go to **OmniOffloader → Tools → Apply Retention Policy to Existing Media** (or run `wp omnioffloader retention`) to update media you offloaded earlier. Before a local file is deleted, its cloud copy is checked by size. Files missing from the cloud are uploaded first. A file whose cloud copy differs is uploaded again and checked; if that fails, the local file is kept and reported. Moving to a looser policy (for example from Full Cloud Migration back to Retain Local Files) downloads the local copies again.

= Why do I need a custom domain or CDN URL? =

Most storage buckets do not serve files publicly by default, and Cloudflare R2 has no public URL at all until you connect one. Pointing a custom domain or CDN hostname at your bucket is what makes your media reachable — and it lets the files be cached at the edge.

= Can I offload now and switch to the CDN later? =

Yes. With the **Retain Local Files** policy, turn off **Serve Media from Cloud** in **Settings → Retention Policy**. Media is still offloaded to your bucket, but your site keeps loading it from your own server. When your CDN is ready, turn it on: every offloaded file switches over at once, with nothing uploaded again. Clear your page cache after changing it. The cleanup policies delete local files, so with them media always loads from the cloud.

= Can I keep my access keys out of the database? =

Yes. Define them as constants in `wp-config.php`, named `OMNIOFFLOADER_{PROVIDER}_{FIELD}` — for example:

`define( 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY', '...' );`
`define( 'OMNIOFFLOADER_CLOUDFLARE_R2_SECRET', '...' );`

Constants take priority over saved values, and the settings screen shows those fields as locked. Secrets are never sent back to the browser either way, and stored credentials are kept out of WordPress's autoloaded options.

= Can I run it from WP-CLI? =

Yes. **OmniOffloader → Tools** lists every command and option with copy buttons.

`wp omnioffloader offload --all [--limit=<number>] [--dry-run]`
`wp omnioffloader offload --ids=12,34`
`wp omnioffloader offload --all --concurrency=32`
`wp omnioffloader restore --all [--limit=<number>] [--delete-cloud] [--dry-run]`
`wp omnioffloader retention [--policy=<0|1|2>] [--dry-run] [--format=json]`
`nohup wp omnioffloader offload --all > ~/offload.log 2>&1 &`
`wp omnioffloader status`
`wp omnioffloader stop`

Start a long job with `nohup … &` (as above) to keep it running after you close the terminal; `status` shows its progress and `stop` ends it from any terminal. Every write command supports `--dry-run`, and destructive options ask for confirmation unless you pass `--yes`. Commands refuse to start while another bulk job is running (`--force` overrides this), so two jobs never touch the same files. WP-CLI offloads use your Transfer Speed setting.

= How can I offload a very large library faster? =

Run the offload from WP-CLI on your server with more files at once: `wp omnioffloader offload --all --concurrency=32`, up to 64. The admin screens stay at 10 at most, because they run inside web requests. Try a short run first (`--limit=500`) and time it, then start the full run with `nohup … &` so it keeps going after you close the terminal.

= How do I follow a long WP-CLI run? =

Start it in the background with a log file in your home folder: `nohup wp omnioffloader offload --all > ~/offload.log 2>&1 &`. The answer `[1] 12345` is the job and process number, not an error. Follow the progress with `tail -f ~/offload.log` (Ctrl+C only stops watching, not the job) or `wp omnioffloader status`; the log gets a timestamped line every 25 attachments, so you can work out the speed and the time left. Stop it with `wp omnioffloader stop` and run the same command again to continue. Step-by-step guide: [Long runs and log files](https://omnioffloader.vercel.app/wp-cli/long-runs/).

= Who can offload or bring back media? =

Only administrators (users with the `manage_options` capability). Other users keep their normal WordPress rights: they upload and delete their own media, and with Mirror Delete on, deleting an attachment also removes its cloud copy.

= What about very large videos or files? =

Files above 64 MB are uploaded in parts, so a dropped connection costs one chunk rather than the whole transfer, and files larger than 5 GB are supported. If a transfer is still cut short by your host's time limit, that attachment is reported with an explanation and the job moves on.

= Does it work with caching plugins and object caches? =

Yes. The plugin's own REST requests tell page caches not to store them (including LiteSpeed Cache and Breeze), and bulk progress stays live under Redis or Memcached object caching. On the front end the plugin only rewrites URLs and does not add database queries per image.

= Does this plugin work on multisite? =

== Screenshots ==

1. Dashboard — Real-time cloud provider status, media offload stats, and quick actions.
2. Cloud Provider & Credentials — Multi-cloud provider selection (Cloudflare R2, AWS S3, Wasabi, DigitalOcean, Backblaze B2, S3-Compatible) with instant "Test Connection" verification.
3. Retention & Delivery Policies — Flexible local retention policies (Retain Local, Smart Cleanup, Full Migration), Transfer Speed, Mirror Delete, Object Versioning, and custom path prefixes.
4. Bulk Offload Center — Active background media offloader with custom batch amount stepper, preset chips, and real-time status counters.
5. Zero-Lock-In Bring Back (Restore) — Safely download offloaded media back to your local server with automatic disk space pre-flight checks and atomic restore.
6. WordPress Media Library Integration — Dedicated cloud offload status column, offload state filter, contextual row actions, and native bulk offload/restore actions.
7. Apply Retention Policy to Existing Media — Tools screen to synchronize existing media with updated retention policies, including pre-flight storage estimates and batch scopes.
8. Safe Mode Copy & Staging Protection — Automatic detection of staging/dev clones, address changes, and shared bucket conflicts, pausing cloud writes to protect production data.

== External services ==

This plugin connects to the cloud storage provider **you choose and configure** in its settings. Nothing is sent anywhere until you enter credentials for a provider, and it only ever connects to that provider.

**What is sent and when:** your media files (originals, thumbnails and other generated sizes) and their file paths are uploaded when media is offloaded — automatically on upload, from Bulk Offload, the Media Library, or WP-CLI. Files are downloaded again when you use Bring Back or apply a looser retention policy, and deleted from the bucket when you delete an attachment with Mirror Delete on, or restore with "delete from cloud" on. **Test Connection** sends a request to check that your bucket is reachable. Every request is signed with the access key you entered; the secret key itself is never transmitted. Visitors then load the offloaded files from the storage or CDN address you configured.

No analytics, tracking or other data is sent to the plugin author or any other party.

Depending on the provider you select:

* **Amazon S3** (Amazon Web Services) — [Service Terms](https://aws.amazon.com/service-terms/), [Privacy Notice](https://aws.amazon.com/privacy/)
* **Cloudflare R2** (Cloudflare) — [Terms](https://www.cloudflare.com/terms/), [Privacy Policy](https://www.cloudflare.com/privacypolicy/)
* **DigitalOcean Spaces** (DigitalOcean) — [Terms of Service](https://www.digitalocean.com/legal/terms-of-service-agreement), [Privacy Policy](https://www.digitalocean.com/legal/privacy-policy)
* **Backblaze B2** (Backblaze) — [Terms of Service](https://www.backblaze.com/company/terms.html), [Privacy Policy](https://www.backblaze.com/company/privacy.html)
* **Wasabi** (Wasabi Technologies) — [Customer Agreement](https://wasabi.com/legal/customer-agreement), [Privacy Policy](https://wasabi.com/legal/privacy-policy)
* **Any S3-compatible storage** — the service at the endpoint you enter; its own terms and privacy policy apply.

== Changelog ==

= 0.1.4 =

**Serve Media from Cloud**

* New setting in **Settings → Retention Policy**: with **Retain Local Files**, turn off **Serve Media from Cloud** to keep offloading media while your site still loads it from your own server, for example while you set up your CDN. Turn it on and every offloaded file switches to your cloud storage or CDN at once, with no re-upload.
* Smart Local Cleanup and Full Cloud Migration delete local files, so with those policies media always loads from the cloud. Files an earlier policy already removed keep loading from the cloud until they are downloaded again, so turning serving off never breaks an image.

**WP-CLI: upload more files at once**

* New `wp omnioffloader offload --concurrency=<1–64>`: upload up to 64 files at the same time for that run, instead of the Transfer Speed setting (at most 10, which the admin screens keep). For a VPS or dedicated server offloading a large library. More attachments share one batch so every slot stays busy, and a file above 64 MB still uploads at most 10 parts at once.

**WP-CLI: stop command and long runs**

* Long runs started with `nohup wp omnioffloader … > file.log 2>&1 &` keep going after you close the terminal and write a progress line every 25 attachments to the log. A run without a terminal that would ask for confirmation stops with a message asking for `--yes`, instead of waiting for an answer.
* New `wp omnioffloader stop` ends the running job from any terminal. A WP-CLI run finishes the file it is transferring first; a job started from the admin screen is cancelled.
* `wp omnioffloader status` shows a running WP-CLI job: progress, failures, process and log file.
* The WP-CLI reference on the Tools screen lists the new `stop` command and how to keep a job running with `nohup`.
* New documentation page [Long runs and log files](https://omnioffloader.vercel.app/wp-cli/long-runs/): start a big job with a log file, watch it, work out the speed and time left, and what to do when it ends.
* A lock left behind by a killed run (for example after a server restart) is cleared by the next command on the same server, instead of blocking it for 10 minutes.

**Documentation links**

* Every OmniOffloader screen links to the documentation: a **Documentation** link in the page header, **Learn more** on each section and setting, and a setup guide for the selected storage provider. The Tools page shows how to run a long WP-CLI job in the background.

**Fixes**

* Switching to another storage provider no longer breaks media you already offloaded: it keeps loading from the provider it was offloaded to (through its saved settings), Mirror Delete deletes it there, and Move Media brings it over to the new provider.
* The bucket can no longer be changed while media is offloaded to it, which used to break those URLs and Bring Back. Settings warns under the Bucket field, with the steps: Bring Back, change the bucket, offload again.
* Media Library: when Offload or Bring Back fails, the reason is shown as a notice (for example Safe mode, or another job running) instead of the page silently reloading; a failed bulk action shows it too.
* Settings: a save error stays visible until you change something or save again, and a save that stored the provider details but not the other settings says so.
* WP-CLI: `wp omnioffloader retention` now stops after repeated failures when the storage provider can't be reached, and stops at once when the server runs out of disk space, like `restore`.
* Background upload of new media: a large file is no longer uploaded twice at the same time (the worker lock lasts through the transfer and only one worker runs), and an upload the server keeps stopping (time or memory limit) is marked failed after three tries instead of being retried forever.
* Bulk jobs: cancelling and immediately starting again no longer lets the cancelled run's worker carry on inside the new one (processing items the new run did not choose, with its settings, and counting them in its progress).
* Cover and Media & Text blocks no longer rewrite links to other files from the same upload folder (another image or a PDF inside the block) to the cloud. Only the block's own media is rewritten, so those links no longer break.
* WP-CLI: closing the SSH window during `wp omnioffloader offload`, `restore` or `retention` now releases the job lock right away, as Ctrl+C does. Before, the next run was refused for up to 10 minutes.
* WP-CLI: media the run was transferring when it was interrupted (Ctrl+C, a closed window, a killed process) no longer shows "Offloading..." or "Restoring..." in the Media Library forever. It goes back to its real status and the next run picks it up.
* WP-CLI: `wp omnioffloader stop --wait=<seconds>` refuses a value that is not a number, instead of not waiting at all.
* Full Cloud Migration and Smart Local Cleanup: a new upload no longer reuses a file name that another attachment still has in your cloud storage (the original of a scaled image, a thumbnail size, a PDF preview or an edit backup), which could overwrite that attachment's files.
* Bring Back with "Delete from Cloud After Restore" no longer deletes cloud files it did not download, such as the original WordPress keeps after you edit an image. Only files that are now on your server are removed from the cloud.
* Picking Offload or Bring Back in the Media Library for items that have nothing to do (already offloaded, or already local) while a bulk job of the same kind runs no longer stops that job.
* Safe mode: **This is the live site** now asks for confirmation before turning Safe mode off, since clicking it on a staging copy lets that copy write to the live site's cloud storage. If it fails, the real reason is shown.
* Bring Back (and loosening the retention policy) no longer fails for an image whose original was already gone before it was offloaded, for example removed by an image optimizer. Only the attachment's own file must be restored.
* Bulk Bring Back with **Delete from Cloud After Restore** now asks for confirmation before it starts, as WP-CLI's `--delete-cloud` does.
* Media Library: an item stuck on "Queued for background offload" or "Offloading..." (for example when thumbnail generation failed, or the background queue was cleared) goes back to its real status by itself once no job is running.
* `wp omnioffloader status` starts with what is running now: progress with percentage, start time, speed, time left and expected finish, files transferred at once, log file and how to stop it. When nothing runs, it shows how the last WP-CLI job ended (finished, stopped or ended early) and the command to offload media that is still local.
* Path Prefix: changing it while media is offloaded now shows, under the field, that existing media stays in its folder and keeps working, and **Save changes** asks you to confirm. `wp omnioffloader status` shows the current folder and how many offloaded attachments are in other folders.
* New `wp omnioffloader move`: moves media offloaded under an earlier Path Prefix into the current folder. Within the same bucket your storage provider copies the files itself, so nothing is downloaded and your server's files are not touched; old copies are deleted once the new ones are verified. Files that can't be copied that way (over 5 GB) go through the server. Supports `--dry-run`, `--ids`, `--limit`, `--keep-old` and `--concurrency` (up to 64 files at once).
* New **Tools → Move Media to the Current Folder**: the same move from the admin screen, with a progress bar and Cancel. Shown only when some media is outside the current folder.
* The OmniOffloader box on an attachment's edit screen shows its bucket **Folder**, marked *Not in the current folder* when the Path Prefix changed after it was offloaded.
* Confirmations (Bring Back that deletes cloud copies, a bucket folder change, moving media, Safe mode's live-site and folder buttons) now use the plugin's own dialog with clear button labels, instead of the browser's OK / Cancel box.
* Sharing a bucket: a folder this site moved away from keeps its claim, and every upload or delete is now checked against the folder it really goes to. If another site takes over an old folder, this site stops writing to and deleting from it, and `wp omnioffloader status` warns about it.

= 0.1.3 =

**Documentation**

* New documentation site at [omnioffloader.vercel.app](https://omnioffloader.vercel.app/): setup guides for every storage provider, every feature, the WP-CLI commands and the developer hooks. A **Documentation** link on the Plugins screen opens it.

**Apply Retention Policy to Existing Media**

* New Tools screen: apply the saved retention policy to media that was offloaded under an earlier policy. Switching from Retain Local Files to Smart Local Cleanup or Full Cloud Migration now frees space for existing media too, not just new uploads.
* Every local file is checked in cloud storage by size before it is deleted. Files missing from the bucket (for example thumbnails regenerated after the offload) are uploaded first; a file whose cloud copy differs is uploaded again, and kept and logged only if that fails. An attachment only counts as updated once every file was handled, so failures are retried on the next run.
* Moving to a looser policy downloads local copies again while the attachment stays offloaded.
* Preflight shows how many attachments need updating and roughly how much space will be freed or downloaded. Full Cloud Migration asks for confirmation before originals are removed.
* Runs in the background with the same progress bar, activity log, batch sizes and cancel button as Bulk Offload and Bring Back.
* Settings shows a notice after saving a new policy when existing media still follows an older one.
* New WP-CLI command: `wp omnioffloader retention [--policy=<0|1|2>] [--ids=<ids>] [--limit=<number>] [--dry-run] [--yes] [--force]`. `wp omnioffloader status` now reports media that follows an older policy.
* New filter `omnioffloader_should_apply_retention` to keep specific attachments' local files.
* WP-CLI reference on the Tools screen: every command with its options, copy buttons and a link to the matching admin screen.
* Offload, Bring Back and retention jobs can no longer run at the same time. This applies whether a job was started from the admin screens, the Media Library or WP-CLI.

**Faster Offloading**

* Offloading media that is not in the cloud yet is now faster. New uploads were already offloaded instantly in the background; the big win is for your existing library — Bulk Offload, Media Library bulk actions and `wp omnioffloader offload`. An attachment's files (original and every generated size) now upload side by side instead of one after another, and at any speed above Low a few attachments share one batch, with their main files confirmed in the bucket together. Everything still runs inside one PHP process, and each attachment is only marked offloaded once every one of its files uploaded and its main file is confirmed in the bucket.
* New Transfer Speed setting: Low (one file at a time, lowest resource use), Balanced (3 files, default), Fast (6 files, for VPS and dedicated servers) or Custom (any number from 1 to 10). WP-CLI offloads use the same setting. Large multipart uploads follow the same limit. Developers can override it with the `omnioffloader_transfer_concurrency` filter.
* Bring Back is faster: files that are still on your server are checked against the cloud with one request per attachment instead of one per file, and missing files download side by side at the same Transfer Speed. Each file is still written to a temporary file and moved into place only when complete.
* Background passes run longer where the host allows it (up to 40 seconds, based on `max_execution_time`), so WordPress reloads less often during a bulk job.
* Uploads no longer add the optional CRC32 checksum that recent AWS SDK versions send by default. That saves one read of every file, and several S3-compatible services reject the header.

**Security**

* Offloading and bringing back media, from the Media Library links, bulk actions or REST, is now limited to administrators, like the plugin's settings and bulk screens. Before, any user who could upload files could offload or bring back anyone's media and delete its cloud copies. Other users keep their normal WordPress rights: they upload and delete their own media, and with Mirror Delete on, deleting also removes the cloud copy.
* Bring Back from the Media Library screen now follows the "delete from cloud after restore" setting.
* Local files are only deleted inside the uploads folder, and restores refuse paths containing `..` before creating any directory. File paths from attachment metadata that point outside the uploads folder are ignored for upload, cleanup and restore.
* The Media Library status check only returns media the user can edit, and only shows the bucket name to administrators. Only administrators' status checks process pending background uploads.
* Error messages no longer include absolute server paths.
* Media Library status tooltips are escaped.
* REST parameters are validated against their schema (invalid limits, page sizes or actions now return 400), and the Media Library bulk endpoint accepts at most 1,000 IDs.
* "Remove all data on uninstall" keeps the offload records of media that exists only in the cloud, so it can still be recovered after reinstalling.

**Performance**

* Front end: media blocks are the only blocks whose IDs are looked up. A navigation menu no longer costs a database query per item.
* Front end: all attachments used by image, gallery, video, audio, file, cover and media-text blocks are loaded in one batch before the blocks render, so a page costs no extra queries per image (a 6-image gallery went from 11 queries to 6, the same as without the plugin).
* Front end: video and audio links in content are resolved with one query per post instead of one full scan per link, and cached for the rest of the page even when the content is rendered again (for example by an SEO plugin).
* The Bring Back and Tools screens reuse the cached preflight instead of rescanning the whole library every 6 seconds during a run.
* The Media Library status script no longer polls the server on every editor load or window focus when there is nothing to update.
* Each Media Library status check reads the bulk job state once (2 queries when idle instead of 6–8).
* `wp omnioffloader retention --dry-run` and the offload/restore dry runs load attachments 200 at a time instead of one by one.
* Uploading a file whose name is already taken checks all existing names with one query instead of one per "-1", "-2"… suffix.
* Clearing the stats cache costs half as many queries, and background uploads clear it once per batch instead of once per file.
* The post-activation redirect check only runs on the Plugins screen instead of on every admin request.
* Bulk jobs write their progress once per batch instead of two or three times per attachment, which cuts database writes sharply on large libraries.
* The Bring Back preflight reads only the attachment metadata it needs, 500 attachments per query, and keeps its estimate for an hour (it is refreshed whenever media is offloaded, brought back or deleted). Free disk space is always current.
* The Media Library bulk action loads the selected attachments in a few queries instead of several per item.
* On libraries of 5,000 items or more, the Bulk Offload and Bring Back screens suggest the matching WP-CLI command.

**Fixes**

* Bulk jobs: two background workers could pick up the same batch at the same moment and process items twice. A database lock now lets only one worker run a queue at a time.
* Multisite: each site now has its own queues. Before, all sites in a network shared one queue.
* Only administrators' Media Library views keep bulk jobs moving; other users' views only read their status. Idle status responses no longer repeat the last run's numbers.
* WP-CLI: locks are renewed after every file and released on Ctrl-C. `--limit` must be a positive number (a typo no longer means "the whole library"), `--ids` and `--all` can't be combined, and skipped IDs are reported. `restore` stops after 10 failures in a row, `status` exits with an error code when the bucket is unreachable, and `retention --dry-run` supports `--format=json|csv`.
* REST: settings and credentials are validated against their expected shape, the error list supports paging, and responses always use consistent types.
* Accessibility: card choices support arrow keys, progress bars are labelled, and screen readers hear when a run starts or finishes. The admin screen now loads right-to-left styles for RTL languages and respects reduced motion.
* The Dashboard only shows saved settings, the Settings page warns before you leave with unsaved changes, and error messages stay until dismissed.
* Attachment offload records can no longer be edited as custom fields.
* A missing `vendor` folder shows an admin notice instead of a fatal error.
* Bulk jobs no longer stop for good on a stretch of broken attachments. Before, 10 failures in a row, such as files missing from the server, stopped the run. Because those attachments stayed first in line, every restart stopped on them again. Now the connection to the storage provider is checked first. If it answers, the run carries on and the failures stay in the error log. It still stops after 100 failures in a row. Attachments that failed before now wait until the end of the next run. The same applies to `wp omnioffloader offload` and `restore`.
* When the storage provider can't be reached, the stop message now includes the provider's error.
* Cancelling a bulk job now stops the background worker right away. Before, the worker kept going until the end of its pass (up to 40 seconds, and longer with Redis or Memcached). Because the job already showed as cancelled, another job could start while it was still running.
* Media Library items no longer stay stuck on "Queued" or "Offloading…" after a job was cancelled or stopped, or after the server killed the worker mid-file.
* Bring Back stops with a clear "out of disk space" message before the disk fills up, instead of failing downloads and blaming the connection. The attachment stays in the cloud, and the next run picks it up again.
* Bring Back no longer counts an attachment as failed when it was already brought back while it waited in the queue.
* Attachments excluded by the `omnioffloader_should_offload_attachment` filter now show as "Skipped" in the activity log and WP-CLI summary instead of as failures, and no longer keep a "Queued" badge.
* A very long single transfer (a multi-GB file on a slow connection) no longer lets the job lock expire mid-file. The lock is renewed while bytes are moving, so another job can't start alongside it. New action `omnioffloader_transfer_heartbeat` fires every 30 seconds during uploads and downloads.
* Partial downloads left behind when the server stopped a Bring Back mid-file are now cleaned up.
* The Bring Back download estimate now counts only the files that are actually missing from the server. Before, it also counted files your retention policy kept on the server, so a site using Retain Local Files saw its whole library as the download size and could get a false "not enough disk space" warning. Attachments uploaded before WordPress 6.0, which have no recorded file size, are now reported separately instead of silently counting as zero. A note also explains that free disk space is what the server reports, and a hosting plan's storage quota may be lower.
* Shared hosting with a storage quota (cPanel, CloudLinux): PHP can only see the whole server disk, not your plan's quota. Bring Back and retention updates now notice when the server refuses to save a file ("Disk quota exceeded", "No space left on device") and stop right away with a clear message. Before, they kept failing until the failure limit and blamed the attachments. Nothing is lost: attachments that were not brought back stay in the cloud. New filter `omnioffloader_free_disk_space` lets a host or developer supply the real space left in the quota.
* Mirror Delete is now on by default for new installs, so an attachment deleted in WordPress no longer stays publicly reachable, and billed, in the bucket. Existing sites keep their current setting. It never runs on a staging or development copy (Safe mode).
* New: Safe mode for staging, development and local copies. A copy of a live site (made with a backup or migration plugin, a host's staging tool, or on a local machine) is recognised automatically, and nothing is uploaded to or deleted from the live site's cloud storage from it. Before, a copy could overwrite files in the live bucket with its own uploads and, with Mirror Delete on, delete files the live site still served. Media already in the cloud keeps loading and new uploads stay local. Bring Back is hidden and turned off on a copy unless you allow it in Settings ("Allow Bring Back on a copy"), and then it only downloads, never deleting from the cloud. Detection uses the site address, `WP_ENVIRONMENT_TYPE` and local addresses; a banner explains why, with a "This is the live site" button for a real domain move. Control it with the new Settings option "Protect the live site when this is a copy" or the `OMNIOFFLOADER_SAFE_MODE` constant. `wp omnioffloader status` shows the mode; `wp omnioffloader offload` and `restore` refuse to run in Safe mode (`restore` works when Bring Back is allowed, but never with `--delete-cloud`).
* cPanel hosting: the Bring Back screen now shows the space left in your hosting plan (for example "9.3 GB of 10 GB"), read from cPanel's own quota record, together with when cPanel last updated it. Before, it showed the whole server disk (often terabytes). No shell commands are used. The disk check during Bring Back uses the quota too, as long as cPanel updated it within the last hour.
* The retention policy update now checks the storage connection before stopping on repeated failures, like Bulk Offload and Bring Back.
* The Dashboard's local media count now matches what Bulk Offload processes (trashed attachments and entries without a file are not counted), so it reaches 0 after a full run.
* Provider errors are only written to the PHP error log when `WP_DEBUG` is on (filter `omnioffloader_log_provider_errors`); they are always recorded on the attachment.
* Retention: when a cloud copy differs in size from the server file (for example after an image optimizer shrank the thumbnails), the server file is uploaded again and verified, instead of the run stopping with a misleading connection error.
* Retention: attachments excluded with the `omnioffloader_should_apply_retention` filter no longer show as "needs updating" forever.
* Progress polling no longer runs two timers at once, keeps polling after you leave the page, or restarts after Cancel.
* Deactivating the plugin now also clears the background upload queue and its scheduled event.
* Counts use proper singular/plural translations, and the activity log's "Attachment #" label is translatable.
* New filter `omnioffloader_media_block_names` to register third-party media blocks.
* The `omnioffloader_after_offload` action fires again after an attachment is offloaded and verified.
* New: separate sites can safely share one bucket and one CDN address, each in its own Path Prefix folder. Each site marks its folder with a small `.omnioffloader-site.json` file. Before, two sites using the same folder overwrote each other's files, and Mirror Delete on one site deleted files the other still served. Settings now refuses a folder another site uses. A site that finds another site's marker in its folder switches to Safe mode and stops uploading and deleting. It shows a banner with **Choose a Path Prefix** and **This folder belongs to this site**. Bulk Offload and retention runs stop with a clear message instead of failing item by item. Test Connection warns about a taken folder, `wp omnioffloader status` reports it, and a confirmed domain move ("This is the live site") takes the folder's claim along. The folder is checked on the first upload or delete after an update or settings change, then once a day. Developers can turn the check off with the `omnioffloader_check_folder_claim` filter.
* Safe mode and the folder check identify a site by its saved Site Address, so multilingual sites with a different domain per language (WPML, Polylang) are never mistaken for a copy of themselves or for another site.

= 0.1.2 =

**New Features & Batch Controls**

* Custom Batch Size & Quantity Limits: users can now choose between processing the entire media library ("All Media") or specifying a custom number of attachments for both Bulk Offload and Bring Back.
* Interactive Scope Selector: added modern radio card controls with quick preset chips (5, 10, 25, 50, 100, and All) and clean, centered numeric input field with unit labels.
* Dynamic Action Buttons: buttons on Offload and Bring Back screens dynamically display target batch counts (e.g. "Start Bulk Offload (5 of 10)" / "Start Bring Back (5 of 10)").
* Proportional Download Estimation: Bring Back calculates and displays the exact disk space required for the selected custom batch size alongside available server free space before starting.
* REST API `limit` parameter: registered sanitized and validated `limit` parameter on `POST /omnioffloader/v1/offload/bulk` and `POST /omnioffloader/v1/bring-back/bulk`.
* WP-CLI `--limit=<number>`: added `--limit=<number>` option to `wp omnioffloader restore` matching `wp omnioffloader offload --limit=<number>`, enabling batch restores from the terminal.

**Reliability & Caching Fixes**

* LiteSpeed Cache & Breeze compatibility: added strict anti-caching headers (`no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache`, `Expires: 0`, and `X-LiteSpeed-Cache-Control: no-cache`) to all plugin REST endpoints.
* Added `DONOTCACHEPAGE` constant definition and `litespeed_can_cache` / `breeze_can_cache` bypass filters to prevent web-server level caching of dynamic REST routes.
* Client-side request cache-busting: added microsecond timestamp query parameters (`_t=${Date.now()}`) and `cache: 'no-store'` to all API polling and data fetch calls, preventing browsers, edge proxies, and reverse proxies from serving stale HTTP 200 responses.
* Persistent Object Cache synchronization: added automatic cache invalidation (`wp_cache_delete`) for queue state options prior to reading, ensuring multi-worker Redis and Memcached environments observe real-time bulk progress without delay.
* Multi-layer transient flushing: enhanced stats and preflight cache flushes to clean both object cache groups (`transient`) and fallback options table entries.
* Added force-refresh support (`?refresh=1`) to `/core/stats` and `/bring-back/preflight`, allowing immediate post-run verification directly from the database.

**UI & Experience Improvements**

* Standardized badge formatting across option cards to ensure identical phrasing (`%d items` / `%d item`) and consistent badge styling.
* Active selection badge accent transitions and crisp input alignment.
* Real-time Bulk Offload & Bring Back logs: Activity log and progress bar now update live with each poll under any caching plugin or proxy configuration.
* Live-updating summary chips: Offloaded and Local attachment counts now update dynamically in the summary header as items are processed during active bulk runs.
* Fixed "Start Bring Back (20)" button count remaining frozen after media restoration; the preflight check now automatically refreshes and transitions to "No Offloaded Media to Restore".
* Fixed "All Media Offloaded" state not displaying immediately upon offload completion; stats refresh now promptly detects 100% completion and renders the success banner.

= 0.1.1 =

**New Features & Enhancements**

* Multi-provider expansion: added dedicated provider presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi, plus a generic S3-Compatible option for MinIO and other custom endpoints.
* Dedicated OmniOffloader Meta Box on edit attachment screen (`post.php`) with cloud storage status, provider, bucket, offloaded date, CDN status, and one-click actions.
* Media Library live status integration: added minimal status icon column (cloud, spinner, warning, dash) in list view and live badges in grid view modals.
* Media Library bulk actions: added "Offload to Cloud" and "Bring Back to Server" to bulk action dropdowns with instant real-time UI updates.
* Dynamic row actions: attachment row actions beneath titles now dynamically reflect live states ("Offloading...", "Restoring...", "Queued...", "Bring Back", "Offload now", "Retry Offload").
* Non-blocking uploads: introduced `AsyncUploadProcessor` to offload new uploads in the background without delaying server responses.
* Content URL rewriting expansion: rewrites media URLs inside Gutenberg blocks (Video, Audio, File, Media & Text, Cover), Classic Editor HTML media tags, and WordPress audio/video shortcodes.
* Added multipart uploads for files above 64 MB, supporting large transfers beyond 5 GB without memory exhaustion.
* Added `--limit=<number>` to `wp omnioffloader offload` for running bulk jobs in manageable chunks.

**Reliability & Fixes**

* Hardened bulk queue worker (`AbstractBulkProcessor`): claims items before processing so a worker killed by host time limits drops the item instead of getting stuck in an infinite retry loop.
* Extended queue process lock from 60 seconds to 10 minutes (renewed after each item) to prevent duplicate workers from running on small hosting pools.
* Consecutive failure circuit breaker: bulk jobs halt gracefully with clear error logging after 10 consecutive failures instead of grinding through the whole library.
* Fixed Bring Back restore logic: verified files are completely restored on disk before updating metadata or pruning cloud copies.
* Prevented stale activity logs from flashing previous runs when starting a new Offload or Bring Back operation.
* Fixed Cancel button not taking effect on hosts with blocked internal loopback requests.
* Fixed stranded queue rows remaining in the database upon deactivation/uninstall.
* Added connection timeouts to all storage requests to prevent hanging PHP-FPM processes.

**Performance & Optimizations**

* Optimized status polling with delta responses, slashing payload size by ~94% and avoiding unnecessary DOM re-renders.
* Integrated Page Visibility API (`document.hidden`) to pause polling and conserve resources when browser tabs are inactive.
* Throttled database writes on thumbnail generation during bulk transfers.
* Cached media library statistics once per bulk run instead of querying on every single item.
* Fixed Bring Back disk preflight query to run efficiently on large media libraries.
* Bulk jobs now skip trashed attachments and entries with no file on disk.

= 0.1.0 =

* Initial release of OmniOffloader.
* Multi-cloud offload engine with Cloudflare R2 provider support.
* Modern React-based settings and control dashboard.
* Background bulk offload and bulk restore with real-time progress indicators.
* URL rewriting for post content, attachment URLs, and responsive srcset attributes.
* Media library filters and individual attachment action controls.
* Complete WP-CLI commands: `wp omnioffloader offload`, `wp omnioffloader restore`, and `wp omnioffloader status`.

== Upgrade Notice ==

= 0.1.4 =
Serve Media from Cloud setting, `--concurrency` for much faster WP-CLI uploads of large libraries, and a new `wp omnioffloader stop` command. Recommended for all users.

= 0.1.3 =
Faster offloading of existing media with a new Transfer Speed setting, a Tools screen to apply a changed retention policy to offloaded media, a new `wp omnioffloader retention` command, plus security and performance fixes. Recommended for all users.

= 0.1.2 =
Adds custom batch quantity limits and presets for Bulk Offload and Bring Back, proportional Bring Back disk estimation, WP-CLI restore --limit support, and fixes real-time progress updates under LiteSpeed Cache, Breeze, and persistent Object Cache (Redis/Memcached). Recommended for all users.

= 0.1.1 =
Adds presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi; real-time Media Library live status badges, meta box, and bulk actions; multipart uploads for large files; and major background queue stability and performance improvements. Recommended for all users.
