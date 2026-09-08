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
			'secondary_active'         => false,
			'secondary_provider'       => '',
			'retention_policy'         => 0,
			'mirror_delete'            => false,
			'path_prefix_active'       => false,
			'path_prefix'              => '',
			'object_versioning'        => false,
			'bring_back_delete_cloud'  => false,
			'remove_data_on_uninstall' => false,
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
			'secondary_active'         => ! empty( $settings['secondary_active'] ?? $current['secondary_active'] ),
			'secondary_provider'       => sanitize_key( $settings['secondary_provider'] ?? $current['secondary_provider'] ),
			'retention_policy'         => self::sanitize_retention_policy( $settings['retention_policy'] ?? $current['retention_policy'] ),
			'mirror_delete'            => ! empty( $settings['mirror_delete'] ?? $current['mirror_delete'] ),
			'path_prefix_active'       => ! empty( $settings['path_prefix_active'] ?? $current['path_prefix_active'] ),
			'path_prefix'              => Helpers::sanitize_path( (string) ( $settings['path_prefix'] ?? $current['path_prefix'] ) ),
			'object_versioning'        => ! empty( $settings['object_versioning'] ?? $current['object_versioning'] ),
			'bring_back_delete_cloud'  => ! empty( $settings['bring_back_delete_cloud'] ?? $current['bring_back_delete_cloud'] ),
			'remove_data_on_uninstall' => ! empty( $settings['remove_data_on_uninstall'] ?? $current['remove_data_on_uninstall'] ),
		);

		update_option( 'omnioffloader_settings', $sanitized, true );

		return $sanitized;
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

		return (string) $settings['provider'];
	}

	/**
	 * Get the secondary backup provider key.
	 *
	 * @return string Empty string when not selected.
	 */
	public static function get_secondary_provider_key() {
		$settings = self::get_settings();

		return (string) ( $settings['secondary_provider'] ?? '' );
	}

	/**
	 * Get all stored credentials (all providers).
	 *
	 * Never expose this raw via REST.
	 *
	 * @param string $role Storage role ('primary' or 'secondary').
	 * @return array<string, array<string, string>>
	 */
	public static function get_all_credentials( $role = 'primary' ) {
		$option_name = 'secondary' === $role ? 'omnioffloader_secondary_credentials' : 'omnioffloader_credentials';
		$credentials = get_option( $option_name, array() );

		return is_array( $credentials ) ? $credentials : array();
	}

	/**
	 * Get stored credentials for one provider.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $role         Storage role ('primary' or 'secondary').
	 * @return array<string, string>
	 */
	public static function get_provider_credentials( $provider_key, $role = 'primary' ) {
		$all = self::get_all_credentials( $role );

		$creds = $all[ $provider_key ] ?? array();

		return is_array( $creds ) ? $creds : array();
	}

	/**
	 * Update stored credentials for one provider.
	 *
	 * Values are stored as plain strings; fields locked by wp-config
	 * constants are skipped by the caller before reaching here.
	 *
	 * @param string                $provider_key Provider key.
	 * @param array<string, string> $values       Field name => value map.
	 * @param string                $role         Storage role ('primary' or 'secondary').
	 * @return void
	 */
	public static function update_provider_credentials( $provider_key, array $values, $role = 'primary' ) {
		$all = self::get_all_credentials( $role );

		$existing = $all[ $provider_key ] ?? array();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( $values as $field => $value ) {
			$existing[ sanitize_key( $field ) ] = sanitize_text_field( (string) $value );
		}

		$all[ $provider_key ] = $existing;

		$option_name = 'secondary' === $role ? 'omnioffloader_secondary_credentials' : 'omnioffloader_credentials';
		// Credentials are only needed on our admin page / REST calls: no autoload.
		update_option( $option_name, $all, false );
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

		$key    = Helpers::get_credential( $provider_key, 'key', 'primary' );
		$bucket = Helpers::get_credential( $provider_key, 'bucket', 'primary' );

		return ! empty( $key ) && ! empty( $bucket );
	}

	/**
	 * Whether secondary backup storage is enabled and minimally configured.
	 *
	 * @return bool
	 */
	public static function is_secondary_configured() {
		$settings = self::get_settings();

		if ( empty( $settings['secondary_active'] ) ) {
			return false;
		}

		$provider_key = self::get_secondary_provider_key();

		if ( '' === $provider_key ) {
			return false;
		}

		$key    = Helpers::get_credential( $provider_key, 'key', 'secondary' );
		$bucket = Helpers::get_credential( $provider_key, 'bucket', 'secondary' );

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
