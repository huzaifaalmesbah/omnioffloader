# OmniOffloader — Multi-Cloud Media Offload & CDN for WordPress

[![Version](https://img.shields.io/badge/version-0.1.3-blue.svg)](https://wordpress.org/plugins/omnioffloader/)
[![WordPress](https://img.shields.io/badge/WordPress-6.6%20--%207.1-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**OmniOffloader** offloads your WordPress media library to cloud object storage, serves it from your CDN or custom domain, and frees server disk space.

Moving to the cloud is not a one-way trip: **Bring Back** restores offloaded media from the cloud to your server at any time, per file or in bulk, and verifies every file before handing URLs back to local paths.

---

## ✨ Features

- **🌐 Multi-cloud storage providers**
  - Amazon S3
  - Cloudflare R2
  - Backblaze B2
  - DigitalOcean Spaces
  - Wasabi
  - Any S3-compatible storage (MinIO, Storj, OVHcloud, Linode Object Storage, Scaleway, …) with your own endpoint, region and display name

- **🔄 Bring Back (restoration engine)**
  - Restore single files, a chosen number, or the whole library.
  - Files already on the server are checked against the cloud with one request per attachment; missing ones download side by side.
  - Every download goes to a temp file and is moved into place only when complete; offload records are cleared only after every expected file is present.
  - Optional cloud cleanup after a verified restore (off by default).
  - Disk-space preflight comparing the required space with what the server has free.

- **⚡ Transfer Speed**
  - Low (1 file at a time), Balanced (3, default), Fast (6) or Custom (1–10).
  - Applies to Bulk Offload, Bring Back, new uploads and WP-CLI.
  - Always a single PHP process — faster settings overlap network waits instead of starting more workers.

- **📦 Bulk jobs and batch controls**
  - Process the whole library or a custom number, with quick presets (`5`, `10`, `25`, `50`, `100`, `All`).
  - Background queue with lock renewal, stall self-healing, a consecutive-failure circuit breaker and live progress.
  - Only one bulk job (offload, Bring Back or retention) runs at a time, whether started from the admin, the Media Library or WP-CLI.
  - Multipart uploads for files of 64 MB and more, including files beyond 5 GB.

- **🛡️ Retention policies**
  - **Retain Local Files** — the cloud is a second copy; nothing is deleted.
  - **Smart Local Cleanup** — keep originals, delete generated thumbnails.
  - **Full Cloud Migration** — keep nothing on the server.
  - **Apply to existing media** from the Tools screen or WP-CLI: every local file is verified in the bucket by size before it is deleted; moving to a looser policy downloads local copies again.

- **🎨 Media Library integration**
  - List view status column, grid view badges and a meta box on the edit screen.
  - Row actions and bulk actions to offload or bring back selected media (administrators only).

- **🔗 Transparent URL rewriting**
  - Image, gallery, video, audio, file, cover and media-text blocks, Classic Editor media tags, audio/video shortcodes and `srcset` are served from the cloud.
  - Post content in the database is never modified.

- **💻 React admin app**
  - Built with `@wordpress/components` and TypeScript: Dashboard, Settings, Offload, Bring Back and Tools screens with live progress and activity logs.

- **⌨️ WP-CLI**
  - `wp omnioffloader offload` — offload media (`--all`, `--ids`, `--limit`, `--dry-run`).
  - `wp omnioffloader restore` — Bring Back (`--all`, `--ids`, `--limit`, `--delete-cloud`, `--dry-run`).
  - `wp omnioffloader retention` — apply the retention policy to offloaded media (`--policy`, `--ids`, `--limit`, `--dry-run`, `--format`).
  - `wp omnioffloader status` — library and connection status.
  - The Tools screen lists every command and option with copy buttons.

---

## 📋 Requirements

- **WordPress**: 6.6 or higher (tested up to 7.1)
- **PHP**: 8.1 or higher
- **PHP extensions**: `curl`, `json`, `mbstring`, `openssl`, `simplexml`

---

## 🚀 Installation

Install **OmniOffloader** from **Plugins → Add New** in WordPress, or build it from source (see [Development](#️-development)).

1. Activate the plugin through **Plugins → Installed Plugins**, or with WP-CLI:
   ```bash
   wp plugin activate omnioffloader
   ```
2. Open **OmniOffloader → Settings** in the WordPress admin menu.
3. Select your storage provider, enter your bucket and credentials, and click **Test Connection**.
4. Choose a retention policy and a Transfer Speed, then start a bulk offload from **OmniOffloader → Offload**.

---

## ⚙️ Provider Configuration Guide

### Cloudflare R2
* **Access Key ID & Secret Access Key:** Cloudflare dashboard → **R2 → Manage R2 API Tokens**, create a token with **Object Read & Write** permission.
* **Endpoint URL:** the bucket's S3 API endpoint, `https://<account-id>.r2.cloudflarestorage.com`, from the bucket settings.
* **Bucket Name:** your R2 bucket name.
* **Custom Domain / CDN URL:** in the bucket's **Settings**, connect a custom domain (for example `https://media.yourdomain.com`) or enable the `r2.dev` public URL, and paste it here. R2 has no public URL until you do.

### Amazon S3
* **Access Key ID & Secret Access Key:** create an IAM user with programmatic access and a policy allowing object read/write on the bucket.
* **Bucket Name** and **Region** (for example `us-east-1`).
* **Custom Domain / CDN URL:** your CloudFront domain, or leave blank to serve from S3 (the bucket policy must then allow public `s3:GetObject`).

### DigitalOcean Spaces
* **Spaces Access Key & Secret Key:** create a Spaces access key in the control panel.
* **Endpoint URL** (for example `https://nyc3.digitaloceanspaces.com`) and **Region** (`nyc3`).
* **Space / Bucket Name** and your Spaces CDN or custom domain.

### Backblaze B2
* **Bucket:** set **Files in Bucket are** to **Public** so browsers can load the media.
* **S3 Endpoint URL & Region:** from the bucket details (for example `https://s3.us-east-005.backblazeb2.com` and `us-east-005`).
* **Application Key ID & Application Key:** **Application Keys → Add a New Application Key** with **Read and Write** access to the bucket.
* **Custom Domain / CDN URL:** leave blank to serve from Backblaze, or enter your CDN domain.

### Wasabi
* **Access Key ID & Secret Access Key:** create an access key in the Wasabi console.
* **Bucket Name** and **Region** (for example `us-east-1`).
* **Custom Domain / CDN URL:** make the files publicly readable with a bucket policy, or serve them through a CDN.

### Storj (S3-compatible)
* **Storage Provider:** `Any S3-Compatible Storage`, **Provider Label:** `Storj`
* **S3 Endpoint URL:** `https://gateway.storjshare.io` (or a regional gateway such as `https://gateway.ap1.storjshare.io`)
* **Region:** `us-east-1`, **Use Path-Style Endpoint:** enabled
* **Access Key & Secret Key:** Storj console → **Access → Create S3 Credentials**.
* **Custom Domain / CDN URL:** share the bucket publicly (**Objects → your bucket → Share**), replace `/s/` with `/raw/` in the link (`https://link.storjshare.io/raw/<ACCESS_KEY>/<BUCKET>`) and paste it here.

### MinIO (local & self-hosted)
* **Storage Provider:** `Any S3-Compatible Storage`, **Provider Label:** `MinIO`
* **S3 Endpoint URL:** your MinIO API address, for example `http://127.0.0.1:9000`
* **Region:** `us-east-1`, **Use Path-Style Endpoint:** enabled
* **Access Key ID & Secret Access Key:** a MinIO access key.
* **Bucket:** allow public reads (`mc anonymous set download <alias>/<bucket>`); **Custom Domain / CDN URL:** leave blank or enter `http://127.0.0.1:9000/<bucket>`.

### Keeping keys out of the database

Define credentials as constants in `wp-config.php`, named `OMNIOFFLOADER_{PROVIDER}_{FIELD}`:

```php
define( 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY', '...' );
define( 'OMNIOFFLOADER_CLOUDFLARE_R2_SECRET', '...' );
```

Constants take priority over saved values, and the settings screen shows those fields as locked.

---

## 🛠️ Development

This project uses **[pnpm](https://pnpm.io/)** — the version is pinned in `package.json` (`packageManager`). The easiest way to get the right version is Corepack, which ships with Node.js:

```bash
corepack enable
```

### Setup

```bash
# PHP dependencies (AWS SDK, dev tools)
composer install

# JavaScript/TypeScript dependencies
pnpm install
```

### Build & quality commands

```bash
pnpm start            # Development build with watch mode (wp-scripts)
pnpm build            # Production build
pnpm check-types      # Strict TypeScript check (tsc --noEmit)
pnpm lint:js          # ESLint with WordPress standards
composer phpcs        # WordPress Coding Standards
composer phpstan      # PHPStan static analysis (level 8)
pnpm bundle           # Build + distribution zip
pnpm bundle:org       # Same, shaped for the WordPress.org SVN layout
```

Run `pnpm check-types`, `pnpm lint:js`, `composer phpstan` and `composer phpcs` before committing — CI only builds and deploys.

### Integration tests

The tests are `wp eval-file` scripts run against a real WordPress install. `tests/MockProvider.php` stands in for cloud storage, so no credentials are needed:

```bash
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-retention.php
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-async-upload.php

# Seed local-only test attachments: <count> <prefix>
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php 20 myprefix
```

`tests/integration-bulk-rest.php` hits a live bucket and needs real credentials.

---

## 🚀 WordPress.org Deployment & Maintenance

### 1. Full Release Deployment
To deploy a new plugin version to the WordPress.org Plugin Directory:
1. Update `Version:` in `omnioffloader.php`, `OMNIOFFLOADER_VERSION`, and `Stable tag` in `readme.txt`.
2. Commit and create a GitHub Release or tag (e.g. `0.1.3`).
3. The `.github/workflows/deploy-on-pushing-plugin.yml` workflow automatically builds production assets and commits them to WordPress.org SVN `trunk/` and `tags/<version>`.

### 2. Readme & Asset Updates (Without a Code Release)
When you only want to update `readme.txt` (FAQ, description, changelog text) or banners/icons in `.wordpress-org/` without releasing new code or bumping the version:
* **Option A (Branch Push):** Push to the `assets` branch:
  ```bash
  git checkout -b assets
  git add readme.txt .wordpress-org/
  git commit -m "docs: update readme and banners"
  git push origin assets --force
  ```
* **Option B (Manual Dispatch):** Go to GitHub repo → **Actions** → **Plugin asset/readme update** → click **Run workflow**.

### 3. Removing a Broken or Mistagged SVN Tag
If a broken tag was deployed or a version tag needs to be removed, do it from the command line:
```bash
# Remove from WordPress.org SVN
svn rm "https://plugins.svn.wordpress.org/omnioffloader/tags/0.1.0" -m "Remove broken tag 0.1.0" --username your_svn_username

# Remove from GitHub Git
git tag -d 0.1.0
git push origin --delete 0.1.0
```

---

## 📄 License

OmniOffloader is open-source software licensed under the **[GNU General Public License v2.0 or later (GPL-2.0-or-later)](https://www.gnu.org/licenses/gpl-2.0.html)**.
