<?php
/**
 * WP-CLI: offload media to the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Offloads media library attachments to the configured cloud provider.
 */
class OffloadCommand {

	use AttachmentHelper;
	use CliJobGuard;

	/**
	 * Failures in a row that trigger a connection check.
	 */
	private const FAILURE_LIMIT = 10;

	/**
	 * Failures in a row, as a multiple of FAILURE_LIMIT, that stop the run
	 * even while the provider still answers.
	 */
	private const UNEXPLAINED_FAILURE_FACTOR = 10;

	/**
	 * Offload attachments to the cloud.
	 *
	 * Runs synchronously with a progress bar. Local files are handled
	 * per the configured retention policy after each verified upload.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Offload every attachment that is not offloaded yet.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to offload.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments. Useful for working through a large
	 * library in scheduled chunks.
	 *
	 * [--dry-run]
	 * : List what would be offloaded without uploading anything.
	 *
	 * [--force]
	 * : Run even when a bulk offload started from the admin screen is active.
	 *
	 * [--concurrency=<number>]
	 * : Files uploaded at the same time for this run, 1 to 64, instead of the
	 * Transfer Speed setting (at most 10). For a VPS or dedicated server; try
	 * a short run first, e.g. with --limit=500.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader offload --all
	 *     wp omnioffloader offload --ids=12,15,42
	 *     wp omnioffloader offload --all --limit=500
	 *     wp omnioffloader offload --all --dry-run
	 *     nohup wp omnioffloader offload --all > ~/offload.log 2>&1 &
	 *     wp omnioffloader offload --all --concurrency=32
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$plugin   = Plugin::get_instance();
		$provider = $plugin->get_provider();

		if ( null === $provider ) {
			WP_CLI::error( self::engine_off_reason() );
		}

		$queue  = $plugin->get_bulk_offload();
		$is_dry = isset( $assoc_args['dry-run'] );

		if ( isset( $assoc_args['concurrency'] ) ) {
			$concurrency = (string) $assoc_args['concurrency'];

			// A typo must not silently mean "the setting" or "64".
			if ( ! ctype_digit( $concurrency ) || (int) $concurrency < 1 || (int) $concurrency > Helpers::MAX_CLI_CONCURRENCY ) {
				WP_CLI::error( sprintf( '--concurrency must be a whole number from 1 to %d.', Helpers::MAX_CLI_CONCURRENCY ) );
			}

			Helpers::set_transfer_concurrency_override( (int) $concurrency );
		}

		// The admin bulk queue and this command would otherwise offload the
		// same attachments at once: with a cleanup retention policy one side
		// deletes local files the other is still reading, which fails uploads
		// and fills the error log for no reason.
		// A copy of the site (staging, dev, local) never uploads to the live
		// bucket. OMNIOFFLOADER_SAFE_MODE false in wp-config.php overrides it.
		if ( ! $is_dry ) {
			FolderClaim::refresh();
		}

		if ( ! $is_dry && SafeMode::is_active() ) {
			// The constant only overrides copy detection, not a folder conflict.
			WP_CLI::error( SafeMode::message() . ( 'folder' === SafeMode::reason() ? '' : ' (Or define OMNIOFFLOADER_SAFE_MODE as false in wp-config.php.)' ) );
		}

		if ( ! $is_dry ) {
			$this->refuse_if_busy( $plugin, $assoc_args );
		}

		$ids = $this->resolve_ids( $assoc_args );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to offload.' );

			return;
		}

		if ( $is_dry ) {
			WP_CLI::log( sprintf( 'Would offload %d attachment(s):', count( $ids ) ) );
			// One posts query per 200 titles instead of one per attachment.
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				_prime_post_caches( $chunk, false, false );

				foreach ( $chunk as $id ) {
					WP_CLI::log( sprintf( '  %d  %s', $id, get_the_title( $id ) ) );
					clean_post_cache( $id );
				}
			}

			return;
		}

		// One stats flush at the end instead of ~8 queries per attachment.
		// Hold the queue's process lock and the CLI claim for the duration, so
		// the loopback worker, cron healthcheck, admin poll and admin-started
		// jobs of every type all stand down.
		$this->begin_job( $plugin, $queue, 'offload', count( $ids ) );

		Helpers::defer_media_stats_flush( true );

		$uploader = new AttachmentUploader( $provider );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Offloading', count( $ids ) );

		$done      = 0;
		$failed    = 0;
		$skipped   = 0;
		$streak    = 0;
		$fail_run  = 0;
		$processed = 0;
		$aborted   = false;
		$last_fail = 0;
		$reason    = '';
		$capped    = false;
		$stopped   = false;

		if ( Helpers::transfer_concurrency_override() > 0 ) {
			WP_CLI::log( sprintf( 'Uploading up to %d files at once.', AttachmentUploader::files_at_once() ) );
		}

		// A few attachments share one transfer pool (see upload_many()).
		foreach ( array_chunk( $ids, AttachmentUploader::batch_size() ) as $chunk ) {
			$this->begin_items( $chunk );
			$results = $uploader->upload_many( $chunk );
			$this->end_items();

			foreach ( $chunk as $id ) {
				if ( array_key_exists( $id, $results ) && null === $results[ $id ] ) {
					// Excluded by omnioffloader_should_offload_attachment:
					// neither a success nor a failure.
					++$skipped;
				} elseif ( ! empty( $results[ $id ] ) ) {
					++$done;
					$streak   = 0;
					$fail_run = 0;
				} else {
					++$failed;
					++$streak;
					++$fail_run;
					$last_fail = $id;
				}

				$progress->tick();
				clean_post_cache( $id );
				++$processed;

				$this->keep_job_alive( $processed, $failed );
			}

			// Between batches: every upload of this one has finished.
			if ( $this->stop_requested() ) {
				$stopped = true;
				break;
			}

			// Bad credentials or an unreachable bucket fail identically for
			// every attachment; stop rather than grind through the library.
			// Checked per batch, so the whole batch is counted first. When the
			// bucket still answers, the attachments are at fault (files
			// missing on disk): keep going, up to a hard cap.
			if ( $streak >= self::FAILURE_LIMIT ) {
				$check = $fail_run < self::FAILURE_LIMIT * self::UNEXPLAINED_FAILURE_FACTOR ? $provider->check_connection() : null;

				if ( true === $check ) {
					$streak = 0;
					continue;
				}

				$reason = is_wp_error( $check ) ? $check->get_error_message() : '';
				// The connection was not checked: too many failures with the
				// provider answering. The attachments themselves are failing.
				$capped  = null === $check && $fail_run >= self::FAILURE_LIMIT * self::UNEXPLAINED_FAILURE_FACTOR;
				$aborted = true;
				break;
			}
		}

		$progress->finish();

		Helpers::defer_media_stats_flush( false );
		$this->end_job();

		if ( $aborted ) {
			WP_CLI::error(
				sprintf(
					'Stopped after %d failures in a row (%d offloaded first). %sLast error: %s',
					$fail_run,
					$done,
					$capped ? 'The storage provider answers, so the attachments themselves are failing. ' : ( '' !== $reason ? $reason . '. ' : '' ),
					$this->last_error( $last_fail )
				)
			);
		}

		$skipped_note = $skipped > 0 ? sprintf( ', %d skipped by the omnioffloader_should_offload_attachment filter', $skipped ) : '';

		if ( $stopped ) {
			$this->report_stopped( sprintf( '%d offloaded, %d failed%s', $done, $failed, $skipped_note ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d offloaded, %d failed%s (see omnioffloader_error_log meta).', $done, $failed, $skipped_note ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) offloaded%s.', $done, $skipped_note ) );
	}

	/**
	 * The most recent error recorded against an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function last_error( $attachment_id ) {
		$log = get_post_meta( $attachment_id, 'omnioffloader_error_log', true );

		if ( ! is_array( $log ) || empty( $log ) ) {
			return 'unknown';
		}

		$last = end( $log );

		return is_array( $last ) && isset( $last['message'] ) ? (string) $last['message'] : 'unknown';
	}

	/**
	 * Resolve the target attachment IDs from CLI args.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return int[]
	 */
	private function resolve_ids( array $assoc_args ) {
		$limit     = $this->parse_limit( $assoc_args );
		$requested = $this->parse_ids( $assoc_args );

		if ( null !== $requested ) {
			_prime_post_caches( $requested, false, true );

			$ids = array_values(
				array_filter(
					$requested,
					static function ( $id ) {
						return 'attachment' === get_post_type( $id ) && ! self::is_offloaded( $id );
					}
				)
			);

			$this->warn_skipped_ids( $requested, $ids, 'not attachments, or already offloaded' );

			return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --all or --ids=<ids>.' );
		}

		global $wpdb;

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'omnioffloader_status' AND s.meta_value = 'failed'
			 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND m.post_id IS NULL
			 ORDER BY s.post_id IS NOT NULL, p.ID ASC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- CLI batch IDs query; only the integer limit is interpolated.
			$ids = $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT %d', $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- CLI batch IDs query, no user input.
			$ids = $wpdb->get_col( $sql );
		}

		return array_map( 'intval', $ids );
	}
}
