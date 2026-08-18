=== Smart Media Offloader ===
Contributors: huzaifaalmesbah
Tags: media, offload, cloudflare r2, cdn, cloud storage
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Cloudflare R2, serve it from your CDN, and bring files back to your server anytime.

== Description ==

Smart Media Offloader copies your media library to Cloudflare R2 object storage and serves it from your custom domain (CDN), saving server disk space and speeding up your site.

**Features (v0.x pre-release):**

* Automatic offload of new uploads to Cloudflare R2
* Serve media from your custom domain / CDN
* Three retention policies: keep local files, smart cleanup (remove thumbnails), or full cloud migration
* **Bring Back**: restore offloaded media from the cloud back to your server — per file or in bulk
* Modern React-based admin panel
* Credentials via settings or `wp-config.php` constants
* WP-CLI commands

More S3-compatible providers (Amazon S3, Backblaze B2, DigitalOcean Spaces, Wasabi, MinIO) are planned, added one per release.

**Licensing note:** this plugin bundles the AWS SDK for PHP (Apache-2.0), which is GPL-compatible under GPLv3 — covered by this plugin's "GPL-2.0-or-later" license.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/smart-media-offloader/`, or install via the Plugins screen.
2. Activate the plugin.
3. Go to **Media Offloader** in the admin menu.
4. Enter your Cloudflare R2 credentials (Access Key, Secret, Endpoint, Bucket, Custom Domain) and press **Test Connection**.

== Frequently Asked Questions ==

= Where do I get Cloudflare R2 credentials? =

In the Cloudflare dashboard, open R2, create a bucket, then create an R2 API token (Access Key ID + Secret Access Key). Your endpoint is `https://<account-id>.r2.cloudflarestorage.com`.

= Why is a custom domain required? =

R2 buckets have no public URL by default. Connect a custom domain (or the public r2.dev URL) to the bucket so media can be served publicly.

= Can I undo offloading? =

Yes — the Bring Back feature downloads your files from the cloud back to the server and switches URLs back to local, per attachment or in bulk.

== Changelog ==

= 0.1.0 =
* Pre-release: Cloudflare R2 provider, React admin panel, credentials + connection test.
* Offload pipeline: new uploads are automatically offloaded to Cloudflare R2 (original, thumbnails, scaled original, WebP/AVIF sources).
* URL rewriting: attachment URLs, srcset sources, and content image tags serve from your custom domain.
* Retention policies enforced after verified uploads: retain local, smart cleanup, or full cloud migration.
* Mirror Delete: deleting an attachment also removes its cloud objects (including editor backups).
* Filename collision protection when local copies were removed.
* Bulk offload: move the whole library to the cloud in the background, with live progress and cancel in the admin panel.
* Media Library: "Offloaded / Local only" filter, offload status in attachment details, "Offload now" row action.
* Bring Back: restore offloaded media from the cloud to your server — per attachment ("Bring Back" row action) or in bulk, with live progress and cancel.
* Disk-space preflight: see how much a full restore would download and whether your disk has room, before starting.
* Optional cloud cleanup: choose to delete cloud objects after each restore is verified on your server (off by default).
* Restores are atomic and verified, confined to the uploads directory, and resolve each attachment's original provider — they keep working after switching providers.
* WP-CLI: `wp smowp offload --all|--ids=… [--dry-run]`, `wp smowp restore --all|--ids=… [--delete-cloud] [--dry-run]`, and `wp smowp status`.
