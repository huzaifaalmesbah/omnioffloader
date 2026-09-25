---
title: wp-config.php constants
description: Constants you can define in wp-config.php to control OmniOffloader.
sidebar:
  order: 2
---

Add these constants to `wp-config.php`, above the line `/* That's all, stop editing! */`.

## Credentials

Every provider field can be set as a constant instead of being saved in the database. The name follows this pattern:

```
OMNIOFFLOADER_{PROVIDER}_{FIELD}
```

```php
define( 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY', 'your-access-key-id' );
define( 'OMNIOFFLOADER_CLOUDFLARE_R2_SECRET', 'your-secret-access-key' );
```

A constant always wins over a saved value, and the settings screen shows that field as locked. See [Credentials in wp-config.php](/providers/wp-config-credentials/) for every provider and field name.

## `OMNIOFFLOADER_SAFE_MODE`

Forces [Safe mode](/features/safe-mode/) on or off, whatever the plugin detects.

```php
// This is a staging copy: never upload to or delete from the bucket.
define( 'OMNIOFFLOADER_SAFE_MODE', true );
```

```php
// This site is the live site, even though its environment type is not "production".
define( 'OMNIOFFLOADER_SAFE_MODE', false );
```

| Value | Effect |
| --- | --- |
| `true` | Safe mode is always on. Nothing is uploaded to or deleted from cloud storage. |
| `false` | Safe mode is off, even if the site address changed, `WP_ENVIRONMENT_TYPE` is not `production`, or the site runs on a local address. |
| not defined | The plugin decides automatically (and the **Protect the live site when this is a copy** setting applies). |

While the constant is defined, the Settings screen shows **Set by OMNIOFFLOADER_SAFE_MODE in wp-config.php**.

:::note
The constant only controls copy detection. It does **not** switch off Safe mode caused by a [bucket folder another site uses](/features/shared-bucket/). That one ends only when this site gets its own folder, or you confirm the folder belongs to this site.
:::

:::tip
Deploy tools often copy `wp-config.php` along with the site. Put `define( 'OMNIOFFLOADER_SAFE_MODE', true );` in the staging site's `wp-config.php` only, never in the live one.
:::

## Advanced: `OMNIOFFLOADER_DISABLE_ENGINE`

An emergency switch for debugging. When it is `true`, the offload engine does not start at all.

```php
define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );
```

:::danger
With the engine off, **nothing** of the offload engine runs: no uploads, no background jobs, no Mirror Delete, and **no URL rewriting**. Media that was removed from the server by a retention policy then shows as broken images. Use it only for a short time while you debug a problem, and remove it again.
:::

The WP-CLI commands tell you when this constant is on, for example:

```
Warning: Offload engine is OFF: disabled by OMNIOFFLOADER_DISABLE_ENGINE in wp-config.php.
```
