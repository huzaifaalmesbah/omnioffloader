<?php
/**
 * Safe mode: protects the live site's bucket when this site is a copy.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SafeMode
 *
 * A staging, development or local copy of a site carries the live site's
 * settings and credentials, so without this it would upload into the live
 * bucket — overwriting files the live site added after the copy was made —
 * and, with Mirror Delete on, delete files the live site still serves.
 *
 * While Safe mode is on, nothing is uploaded to or deleted from the cloud:
 * media already offloaded keeps loading from it and new uploads stay on this
 * server. Bring Back is hidden and refused too, unless the site owner allows
 * it (setting `safe_mode_bring_back`) — then it only downloads, never
 * deleting the cloud copy.
 *
 * A copy is recognised by any of:
 *  - the site address no longer matching the one recorded as live — stored
 *    as a hash, because migration tools search-and-replace the old domain
 *    everywhere in the database and would rewrite a plain URL too;
 *  - `wp_get_environment_type()` being staging, development or local;
 *  - a local-only host name (localhost, *.local, *.test …).
 *
 * `OMNIOFFLOADER_SAFE_MODE` (true/false) in wp-config.php overrides all of it.
 *
 * Safe mode also covers a site whose bucket folder turned out to belong to
 * another site sharing the bucket (reason `folder`, see Core\FolderClaim).
 * That one is not about being a copy, so the constant and the "protect
 * copies" setting do not switch it off.
 *
 * @since 0.1.3
 */
class SafeMode {

	/**
	 * Option holding the recorded live address (hash + display label).
	 */
	public const OPTION = 'omnioffloader_live_site';

	/**
	 * Why Safe mode is on for this request, memoized; null until computed.
	 *
	 * @var string|null
	 */
	private static $reason = null;

	/**
	 * Why this site counts as a copy, memoized; null until computed.
	 *
	 * @var string|null
	 */
	private static $copy_reason = null;

	/**
	 * Whether Safe mode is on.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return '' !== self::reason();
	}

	/**
	 * Why Safe mode is on.
	 *
	 * @return string 'constant', 'environment', 'local', 'address', 'folder', or '' when off.
	 */
	public static function reason() {
		if ( null !== self::$reason ) {
			return self::$reason;
		}

		self::$reason = self::copy_reason();

		if ( '' === self::$reason && null !== FolderClaim::active_conflict() ) {
			self::$reason = 'folder';
		}

		return self::$reason;
	}

	/**
	 * Whether this site is a staging, development or local copy — Safe mode
	 * for any reason except a folder conflict.
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	public static function is_copy() {
		return '' !== self::copy_reason();
	}

	/**
	 * Why this site counts as a copy.
	 *
	 * @return string
	 */
	private static function copy_reason() {
		if ( null === self::$copy_reason ) {
			self::$copy_reason = self::detect();
		}

		return self::$copy_reason;
	}

	/**
	 * Drop the memoized result, after something it depends on changed.
	 *
	 * @since 0.1.3
	 *
	 * @return void
	 */
	public static function reset() {
		self::$reason      = null;
		self::$copy_reason = null;
	}

	/**
	 * Whether Bring Back is refused: Safe mode is on and the site owner has
	 * not allowed download-only Bring Back on a copy.
	 *
	 * @return bool
	 */
	public static function blocks_bring_back() {
		return self::is_active() && empty( Plugin::get_settings()['safe_mode_bring_back'] );
	}

	/**
	 * Refusal for Bring Back on a copy of the site.
	 *
	 * @return \WP_Error
	 */
	public static function bring_back_refusal() {
		return new \WP_Error(
			'omnioffloader_safe_mode',
			self::bring_back_message(),
			array( 'status' => 409 )
		);
	}

	/**
	 * Why Bring Back is unavailable on a copy.
	 *
	 * @return string
	 */
	public static function bring_back_message() {
		if ( 'folder' === self::reason() ) {
			return self::message() . ' ' . __( 'Bring Back is paused too, because files in that folder may now be the other site\'s. To download anyway (the cloud copy is always kept), turn on "Allow Bring Back on a copy" in OmniOffloader → Settings.', 'omnioffloader' );
		}

		return __( 'Safe mode is on: this site looks like a staging, development or local copy, so Bring Back is turned off. To download media from the cloud to this copy (the cloud copy is always kept), turn on "Allow Bring Back on a copy" in OmniOffloader → Settings.', 'omnioffloader' );
	}

	/**
	 * Work out whether this site is a copy.
	 *
	 * @return string See reason(); never 'folder'.
	 */
	private static function detect() {
		if ( defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
			return OMNIOFFLOADER_SAFE_MODE ? 'constant' : '';
		}

		$settings = Plugin::get_settings();

		if ( empty( $settings['protect_copies'] ) ) {
			return '';
		}

		if ( in_array( wp_get_environment_type(), array( 'staging', 'development', 'local' ), true ) ) {
			return 'environment';
		}

		if ( self::is_local_host( self::current_host() ) ) {
			return 'local';
		}

		$live = self::live_site();

		if ( null !== $live && ! hash_equals( $live['hash'], self::fingerprint( self::current_address() ) ) ) {
			return 'address';
		}

		return '';
	}

	/**
	 * Record the current address as the live site's, if none is recorded.
	 *
	 * Called once a provider is configured, so the address the plugin was set
	 * up on becomes the reference a copy is measured against.
	 *
	 * @return void
	 */
	public static function maybe_record_live_site() {
		if ( null === self::live_site() ) {
			self::record_live_site();
		}
	}

	/**
	 * Record the current address as the live site's and leave Safe mode.
	 *
	 * For a live site that really moved (new domain): the "This is the live
	 * site" button. Only clears an address-based Safe mode — the constant and
	 * the environment type are the site owner's explicit say.
	 *
	 * @return void
	 */
	public static function record_live_site() {
		$address = self::current_address();

		update_option(
			self::OPTION,
			array(
				'hash'  => self::fingerprint( $address ),
				// Encoded so a migration's search-replace leaves it alone too;
				// only used to tell the admin which address was live.
				'label' => base64_encode( $address ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- not obfuscation: keeps a display label out of reach of database search-replace.
			),
			true
		);

		self::reset();
	}

	/**
	 * The recorded live site, or null when none is recorded yet.
	 *
	 * @return array{hash: string, label: string}|null
	 */
	public static function live_site() {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || ! is_string( $stored['hash'] ) ) {
			return null;
		}

		return array(
			'hash'  => $stored['hash'],
			'label' => isset( $stored['label'] ) && is_string( $stored['label'] ) ? $stored['label'] : '',
		);
	}

	/**
	 * State for the admin UI and REST.
	 *
	 * @return array{active: bool, reason: string, locked: bool, live_address: string, current_address: string, environment: string, bring_back: bool, folder_owner: string, folder: string}
	 */
	public static function status() {
		$conflict = 'folder' === self::reason() ? FolderClaim::active_conflict() : null;
		$live     = self::live_site();
		$label    = null !== $live ? base64_decode( $live['label'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see record_live_site().

		return array(
			'active'          => self::is_active(),
			'reason'          => self::reason(),
			'locked'          => defined( 'OMNIOFFLOADER_SAFE_MODE' ),
			'live_address'    => is_string( $label ) ? $label : '',
			'current_address' => self::current_address(),
			'environment'     => wp_get_environment_type(),
			// Whether Bring Back is available (always, unless Safe mode blocks it).
			'bring_back'      => ! self::blocks_bring_back(),
			// Folder conflict: the site that owns this site's bucket folder.
			'folder_owner'    => null !== $conflict ? $conflict['owner'] : '',
			'folder'          => null !== $conflict ? $conflict['folder'] : '',
		);
	}

	/**
	 * Refusal for an action that would write to or delete from the cloud.
	 *
	 * @return \WP_Error
	 */
	public static function refusal() {
		return new \WP_Error(
			'omnioffloader_safe_mode',
			self::message(),
			array( 'status' => 409 )
		);
	}

	/**
	 * Why cloud writes are paused, for notices, REST and WP-CLI.
	 *
	 * @return string
	 */
	public static function message() {
		if ( 'folder' === self::reason() ) {
			$conflict = FolderClaim::active_conflict();

			if ( null !== $conflict ) {
				return FolderClaim::message( $conflict['owner'], $conflict['folder'] );
			}
		}

		return __( 'Safe mode is on: this site looks like a staging, development or local copy, so nothing is uploaded to or deleted from your cloud storage. Media already in the cloud still loads from it. If this is the live site, turn Safe mode off in OmniOffloader → Settings.', 'omnioffloader' );
	}

	/**
	 * The site address in the form it is compared in: host (without "www.")
	 * plus path, lower-case, without scheme or trailing slash — so moving to
	 * https or adding "www." is not mistaken for a copy.
	 *
	 * Read from the stored Site Address (the `home` option, or `WP_HOME`),
	 * not home_url(): multilingual and domain-mapping plugins filter
	 * home_url() per request (one domain per language), which would make a
	 * site look like a copy of itself, or like another site in its own
	 * bucket folder. A real migration still changes the stored address.
	 *
	 * @return string E.g. "example.com" or "example.com/blog".
	 */
	public static function current_address() {
		$parts = wp_parse_url( self::site_address() );
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$host  = (string) preg_replace( '/^www\./', '', $host );
		$port  = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path  = untrailingslashit( strtolower( (string) ( $parts['path'] ?? '' ) ) );

		return $host . $port . $path;
	}

	/**
	 * Host name of the site address.
	 *
	 * @return string
	 */
	private static function current_host() {
		return strtolower( (string) wp_parse_url( self::site_address(), PHP_URL_HOST ) );
	}

	/**
	 * The stored Site Address, unaffected by per-request home_url filters.
	 *
	 * @return string
	 */
	private static function site_address() {
		return (string) get_option( 'home' );
	}

	/**
	 * Whether a host name can only be a local development site.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	private static function is_local_host( $host ) {
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '[::1]' ), true ) ) {
			return true;
		}

		// Reserved for local use (RFC 6761 / 6762); never public websites.
		return (bool) preg_match( '/\.(local|localhost|test|invalid|example)$/', $host );
	}

	/**
	 * Hash of an address.
	 *
	 * @param string $address Normalized address.
	 * @return string
	 */
	private static function fingerprint( $address ) {
		return hash( 'sha256', 'omnioffloader|' . $address );
	}
}
