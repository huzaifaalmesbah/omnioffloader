<?php
/**
 * Options helper trait.
 *
 * Central read/write access to all plugin options, with sanitization.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Traits;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait OptionsHelper
 */
trait OptionsHelper {

	/**
	 * Default plugin settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_default_settings() {
		return array(
			'provider'                 => '',
			'retention_policy'         => 0,
			'mirror_delete'            => false,
			'path_prefix_active'       => false,
			'path_prefix'              => '',
			'object_versioning'        => false,
			'async_upload'             => true,
			'transfer_speed'           => 'balanced',
			'transfer_concurrency'     => 3,
			'bring_back_delete_cloud'  => false,
			'remove_data_on_uninstall' => false,
			// Safe mode on staging/dev/local copies (see Core\SafeMode).
			'protect_copies'           => true,
			// In Safe mode, allow Bring Back as download-only (off: hidden).
			'safe_mode_bring_back'     => false,
		);
	}

	/**
	 * Get plugin settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_settings() {
		$settings = get_option( 'omnioffloader_settings', array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return array_merge( self::get_default_settings(), $settings );
	}

	/**
	 * Sanitize and persist plugin settings.
	 *
	 * Unknown keys are dropped; every known key is coerced to its expected type.
	 *
	 * @param array<string, mixed> $settings Raw settings input.
	 * @return array<string, mixed> The sanitized settings that were saved.
	 */
	public static function update_settings( array $settings ) {
		$current   = self::get_settings();
		$sanitized = array(
			'provider'                 => sanitize_key( $settings['provider'] ?? $current['provider'] ),
			'retention_policy'         => self::sanitize_retention_policy( $settings['retention_policy'] ?? $current['retention_policy'] ),
			'mirror_delete'            => ! empty( $settings['mirror_delete'] ?? $current['mirror_delete'] ),
			'path_prefix_active'       => ! empty( $settings['path_prefix_active'] ?? $current['path_prefix_active'] ),
			'path_prefix'              => Helpers::sanitize_path( (string) ( $settings['path_prefix'] ?? $current['path_prefix'] ) ),
			'object_versioning'        => ! empty( $settings['object_versioning'] ?? $current['object_versioning'] ),
			'async_upload'             => ! empty( $settings['async_upload'] ?? $current['async_upload'] ),
			'transfer_speed'           => self::sanitize_transfer_speed( $settings['transfer_speed'] ?? $current['transfer_speed'] ),
			'transfer_concurrency'     => self::sanitize_transfer_concurrency( $settings['transfer_concurrency'] ?? $current['transfer_concurrency'] ),
			'bring_back_delete_cloud'  => ! empty( $settings['bring_back_delete_cloud'] ?? $current['bring_back_delete_cloud'] ),
			'remove_data_on_uninstall' => ! empty( $settings['remove_data_on_uninstall'] ?? $current['remove_data_on_uninstall'] ),
			'protect_copies'           => ! empty( $settings['protect_copies'] ?? $current['protect_copies'] ),
			'safe_mode_bring_back'     => ! empty( $settings['safe_mode_bring_back'] ?? $current['safe_mode_bring_back'] ),
		);

		update_option( 'omnioffloader_settings', $sanitized, true );

		return $sanitized;
	}

	/**
	 * Files uploaded at once for each transfer speed setting.
	 *
	 * All of them run inside one PHP process over curl_multi — a faster
	 * setting overlaps network waits, it never starts extra PHP workers.
	 *
	 * A method, not a constant: trait constants need PHP 8.2.
	 *
	 * @since 0.1.3
	 *
	 * @return array<string, int> Speed => files in flight.
	 */
	public static function transfer_speeds() {
		return array(
			'low'      => 1,
			'balanced' => 3,
			'fast'     => 6,
		);
	}

	/**
	 * Sanitize the transfer speed to a known value.
	 *
	 * @since 0.1.3
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_transfer_speed( $value ) {
		$value = is_string( $value ) ? $value : '';

		return isset( self::transfer_speeds()[ $value ] ) || 'custom' === $value ? $value : 'balanced';
	}

	/**
	 * Sanitize the custom files-at-once count to 1–10.
	 *
	 * @since 0.1.3
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_transfer_concurrency( $value ) {
		return max( 1, min( 10, (int) $value ) );
	}

	/**
	 * How many files a transfer (upload or download) moves at once.
	 *
	 * @since 0.1.3
	 *
	 * @return int Between 1 and 10.
	 */
	public static function files_at_once() {
		$settings = self::get_settings();
		$speed    = self::sanitize_transfer_speed( $settings['transfer_speed'] );
		$default  = 'custom' === $speed
			? self::sanitize_transfer_concurrency( $settings['transfer_concurrency'] )
			: self::transfer_speeds()[ $speed ];

		/**
		 * Filter how many files are uploaded to or downloaded from cloud
		 * storage at once.
		 *
		 * Transfers run side by side inside one PHP process, so this trades a
		 * little memory and bandwidth for speed. 1 moves one file at a time.
		 *
		 * @since 0.1.3
		 *
		 * @param int    $concurrency Files in flight at once.
		 * @param string $speed       Transfer speed setting ('low', 'balanced', 'fast' or 'custom').
		 */
		$concurrency = (int) apply_filters( 'omnioffloader_transfer_concurrency', $default, $speed );

		return max( 1, min( 10, $concurrency ) );
	}

	/**
	 * Sanitize the retention policy value to 0, 1 or 2.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_retention_policy( $value ) {
		$value = (int) $value;

		return in_array( $value, array( 0, 1, 2 ), true ) ? $value : 0;
	}

	/**
	 * Get the selected cloud provider key.
	 *
	 * @return string Empty string when not selected.
	 */
	public static function get_provider_key() {
		$settings = self::get_settings();
		$key      = (string) ( $settings['provider'] ?? '' );

		if ( '' === $key || ! ProviderRegistry::has( $key ) ) {
			return '';
		}

		return ProviderRegistry::canonical_key( $key );
	}

	/**
	 * Get all stored credentials (all providers).
	 *
	 * Never expose this raw via REST.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function get_all_credentials() {
		$credentials = get_option( 'omnioffloader_credentials', array() );

		return is_array( $credentials ) ? $credentials : array();
	}

	/**
	 * Update stored credentials for one provider.
	 *
	 * Values are stored as plain strings; fields locked by wp-config
	 * constants are skipped by the caller before reaching here.
	 *
	 * @param string                $provider_key Provider key.
	 * @param array<string, string> $values       Field name => value map.
	 * @return void
	 */
	public static function update_provider_credentials( $provider_key, array $values ) {
		$all = self::get_all_credentials();

		$existing = $all[ $provider_key ] ?? array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( $values as $field => $value ) {
			$existing[ sanitize_key( $field ) ] = sanitize_text_field( (string) $value );
		}

		$all[ $provider_key ] = $existing;

		// Not autoloaded: autoloaded options sit in the shared alloptions
		// cache on every request, and secrets do not belong there. Costs one
		// small query per request in is_configured(). Never exposed via REST.
		update_option( 'omnioffloader_credentials', $all, false );
	}

	/**
	 * Whether a provider is selected and minimally configured.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider_key = self::get_provider_key();

		if ( '' === $provider_key ) {
			return false;
		}

		$key    = Helpers::get_credential( $provider_key, 'key' );
		$bucket = Helpers::get_credential( $provider_key, 'bucket' );

		return ! empty( $key ) && ! empty( $bucket );
	}

	/**
	 * Get the stored plugin version (for migrations).
	 *
	 * @return string
	 */
	public static function get_plugin_version() {
		return (string) get_option( 'omnioffloader_version', '0.0.0' );
	}

	/**
	 * Update the stored plugin version.
	 *
	 * @param string $version Version string.
	 * @return void
	 */
	public static function update_plugin_version( $version ) {
		update_option( 'omnioffloader_version', $version, true );
	}
}
