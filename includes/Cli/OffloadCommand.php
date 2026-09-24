<?php
/**
 * WP-CLI: offload media to the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
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
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader offload --all
	 *     wp omnioffloader offload --ids=12,15,42
	 *     wp omnioffloader offload --all --limit=500
	 *     wp omnioffloader offload --all --dry-run
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

		// The admin bulk queue and this command would otherwise offload the
		// same attachments at once: with a cleanup retention policy one side
		// deletes local files the other is still reading, which fails uploads
		// and fills the error log for no reason.
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
		$this->begin_job( $plugin, $queue, 'offload' );

		Helpers::defer_media_stats_flush( true );

		$uploader = new AttachmentUploader( $provider );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Offloading', count( $ids ) );

		$done      = 0;
		$failed    = 0;
		$streak    = 0;
		$processed = 0;
		$aborted   = false;
		$last_fail = 0;

		// A few attachments share one transfer pool (see upload_many()).
		foreach ( array_chunk( $ids, AttachmentUploader::batch_size() ) as $chunk ) {
			$results = $uploader->upload_many( $chunk );

			foreach ( $chunk as $id ) {
				if ( ! empty( $results[ $id ] ) ) {
					++$done;
					$streak = 0;
				} else {
					++$failed;
					++$streak;
					$last_fail = $id;
				}

				$progress->tick();
				clean_post_cache( $id );
				++$processed;

				$this->keep_job_alive( $processed );

				// Bad credentials or an unreachable bucket fail identically for
				// every attachment; stop rather than grind through the library.
				if ( $streak >= 10 ) {
					$aborted = true;
					break 2;
				}
			}
		}

		$progress->finish();

		Helpers::defer_media_stats_flush( false );
		$this->end_job();

		if ( $aborted ) {
			WP_CLI::error(
				sprintf(
					'Stopped after %d failures in a row (%d offloaded first). Last error: %s',
					$streak,
					$done,
					$this->last_error( $last_fail )
				)
			);
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d offloaded, %d failed (see omnioffloader_error_log meta).', $done, $failed ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) offloaded.', $done ) );
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
			 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND m.post_id IS NULL
			 ORDER BY p.ID ASC";

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
