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
	 * Files in flight at once for this process, set by `wp omnioffloader
	 * offload --concurrency`; 0 follows the Transfer Speed setting.
	 *
	 * @var int<0, 64>
	 */
	private static $transfer_concurrency_override = 0;

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
	 * Offloaded attachments stored outside the folder new offloads go to:
	 * offloaded under an earlier Path Prefix (or none). They keep working,
	 * since every attachment keeps the path it was offloaded to.
	 *
	 * One query; for WP-CLI, not for page loads.
	 *
	 * @since 0.1.4
	 *
	 * @param string $prefix Current folder with a trailing slash, or '' for the bucket root (see FolderClaim::prefix()).
	 * @return int
	 */
	public static function count_offloaded_elsewhere( $prefix ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- one count for WP-CLI; the clauses are prepared in elsewhere_sql().
		return (int) $wpdb->get_var( 'SELECT COUNT(*) ' . self::elsewhere_sql( $prefix ) );
	}

	/**
	 * Attachments this request marked as being worked on.
	 *
	 * @var array<int, true>
	 */
	private static $working = array();

	/**
	 * Mark an attachment as being worked on by this request, outside any
	 * bulk job or WP-CLI run (a single Offload or Bring Back, a synchronous
	 * upload), so the Media Library's stale-status clean-up leaves its
	 * "processing" badge alone. Jobs are detected as running anyway, and
	 * batch their stats flush, which is how they are told apart here.
	 *
	 * Cleared when the request ends; expires on its own if it dies.
	 *
	 * @since 0.1.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function mark_working( $attachment_id ) {
		if ( self::$defer_stats_flush ) {
			return;
		}

		$attachment_id = (int) $attachment_id;

		if ( empty( self::$working ) ) {
			register_shutdown_function(
				static function () {
					foreach ( array_keys( self::$working ) as $id ) {
						delete_transient( 'omnioffloader_working_' . $id );
					}
					self::$working = array();
				}
			);
		}

		self::$working[ $attachment_id ] = true;
		set_transient( 'omnioffloader_working_' . $attachment_id, 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Whether a request is working on the attachment (see mark_working()).
	 *
	 * @since 0.1.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_working( $attachment_id ) {
		return (bool) get_transient( 'omnioffloader_working_' . (int) $attachment_id );
	}

	/**
	 * Offloaded attachments stored in a provider's bucket.
	 *
	 * @since 0.1.4
	 *
	 * @param string $provider_key Provider key.
	 * @param string $bucket       Bucket name.
	 * @return int
	 */
	public static function count_offloaded_in_bucket( $provider_key, $bucket ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one count when a bucket setting is saved.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} o
				 INNER JOIN {$wpdb->postmeta} p ON p.post_id = o.post_id AND p.meta_key = 'omnioffloader_provider' AND p.meta_value = %s
				 INNER JOIN {$wpdb->postmeta} b ON b.post_id = o.post_id AND b.meta_key = 'omnioffloader_bucket' AND b.meta_value = %s
				 WHERE o.meta_key = 'omnioffloader_offloaded' AND o.meta_value = '1'",
				$provider_key,
				$bucket
			)
		);
	}

	/**
	 * IDs of the attachments count_offloaded_elsewhere() counts, failed
	 * ones last, for `wp omnioffloader move`.
	 *
	 * @since 0.1.4
	 *
	 * @param string $prefix Current folder (see count_offloaded_elsewhere()).
	 * @param int    $limit  Most IDs; 0 for all.
	 * @return int[]
	 */
	public static function offloaded_elsewhere_ids( $prefix, $limit = 0 ) {
		global $wpdb;

		$sql = 'SELECT p.post_id ' . self::elsewhere_sql( $prefix )
			. " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.post_id AND s.meta_key = 'omnioffloader_status' AND s.meta_value = 'failed' )";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- CLI batch IDs; the clauses are prepared in elsewhere_sql().
		$ids = array_map( 'intval', $wpdb->get_col( $sql . ' ORDER BY p.post_id ASC' ) );

		// Failed ones last, so a broken item never holds up a restart.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- as above.
		$failed = array_map( 'intval', $wpdb->get_col( 'SELECT p.post_id ' . self::elsewhere_sql( $prefix ) . " AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = p.post_id AND s.meta_key = 'omnioffloader_status' AND s.meta_value = 'failed' ) ORDER BY p.post_id ASC" ) );
		$ids    = array_merge( $ids, $failed );

		return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
	}

	/**
	 * Whether an offloaded attachment is stored outside a folder.
	 *
	 * @since 0.1.4
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $prefix        Folder (see count_offloaded_elsewhere()).
	 * @return bool
	 */
	public static function is_offloaded_elsewhere( $attachment_id, $prefix ) {
		if ( '1' !== (string) get_post_meta( $attachment_id, 'omnioffloader_offloaded', true ) ) {
			return false;
		}

		list( $provider, $bucket ) = self::active_location();

		$stored_provider = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );
		$stored_bucket   = (string) get_post_meta( $attachment_id, 'omnioffloader_bucket', true );

		// Offloaded to another provider or bucket (an older setting).
		if ( ( '' !== $stored_provider && $stored_provider !== $provider ) || ( '' !== $stored_bucket && $stored_bucket !== $bucket ) ) {
			return true;
		}

		$path     = (string) get_post_meta( $attachment_id, 'omnioffloader_path', true );
		$attached = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$dir      = dirname( $attached );
		$dir      = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

		return 0 !== strpos( $path, $prefix . $dir );
	}

	/**
	 * The active provider key and its bucket.
	 *
	 * @return array{0: string, 1: string}
	 */
	private static function active_location() {
		$provider = (string) Plugin::get_provider_key();

		return array( $provider, '' !== $provider ? self::get_credential( $provider, 'bucket' ) : '' );
	}

	/**
	 * FROM / WHERE of the "offloaded outside this folder" queries. Compared
	 * byte for byte (BINARY): bucket keys are case-sensitive, the column's
	 * collation is not.
	 *
	 * An attachment is in the folder when it was offloaded to the active
	 * provider and bucket, and its stored path starts with the prefix plus
	 * its upload folder ("2026/09/", or "" at the root), which is
	 * _wp_attached_file without its file name.
	 *
	 * @param string $prefix Current folder.
	 * @return string Prepared SQL.
	 */
	private static function elsewhere_sql( $prefix ) {
		global $wpdb;

		list( $provider, $bucket ) = self::active_location();

		return $wpdb->prepare(
			"FROM {$wpdb->postmeta} p
			 INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.post_id AND o.meta_key = 'omnioffloader_offloaded' AND o.meta_value = '1'
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.post_id AND f.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} pv ON pv.post_id = p.post_id AND pv.meta_key = 'omnioffloader_provider'
			 LEFT JOIN {$wpdb->postmeta} bk ON bk.post_id = p.post_id AND bk.meta_key = 'omnioffloader_bucket'
			 WHERE p.meta_key = 'omnioffloader_path'
			 AND (
			     ( pv.meta_value IS NOT NULL AND pv.meta_value <> '' AND BINARY pv.meta_value <> BINARY %s )
			     OR ( bk.meta_value IS NOT NULL AND bk.meta_value <> '' AND BINARY bk.meta_value <> BINARY %s )
			     OR BINARY LEFT( p.meta_value, CHAR_LENGTH( %s ) + CHAR_LENGTH( f.meta_value ) - CHAR_LENGTH( SUBSTRING_INDEX( f.meta_value, '/', -1 ) ) )
			        <> BINARY CONCAT( %s, LEFT( f.meta_value, CHAR_LENGTH( f.meta_value ) - CHAR_LENGTH( SUBSTRING_INDEX( f.meta_value, '/', -1 ) ) ) )
			 )",
			$provider,
			$bucket,
			$prefix,
			$prefix
		);
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed meta count query cached via transient below.
		$offloaded = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				'omnioffloader_offloaded',
				'1'
			)
		);

		// Counted the way Bulk Offload queues them (not trashed, has a file,
		// not offloaded), so "local" reaches 0 once a full run is done.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed join count cached via transient below.
		$local = (int) $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
			 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND m.post_id IS NULL"
		);

		$stats = array(
			'total'     => $offloaded + $local,
			'offloaded' => $offloaded,
			'local'     => $local,
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
	 * Most files a WP-CLI run may move at once with --concurrency. The admin
	 * screens stay at 10: they run inside web requests, often on shared hosts.
	 */
	public const MAX_CLI_CONCURRENCY = 64;

	/**
	 * Move this many files at once for the rest of this process, ignoring
	 * the Transfer Speed setting. WP-CLI only (`offload --concurrency`).
	 *
	 * @since 0.1.4
	 *
	 * @param int $concurrency 1 to MAX_CLI_CONCURRENCY; 0 follows the setting again.
	 * @return void
	 */
	public static function set_transfer_concurrency_override( $concurrency ) {
		self::$transfer_concurrency_override = max( 0, min( self::MAX_CLI_CONCURRENCY, (int) $concurrency ) );
	}

	/**
	 * The --concurrency value of this process, 0 when none.
	 *
	 * @since 0.1.4
	 *
	 * @return int<0, 64>
	 */
	public static function transfer_concurrency_override() {
		return self::$transfer_concurrency_override;
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
	 * Free space, in bytes, for files written to the uploads folder.
	 *
	 * The lower of what the filesystem reports and, on cPanel hosting, the
	 * space left in the account's storage quota (see hosting_quota()). PHP
	 * alone only sees the whole server disk, which on shared hosting can be
	 * terabytes while the plan allows 10 GB. The `omnioffloader_free_disk_space`
	 * filter lets a host or developer supply the real figure on other panels.
	 * Restores still stop cleanly when a write is refused (see
	 * AbstractS3Provider::download_hit_disk_limit()).
	 *
	 * @since 0.1.3
	 *
	 * @param int $max_quota_age Ignore a quota figure older than this many seconds.
	 * @return int|null Null when unknown (disk_free_space() disabled, no quota).
	 */
	public static function free_disk_space( $max_quota_age = DAY_IN_SECONDS ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disk_free_space warns on some hosts; false is handled.
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( wp_get_upload_dir()['basedir'] ) : false;
		$free = false === $free ? null : (int) $free;

		$quota = self::hosting_quota();

		if ( null !== $quota && time() - $quota['updated_at'] <= $max_quota_age ) {
			$free = null === $free ? $quota['remaining'] : min( $free, $quota['remaining'] );
		}

		/**
		 * Filter the free space available for restored media, in bytes.
		 *
		 * Return the space left in a hosting plan's storage quota when the
		 * server disk (what PHP sees) is larger than the plan allows and the
		 * plugin cannot read the quota itself (it reads cPanel's).
		 *
		 * @since 0.1.3
		 *
		 * @param int|null $free Bytes free, or null when unknown.
		 */
		$free = apply_filters( 'omnioffloader_free_disk_space', $free );

		return is_numeric( $free ) && $free >= 0 ? (int) $free : null;
	}

	/**
	 * The cPanel account's storage quota, from cPanel's own cache file.
	 *
	 * The account quota lives in `~/.cpanel/datastore/_Cpanel::Quota.pm__{user}`
	 * as JSON — {"data":[used, limit, remaining, inodes used, inode limit,
	 * inodes remaining]}, sizes in bytes — and rewrites it whenever it checks
	 * the quota. Reading it needs no shell command. The file's age is
	 * returned so callers can decide how far to trust a figure that cPanel
	 * has not refreshed lately. Memoized per request and file.
	 *
	 * @since 0.1.3
	 *
	 * @return array{limit: int, used: int, remaining: int, updated_at: int}|null
	 *         Null off cPanel, when the file is unreadable, or the plan is unlimited.
	 */
	public static function hosting_quota() {
		static $memo = array();

		$file = self::cpanel_quota_file();

		if ( array_key_exists( $file, $memo ) ) {
			return $memo[ $file ];
		}

		$memo[ $file ] = self::read_cpanel_quota( $file );

		return $memo[ $file ];
	}

	/**
	 * Parse cPanel's quota cache file (see hosting_quota()).
	 *
	 * @since 0.1.3
	 *
	 * @param string $file Absolute path, or ''.
	 * @return array{limit: int, used: int, remaining: int, updated_at: int}|null
	 */
	private static function read_cpanel_quota( $file ) {
		if ( '' === $file || ! self::open_basedir_allows( $file ) || ! is_readable( $file ) || (int) filesize( $file ) > 4096 ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small local file in the account's home directory.
		$json = json_decode( (string) file_get_contents( $file ), true );
		$data = is_array( $json ) && isset( $json['data'] ) && is_array( $json['data'] ) ? array_values( $json['data'] ) : array();

		if ( count( $data ) < 3 || ! is_numeric( $data[0] ) || ! is_numeric( $data[1] ) || ! is_numeric( $data[2] ) ) {
			return null;
		}

		$used      = (int) $data[0];
		$limit     = (int) $data[1];
		$remaining = (int) $data[2];

		// 0 is an unlimited plan; anything inconsistent is a format we don't know.
		if ( $limit <= 0 || $used < 0 || $remaining < 0 || $remaining > $limit ) {
			return null;
		}

		return array(
			'limit'      => $limit,
			'used'       => $used,
			'remaining'  => $remaining,
			'updated_at' => (int) filemtime( $file ),
		);
	}

	/**
	 * Whether open_basedir lets PHP look at a path.
	 *
	 * Touching a path outside it (is_readable() included) logs a warning, and
	 * servers without cPanel (Plesk, many VPS setups) often restrict PHP to
	 * the site folder while the account's home directory lies outside it.
	 *
	 * @since 0.1.3
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function open_basedir_allows( $path ) {
		$allowed = (string) ini_get( 'open_basedir' );

		if ( '' === $allowed ) {
			return true;
		}

		foreach ( explode( PATH_SEPARATOR, $allowed ) as $dir ) {
			$dir = trim( $dir );

			// PHP matches open_basedir entries as plain prefixes.
			if ( '' !== $dir && 0 === strpos( $path, $dir ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Path of cPanel's quota cache file for the account PHP runs as.
	 *
	 * @since 0.1.3
	 *
	 * @return string Empty when it cannot be located.
	 */
	private static function cpanel_quota_file() {
		$home = '';
		$user = '';

		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$account = posix_getpwuid( posix_geteuid() );

			if ( is_array( $account ) ) {
				$home = (string) $account['dir'];
				$user = (string) $account['name'];
			}
		}

		// Without POSIX, cPanel's layout (/home/{user}/public_html) gives both.
		if ( ( '' === $home || '' === $user ) && preg_match( '#^(/home\d*/([^/]+))/#', wp_normalize_path( ABSPATH ), $m ) ) {
			$home = $m[1];
			$user = $m[2];
		}

		$file = ( '' !== $home && '' !== $user ) ? trailingslashit( $home ) . '.cpanel/datastore/_Cpanel::Quota.pm__' . $user : '';

		/**
		 * Filter the path of cPanel's quota cache file.
		 *
		 * @since 0.1.3
		 *
		 * @param string $file Absolute path, or '' when not found.
		 */
		return (string) apply_filters( 'omnioffloader_cpanel_quota_file', $file );
	}

	/**
	 * Bring Back preflight: how much would a full restore download, and
	 * does the disk have room for it?
	 *
	 * Bytes come from each offloaded attachment's stored metadata, counting
	 * only the files its retention policy removed from the server — Retain
	 * Local keeps everything (nothing to download), Smart Cleanup keeps the
	 * original (sizes only), Full Migration keeps nothing. Attachments whose
	 * metadata has no file size (uploaded before WordPress 6.0) cannot be
	 * counted and are reported in `unsized` instead. Only computed on the
	 * Bring Back page / preflight endpoint.
	 *
	 * The count and estimate are cached for an hour: every offload, restore
	 * and delete clears the cache (flush_media_stats()), and on a large
	 * library the scan takes seconds. Free space is read on every call.
	 *
	 * @param bool $force_refresh Optional. True to bypass transient cache and recompute. Default false.
	 * @return array{offloaded: int, estimated_bytes: int, unsized: int, quota: array{limit: int, used: int, remaining: int, updated_at: int}|null, free_bytes: int, disk_ok: bool}
	 */
	public static function get_restore_preflight( $force_refresh = false ) {
		$cached = $force_refresh ? false : get_transient( 'omnioffloader_restore_preflight' );

		// 'unsized' marks the current shape; older cached values are rebuilt.
		if ( is_array( $cached ) && isset( $cached['offloaded'], $cached['estimated_bytes'], $cached['unsized'] ) ) {
			$offloaded = (int) $cached['offloaded'];
			$estimated = (int) $cached['estimated_bytes'];
			$unsized   = (int) $cached['unsized'];
		} else {
			list( $offloaded, $estimated, $unsized ) = self::scan_restore_size();

			set_transient(
				'omnioffloader_restore_preflight',
				array(
					'offloaded'       => $offloaded,
					'estimated_bytes' => $estimated,
					'unsized'         => $unsized,
				),
				HOUR_IN_SECONDS
			);
		}

		$free  = (int) self::free_disk_space();
		$quota = self::hosting_quota();

		return array(
			'offloaded'       => $offloaded,
			'estimated_bytes' => $estimated,
			'unsized'         => $unsized,
			// The hosting plan's quota when the plugin could read it (cPanel).
			'quota'           => $quota,
			// 0 means "unknown" on hosts where disk_free_space is disabled —
			// treated as OK with a caveat in the UI.
			'free_bytes'      => $free,
			// 10% headroom over the estimate.
			'disk_ok'         => 0 === $free || $free > $estimated * 1.1,
		);
	}

	/**
	 * Count offloaded attachments and add up the bytes a restore downloads.
	 *
	 * Reads only the `_wp_attachment_metadata` and `omnioffloader_retention`
	 * rows, 500 attachments per query, rather than priming every meta row of
	 * every attachment. No filesystem access: the retention policy each
	 * attachment was cleaned up under says which of its files left the server.
	 *
	 * @since 0.1.3 Split out of get_restore_preflight().
	 *
	 * @return array{0: int, 1: int, 2: int} Offloaded count, estimated bytes,
	 *                                      attachments with files to download
	 *                                      but no recorded size.
	 */
	private static function scan_restore_size() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- indexed meta join query cached via transient by the caller.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'"
		);

		$estimated = 0;
		$unsized   = 0;

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one query per 500 attachments, cached via transient by the caller; placeholders are generated above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp_attachment_metadata', 'omnioffloader_retention' ) AND post_id IN ( {$placeholders} )", $chunk ) );

			$metadata = array();
			$policies = array();

			foreach ( $rows as $row ) {
				if ( 'omnioffloader_retention' === $row->meta_key ) {
					$policies[ (int) $row->post_id ] = max( 0, min( 2, (int) $row->meta_value ) );
				} else {
					$metadata[ (int) $row->post_id ] = maybe_unserialize( $row->meta_value );
				}
			}

			foreach ( $chunk as $id ) {
				// Missing meta means Retain Local (see RetentionApplier::stored_policy()).
				$roles = RetentionApplier::local_roles( $policies[ $id ] ?? 0 );

				if ( $roles['main'] && $roles['sizes'] ) {
					continue;
				}

				$meta   = isset( $metadata[ $id ] ) && is_array( $metadata[ $id ] ) ? $metadata[ $id ] : array();
				$known  = true;
				$sizes  = ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
				$amount = 0;

				if ( ! $roles['main'] ) {
					if ( isset( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) ) {
						$amount += (int) $meta['filesize'];
					} else {
						$known = false;
					}
				}

				if ( ! $roles['sizes'] ) {
					foreach ( $sizes as $size_data ) {
						if ( is_array( $size_data ) && isset( $size_data['filesize'] ) && is_numeric( $size_data['filesize'] ) ) {
							$amount += (int) $size_data['filesize'];
						} elseif ( is_array( $size_data ) ) {
							$known = false;
						}
					}
				}

				$estimated += $amount;

				if ( ! $known ) {
					++$unsized;
				}
			}
		}

		return array( count( $ids ), $estimated, $unsized );
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
				// Rebuilt key by key: a transient is untyped storage.
				return array(
					'policy'         => $policy,
					'eligible'       => (int) $cached['eligible'],
					'to_clean'       => (int) ( $cached['to_clean'] ?? 0 ),
					'to_download'    => (int) ( $cached['to_download'] ?? 0 ),
					'freed_bytes'    => (int) ( $cached['freed_bytes'] ?? 0 ),
					'download_bytes' => (int) ( $cached['download_bytes'] ?? 0 ),
					'free_bytes'     => (int) ( $cached['free_bytes'] ?? 0 ),
					'disk_ok'        => (bool) ( $cached['disk_ok'] ?? true ),
				);
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

		$free = (int) self::free_disk_space();

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
