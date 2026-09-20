# OmniOffloader — Multi-Cloud Media Offload & CDN for WordPress

[![Version](https://img.shields.io/badge/version-0.1.1-blue.svg)](https://github.com/huzaifaalmesbah/omnioffloader)
[![WordPress](https://img.shields.io/badge/WordPress-6.6%20--%207.1-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**OmniOffloader** automatically offloads your WordPress media library to cloud object storage, serves assets lightning-fast from your CDN or custom domain, and frees server disk space. 

Unlike traditional offloaders where moving to the cloud is a one-way trip, OmniOffloader features **Bring Back**: seamless, verified restoration of offloaded media from the cloud back to your local server at any time.

---

## ✨ Features

- **🌐 Multi-Cloud Storage Providers**:
  - **Amazon S3** (AWS S3)
  - **Cloudflare R2** (Zero egress fees)
  - **Backblaze B2**
  - **DigitalOcean Spaces**
  - **Wasabi**
  - **S3-Compatible Storage** (MinIO, Ceph, Linode Object Storage, etc.)

- **🔄 Bring Back (Restoration Engine)**:
  - Restore single files, selected batches, or your entire library from the cloud back to your local server.
  - Verifies file integrity (MD5 checksum) before safely clearing offload metadata.
  - Optional cloud cleanup upon verified restore.

- **⚡ High-Performance Background Queues**:
  - Asynchronous, non-blocking upload handler for instant media uploads without browser waiting.
  - Hardened background workers with automatic lock renewal, self-healing stall recovery, consecutive failure circuit breakers, and delta status polling.
  - Multipart upload pipeline for large files (>64 MB, up to 5 GB+).

- **🛡️ Flexible Retention Policies**:
  - **Keep Local**: Copies media to cloud storage and serves via CDN while retaining full local backups.
  - **Smart Cleanup**: Retains the local original file and removes generated thumbnails to save 70%+ disk space while maintaining safety.
  - **Full Cloud Migration**: Moves all media files and thumbnails to the cloud, reclaiming 100% of uploads disk space on your server.

- **🎨 Seamless Media Library Integration**:
  - **List View**: Dedicated offload status column, live animated processing indicators, and instant AJAX row actions (`Offload now` / `Bring Back`).
  - **Grid View**: Real-time cloud badges on thumbnails, live progress indicators, and Attachment Details modal integration.
  - **Media Editor**: OmniOffloader Meta Box on edit media screens with file metadata, bucket details, and one-click actions.
  - **Native Bulk Actions**: Bulk offload or restore multiple files directly from the WordPress media library dropdown.

- **🔗 Transparent Content Rewriting**:
  - Rewrites media URLs and `srcset` responsive image attributes on the fly across Gutenberg blocks (`render_block`), Classic Editor content (`the_content`), and audio/video shortcodes.
  - Post database content is untouched and cleanly preserved.

- **💻 Modern React Admin Dashboard**:
  - Fast, accessible settings interface built with Gutenberg components (`@wordpress/components`).
  - Live progress bars, activity logs, stat cards, and credential verification testing.

- **⌨️ Comprehensive WP-CLI Support**:
  - `wp omnioffloader status`: Inspect library status, offloaded file counts, and storage metrics.
  - `wp omnioffloader offload`: Trigger background or inline media offloading.
  - `wp omnioffloader restore`: Trigger background or inline Bring Back restoration.

---

## 📋 Requirements

- **WordPress**: 6.6 or higher (tested up to 7.1)
- **PHP**: 8.1 or higher
- **PHP Extensions**: `curl`, `json`, `mbstring`, `openssl`, `simplexml`

---

## 🚀 Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/huzaifaalmesbah/omnioffloader.git
   ```
2. Activate the plugin through the WordPress admin (**Plugins > Installed Plugins**) or via WP-CLI:
   ```bash
   wp plugin activate omnioffloader
   ```
3. Navigate to **Settings > OmniOffloader** in your WordPress dashboard.
4. Select your storage provider, enter your bucket and credentials, and choose your retention policy.

---

## ⚙️ Provider Configuration Guide

### 1. Cloudflare R2
* **Storage Provider:** `Cloudflare R2`
* **Account ID:** Found on the right sidebar of the Cloudflare Dashboard under Account ID.
* **Access Key ID & Secret Access Key:** In Cloudflare Dashboard, go to **R2 > Manage R2 API Tokens**, create a token with **Object Read & Write** permissions.
* **Bucket Name:** Your R2 bucket name.
* **Custom Domain / CDN URL:** In your R2 bucket settings under **Settings > Public Access**, connect a custom domain (e.g. `https://media.yourdomain.com`) or enable the `r2.dev` public access subdomain. Paste that URL here.

### 2. Backblaze B2
* **Storage Provider:** `Backblaze B2`
* **Public Bucket Requirement:** In Backblaze B2 Console under **Bucket Settings**, set **Files in Bucket are:** to **`Public`** so media files can be displayed by web browsers.
* **S3 Endpoint URL & Region:** Found in bucket details (e.g. Endpoint: `https://s3.us-east-005.backblazeb2.com`, Region: `us-east-005`).
* **Application Key ID & Key:** In Backblaze Console, go to **Application Keys > Add a New Application Key** with **Read and Write** access to your bucket. Use `keyID` as Key and `applicationKey` as Secret.
* **Custom Domain / CDN URL:** Leave blank to serve directly from Backblaze, or enter your Cloudflare CDN domain if configured.

### 3. Amazon S3 (AWS)
* **Storage Provider:** `Amazon S3`
* **Region:** AWS region where your bucket was created (e.g. `us-east-1`).
* **Access Key ID & Secret Access Key:** In AWS IAM Console, create an IAM user with programmatic access and attach an S3 policy (e.g. `AmazonS3FullAccess` or scoped bucket policy).
* **Bucket Name:** Your AWS S3 bucket name.
* **Public Access / CDN:** Ensure your bucket policy allows public `s3:GetObject` reads (or connect an **Amazon CloudFront** distribution). Enter your CloudFront domain (e.g. `https://d111111abcdef8.cloudfront.net`) or leave blank to serve via AWS default S3 domain.

### 4. Storj (S3-Compatible)
* **Storage Provider:** `Any S3-Compatible Storage`
* **Provider Label:** `Storj`
* **S3 Endpoint URL:** `https://gateway.storjshare.io` (or satellite gateway e.g. `https://gateway.ap1.storjshare.io`).
* **Region:** `us-east-1`
* **Use Path-Style Endpoint:** `Enabled`
* **Access Key & Secret Key:** In Storj Satellite console, go to **Access > Create S3 Credentials**.
* **Custom Domain / CDN URL:** Create a public linkshare grant via **Objects > [Your Bucket] > Share**, and copy the URL. Replace `/s/` with `/raw/` (e.g. `https://link.storjshare.io/raw/<ACCESS_KEY>/<YOUR_BUCKET>`) and paste it here.

### 5. MinIO (Local & Self-Hosted)
* **Storage Provider:** `Any S3-Compatible Storage`
* **Provider Label:** `MinIO Local`
* **S3 Endpoint URL:** `http://127.0.0.1:9090` (or `http://127.0.0.1:9000`)
* **Region:** `us-east-1`
* **Use Path-Style Endpoint:** `Enabled`
* **Access Key ID & Secret Access Key:** Root credentials (e.g. `minioadmin` / `minioadmin`).
* **Bucket Name:** Your MinIO bucket name (set bucket policy to `download` / public read via `mc anonymous set download <alias>/<bucket>`).
* **Custom Domain / CDN URL:** Leave blank or enter `http://127.0.0.1:9090/<bucket>`.

---

## 🛠️ Development

### Setup

```bash
# Install PHP dependencies (AWS SDK, dev tools)
composer install

# Install JavaScript/TypeScript dependencies
npm install
```

### Build & Quality Commands

```bash
npm start            # Development build with watch mode (wp-scripts)
npm run build        # Production build
npm run check-types  # Strict TypeScript check (tsc --noEmit)
npm run lint:js      # ESLint with WordPress standards
composer phpcs       # WordPress Coding Standards (WPCS)
composer phpstan     # PHPStan static analysis (Level 6+)
npm run bundle       # Package production distribution zip
npm run bundle:org   # Package shaped for WordPress.org SVN release
```

### Automated Integration Tests

Integration tests run against a WordPress environment using WP-CLI `eval-file`:

```bash
# End-to-end offload test suite
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-offload.php

# End-to-end Bring Back restore test suite
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-restore.php

# Asynchronous non-blocking upload test suite
wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-async-upload.php
```

---

## 📄 License

OmniOffloader is open-source software licensed under the **[GNU General Public License v2.0 or later (GPL-2.0-or-later)](https://www.gnu.org/licenses/gpl-2.0.html)**.
