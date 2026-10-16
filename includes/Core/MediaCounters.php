<?php
/**
 * Running counters of offloaded media, so the Bring Back and retention
 * preflights never scan the library.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Services\RetentionApplier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MediaCounters
 *
 * For each stored retention policy (0, 1, 2) it keeps: how many offloaded
 * attachments follow it, the bytes of their main files and of their
 * generated sizes (from `_wp_attachment_metadata`), and how many have no
 * recorded size for the main file, a size, or either.
 *
 * Kept current by watching the three meta keys the numbers depend on
 * (`omnioffloader_offloaded`, `omnioffloader_retention`,
 * `_wp_attachment_metadata`) and attachment deletion — not by every writer:
 * before an attachment's first change in a request its counted contribution
 * is remembered, and after the writes the difference is applied. Batches of
 * changes are applied together (every FLUSH_AT attachments and at shutdown).
 *
 * Each figure is its own option row, changed with an atomic SQL increment,
 * so two processes (an upload and a bulk run) never lose each other's update.
 * A missing or outdated set is rebuilt from a chunked scan; a cheap grouped
 * count checks the stored counts once an hour and rebuilds them on drift.
 *
 * @since 0.1.7
 */
class MediaCounters {

	/**
	 * Data format; bump to force a rebuild after changing what is counted.
	 */
	const VERSION = 1;

	/**
	 * Option holding the VERSION the counters were built with.
	 */
	const VERSION_OPTION = 'omnioffloader_counters_version';

	/**
	 * Prefix of the per-figure option rows: omnioffloader_cnt_{policy}_{field}.
	 */
	const ROW_PREFIX = 'omnioffloader_cnt_';

	/**
	 * Transient set while the hourly drift check is not due.
	 */
	const CHECKED_TRANSIENT = 'omnioffloader_counters_checked';

	/**
	 * Attachments changed before the pending differences are applied.
	 */
	const FLUSH_AT = 50;

	/**
	 * The figures kept per policy.
	 */
	const FIELDS = array( 'count', 'main_bytes', 'size_bytes', 'main_unknown', 'sizes_unknown', 'any_unknown' );

	/**
	 * Meta keys whose changes move the counters.
	 */
	const KEYS = array( 'omnioffloader_offloaded', 'omnioffloader_retention', '_wp_attachment_metadata' );

	/**
	 * Counted contribution of each attachment changed in this request, by
	 * blog ID then attachment ID, taken before its first change.
	 *
	 * @var array<int, array<int, array<string, int>|null>>
	 */
	private static $baseline = array();

	/**
	 * Whether the hooks are registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Set while rebuild() runs, so its own reads are not tracked.
	 *
	 * @var bool
	 */
	private static $rebuilding = false;

	/**
	 * Hook into meta changes. Cheap on every request: each callback first
	 * checks the meta key against three names.
	 *
	 * @return void
	 */
	public static function register() {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		add_filter( 'add_post_metadata', array( self::class, 'before_meta_change' ), 10, 3 );
		add_filter( 'update_post_metadata', array( self::class, 'before_meta_change' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( self::class, 'before_meta_change' ), 10, 3 );
		add_action( 'added_post_meta', array( self::class, 'after_meta_change' ), 10, 3 );
		add_action( 'updated_post_meta', array( self::class, 'after_meta_change' ), 10, 3 );
		add_action( 'deleted_post_meta', array( self::class, 'after_meta_change' ), 10, 3 );
		add_action( 'delete_attachment', array( self::class, 'remember' ) );
		add_action( 'shutdown', array( self::class, 'flush' ) );
	}

	/**
	 * Remember an attachment's contribution before its meta changes.
	 * Filter callback: passes $check through untouched.
	 *
	 * @param mixed  $check     Short-circuit value from other filters.
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return mixed
	 */
	public static function before_meta_change( $check, $object_id, $meta_key ) {
		if ( in_array( $meta_key, self::KEYS, true ) && (int) $object_id > 0 ) {
			self::remember( (int) $object_id );
		}

		return $check;
	}

	/**
	 * After a tracked change: apply pending differences once enough piled up.
	 *
	 * @param mixed  $meta_ids  Meta ID(s).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return void
	 */
	public static function after_meta_change( $meta_ids, $object_id, $meta_key ) {
		unset( $meta_ids );

		if ( ! in_array( $meta_key, self::KEYS, true ) || (int) $object_id <= 0 ) {
			return;
		}

		$pending = 0;

		foreach ( self::$baseline as $ids ) {
			$pending += count( $ids );
		}

		if ( $pending >= self::FLUSH_AT ) {
			self::flush();
		}
	}

	/**
	 * Remember an attachment's counted contribution, once per batch.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function remember( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$blog          = get_current_blog_id();

		if ( self::$rebuilding || ( isset( self::$baseline[ $blog ] ) && array_key_exists( $attachment_id, self::$baseline[ $blog ] ) ) ) {
			return;
		}

		// Nothing to keep current until the counters exist; the first read builds them.
		if ( ! self::is_built() ) {
			return;
		}

		self::$baseline[ $blog ][ $attachment_id ] = self::contribution( $attachment_id );
	}

	/**
	 * Apply the differences of every remembered attachment.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( empty( self::$baseline ) ) {
			return;
		}

		$batches        = self::$baseline;
		self::$baseline = array();
		$current        = get_current_blog_id();

		foreach ( $batches as $blog => $ids ) {
			$switched = false;

			if ( $blog !== $current ) {
				if ( ! is_multisite() ) {
					continue;
				}

				switch_to_blog( $blog );
				$switched = true;
			}

			$delta = array();

			foreach ( $ids as $id => $before ) {
				$after = self::contribution( $id );

				foreach ( array( array( $before, -1 ), array( $after, 1 ) ) as $side ) {
					list( $part, $sign ) = $side;

					if ( null === $part ) {
						continue;
					}

					foreach ( self::FIELDS as $field ) {
						$key           = $part['policy'] . '_' . $field;
						$delta[ $key ] = ( $delta[ $key ] ?? 0 ) + $sign * $part[ $field ];
					}
				}
			}

			self::apply( $delta );

			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/**
	 * The counters, rebuilt first when missing, outdated or drifted.
	 *
	 * @return array<int, array<string, int>> Policy => field => value.
	 */
	public static function get() {
		self::flush();

		if ( ! self::is_built() ) {
			self::rebuild();
		} elseif ( false === get_transient( self::CHECKED_TRANSIENT ) ) {
			if ( ! self::counts_match( self::read() ) ) {
				self::rebuild();
			}

			set_transient( self::CHECKED_TRANSIENT, 1, HOUR_IN_SECONDS );
		}

		return self::read();
	}

	/**
	 * What a full Bring Back would download, from the counters.
	 *
	 * Sizes left the server under policy 1, everything under policy 2.
	 *
	 * @return array{offloaded: int, estimated_bytes: int, unsized: int}
	 */
	public static function restore_estimate() {
		$c = self::get();

		return array(
			'offloaded'       => $c[0]['count'] + $c[1]['count'] + $c[2]['count'],
			'estimated_bytes' => $c[1]['size_bytes'] + $c[2]['main_bytes'] + $c[2]['size_bytes'],
			'unsized'         => $c[1]['sizes_unknown'] + $c[2]['any_unknown'],
		);
	}

	/**
	 * What applying a retention policy to offloaded media would do.
	 *
	 * @param int $policy Target retention policy.
	 * @return array{eligible: int, to_clean: int, to_download: int, freed_bytes: int, download_bytes: int}
	 */
	public static function retention_estimate( $policy ) {
		$c      = self::get();
		$policy = max( 0, min( 2, (int) $policy ) );
		$new    = RetentionApplier::local_roles( $policy );
		$result = array(
			'eligible'       => 0,
			'to_clean'       => 0,
			'to_download'    => 0,
			'freed_bytes'    => 0,
			'download_bytes' => 0,
		);

		foreach ( array( 0, 1, 2 ) as $stored ) {
			if ( $stored === $policy || 0 === $c[ $stored ]['count'] ) {
				continue;
			}

			$old = RetentionApplier::local_roles( $stored );

			$result['eligible']                                       += $c[ $stored ]['count'];
			$result[ $policy > $stored ? 'to_clean' : 'to_download' ] += $c[ $stored ]['count'];

			foreach ( array(
				'main'  => $c[ $stored ]['main_bytes'],
				'sizes' => $c[ $stored ]['size_bytes'],
			) as $role => $bytes ) {
				if ( $old[ $role ] && ! $new[ $role ] ) {
					$result['freed_bytes'] += $bytes;
				} elseif ( ! $old[ $role ] && $new[ $role ] ) {
					$result['download_bytes'] += $bytes;
				}
			}
		}

		return $result;
	}

	/**
	 * Storage totals for the Dashboard: what is in the cloud, what offloaded
	 * media still keeps on this server, and the space its retention policy
	 * freed, overall and per policy.
	 *
	 * Every offloaded file is in the cloud; Retain Local keeps all of them on
	 * the server, Smart Cleanup the originals, Full Migration none.
	 *
	 * @since 0.1.7
	 *
	 * @return array{cloud_bytes: int, server_bytes: int, freed_bytes: int, unsized: int, policies: array<int, array{policy: int, count: int, cloud_bytes: int, server_bytes: int, freed_bytes: int}>}
	 */
	public static function storage() {
		$c      = self::get();
		$totals = array(
			'cloud_bytes'  => 0,
			'server_bytes' => 0,
			'freed_bytes'  => 0,
			'unsized'      => 0,
			'policies'     => array(),
		);

		foreach ( array( 0, 1, 2 ) as $policy ) {
			$roles  = RetentionApplier::local_roles( $policy );
			$cloud  = $c[ $policy ]['main_bytes'] + $c[ $policy ]['size_bytes'];
			$server = ( $roles['main'] ? $c[ $policy ]['main_bytes'] : 0 ) + ( $roles['sizes'] ? $c[ $policy ]['size_bytes'] : 0 );

			$totals['cloud_bytes']  += $cloud;
			$totals['server_bytes'] += $server;
			$totals['freed_bytes']  += $cloud - $server;
			$totals['unsized']      += $c[ $policy ]['any_unknown'];
			$totals['policies'][]    = array(
				'policy'       => $policy,
				'count'        => $c[ $policy ]['count'],
				'cloud_bytes'  => $cloud,
				'server_bytes' => $server,
				'freed_bytes'  => $cloud - $server,
			);
		}

		return $totals;
	}

	/**
	 * Recount everything from the database: a chunked scan of the offloaded
	 * attachments' metadata and retention rows, 500 attachments per query.
	 *
	 * @return void
	 */
	public static function rebuild() {
		global $wpdb;

		self::$rebuilding = true;

		$totals = self::empty_counters();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- full recount, run only when the counters are missing or drifted.
		$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1' WHERE p.post_type = 'attachment'" ) );

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one query per 500 attachments; placeholders are generated above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ( '_wp_attachment_metadata', 'omnioffloader_retention' ) AND post_id IN ( {$placeholders} )", $chunk ) );

			$metadata = array();
			$policies = array();

			foreach ( $rows as $row ) {
				if ( 'omnioffloader_retention' === $row->meta_key ) {
					$policies[ (int) $row->post_id ] = (string) $row->meta_value;
				} else {
					$metadata[ (int) $row->post_id ] = maybe_unserialize( $row->meta_value );
				}
			}

			foreach ( $chunk as $id ) {
				$part = self::measure( self::clamp_policy( $policies[ $id ] ?? '' ), $metadata[ $id ] ?? null );

				foreach ( self::FIELDS as $field ) {
					$totals[ $part['policy'] ][ $field ] += $part[ $field ];
				}
			}
		}

		foreach ( $totals as $policy => $fields ) {
			foreach ( $fields as $field => $value ) {
				update_option( self::ROW_PREFIX . $policy . '_' . $field, $value, false );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION, false );
		set_transient( self::CHECKED_TRANSIENT, 1, HOUR_IN_SECONDS );

		self::$rebuilding = false;
	}

	/**
	 * Remove every counter row (uninstall).
	 *
	 * @return void
	 */
	public static function delete_all() {
		foreach ( array( 0, 1, 2 ) as $policy ) {
			foreach ( self::FIELDS as $field ) {
				delete_option( self::ROW_PREFIX . $policy . '_' . $field );
			}
		}

		delete_option( self::VERSION_OPTION );
		delete_transient( self::CHECKED_TRANSIENT );
	}

	/**
	 * Whether the counters exist in the current format.
	 *
	 * @return bool
	 */
	private static function is_built() {
		return self::VERSION === (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * An attachment's part of the counters, or null when it isn't offloaded.
	 *
	 * Reads raw meta (no filters) through the meta cache.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int>|null
	 */
	private static function contribution( $attachment_id ) {
		if ( '1' !== (string) get_post_meta( $attachment_id, 'omnioffloader_offloaded', true ) ) {
			return null;
		}

		return self::measure(
			self::clamp_policy( get_post_meta( $attachment_id, 'omnioffloader_retention', true ) ),
			get_post_meta( $attachment_id, '_wp_attachment_metadata', true )
		);
	}

	/**
	 * The figures one offloaded attachment adds.
	 *
	 * @param int   $policy   Stored retention policy.
	 * @param mixed $metadata Attachment metadata.
	 * @return array<string, int>
	 */
	private static function measure( $policy, $metadata ) {
		$meta  = is_array( $metadata ) ? $metadata : array();
		$main  = isset( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) ? (int) $meta['filesize'] : null;
		$bytes = 0;
		$known = true;

		foreach ( ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['filesize'] ) && is_numeric( $size['filesize'] ) ) {
				$bytes += (int) $size['filesize'];
			} elseif ( is_array( $size ) ) {
				$known = false;
			}
		}

		return array(
			'policy'        => $policy,
			'count'         => 1,
			'main_bytes'    => (int) $main,
			'size_bytes'    => $bytes,
			'main_unknown'  => null === $main ? 1 : 0,
			'sizes_unknown' => $known ? 0 : 1,
			'any_unknown'   => null === $main || ! $known ? 1 : 0,
		);
	}

	/**
	 * A stored retention value as 0, 1 or 2 (missing counts as 0, like
	 * RetentionApplier::stored_policy()).
	 *
	 * @param mixed $value Raw meta value.
	 * @return int
	 */
	private static function clamp_policy( $value ) {
		return '' === (string) $value ? 0 : max( 0, min( 2, (int) $value ) );
	}

	/**
	 * Add differences to the counter rows with atomic SQL increments.
	 *
	 * @param array<string, int> $delta "{policy}_{field}" => difference.
	 * @return void
	 */
	private static function apply( array $delta ) {
		global $wpdb;

		foreach ( $delta as $key => $change ) {
			if ( 0 === $change ) {
				continue;
			}

			$name = self::ROW_PREFIX . $key;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic increment; the option cache is cleared below.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CAST( option_value AS SIGNED ) + %d WHERE option_name = %s", $change, $name ) );
			wp_cache_delete( $name, 'options' );
		}
	}

	/**
	 * The counter rows as stored, straight from the database.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function read() {
		global $wpdb;

		$counters = self::empty_counters();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 18 small rows, read past the object cache so increments by other processes are seen.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::ROW_PREFIX ) . '%' ) );

		foreach ( $rows as $row ) {
			if ( preg_match( '/^' . preg_quote( self::ROW_PREFIX, '/' ) . '([0-2])_([a-z_]+)$/', (string) $row->option_name, $m ) && in_array( $m[2], self::FIELDS, true ) ) {
				$counters[ (int) $m[1] ][ $m[2] ] = max( 0, (int) $row->option_value );
			}
		}

		return $counters;
	}

	/**
	 * Whether the stored per-policy counts match the database: one grouped
	 * count of the offloaded attachments' retention rows.
	 *
	 * @param array<int, array<string, int>> $counters Stored counters.
	 * @return bool
	 */
	private static function counts_match( array $counters ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- hourly drift check; indexed meta rows only.
		$rows = $wpdb->get_results( "SELECT COALESCE( NULLIF( r.meta_value, '' ), '0' ) AS policy, COUNT(*) AS total FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1' LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'omnioffloader_retention' WHERE p.post_type = 'attachment' GROUP BY policy" );

		$actual = array( 0, 0, 0 );

		foreach ( $rows as $row ) {
			$actual[ self::clamp_policy( $row->policy ) ] += (int) $row->total;
		}

		foreach ( array( 0, 1, 2 ) as $policy ) {
			if ( $actual[ $policy ] !== $counters[ $policy ]['count'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * All-zero counters.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function empty_counters() {
		$fields = array_fill_keys( self::FIELDS, 0 );

		return array(
			0 => $fields,
			1 => $fields,
			2 => $fields,
		);
	}
}
