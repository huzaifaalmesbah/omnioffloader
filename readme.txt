=== OmniOffloader — Multi-Cloud Media Offload & CDN ===
Contributors: huzaifaalmesbah
Tags: offload, cloud storage, cdn, media library, s3
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload media to Amazon S3, Cloudflare R2, Wasabi or any S3 storage, serve it from a CDN, and restore it anytime with Bring Back.

== Description ==

Your media library is probably the biggest thing on your server. OmniOffloader moves it to cloud object storage, serves it from your own domain or CDN, and frees the disk space it was using — without changing a single URL in your content.

Every upload is copied to the cloud automatically: the original, every generated thumbnail, scaled versions, and modern WebP/AVIF sources. Image URLs and `srcset` attributes are rewritten on the fly, so your posts, pages and page builders keep working exactly as before.

And because offloading should never be a one-way door, **Bring Back** downloads everything from the cloud to your server again — one file or the whole library — and hands your URLs back to local paths.

= Supported cloud storage providers =

* **Amazon S3**
* **Cloudflare R2**
* **DigitalOcean Spaces**
* **Backblaze B2**
* **Wasabi**
* **Any S3-compatible storage** — MinIO, Storj, Linode, Scaleway and others, via the generic option where you supply your own endpoint and region

= Key features =

* **Automatic media offloading** — new uploads go to the cloud on upload, including thumbnails, `-scaled` originals and WebP/AVIF sources.
* **Content URL rewriting** — rewrites images, videos, audio, and Gutenberg media blocks to cloud storage on the fly without altering post database content.
* **Serve from your CDN** — media is delivered from your custom domain or CDN edge, not your origin server.
* **Three retention policies** — keep every local copy, delete only generated thumbnails (smart cleanup), or move everything to the cloud and reclaim the full disk space.
* **Bring Back restore** — pull media back from the cloud to your server, per file or in bulk, with a disk-space preflight so you know what it needs before it starts.
* **Bulk offload with live progress** — background processing with a real-time activity log, cancel at any time, resumes safely if a request is interrupted.
* **Custom batch processing** — offload or restore your entire library or process a specific custom quantity (e.g. 5, 10, 25, 50, 100, or any number) with quick presets and live progress tracking.
* **Mirror deletion** — deleting an attachment in WordPress removes its cloud objects too (optional, off by default).
* **Media library integration** — real-time status column icon, dedicated meta box on the edit screen, grid view badges, bulk actions, and dynamic row actions.
* **WP-CLI commands** — automate everything from the terminal or a scheduled job.
* **Credentials in wp-config** — define keys as PHP constants instead of storing them in the database, and the UI locks those fields.

= Works on any host, at any library size =

Bulk jobs are built for a small shared plan as much as a dedicated server. Work is processed in the background, large files are uploaded in parts, transfers that a host cuts short are reported and skipped instead of retried forever, and a misconfigured connection stops the run early with an explanation rather than grinding through your whole library.

= Why offload media to cloud storage? =

* **Reclaim disk space** — media is usually the bulk of a WordPress install, and object storage costs a fraction of hosting disk.
* **Faster media delivery** — files served from a CDN edge load closer to your visitors.
* **Lighter backups and migrations** — a site without gigabytes of uploads is far quicker to back up and move.
* **No lock-in** — Bring Back reverses the whole thing whenever you want.

= Licensing note =

This plugin bundles the AWS SDK for PHP (Apache-2.0), which is GPL-compatible under GPLv3 — covered by this plugin's "GPL-2.0-or-later" license.

== Installation ==

1. Upload the `omnioffloader` folder to `/wp-content/plugins/`, or install it from the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** menu.
3. Go to **OmniOffloader** in the admin menu and open **Settings**.
4. Pick your storage provider and enter its credentials (Access Key, Secret, Endpoint, Bucket) plus your Custom Domain / CDN URL, then click **Test Connection**.
5. Choose a retention policy. Start with **Retain Local Files** — it keeps every local copy while you confirm everything serves correctly.
6. Open the **Offload** page and start a bulk offload for your existing media.

== Frequently Asked Questions ==

= Which cloud storage providers are supported? =

Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 and Wasabi each have a dedicated setup preset. Anything else that speaks the S3 API — MinIO, Storj, Linode Object Storage, Scaleway and so on — works through the generic S3-Compatible option, where you supply the endpoint and region yourself.

= How do I configure Cloudflare R2? =

1. In Cloudflare Dashboard, navigate to **R2 > Overview** and create a bucket.
2. Under your bucket settings, connect a **Custom Domain** (e.g., `https://media.yourdomain.com`) or enable the `r2.dev` public access subdomain so your files can be served publicly.
3. On the R2 overview page, click **Manage R2 API Tokens**, create a token with **Object Read & Write** permissions, and copy the Access Key ID and Secret Access Key.
4. Note your **Account ID** from the right sidebar of the Cloudflare dashboard.
5. In OmniOffloader Settings, select **Cloudflare R2**, enter your Account ID, Access Key, Secret Key, Bucket Name, and your Custom Domain / CDN URL, then click **Test Connection**.

= How do I configure Backblaze B2? =

1. In Backblaze B2 Console, create a bucket. Under **Bucket Settings**, set **Files in Bucket are:** to **Public** so uploaded media can be viewed in web browsers.
2. Note your S3 Endpoint URL (e.g., `https://s3.us-east-005.backblazeb2.com`) and Region (e.g., `us-east-005`) from the bucket details.
3. Go to **Application Keys**, click **Add a New Application Key**, allow access to your bucket with **Read and Write** permissions, and copy the `keyID` and `applicationKey`.
4. In OmniOffloader Settings, select **Backblaze B2**, enter your Application Key ID, Application Key, Endpoint URL, Region, and Bucket Name. Leave the CDN URL blank to serve directly from Backblaze, or enter your Cloudflare CDN domain if configured.

= How do I configure Amazon S3? =

1. In the AWS Management Console, create an S3 bucket in your preferred region.
2. In the AWS IAM Console, create an IAM user with programmatic access and attach an S3 policy granting read/write permissions (e.g., `AmazonS3FullAccess` or a scoped bucket policy). Create and copy the Access Key ID and Secret Access Key.
3. To serve media publicly, configure a bucket policy granting `s3:GetObject` permission to `*`, or connect an **Amazon CloudFront** distribution pointing to your S3 bucket.
4. In OmniOffloader Settings, select **Amazon S3**, enter your Access Key, Secret Key, Region (e.g., `us-east-1`), Bucket Name, and your CloudFront/CDN URL (or leave blank to serve directly from AWS).

= How do I configure Storj (or other S3-compatible storage)? =

1. In the Storj Satellite console, create a bucket and navigate to **Access > Create S3 Credentials**. Copy your S3 Access Key and Secret Key.
2. In Storj, create a public access grant for your bucket via **Objects > [Your Bucket] > Share**, and copy the linksharing URL (e.g., `https://link.storjshare.io/s/<KEY>/<BUCKET>`). Replace `/s/` with `/raw/` (e.g., `https://link.storjshare.io/raw/<KEY>/<BUCKET>`).
3. In OmniOffloader Settings, select **Any S3-Compatible Storage**.
4. Set S3 Endpoint URL to `https://gateway.storjshare.io` (or `https://gateway.ap1.storjshare.io`), Region to `us-east-1`, check **Use Path-Style Endpoint**, enter your Access Key, Secret Key, Bucket Name, and paste your `https://link.storjshare.io/raw/...` URL in **Custom Domain / CDN URL**.

= Will offloading break my existing image URLs? =

No. URLs are rewritten as pages render, so your posts, pages, featured images and responsive `srcset` attributes all keep working. Nothing in your post content is edited, and no database search-replace is needed.

= Can I move my media back to my server later? =

Yes — that is what **Bring Back** is for. It downloads your media from the cloud to its original paths on your server, verifies every file arrived, and then stops rewriting URLs so everything serves locally again. You can restore a single attachment or the entire library.

= What happens to my images if I deactivate the plugin? =

That depends on the retention policy you used. With **Retain Local Files**, every file is still on your server and nothing changes. With **Smart Local Cleanup** or **Full Cloud Migration**, some or all local files were deleted after being uploaded, so URLs would point at files that are no longer on disk. **Run Bring Back before deactivating** if you used either cleanup policy.

= Do I have to delete my local files? =

No. The default policy, **Retain Local Files**, deletes nothing — the cloud simply becomes a second copy. Choose **Smart Local Cleanup** to delete only generated thumbnails while keeping every original, or **Full Cloud Migration** to reclaim the most space. Local files are only ever removed after the upload has been verified in your bucket.

= Why do I need a custom domain or CDN URL? =

Most storage buckets do not serve files publicly over HTTP by default, and Cloudflare R2 has no public URL at all until you connect one. Pointing a custom domain or CDN hostname at your bucket is what makes your media publicly reachable — and it is what lets the files be cached at the edge.

= Can I keep my access keys out of the database? =

Yes. Define them as constants in `wp-config.php` — for example `OMNIOFFLOADER_CLOUDFLARE_R2_KEY` and `OMNIOFFLOADER_CLOUDFLARE_R2_SECRET`. The plugin prefers constants over stored values, and the settings screen marks those fields as locked. Secrets are never sent back to the browser in either case.

= How long does a bulk offload take, and can I close the browser tab? =

Files are uploaded one at a time, so budget roughly one to four seconds per attachment depending on file size and your server's upload speed. Jobs run in the background and keep going after you close the tab. On hosts that block internal loopback requests, the admin screen drives the job instead — there, leave the Offload page open until it finishes.

= Can I run a bulk offload from WP-CLI? =

Yes:

`wp omnioffloader offload --all`
`wp omnioffloader offload --all --limit=500`
`wp omnioffloader restore --all`
`wp omnioffloader status`

Every write command supports `--dry-run`, and destructive options ask for confirmation unless you pass `--yes`. The command refuses to run while a bulk job started from the admin screen is active, so the two can never touch the same files at once.

= What about very large videos or files? =

Files above 64 MB are uploaded in parts, so a dropped connection costs one chunk rather than the whole transfer, and files larger than 5 GB are supported. If a transfer is still cut short by your host's time limit, that attachment is reported with an explanation and the job continues instead of getting stuck on it.

= Does this plugin work on multisite? =

The plugin activates and runs per site. It has not been through a dedicated multisite test pass yet, so treat multisite as unverified for now.

== Changelog ==

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

* Multi-provider expansion: added dedicated provider presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi, plus a generic S3-Compatible option for MinIO, Cloudflare R2, and custom endpoints.
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

= 0.1.2 =
Adds custom batch quantity limits and presets for Bulk Offload and Bring Back, proportional Bring Back disk estimation, WP-CLI restore --limit support, and fixes real-time progress updates under LiteSpeed Cache, Breeze, and persistent Object Cache (Redis/Memcached). Recommended for all users.

= 0.1.1 =
Adds presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi; real-time Media Library live status badges, meta box, and bulk actions; multipart uploads for large files; and major background queue stability and performance improvements. Recommended for all users.
