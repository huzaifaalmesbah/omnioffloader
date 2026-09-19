=== OmniOffloader — Multi-Cloud Media Offload & CDN ===
Contributors: huzaifaalmesbah
Tags: offload, cloud storage, cdn, media library, s3
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
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

= 0.1.1 =
Adds presets for Amazon S3, DigitalOcean Spaces, Backblaze B2, and Wasabi; real-time Media Library live status badges, meta box, and bulk actions; multipart uploads for large files; and major background queue stability and performance improvements. Recommended for all users.
