=== OmniOffloader — Multi-Cloud Media Offload & CDN ===
Contributors: huzaifaalmesbah
Tags: offload, cloud storage, cdn, media library, s3
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload media to Amazon S3, Cloudflare R2, Wasabi or any S3 storage, serve it from a CDN, and restore it anytime with Bring Back.

== Description ==

Your media library is probably the biggest thing on your server. OmniOffloader moves it to cloud object storage, serves it from your own domain or CDN, and frees the disk space it was using — without changing a single URL in your content.

Every upload is copied to the cloud automatically: the original, every generated thumbnail, the `-scaled` version and modern WebP/AVIF sources. Image URLs and `srcset` attributes are rewritten as pages render, so your posts, pages and page builders keep working exactly as before, and nothing in your database is search-replaced.

And because offloading should never be a one-way door, **Bring Back** downloads everything from the cloud to your server again — one file or the whole library — and hands your URLs back to local paths.

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
* **Adjustable transfer speed** — offload your existing, not-yet-offloaded media library and bring it back faster by moving 1, 3 or 6 files at the same time, or your own number from 1 to 10. Every setting runs inside a single PHP process, so faster never means more server processes.
* **Content URL rewriting** — images, galleries, video, audio, file, cover and media-text blocks, Classic Editor media tags and audio/video shortcodes are all served from the cloud, without editing post content.
* **Serve from your CDN** — media is delivered from your custom domain or CDN edge, not your origin server.
* **Three retention policies** — keep every local copy, delete only generated thumbnails (Smart Local Cleanup), or move everything to the cloud (Full Cloud Migration).
* **Apply a new policy to old media** — change the retention policy later and apply it to media you already offloaded from the Tools screen. Every file is checked in the cloud before its local copy is deleted.
* **Bring Back** — restore media from the cloud to your server, per file or in bulk, with a disk-space preflight so you know what it needs before it starts. Deleting the cloud copy afterwards is optional and off by default.
* **Media Library integration** — a status column, grid view badges, a meta box on the edit screen, row actions and bulk actions for Offload and Bring Back.
* **Mirror Delete** — deleting an attachment in WordPress removes its cloud copies too (optional).
* **Object versioning and path prefix** — add a timestamp segment to object keys so CDN caches never serve a stale file, and store objects under a folder of your choice.
* **WP-CLI** — offload, restore, apply retention and check status from the terminal, with a full command reference on the Tools screen.
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

= For developers =

Filters: `omnioffloader_cloud_providers`, `omnioffloader_object_acl`, `omnioffloader_attachment_key`, `omnioffloader_attachment_delete_keys`, `omnioffloader_should_offload_attachment`, `omnioffloader_use_async_upload`, `omnioffloader_should_apply_retention`, `omnioffloader_media_block_names`, `omnioffloader_log_provider_errors`, `omnioffloader_multipart_threshold`, `omnioffloader_transfer_concurrency`, `omnioffloader_bulk_failure_limit`.

Actions: `omnioffloader_before_offload`, `omnioffloader_after_offload`, `omnioffloader_before_restore`, `omnioffloader_after_restore`.

REST API namespace: `omnioffloader/v1`. WP-CLI: `wp omnioffloader`.

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

= How do I configure Amazon S3? =

1. In the AWS console, create an S3 bucket in your preferred region.
2. In IAM, create a user with programmatic access and a policy that allows reading and writing objects in that bucket. Create an access key and copy the Access Key ID and Secret Access Key.
3. To serve media publicly, allow public `s3:GetObject` on the bucket with a bucket policy, or put an **Amazon CloudFront** distribution in front of it.
4. In OmniOffloader Settings, select **Amazon S3** and enter the keys, Bucket Name, Region (for example `us-east-1`) and your CloudFront or CDN URL (or leave it blank to serve directly from S3).

= How do I configure DigitalOcean Spaces? =

1. In the DigitalOcean control panel, create a Space and, if you want, enable its built-in CDN.
2. Create a **Spaces access key** and copy the key and secret.
3. Note the region endpoint (for example `https://nyc3.digitaloceanspaces.com`) and region (`nyc3`).
4. In OmniOffloader Settings, select **DigitalOcean Spaces** and enter the keys, Endpoint URL, Region, Space name and your CDN or custom domain.

= How do I configure Backblaze B2? =

1. In the Backblaze B2 console, create a bucket and set **Files in Bucket are** to **Public** so media can be viewed in browsers.
2. Note the bucket's S3 Endpoint (for example `https://s3.us-east-005.backblazeb2.com`) and its region (`us-east-005`).
3. Under **Application Keys**, add a key with **Read and Write** access to the bucket, and copy the `keyID` and `applicationKey`.
4. In OmniOffloader Settings, select **Backblaze B2** and enter the Application Key ID, Application Key, Endpoint URL, Region and Bucket Name. Leave the CDN URL blank to serve directly from Backblaze, or enter your CDN domain.

= How do I configure Wasabi? =

1. In the Wasabi console, create a bucket and note its region (for example `us-east-1`).
2. Create an access key and copy the Access Key ID and Secret Access Key.
3. Make the files publicly readable with a bucket policy, or serve them through a CDN in front of the bucket.
4. In OmniOffloader Settings, select **Wasabi** and enter the keys, Bucket Name, Region and your CDN or custom domain.

= How do I configure Storj or other S3-compatible storage? =

1. In the Storj console, create a bucket and open **Access → Create S3 Credentials**. Copy the Access Key and Secret Key.
2. Share the bucket publicly (**Objects → your bucket → Share**) and copy the link-sharing URL, for example `https://link.storjshare.io/s/<KEY>/<BUCKET>`. Replace `/s/` with `/raw/`.
3. In OmniOffloader Settings, select **Any S3-Compatible Storage**.
4. Set the S3 Endpoint URL to `https://gateway.storjshare.io`, Region to `us-east-1`, turn on **Use Path-Style Endpoint**, enter your keys and Bucket Name, and paste the `https://link.storjshare.io/raw/...` URL as the Custom Domain / CDN URL.

The same fields work for MinIO, OVHcloud, Linode, Scaleway and other S3-compatible services. The optional **Provider Label** lets you name the provider as it appears in the plugin.

= Which Transfer Speed should I choose? =

It matters most when you offload media that is already in your library but not in the cloud yet — with Bulk Offload, Media Library bulk actions or WP-CLI — and when you use Bring Back. New uploads are offloaded in the background as they arrive, so you rarely notice their speed. The setting chooses how many files are uploaded or downloaded at the same time:

* **Low** — one file at a time. The lightest option for small shared hosting plans.
* **Balanced** — 3 files at a time. The default, and right for most sites.
* **Fast** — 6 files at a time, for a VPS or dedicated server.
* **Custom** — any number from 1 to 10.

All four run in the same single PHP process; a faster setting overlaps the time spent waiting on the network instead of starting more processes. Each extra file only adds a little memory and bandwidth. Bulk Offload, Bring Back, new uploads and WP-CLI all follow this setting.

= How long does a bulk offload take, and can I close the browser tab? =

It depends mostly on how quickly your server reaches your storage provider, how large your files are, and how many thumbnail sizes your theme creates. A higher Transfer Speed finishes sooner on a server that can handle it.

Jobs run in the background and keep going after you close the tab. On hosts that block WordPress's internal loopback requests, the admin screen drives the job instead — there, leave the Offload page open until it finishes, or use WP-CLI.

= Will offloading break my existing image URLs? =

No. URLs are rewritten as pages render, so posts, pages, featured images and responsive `srcset` attributes all keep working. Nothing in your post content is edited, and no database search-replace is needed.

= Can I move my media back to my server later? =

Yes — that is what **Bring Back** is for. It downloads your media from the cloud to its original paths, checks that every file arrived, and then stops rewriting URLs so everything serves locally again. You can restore a single attachment, a set number, or the whole library. The cloud copy is kept unless you turn on deleting it after restore.

= What happens to my images if I deactivate the plugin? =

That depends on the retention policy you used. With **Retain Local Files**, every file is still on your server and nothing changes. With **Smart Local Cleanup** or **Full Cloud Migration**, some or all local files were deleted after upload, so URLs would point at files that are no longer on disk. **Run Bring Back before deactivating** if you used either cleanup policy.

= Do I have to delete my local files? =

No. The default policy, **Retain Local Files**, deletes nothing — the cloud simply becomes a second copy. Choose **Smart Local Cleanup** to delete only generated thumbnails while keeping every original, or **Full Cloud Migration** to reclaim the most space. Local files are only removed after the upload has been confirmed in your bucket.

= I changed the retention policy. Why are old files still on my server? =

A retention policy is applied when a file is offloaded, so a new policy only affects new uploads at first. Go to **OmniOffloader → Tools → Apply Retention Policy to Existing Media** (or run `wp omnioffloader retention`) to update media you offloaded earlier. Before a local file is deleted, its cloud copy is checked by size. Files missing from the cloud are uploaded first, and a file whose cloud copy differs is kept and reported. Moving to a looser policy (for example from Full Cloud Migration back to Retain Local Files) downloads the local copies again.

= Why do I need a custom domain or CDN URL? =

Most storage buckets do not serve files publicly by default, and Cloudflare R2 has no public URL at all until you connect one. Pointing a custom domain or CDN hostname at your bucket is what makes your media reachable — and it lets the files be cached at the edge.

= Can I keep my access keys out of the database? =

Yes. Define them as constants in `wp-config.php`, named `OMNIOFFLOADER_{PROVIDER}_{FIELD}` — for example:

`define( 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY', '...' );`
`define( 'OMNIOFFLOADER_CLOUDFLARE_R2_SECRET', '...' );`

Constants take priority over saved values, and the settings screen shows those fields as locked. Secrets are never sent back to the browser either way, and stored credentials are kept out of WordPress's autoloaded options.

= Can I run it from WP-CLI? =

Yes. **OmniOffloader → Tools** lists every command and option with copy buttons.

`wp omnioffloader offload --all [--limit=<number>] [--dry-run]`
`wp omnioffloader offload --ids=12,34`
`wp omnioffloader restore --all [--limit=<number>] [--delete-cloud] [--dry-run]`
`wp omnioffloader retention [--policy=<0|1|2>] [--dry-run] [--format=json]`
`wp omnioffloader status`

Every write command supports `--dry-run`, and destructive options ask for confirmation unless you pass `--yes`. Commands refuse to start while another bulk job is running (`--force` overrides this), so two jobs never touch the same files. WP-CLI offloads use your Transfer Speed setting.

= Who can offload or bring back media? =

Only administrators (users with the `manage_options` capability). Other users keep their normal WordPress rights: they upload and delete their own media, and with Mirror Delete on, deleting an attachment also removes its cloud copy.

= What about very large videos or files? =

Files above 64 MB are uploaded in parts, so a dropped connection costs one chunk rather than the whole transfer, and files larger than 5 GB are supported. If a transfer is still cut short by your host's time limit, that attachment is reported with an explanation and the job moves on.

= Does it work with caching plugins and object caches? =

Yes. The plugin's own REST requests tell page caches not to store them (including LiteSpeed Cache and Breeze), and bulk progress stays live under Redis or Memcached object caching. On the front end the plugin only rewrites URLs and does not add database queries per image.

= Does this plugin work on multisite? =

Yes, per site. Each site in a network has its own settings, credentials and background queues. It has not been through a dedicated multisite test pass yet, so treat large networks as unverified for now.

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

= 0.1.3 =

**Apply Retention Policy to Existing Media**

* New Tools screen: apply the saved retention policy to media that was offloaded under an earlier policy. Switching from Retain Local Files to Smart Local Cleanup or Full Cloud Migration now frees space for existing media too, not just new uploads.
* Every local file is checked in cloud storage by size before it is deleted. Files missing from the bucket (for example thumbnails regenerated after the offload) are uploaded first; a file whose cloud copy differs is kept and logged. An attachment only counts as updated once every file was handled, so failures are retried on the next run.
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
* Provider errors are only written to the PHP error log when `WP_DEBUG` is on (filter `omnioffloader_log_provider_errors`); they are always recorded on the attachment.
* Retention: when a cloud copy differs in size from the server file (for example after an image optimizer shrank the thumbnails), the server file is uploaded again and verified, instead of the run stopping with a misleading connection error.
* Retention: attachments excluded with the `omnioffloader_should_apply_retention` filter no longer show as "needs updating" forever.
* Progress polling no longer runs two timers at once, keeps polling after you leave the page, or restarts after Cancel.
* Deactivating the plugin now also clears the background upload queue and its scheduled event.
* Counts use proper singular/plural translations, and the activity log's "Attachment #" label is translatable.
* New filter `omnioffloader_media_block_names` to register third-party media blocks.
* The `omnioffloader_after_offload` action fires again after an attachment is offloaded and verified.

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

= 0.1.3 =
Faster offloading of existing media with a new Transfer Speed setting, a Tools screen to apply a changed retention policy to offloaded media, a new `wp omnioffloader retention` command, plus security and performance fixes. Recommended for all users.

= 0.1.2 =
Adds custom batch quantity limits and presets for Bulk Offload and Bring Back, proportional Bring Back disk estimation, WP-CLI restore --limit support, and fixes real-time progress updates under LiteSpeed Cache, Breeze, and persistent Object Cache (Redis/Memcached). Recommended for all users.

= 0.1.1 =
Adds presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi; real-time Media Library live status badges, meta box, and bulk actions; multipart uploads for large files; and major background queue stability and performance improvements. Recommended for all users.
