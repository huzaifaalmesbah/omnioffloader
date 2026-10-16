<?php
/**
 * WP-CLI: integrity check of offloaded media.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Services\RetentionApplier;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares what the database says with what is really in the bucket and on
 * the server, and optionally repairs the differences.
 *
 * Repairs never delete anything: a file missing from the bucket is uploaded
 * again from the server, a file the retention policy keeps on the server is
 * downloaded again from the bucket. A file that is in neither place is only
 * reported. A file whose cloud state could not be read (network error) is
 * reported as unchecked and never acted on.
 *
 * @since 0.1.6
 */
class VerifyCommand {

	use AttachmentHelper;
	use CliJobGuard;

	/**
	 * Attachments primed per database round trip.
	 */
	private const CHUNK = 100;

	/**
	 * Issues found, one row each.
	 *
	 * @var array<int, array<string, string|int>>
	 */
	private $rows = array();

	/**
	 * Providers whose connection was checked, by "key|bucket".
	 *
	 * @var array<string, true>
	 */
	private $reachable = array();

	/**
	 * Counters for the summary.
	 *
	 * @var array<string, int>
	 */
	private $counts = array(
		'attachments' => 0,
		'files'       => 0,
		'issues'      => 0,
		'repaired'    => 0,
		'failed'      => 0,
	);

	/**
	 * Check offloaded media against the bucket and the server.
	 *
	 * For every offloaded attachment, checks each file (original, scaled
	 * original and every size):
	 *
	 * - cloud_missing: not in the bucket. Repair: upload it from the server.
	 * - cloud_differs: the bucket copy has another size than the server's
	 *   (an image optimizer changed it after the offload). Repair: upload the
	 *   server's copy.
	 * - local_missing: the attachment's retention policy keeps it on the
	 *   server, but it is gone. Repair: download it from the bucket.
	 * - lost: in neither place. Reported only; restore it from a backup.
	 * - unchecked: the bucket could not be read for it (network error).
	 *
	 * Also finds attachments stuck as "Offloading…" / "Queued" while no job
	 * runs (stuck). Repair: clear the status.
	 *
	 * Without --repair it changes nothing. Exits with 1 when an issue is
	 * left unrepaired, so it can be used in monitoring.
	 *
	 * ## OPTIONS
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs. Default: every offloaded attachment.
	 *
	 * [--limit=<number>]
	 * : Check at most this many attachments.
	 *
	 * [--repair]
	 * : Fix what can be fixed. Never deletes a file.
	 *
	 * [--dry-run]
	 * : With --repair, list the repairs without making them.
	 *
	 * [--format=<format>]
	 * : Output format for the issues.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * [--force]
	 * : With --repair, run even when a bulk job started from the admin
	 * screen is active.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader verify
	 *     wp omnioffloader verify --limit=500
	 *     wp omnioffloader verify --repair --dry-run
	 *     wp omnioffloader verify --repair
	 *     wp omnioffloader verify --ids=12,15 --format=json
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$plugin = Plugin::get_instance();
		$repair = isset( $assoc_args['repair'] );
		$is_dry = isset( $assoc_args['dry-run'] );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fix    = $repair && ! $is_dry;

		if ( $fix && SafeMode::is_active() ) {
			WP_CLI::error( SafeMode::message() . ' Run verify without --repair to only check.' );
		}

		if ( $fix ) {
			$this->refuse_if_busy( $plugin, $assoc_args );
		} elseif ( '' !== $plugin->get_active_bulk_job() ) {
			WP_CLI::warning( 'A job is running, so files it is working on may show as issues. Run verify again once it ends.' );
		}

		$ids = $this->resolve_ids( $assoc_args );

		// Before this run claims the job: a stuck status means no job runs.
		$stuck = $this->find_stuck( $plugin, isset( $assoc_args['ids'] ) ? $ids : null );

		if ( empty( $ids ) && empty( $stuck ) ) {
			WP_CLI::success( 'No offloaded media to verify.' );
			return;
		}

		if ( $fix ) {
			$this->begin_job( $plugin, null, 'verify', count( $ids ) );
		}

		Helpers::defer_media_stats_flush( true );

		foreach ( $stuck as $id ) {
			$this->add_issue( $id, 'stuck', '', 'clear status', $repair );
		}

		if ( $fix && ! empty( $stuck ) ) {
			AbstractBulkProcessor::clear_pending_status( $stuck );
			$this->mark_last_rows( count( $stuck ), 'repaired' );
		}

		$progress = 'table' === $format && count( $ids ) > 1
			? \WP_CLI\Utils\make_progress_bar( 'Verifying', count( $ids ) )
			: null;

		$done = 0;

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				$this->verify_attachment( $id, $repair, $fix );
				++$done;

				if ( null !== $progress ) {
					$progress->tick();
				}

				if ( $fix ) {
					$this->keep_job_alive( $done, $this->counts['failed'] );

					if ( $this->stop_requested() ) {
						break 2;
					}
				}

				clean_post_cache( $id );
			}
		}

		if ( null !== $progress ) {
			$progress->finish();
		}

		Helpers::defer_media_stats_flush( false );

		if ( $fix ) {
			$this->end_job();
		}

		$this->report( $format, $repair, $is_dry, $done < count( $ids ) );
	}

	/**
	 * Check one attachment's files and repair what --repair may.
	 *
	 * @param int  $id     Attachment ID.
	 * @param bool $repair --repair given (with or without --dry-run).
	 * @param bool $fix    Make the repairs (--repair without --dry-run).
	 * @return void
	 */
	private function verify_attachment( $id, $repair, $fix ) {
		++$this->counts['attachments'];

		$provider = AttachmentRestorer::provider_for( $id );

		if ( null === $provider ) {
			$this->add_issue( $id, 'no_provider', '', '', false );
			return;
		}

		$this->require_reachable( $provider );

		$files   = self::collect_attachment_files( $id );
		$keeps   = RetentionApplier::local_roles( RetentionApplier::stored_policy( $id ) );
		$entries = array();

		foreach ( array( 'main', 'sizes' ) as $role ) {
			foreach ( $files[ $role ] as $entry ) {
				$entries[] = $entry + array( 'kept' => $keeps[ $role ] );
			}
		}

		if ( empty( $entries ) ) {
			$this->add_issue( $id, 'lost', '', '', false );
			return;
		}

		$remote   = $this->remote_sizes_of( $provider, wp_list_pluck( $entries, 'key' ) );
		$download = false;

		foreach ( $entries as $entry ) {
			++$this->counts['files'];

			$file = (string) $entry['file'];
			$key  = (string) $entry['key'];
			$name = wp_basename( $file );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled.
			$local_size  = @filesize( $file );
			$remote_size = null === $remote ? null : ( $remote[ $key ] ?? false );

			if ( null === $remote_size ) {
				$this->add_issue( $id, 'unchecked', $name, '', false );
				continue;
			}

			if ( false === $remote_size ) {
				if ( false === $local_size ) {
					$this->add_issue( $id, 'lost', $name, '', false );
					self::log_attachment_error( $id, sprintf( 'Verify: "%s" is neither in cloud storage nor on the server.', $name ) );
					continue;
				}

				$this->add_issue( $id, 'cloud_missing', $name, 'upload', $repair );

				if ( $fix ) {
					$this->upload( $id, $provider, $file, $key, (int) $local_size );
				}

				continue;
			}

			if ( false !== $local_size && (int) $local_size !== (int) $remote_size ) {
				$this->add_issue( $id, 'cloud_differs', $name, 'upload', $repair );

				if ( $fix ) {
					$this->upload( $id, $provider, $file, $key, (int) $local_size );
				}

				continue;
			}

			if ( false === $local_size && $entry['kept'] ) {
				$this->add_issue( $id, 'local_missing', $name, 'download', $repair );
				$download = true;
			}
		}

		if ( $fix && $download ) {
			$this->download( $id );
		}
	}

	/**
	 * Sizes of the attachment's objects, from bucket listings.
	 *
	 * Listing (not HEAD) so that "not listed" really means missing: HEAD
	 * returns the same false for a missing object and a network error.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param string[]          $keys     Object keys.
	 * @return array<string, int>|null Key => size, or null when the bucket could not be read.
	 */
	private function remote_sizes_of( ProviderInterface $provider, array $keys ) {
		if ( ! method_exists( $provider, 'list_object_sizes' ) ) {
			$sizes = array();

			foreach ( $keys as $key ) {
				$size = $provider->object_size( $key );

				if ( false === $size ) {
					// Missing or unreachable: HEAD cannot tell, so never act on it.
					return null;
				}

				$sizes[ $key ] = (int) $size;
			}

			return $sizes;
		}

		$prefix   = self::size_listing_prefix( $keys );
		$prefixes = '' !== $prefix ? array( $prefix ) : array_values( array_unique( $keys ) );
		$sizes    = array();

		foreach ( $prefixes as $listing_prefix ) {
			$listed = $provider->list_object_sizes( $listing_prefix );

			if ( ! is_array( $listed ) ) {
				return null;
			}

			$sizes += $listed;
		}

		return $sizes;
	}

	/**
	 * Upload the server's copy of a file and confirm it by size.
	 *
	 * @param int               $id         Attachment ID.
	 * @param ProviderInterface $provider   Provider.
	 * @param string            $file       Local path.
	 * @param string            $key        Object key.
	 * @param int               $local_size Local size in bytes.
	 * @return void
	 */
	private function upload( $id, ProviderInterface $provider, $file, $key, $local_size ) {
		$ok = false !== $provider->upload_file( $file, $key ) && (int) $provider->object_size( $key ) === $local_size;

		$this->mark_last_rows( 1, $ok ? 'repaired' : 'failed' );

		if ( ! $ok ) {
			self::log_attachment_error( $id, sprintf( 'Verify: could not upload "%s" to cloud storage.', wp_basename( $file ) ) );
		}
	}

	/**
	 * Download the files the attachment's retention policy keeps on the
	 * server (atomic temp file + rename, confined to the uploads directory).
	 *
	 * @param int $id Attachment ID.
	 * @return void
	 */
	private function download( $id ) {
		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore_local_copies( $id, RetentionApplier::stored_policy( $id ) );

		// Every local_missing row of this attachment shares the outcome.
		$pending = 0;

		foreach ( $this->rows as $row ) {
			if ( $row['id'] === $id && 'local_missing' === $row['issue'] && 'pending' === $row['result'] ) {
				++$pending;
			}
		}

		foreach ( $this->rows as $i => $row ) {
			if ( $row['id'] === $id && 'local_missing' === $row['issue'] && 'pending' === $row['result'] ) {
				$this->rows[ $i ]['result'] = $ok ? 'repaired' : 'failed';
			}
		}

		$this->counts[ $ok ? 'repaired' : 'failed' ] += $pending;

		if ( $restorer->ran_out_of_disk() ) {
			$this->end_job();
			WP_CLI::error( 'The server ran out of disk space while downloading. Free some space and run verify --repair again.' );
		}
	}

	/**
	 * Record an issue.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $issue  Issue code.
	 * @param string $file   File name, '' for the attachment as a whole.
	 * @param string $action Repair, '' when there is none.
	 * @param bool   $repair --repair given.
	 * @return void
	 */
	private function add_issue( $id, $issue, $file, $action, $repair ) {
		++$this->counts['issues'];

		$result = '';

		if ( '' === $action ) {
			$result = 'not repairable';
		} elseif ( $repair ) {
			$result = 'pending';
		}

		$this->rows[] = array(
			'id'     => (int) $id,
			'title'  => get_the_title( $id ),
			'issue'  => $issue,
			'file'   => $file,
			'repair' => $action,
			'result' => $result,
		);
	}

	/**
	 * Set the result of the last $n pending rows.
	 *
	 * @param int    $n      Rows.
	 * @param string $result 'repaired' or 'failed'.
	 * @return void
	 */
	private function mark_last_rows( $n, $result ) {
		for ( $i = count( $this->rows ) - 1; $i >= 0 && $n > 0; $i-- ) {
			if ( 'pending' === $this->rows[ $i ]['result'] ) {
				$this->rows[ $i ]['result'] = $result;
				++$this->counts[ $result ];
				--$n;
			}
		}
	}

	/**
	 * Every offloaded attachment, or the --ids given, oldest first.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return int[]
	 */
	private function resolve_ids( array $assoc_args ) {
		global $wpdb;

		$limit     = $this->parse_limit( $assoc_args );
		$requested = $this->parse_ids( $assoc_args );

		if ( null !== $requested ) {
			_prime_post_caches( $requested, false, true );

			$ids = array_values(
				array_filter(
					$requested,
					static function ( $id ) {
						return 'attachment' === get_post_type( $id ) && self::is_offloaded( $id );
					}
				)
			);

			$this->warn_skipped_ids( $requested, $ids, 'not attachments, or not offloaded' );

			return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		}

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			ORDER BY p.ID ASC";

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- fixed SQL; LIMIT prepared above.
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Attachments showing "Offloading…" / "Queued" while nothing runs.
	 *
	 * @param Plugin     $plugin Plugin instance.
	 * @param int[]|null $ids    Restrict to these (--ids), null for all.
	 * @return int[]
	 */
	private function find_stuck( Plugin $plugin, $ids ) {
		global $wpdb;

		$async = $plugin->get_async_upload();

		if ( '' !== $plugin->get_active_bulk_job() || ( null !== $async && ! $async->is_idle() ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed meta lookup.
		$stuck = array_map( 'intval', (array) $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'omnioffloader_status' AND meta_value IN ( 'queued', 'processing' )" ) );

		// A single Offload or Bring Back in another request is still on it.
		$stuck = array_values(
			array_filter(
				$stuck,
				static function ( $id ) {
					return ! Helpers::is_working( $id );
				}
			)
		);

		return null === $ids ? $stuck : array_values( array_intersect( $stuck, $ids ) );
	}

	/**
	 * Stop when a provider the media uses cannot be reached: every file
	 * would show as unchecked. Checked once per provider, when first met.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return void
	 */
	private function require_reachable( ProviderInterface $provider ) {
		$key = $provider->get_key() . '|' . (string) $provider->get_bucket();

		if ( isset( $this->reachable[ $key ] ) ) {
			return;
		}

		$connection = $provider->check_connection();

		if ( true !== $connection ) {
			if ( '' !== $this->guard_job ) {
				$this->end_job();
			}

			WP_CLI::error( sprintf( 'Cannot reach %s: %s', $provider->get_name(), $connection->get_error_message() ) );
		}

		$this->reachable[ $key ] = true;
	}

	/**
	 * Print the issues and the summary; exit 1 when issues are left.
	 *
	 * @param string $format  Output format.
	 * @param bool   $repair  --repair given.
	 * @param bool   $is_dry  --dry-run given.
	 * @param bool   $stopped Stopped with `wp omnioffloader stop`.
	 * @return void
	 */
	private function report( $format, $repair, $is_dry, $stopped ) {
		$fields = array( 'id', 'title', 'issue', 'file', 'repair', 'result' );

		if ( $repair && $is_dry ) {
			foreach ( $this->rows as $i => $row ) {
				if ( 'pending' === $row['result'] ) {
					$this->rows[ $i ]['result'] = 'would repair';
				}
			}
		}

		$left = $this->counts['issues'] - $this->counts['repaired'];

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $left );
		} elseif ( 'table' !== $format ) {
			\WP_CLI\Utils\format_items( $format, $this->rows, $fields );
		} else {
			if ( ! empty( $this->rows ) ) {
				\WP_CLI\Utils\format_items( 'table', $this->rows, $fields );
			}

			WP_CLI::log(
				sprintf(
					'Checked %d attachment(s), %d file(s): %d issue(s)%s.',
					$this->counts['attachments'],
					$this->counts['files'],
					$this->counts['issues'],
					$repair && ! $is_dry ? sprintf( ', %d repaired, %d failed', $this->counts['repaired'], $this->counts['failed'] ) : ''
				)
			);

			if ( $stopped ) {
				WP_CLI::log( 'Stopped with `wp omnioffloader stop` before every attachment was checked.' );
			}

			if ( 0 === $this->counts['issues'] ) {
				WP_CLI::success( 'Everything matches.' );
			} elseif ( ! $repair ) {
				WP_CLI::log( 'Fix what can be fixed with: wp omnioffloader verify --repair (try --dry-run first).' );
			}
		}

		if ( $left > 0 ) {
			WP_CLI::halt( 1 );
		}
	}
}
