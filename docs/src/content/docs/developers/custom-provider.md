---
title: Adding a custom provider
description: Register your own S3-compatible storage provider with one class and one filter.
sidebar:
  order: 3
---

Most S3-compatible services already work through **Any S3-Compatible Storage** in Settings. See [S3-compatible storage](/providers/s3-compatible/). Write your own provider only when you want a dedicated entry in the provider list, with its own fields, defaults and endpoint logic.

A provider is **one PHP class** plus **one filter**. The settings screen builds the credential form from your class, so you don't write any JavaScript.

## 1. Write the provider class

Extend `OmniOffloader\Providers\AbstractS3Provider`. It already handles uploads (including multipart uploads for large files), downloads, deletes, existence checks, the connection test and Safe mode. You supply four things:

| Method | Returns |
| --- | --- |
| `get_key()` | A unique machine key, for example `acme_storage`. Lowercase letters, numbers and underscores. |
| `get_name()` | The name shown in the plugin, for example `Acme Storage`. |
| `create_client()` | A configured `Aws\S3\S3Client`. Pass your config through `$this->client_config()` so the plugin's shared defaults apply. |
| `credential_fields()` | The fields shown on the settings screen. |

Read saved values with `$this->credential( 'field_name' )`. It returns the `wp-config.php` constant when one is defined, otherwise the saved value.

The base class reads the bucket from a field named `bucket` and the public URL from a field named `domain`. Override `get_domain()` if your service has a default public URL.

```php title="acme-storage/class-acme-storage-provider.php"
<?php
namespace Acme\Storage;

use Aws\S3\S3Client;
use OmniOffloader\Providers\AbstractS3Provider;

class AcmeStorageProvider extends AbstractS3Provider {

	public function get_key() {
		return 'acme_storage';
	}

	public function get_name() {
		return 'Acme Storage';
	}

	protected function create_client() {
		$region = '' !== $this->credential( 'region' ) ? $this->credential( 'region' ) : 'eu-west-1';

		return new S3Client(
			$this->client_config(
				array(
					'endpoint'                => 'https://s3.' . $region . '.acme-storage.example',
					'region'                  => $region,
					'use_path_style_endpoint' => true,
					'credentials'             => array(
						'key'    => $this->credential( 'key' ),
						'secret' => $this->credential( 'secret' ),
					),
				)
			)
		);
	}

	public function credential_fields() {
		return array(
			array(
				'name'        => 'key',
				'label'       => __( 'Access Key ID', 'acme-storage' ),
				'type'        => 'text',
				'placeholder' => '',
				'description' => '',
			),
			array(
				'name'        => 'secret',
				'label'       => __( 'Secret Access Key', 'acme-storage' ),
				'type'        => 'password',
				'placeholder' => '',
				'description' => '',
			),
			array(
				'name'        => 'bucket',
				'label'       => __( 'Bucket Name', 'acme-storage' ),
				'type'        => 'text',
				'placeholder' => '',
				'description' => '',
			),
			array(
				'name'        => 'region',
				'label'       => __( 'Region', 'acme-storage' ),
				'type'        => 'text',
				'placeholder' => 'eu-west-1',
				'description' => __( 'Default: eu-west-1.', 'acme-storage' ),
			),
			array(
				'name'        => 'domain',
				'label'       => __( 'Custom Domain / CDN URL', 'acme-storage' ),
				'type'        => 'text',
				'placeholder' => 'https://media.yourdomain.com',
				'description' => '',
			),
		);
	}
}
```

### Field types

| `type` | Shown as |
| --- | --- |
| `text` | A text input. |
| `password` | A secret input. Its value is never sent back to the browser. The screen only shows that a value is saved, and saving with the field empty keeps the old value. |
| `checkbox` | A toggle. The saved value is `'1'` when on and `'0'` when off. |

## 2. Register it

Add the class to the provider map with the [`omnioffloader_cloud_providers`](/developers/hooks/#omnioffloader_cloud_providers) filter:

```php title="acme-storage/acme-storage.php"
<?php
/**
 * Plugin Name: Acme Storage for OmniOffloader
 */

add_filter( 'omnioffloader_cloud_providers', function ( $providers ) {
	// Load the class here, not at the top of the file: OmniOffloader's
	// classes (and the AWS SDK) are only available once it has loaded.
	require_once __DIR__ . '/class-acme-storage-provider.php';

	$providers['acme_storage'] = \Acme\Storage\AcmeStorageProvider::class;
	return $providers;
} );
```

That's it. **Acme Storage** now appears in **OmniOffloader → Settings**, with its own fields, **Test Connection** and all features.

:::caution
Don't declare the class at the top level of your plugin file. If your plugin loads before OmniOffloader, `AbstractS3Provider` does not exist yet and PHP stops with a fatal error. Loading the file inside the filter callback avoids this.
:::

## Credentials in wp-config.php

Your fields get constants automatically, named `OMNIOFFLOADER_{PROVIDER KEY}_{FIELD NAME}` in upper case:

```php
define( 'OMNIOFFLOADER_ACME_STORAGE_KEY', '...' );
define( 'OMNIOFFLOADER_ACME_STORAGE_SECRET', '...' );
```

## Good to know

- **Don't send an ACL by default.** Uploads send no ACL header unless the [`omnioffloader_object_acl`](/developers/hooks/#omnioffloader_object_acl) filter returns one. Several services reject it.
- **Checksums.** `client_config()` sets `request_checksum_calculation` to `when_required`, because several S3-compatible services reject the newer checksum headers. Keep your config going through it.
- **Existing media keeps its provider.** Each offloaded attachment remembers the provider it was offloaded to, and Bring Back downloads from that provider. If you remove your provider later, Bring Back for its media stops working. Bring the media back before you remove it.
