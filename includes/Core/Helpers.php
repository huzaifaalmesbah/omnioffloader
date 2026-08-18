<?php
/**
 * Static helper functions.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Helpers
 */
class Helpers {

	/**
	 * Build the wp-config constant name for a credential field.
	 *
	 * E.g. ( 'cloudflare_r2', 'key' ) => 'SMOWP_CLOUDFLARE_R2_KEY'.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $field        Field name.
	 * @return string
	 */
	public static function credential_constant_name( $provider_key, $field ) {
		return 'SMOWP_' . strtoupper( $provider_key ) . '_' . strtoupper( $field );
	}

	/**
	 * Whether a credential field is locked by a wp-config constant.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $field        Field name.
	 * @return bool
	 */
	public static function is_credential_locked( $provider_key, $field ) {
		return defined( self::credential_constant_name( $provider_key, $field ) );
	}

	/**
	 * Resolve a credential value: wp-config constant wins, option is the fallback.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $field        Field name.
	 * @return string
	 */
	public static function get_credential( $provider_key, $field ) {
		$constant = self::credential_constant_name( $provider_key, $field );

		if ( defined( $constant ) ) {
			return (string) constant( $constant );
		}

		$credentials = get_option( 'smowp_credentials', array() );

		if ( ! is_array( $credentials ) ) {
			return '';
		}

		return (string) ( $credentials[ $provider_key ][ $field ] ?? '' );
	}

	/**
	 * Sanitize a bucket path (prefix or subdir).
	 *
	 * Removes traversal segments, leading/trailing slashes and repeated slashes.
	 *
	 * @param string $path Raw path.
	 * @return string Sanitized path without leading/trailing slash.
	 */
	public static function sanitize_path( $path ) {
		$path = (string) $path;

		// Normalize separators, strip traversal and null bytes.
		$path = str_replace( array( '\\', "\0" ), array( '/', '' ), $path );

		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			$segment = trim( $segment );
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				continue;
			}
			$segments[] = sanitize_file_name( $segment );
		}

		return implode( '/', $segments );
	}

	/**
	 * Normalize a URL to https:// form without trailing slash.
	 *
	 * @param string $url Raw URL.
	 * @return string Normalized URL or empty string when invalid.
	 */
	public static function normalize_url( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return '';
		}

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$url = esc_url_raw( untrailingslashit( $url ) );

		return $url;
	}

	/**
	 * Media library counts for the stats endpoint.
	 *
	 * One indexed query on postmeta, cached in a short transient.
	 *
	 * @return array{total: int, offloaded: int, local: int}
	 */
	public static function get_media_stats() {
		$cached = get_transient( 'smowp_media_stats' );

		if ( is_array( $cached ) && isset( $cached['total'], $cached['offloaded'], $cached['local'] ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fast count query cached via transient below.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed meta count query cached via transient below.
		$offloaded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'smowp_offloaded',
				'1'
			)
		);

		$stats = array(
			'total'     => $total,
			'offloaded' => $offloaded,
			'local'     => max( 0, $total - $offloaded ),
		);

		set_transient( 'smowp_media_stats', $stats, MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Bust the cached media stats (call after offload/restore state changes).
	 *
	 * @return void
	 */
	public static function flush_media_stats() {
		delete_transient( 'smowp_media_stats' );
		delete_transient( 'smowp_restore_preflight' );
	}

	/**
	 * Bring Back preflight: how much would a full restore download, and
	 * does the disk have room for it?
	 *
	 * Estimated bytes come from each offloaded attachment's stored metadata
	 * (main + sizes filesizes) — an upper bound, since files still present
	 * locally are skipped during restore. Cached in a short transient; only
	 * computed on the Bring Back page / preflight endpoint.
	 *
	 * @return array{offloaded: int, estimated_bytes: int, free_bytes: int, disk_ok: bool}
	 */
	public static function get_restore_preflight() {
		$cached = get_transient( 'smowp_restore_preflight' );

		if ( is_array( $cached ) && isset( $cached['offloaded'], $cached['estimated_bytes'], $cached['free_bytes'], $cached['disk_ok'] ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed meta join query cached via transient below.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'smowp_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'"
		);

		$estimated = 0;

		foreach ( $ids as $id ) {
			$metadata = wp_get_attachment_metadata( (int) $id );

			if ( ! is_array( $metadata ) ) {
				continue;
			}

			// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var for PHPStan.
			/** @var array<string, mixed> $metadata */
			if ( isset( $metadata['filesize'] ) && is_numeric( $metadata['filesize'] ) ) {
				$estimated += (int) $metadata['filesize'];
			}

			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( is_array( $size_data ) && isset( $size_data['filesize'] ) && is_numeric( $size_data['filesize'] ) ) {
						$estimated += (int) $size_data['filesize'];
					}
				}
			}
		}

		$upload_dir = wp_get_upload_dir();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space warns on some hosts; false is handled.
		$free = @disk_free_space( $upload_dir['basedir'] );
		$free = false === $free ? 0 : (int) $free;

		$preflight = array(
			'offloaded'       => count( $ids ),
			'estimated_bytes' => $estimated,
			// 0 means "unknown" on hosts where disk_free_space is disabled —
			// treated as OK with a caveat in the UI.
			'free_bytes'      => $free,
			// 10% headroom over the (upper-bound) estimate.
			'disk_ok'         => 0 === $free || $free > $estimated * 1.1,
		);

		set_transient( 'smowp_restore_preflight', $preflight, MINUTE_IN_SECONDS );

		return $preflight;
	}
}
