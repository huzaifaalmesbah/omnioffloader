---
title: Hooks reference
description: Every filter and action OmniOffloader provides, with parameters, defaults and examples.
sidebar:
  order: 1
---

OmniOffloader has filters and actions for the parts of offloading you may want to change. Put the code in a small custom plugin or a must-use plugin (`wp-content/mu-plugins/`), so it keeps working when you change your theme.

## Filters at a glance

| Filter | Use it to |
| --- | --- |
| [`omnioffloader_should_offload_attachment`](#omnioffloader_should_offload_attachment) | Keep some attachments out of the cloud. |
| [`omnioffloader_should_apply_retention`](#omnioffloader_should_apply_retention) | Keep some attachments' local files when a retention policy is applied. |
| [`omnioffloader_use_async_upload`](#omnioffloader_use_async_upload) | Upload a new attachment right away instead of in the background. |
| [`omnioffloader_attachment_key`](#omnioffloader_attachment_key) | Change the object key of an attachment's main file. |
| [`omnioffloader_attachment_delete_keys`](#omnioffloader_attachment_delete_keys) | Add extra files to delete from the cloud with Mirror Delete. |
| [`omnioffloader_object_acl`](#omnioffloader_object_acl) | Send an ACL (such as `public-read`) with each upload. |
| [`omnioffloader_media_block_names`](#omnioffloader_media_block_names) | Rewrite URLs in third-party media blocks. |
| [`omnioffloader_transfer_concurrency`](#omnioffloader_transfer_concurrency) | Override how many files move at the same time. |
| [`omnioffloader_multipart_threshold`](#omnioffloader_multipart_threshold) | Change the file size at which uploads are split into parts. |
| [`omnioffloader_bulk_failure_limit`](#omnioffloader_bulk_failure_limit) | Change how many failures in a row stop a bulk job. |
| [`omnioffloader_free_disk_space`](#omnioffloader_free_disk_space) | Report the real free space in a hosting quota. |
| [`omnioffloader_cpanel_quota_file`](#omnioffloader_cpanel_quota_file) | Point to cPanel's quota file on a custom layout. |
| [`omnioffloader_check_folder_claim`](#omnioffloader_check_folder_claim) | Turn off the shared-bucket folder check. |
| [`omnioffloader_log_provider_errors`](#omnioffloader_log_provider_errors) | Write provider errors to the PHP error log. |
| [`omnioffloader_cloud_providers`](#omnioffloader_cloud_providers) | Register your own storage provider. |

## Actions at a glance

| Action | Fires |
| --- | --- |
| [`omnioffloader_before_offload`](#omnioffloader_before_offload) | Before an attachment is offloaded. |
| [`omnioffloader_after_offload`](#omnioffloader_after_offload) | After an attachment was offloaded and verified in the bucket. |
| [`omnioffloader_before_restore`](#omnioffloader_before_restore) | Before an attachment is restored (Bring Back). |
| [`omnioffloader_after_restore`](#omnioffloader_after_restore) | After an attachment was fully restored to the server. |
| [`omnioffloader_transfer_heartbeat`](#omnioffloader_transfer_heartbeat) | Every 30 seconds while a file is uploading or downloading. |

---

## Offloading

### `omnioffloader_should_offload_attachment`

Whether an attachment should be offloaded. Return `false` to keep it on the server only.

| Parameter | Type | Description |
| --- | --- | --- |
| `$should` | `bool` | Default `true`. |
| `$attachment_id` | `int` | Attachment ID. |

Skipped attachments are not errors. They show as **Skipped** in the activity log and the WP-CLI summary, and they do not count towards the failure limit.

```php
// Keep PDFs on the server.
add_filter( 'omnioffloader_should_offload_attachment', function ( $should, $attachment_id ) {
	if ( 'application/pdf' === get_post_mime_type( $attachment_id ) ) {
		return false;
	}
	return $should;
}, 10, 2 );
```

### `omnioffloader_use_async_upload`

Whether a new upload is offloaded in the background. This only applies when **Background Cloud Offload** is on in Settings.

| Parameter | Type | Description |
| --- | --- | --- |
| `$use_async` | `bool` | Default `true`. `false` when the upload happens in a WP-CLI command (for example `wp media import`), so the terminal waits for the upload. |
| `$attachment_id` | `int` | Attachment ID. |

```php
// Upload videos immediately, while the request is still running.
add_filter( 'omnioffloader_use_async_upload', function ( $use_async, $attachment_id ) {
	if ( str_starts_with( (string) get_post_mime_type( $attachment_id ), 'video/' ) ) {
		return false;
	}
	return $use_async;
}, 10, 2 );
```

### `omnioffloader_attachment_key`

The object key (path inside the bucket) of an attachment's **main file**.

| Parameter | Type | Description |
| --- | --- | --- |
| `$key` | `string` | Default: the attachment's folder path (Path Prefix, upload folder such as `2026/09/`, and the version segment when **Object Versioning** is on) plus the file name. |
| `$attachment_id` | `int` | Attachment ID. |

:::caution
This filter changes the main file's key only. Media URLs and the keys of generated sizes are built from the attachment's folder path. If you move the main file to another folder or name, its URL will not match the object. Use the **Custom Path Prefix** setting to change where files go.
:::

### `omnioffloader_object_acl`

The ACL sent with each uploaded object. The default is an empty string, which sends no ACL header at all.

| Parameter | Type | Description |
| --- | --- | --- |
| `$acl` | `string` | Default `''` (no ACL). For example `'public-read'`. |
| `$file` | `string` | Absolute path of the local file. Empty when the object is copied inside the bucket ([`wp omnioffloader move`](/wp-cli/move/)). |
| `$key` | `string` | Object key. |

```php
// Make objects publicly readable on a bucket that still uses ACLs.
add_filter( 'omnioffloader_object_acl', function ( $acl, $file, $key ) {
	return 'public-read';
}, 10, 3 );
```

:::danger
**Cloudflare R2 rejects ACL headers**, and Amazon S3 buckets with "Bucket owner enforced" object ownership (the default for new buckets) reject them too. With either one, every upload fails. Only return an ACL when you know your bucket accepts it. Most setups make files public with a bucket policy or a CDN instead.
:::

### `omnioffloader_media_block_names`

Block names whose `id` or `mediaId` attribute is an attachment ID. URLs inside these blocks are rewritten to the cloud.

| Parameter | Type | Description |
| --- | --- | --- |
| `$block_names` | `string[]` | Default: `core/image`, `core/video`, `core/audio`, `core/file`, `core/cover`, `core/media-text`, `core/gallery`. |

```php
// A third-party block that stores the attachment ID in "id" or "mediaId".
add_filter( 'omnioffloader_media_block_names', function ( $block_names ) {
	$block_names[] = 'acme/hero-video';
	return $block_names;
} );
```

## Retention

### `omnioffloader_should_apply_retention`

Whether a retention change applies to an attachment, when you [apply a policy to existing media](/features/retention-policies/). Return `false` to leave the attachment's local files untouched, for example downloadable products or PDFs another plugin reads from disk.

| Parameter | Type | Description |
| --- | --- | --- |
| `$apply` | `bool` | Default `true`. |
| `$attachment_id` | `int` | Attachment ID. |
| `$policy` | `int` | Target policy: `0` Retain Local Files, `1` Smart Local Cleanup, `2` Full Cloud Migration. |

Excluded attachments are not counted as "needs updating".

```php
// Keep local files of attachments a download plugin serves from disk.
add_filter( 'omnioffloader_should_apply_retention', function ( $apply, $attachment_id, $policy ) {
	if ( get_post_meta( $attachment_id, '_acme_downloadable', true ) ) {
		return false;
	}
	return $apply;
}, 10, 3 );
```

## Mirror Delete

### `omnioffloader_attachment_delete_keys`

The object keys deleted from the cloud when an attachment is deleted with [Mirror Delete](/features/mirror-delete/) on, or restored with "delete from cloud". Use it to add sidecar files another plugin uploaded next to the attachment, such as an optimizer's WebP copies.

| Parameter | Type | Description |
| --- | --- | --- |
| `$keys` | `string[]` | Object keys collected so far: main file, generated sizes, image editor backups and modern-format (WebP/AVIF) sources. |
| `$attachment_id` | `int` | Attachment ID. |
| `$subdir` | `string` | Key prefix (folder path) of this attachment, ending in `/`. |

```php
// Also delete "photo.jpg.webp" copies made by an image optimizer.
add_filter( 'omnioffloader_attachment_delete_keys', function ( $keys, $attachment_id, $subdir ) {
	foreach ( $keys as $key ) {
		$keys[] = $key . '.webp';
	}
	return $keys;
}, 10, 3 );
```

## Transfers

### `omnioffloader_transfer_concurrency`

How many files are uploaded or downloaded at the same time. This overrides the [Transfer Speed](/features/transfer-speed/) setting. All transfers still run inside one PHP process.

| Parameter | Type | Description |
| --- | --- | --- |
| `$concurrency` | `int` | From the setting: Low `1`, Balanced `3`, Fast `6`, or the Custom number. |
| `$speed` | `string` | `'low'`, `'balanced'`, `'fast'` or `'custom'`. |

The result is kept between 1 and 10. A WP-CLI `--concurrency` value (`offload`, `restore`, `retention` or `move`) wins over both the setting and this filter for that run.

```php
// Go slower during business hours.
add_filter( 'omnioffloader_transfer_concurrency', function ( $concurrency, $speed ) {
	$hour = (int) wp_date( 'G' );
	return ( $hour >= 9 && $hour < 18 ) ? 1 : $concurrency;
}, 10, 2 );
```

### `omnioffloader_multipart_threshold`

The file size, in bytes, from which uploads are split into parts (multipart upload).

| Parameter | Type | Description |
| --- | --- | --- |
| `$threshold` | `int` | Default `67108864` (64 MB). Values below 5 MB are raised to 5 MB, the smallest part size S3 allows. |
| `$provider_key` | `string` | Provider key, for example `cloudflare_r2`. |

```php
add_filter( 'omnioffloader_multipart_threshold', function ( $threshold, $provider_key ) {
	return 128 * MB_IN_BYTES;
}, 10, 2 );
```

## Bulk jobs

### `omnioffloader_bulk_failure_limit`

How many failures in a row make a bulk job started from the admin check the connection and possibly stop. When the provider still answers, the job continues up to 10 times this number.

| Parameter | Type | Description |
| --- | --- | --- |
| `$limit` | `int` | Default `10`. `0` turns the check off. |
| `$action` | `string` | `'bulk_offload'`, `'bulk_restore'`, `'bulk_retention'` or `'bulk_move'`. |

WP-CLI commands use their own fixed limit of 10.

```php
add_filter( 'omnioffloader_bulk_failure_limit', function ( $limit, $action ) {
	return 'bulk_restore' === $action ? 25 : $limit;
}, 10, 2 );
```

### `omnioffloader_free_disk_space`

The free space, in bytes, used to check whether Bring Back and retention downloads fit on the server.

| Parameter | Type | Description |
| --- | --- | --- |
| `$free` | `int\|null` | Bytes free, or `null` when unknown. The smaller of what PHP reports for the disk and, on cPanel, the space left in the account's quota. |

PHP can only see the whole server disk, not your hosting plan's quota. The plugin reads cPanel's quota itself. For other hosts, return the real space left:

```php
add_filter( 'omnioffloader_free_disk_space', function ( $free ) {
	$quota_left = my_host_quota_bytes_left(); // Your host's API.
	return null === $free ? $quota_left : min( $free, $quota_left );
} );
```

### `omnioffloader_cpanel_quota_file`

The path of cPanel's quota cache file, which the plugin reads to know the space left in a cPanel account. No shell commands are used.

| Parameter | Type | Description |
| --- | --- | --- |
| `$file` | `string` | Absolute path, normally `~/.cpanel/datastore/_Cpanel::Quota.pm__{user}`, or `''` when not found. |

```php
add_filter( 'omnioffloader_cpanel_quota_file', function ( $file ) {
	return '/home2/example/.cpanel/datastore/_Cpanel::Quota.pm__example';
} );
```

## Safety and logging

### `omnioffloader_check_folder_claim`

Whether this site checks that its bucket folder is not used by another site. See [Sharing one bucket](/features/shared-bucket/).

| Parameter | Type | Description |
| --- | --- | --- |
| `$enabled` | `bool` | Default `true`. |

:::caution
Only turn this off when two sites deliberately write into the same folder and never share file names. Otherwise one site can overwrite or delete the other site's files.
:::

```php
add_filter( 'omnioffloader_check_folder_claim', '__return_false' );
```

### `omnioffloader_log_provider_errors`

Whether provider errors are also written to the PHP error log. They are always recorded on the attachment itself.

| Parameter | Type | Description |
| --- | --- | --- |
| `$log` | `bool` | Default: the value of `WP_DEBUG`. |
| `$message` | `string` | The error message (object keys and SDK text, never secrets). |

```php
// Log provider errors on production too.
add_filter( 'omnioffloader_log_provider_errors', '__return_true' );
```

## Providers

### `omnioffloader_cloud_providers`

The registered storage providers, as a map of provider key to class name.

| Parameter | Type | Description |
| --- | --- | --- |
| `$providers` | `array<string, class-string>` | Built-in: `amazon_s3`, `cloudflare_r2`, `backblaze_b2`, `digitalocean_spaces`, `wasabi`, `s3_compatible`. |

See [Adding a custom provider](/developers/custom-provider/) for a full example.

---

## Actions

### `omnioffloader_before_offload`

Fires before an attachment is offloaded, after the `omnioffloader_should_offload_attachment` filter allowed it.

| Parameter | Type | Description |
| --- | --- | --- |
| `$attachment_id` | `int` | Attachment ID. |

### `omnioffloader_after_offload`

Fires after an attachment was offloaded, its main file was verified in the bucket, and the retention policy was applied.

| Parameter | Type | Description |
| --- | --- | --- |
| `$attachment_id` | `int` | Attachment ID. |

```php
// Purge a page cache entry once the image is served from the CDN.
add_action( 'omnioffloader_after_offload', function ( $attachment_id ) {
	$parent = wp_get_post_parent_id( $attachment_id );
	if ( $parent ) {
		clean_post_cache( $parent );
	}
} );
```

### `omnioffloader_before_restore`

Fires before an attachment is restored from the cloud (Bring Back).

| Parameter | Type | Description |
| --- | --- | --- |
| `$attachment_id` | `int` | Attachment ID. |

### `omnioffloader_after_restore`

Fires after an attachment was fully restored to the server and its offload records were cleared.

| Parameter | Type | Description |
| --- | --- | --- |
| `$attachment_id` | `int` | Attachment ID. |
| `$delete_cloud` | `bool` | Whether its cloud objects will also be deleted. |

```php
add_action( 'omnioffloader_after_restore', function ( $attachment_id, $delete_cloud ) {
	error_log( sprintf( 'Brought back #%d%s', $attachment_id, $delete_cloud ? ' (cloud copy deleted)' : '' ) );
}, 10, 2 );
```

### `omnioffloader_transfer_heartbeat`

Fires every 30 seconds while bytes are moving during an upload or download. Bulk jobs and WP-CLI commands use it to renew their locks during a very long single transfer. It has no parameters.

```php
add_action( 'omnioffloader_transfer_heartbeat', function () {
	// Keep your own lock alive while a large file transfers.
	set_transient( 'my_job_lock', time(), 10 * MINUTE_IN_SECONDS );
} );
```
