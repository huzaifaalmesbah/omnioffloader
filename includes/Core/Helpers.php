<?php
/**
 * Static helper functions.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Services\RetentionApplier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Helpers
 */
class Helpers {

	/**
	 * Whether stats-cache flushes are being batched for a bulk run.
	 *
	 * @var bool
	 */
	private static $defer_stats_flush = false;

	/**
	 * Whether a deferred flush is owed.
	 *
	 * @var bool
	 */
	private static $stats_flush_pending = false;

	/**
	 * Prefix for background-queue identifiers.
	 *
	 * WP_Background_Process keeps batches, run status and the process lock in
	 * site options / site transients, which on multisite are shared by the
	 * whole network. Scoping the identifier by blog keeps each site's queue to
	 * its own attachments. Single sites keep the historical, unscoped names.
	 *
	 * @since 0.1.3
	 *
	 * @return string
	 */
	public static function queue_prefix() {
		return is_multisite() ? 'omnioffloader_' . get_current_blog_id() : 'omnioffloader';
	}

	/**
	 * Build the wp-config constant name for a credential field.
	 *
	 * E.g. ( 'cloudflare_r2', 'key' ) => 'OMNIOFFLOADER_CLOUDFLARE_R2_KEY'.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $field        Field name.
	 * @return string
	 */
	public static function credential_constant_name( $provider_key, $field ) {
		return 'OMNIOFFLOADER_' . strtoupper( $provider_key ) . '_' . strtoupper( $field );
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

		$credentials = get_option( 'omnioffloader_credentials', array() );

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
	 * @param bool $force_refresh Optional. True to bypass transient cache and recompute. Default false.
	 * @return array{total: int, offloaded: int, local: int}
	 */
	public static function get_media_stats( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'omnioffloader_media_stats' );

			if ( is_array( $cached ) && isset( $cached['total'], $cached['offloaded'], $cached['local'] ) ) {
				return $cached;
			}
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
				'omnioffloader_offloaded',
				'1'
			)
		);

		$stats = array(
			'total'     => $total,
			'offloaded' => $offloaded,
			'local'     => max( 0, $total - $offloaded ),
		);

		set_transient( 'omnioffloader_media_stats', $stats, MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Bust the cached media stats (call after offload/restore state changes).
	 *
	 * Flushes transients from external object cache (Redis/Memcached) as well
	 * as fallback options.
	 *
	 * @return void
	 */
	public static function flush_media_stats() {
		if ( self::$defer_stats_flush ) {
			self::$stats_flush_pending = true;

			return;
		}

		delete_transient( 'omnioffloader_media_stats' );
		delete_transient( 'omnioffloader_restore_preflight' );
		delete_transient( 'omnioffloader_retention_preflight' );

		// With a persistent object cache, delete_transient() only clears the
		// cache; rows written to wp_options before the cache was enabled would
		// otherwise resurface. Without one, delete_transient() already removed
		// those rows, so this is skipped (it costs two queries per key).
		if ( wp_using_ext_object_cache() ) {
			foreach ( array( 'omnioffloader_media_stats', 'omnioffloader_restore_preflight', 'omnioffloader_retention_preflight' ) as $key ) {
				delete_option( '_transient_' . $key );
				delete_option( '_transient_timeout_' . $key );
			}
		}
	}

	/**
	 * Batch the stats-cache flushes of a bulk run into a single flush.
	 *
	 * Each flush is four delete_option() calls, and every one of those costs a
	 * SELECT plus a DELETE — around eight queries per attachment to invalidate
	 * a value nothing reads until the run ends. Over a 15,000 item library
	 * that is six figures of pure overhead.
	 *
	 * Turning deferral off flushes once if anything asked for it meanwhile.
	 *
	 * @since 0.1.1
	 *
	 * @param bool $defer True to start batching, false to stop and flush.
	 * @return void
	 */
	public static function defer_media_stats_flush( $defer ) {
		self::$defer_stats_flush = (bool) $defer;

		if ( ! self::$defer_stats_flush && self::$stats_flush_pending ) {
			self::$stats_flush_pending = false;
			self::flush_media_stats();
		}
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
	 * @param bool $force_refresh Optional. True to bypass transient cache and recompute. Default false.
	 * @return array{offloaded: int, estimated_bytes: int, free_bytes: int, disk_ok: bool}
	 */
	public static function get_restore_preflight( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( 'omnioffloader_restore_preflight' );

			if ( is_array( $cached ) && isset( $cached['offloaded'], $cached['estimated_bytes'], $cached['free_bytes'], $cached['disk_ok'] ) ) {
				return $cached;
			}
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed meta join query cached via transient below.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'"
		);

		$estimated = 0;

		// Prime the meta cache in chunks. Reading metadata one attachment at a
		// time is a query per attachment, which times the page out on a large
		// library; the cache is dropped again after each chunk so a 15,000
		// item run does not hold every meta row in memory at once.
		foreach ( array_chunk( array_map( 'intval', $ids ), 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				$metadata = wp_get_attachment_metadata( $id );

				if ( is_array( $metadata ) ) {
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

				wp_cache_delete( $id, 'post_meta' );
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

		set_transient( 'omnioffloader_restore_preflight', $preflight, MINUTE_IN_SECONDS );

		return $preflight;
	}

	/**
	 * Retention preflight: how many offloaded attachments still follow a
	 * different policy than the saved one, and roughly how many bytes applying
	 * it would free or download.
	 *
	 * Byte counts come from stored metadata (upper-bound estimates, like the
	 * restore preflight). No filesystem or network access.
	 *
	 * @since 0.1.3
	 *
	 * @param bool $force_refresh Optional. True to bypass the transient cache.
	 * @return array{policy: int, eligible: int, to_clean: int, to_download: int, freed_bytes: int, download_bytes: int, free_bytes: int, disk_ok: bool}
	 */
	public static function get_retention_preflight( $force_refresh = false ) {
		$settings = Plugin::get_settings();
		$policy   = Plugin::sanitize_retention_policy( $settings['retention_policy'] );

		if ( ! $force_refresh ) {
			$cached = get_transient( 'omnioffloader_retention_preflight' );

			if ( is_array( $cached ) && isset( $cached['policy'], $cached['eligible'] ) && (int) $cached['policy'] === $policy ) {
				return $cached;
			}
		}

		$ids       = RetentionApplier::eligible_ids( $policy );
		$new_roles = RetentionApplier::local_roles( $policy );

		$to_clean       = 0;
		$to_download    = 0;
		$freed_bytes    = 0;
		$download_bytes = 0;

		// Chunked meta priming, dropped after each chunk (see get_restore_preflight()).
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				$stored    = RetentionApplier::stored_policy( $id );
				$old_roles = RetentionApplier::local_roles( $stored );

				if ( $policy > $stored ) {
					++$to_clean;
				} else {
					++$to_download;
				}

				$metadata   = wp_get_attachment_metadata( $id );
				$main_bytes = 0;
				$size_bytes = 0;

				if ( is_array( $metadata ) ) {
					// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var for PHPStan.
					/** @var array<string, mixed> $metadata */
					if ( isset( $metadata['filesize'] ) && is_numeric( $metadata['filesize'] ) ) {
						$main_bytes = (int) $metadata['filesize'];
					}

					if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
						foreach ( $metadata['sizes'] as $size_data ) {
							if ( is_array( $size_data ) && isset( $size_data['filesize'] ) && is_numeric( $size_data['filesize'] ) ) {
								$size_bytes += (int) $size_data['filesize'];
							}
						}
					}
				}

				foreach ( array(
					'main'  => $main_bytes,
					'sizes' => $size_bytes,
				) as $role => $bytes ) {
					if ( $old_roles[ $role ] && ! $new_roles[ $role ] ) {
						$freed_bytes += $bytes;
					} elseif ( ! $old_roles[ $role ] && $new_roles[ $role ] ) {
						$download_bytes += $bytes;
					}
				}

				wp_cache_delete( $id, 'post_meta' );
			}
		}

		$upload_dir = wp_get_upload_dir();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space warns on some hosts; false is handled.
		$free = @disk_free_space( $upload_dir['basedir'] );
		$free = false === $free ? 0 : (int) $free;

		$preflight = array(
			'policy'         => $policy,
			'eligible'       => count( $ids ),
			'to_clean'       => $to_clean,
			'to_download'    => $to_download,
			'freed_bytes'    => $freed_bytes,
			'download_bytes' => $download_bytes,
			// 0 means unknown (disk_free_space disabled on the host).
			'free_bytes'     => $free,
			'disk_ok'        => 0 === $free || $free > $download_bytes * 1.1,
		);

		set_transient( 'omnioffloader_retention_preflight', $preflight, MINUTE_IN_SECONDS );

		return $preflight;
	}
}
