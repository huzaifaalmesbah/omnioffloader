=== OmniOffloader — Multi-Cloud Media Storage, CDN & Backup ===
Contributors: huzaifaalmesbah
Tags: media, offload, cloud storage, backup, cdn
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to cloud storage (AWS S3, R2, Spaces), deliver via CDN, and backup or restore files anytime with Bring Back.

== Description ==

OmniOffloader copies your WordPress media library to cloud object storage (Cloudflare R2, Amazon S3, DigitalOcean Spaces, Wasabi, Backblaze B2, Google Cloud Storage) and serves it from your custom domain or CDN, saving server disk space and accelerating your website.

**Key Features:**

* **Automatic Cloud Offloading**: Automatically copies new uploads to cloud storage (including original files, thumbnails, scaled images, and WebP/AVIF formats).
* **High-Speed CDN Delivery**: Serves media directly from your custom domain or CDN URL.
* **Smart Retention Policies**: Choose between keeping local copies, smart cleanup (removing generated thumbnails to save disk space), or full cloud migration.
* **Bring Back (Disaster Recovery)**: Restore offloaded media from the cloud back to your server at any time — per file or in bulk.
* **Disk Space Preflight**: Know exactly how much disk space is required before initiating a restore.
* **Mirror Deletion**: Automatically delete cloud objects when attachments are permanently deleted from WordPress.
* **Modern React Dashboard**: Sleek, intuitive interface built with WordPress components.
* **WP-CLI Support**: Complete command line automation for bulk operations and status monitoring.

**Upcoming Roadmap:**
* Amazon AWS S3, DigitalOcean Spaces, Backblaze B2, and Wasabi native presets.
* Automated scheduled Zip archives & safety backups stored directly to Google Drive.
* Multi-CDN routing and cache purge integrations.

**Licensing Note:** This plugin bundles the AWS SDK for PHP (Apache-2.0), which is GPL-compatible under GPLv3 — covered by this plugin's "GPL-2.0-or-later" license.

== Installation ==

1. Upload the `omnioffloader` folder to the `/wp-content/plugins/` directory, or install via the WordPress Plugins screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to **OmniOffloader** in the WordPress admin menu.
4. Enter your storage provider credentials (Access Key, Secret, Endpoint, Bucket, Custom Domain / CDN URL) and click **Test Connection**.

== Frequently Asked Questions ==

= What storage providers are currently supported? =

OmniOffloader currently supports Cloudflare R2 and any S3-compatible object storage provider (including Amazon S3, DigitalOcean Spaces, Wasabi, and Backblaze B2). Additional dedicated configuration presets are released continuously.

= Can I bring all my files back to my local server? =

Yes! The **Bring Back** feature downloads all your media from cloud storage back to your local server and seamlessly restores all WordPress URLs back to local paths.

= Why is a custom domain / CDN URL required? =

Cloud storage buckets often do not expose public HTTP endpoints by default. Connecting a custom domain or CDN URL (such as a Cloudflare custom domain or CDN edge) ensures your images and media are served fast and publicly to your visitors.

== Changelog ==

= 0.1.1 =
* Fix: Ensure vendor dependency source files are included in the distribution package.

= 0.1.0 =
* Initial release of OmniOffloader.
* Multi-cloud offload engine with Cloudflare R2 provider support.
* Modern React-based settings and control dashboard.
* Background bulk offload and bulk restore with real-time progress indicators.
* URL rewriting for post content, attachment URLs, and responsive srcset attributes.
* Media library filters and individual attachment action controls.
* Complete WP-CLI commands: `wp omnioffloader offload`, `wp omnioffloader restore`, and `wp omnioffloader status`.
