<?php
/**
 * WP-CLI: Bring Back — restore media from the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores offloaded attachments from the cloud back to the server.
 */
class RestoreCommand {

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
	 * Restore attachments from the cloud (Bring Back).
	 *
	 * Runs synchronously with a progress bar. Each attachment's provider is
	 * resolved from its own offload meta, so restores work even after
	 * switching the active provider. Downloads are atomic and verified;
	 * cloud objects are only deleted (opt-in) after local verification.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Restore every offloaded attachment.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to restore.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments. Useful for working through a large
	 * library in scheduled chunks.
	 *
	 * [--delete-cloud]
	 * : Also delete the cloud objects after each verified restore.
	 * Destructive — asks for confirmation unless --yes is passed.
	 *
	 * [--yes]
	 * : Answer yes to the --delete-cloud confirmation prompt.
	 *
	 * [--dry-run]
	 * : List what would be restored without downloading anything.
	 *
	 * [--force]
	 * : Run even when a bulk restore started from the admin screen is active.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader restore --all
	 *     wp omnioffloader restore --ids=12,15,42
	 *     wp omnioffloader restore --all --delete-cloud --yes
	 *     wp omnioffloader restore --all --dry-run
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$plugin = Plugin::get_instance();
		$queue  = $plugin->get_bulk_restore();
		$is_dry = isset( $assoc_args['dry-run'] );

		// Restoring the same attachment from two processes races over the same
		// destination files; the admin queue owns them until it is done.
		if ( ! $is_dry ) {
			$this->refuse_if_busy( $plugin, $assoc_args );
		}

		$ids = $this->resolve_ids( $assoc_args );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to restore.' );

			return;
		}

		$delete_cloud = isset( $assoc_args['delete-cloud'] );

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::log(
				sprintf(
					'Would restore %d attachment(s)%s:',
					count( $ids ),
					$delete_cloud ? ' and delete their cloud objects' : ''
				)
			);
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

		if ( $delete_cloud ) {
			WP_CLI::confirm(
				sprintf( 'Delete the cloud objects of %d attachment(s) after restoring them?', count( $ids ) ),
				$assoc_args
			);
		}

		// One stats flush at the end instead of ~8 queries per attachment.
		// Hold the queue's process lock and the CLI claim for the duration, so
		// the loopback worker, cron healthcheck, admin poll and admin-started
		// jobs of every type all stand down.
		$this->begin_job( $plugin, $queue, 'restore' );

		Helpers::defer_media_stats_flush( true );

		$restorer = new AttachmentRestorer();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Restoring', count( $ids ) );

		$done      = 0;
		$failed    = 0;
		$streak    = 0;
		$fail_run  = 0;
		$processed = 0;
		$aborted   = false;
		$reason    = '';
		$no_room   = 0;
		$quota_hit = false;

		foreach ( $ids as $id ) {
			// Every later download would fail the same way; stop with the
			// real reason. The attachment stays offloaded for the next run.
			$no_room = self::is_offloaded( $id ) ? AttachmentRestorer::disk_shortfall( $id ) : 0;

			if ( $no_room > 0 ) {
				break;
			}

			// Brought back some other way since the run started: done.
			$restored = ! self::is_offloaded( $id ) || $restorer->restore( $id, $delete_cloud );

			// A hosting quota PHP cannot see: the refused write is the signal.
			if ( ! $restored && $restorer->ran_out_of_disk() ) {
				$quota_hit = true;
				break;
			}

			if ( $restored ) {
				++$done;
				$streak   = 0;
				$fail_run = 0;
			} else {
				++$failed;
				++$streak;
				++$fail_run;
			}
			$progress->tick();
			clean_post_cache( $id );
			++$processed;

			$this->keep_job_alive( $processed );

			// Bad credentials or an unreachable bucket fail identically for
			// every attachment; stop rather than grind through the library.
			// When the bucket still answers, the attachments are at fault
			// (objects missing): keep going, up to a hard cap.
			if ( $streak >= self::FAILURE_LIMIT ) {
				$provider = AttachmentRestorer::provider_for( $id );
				$check    = null !== $provider && $fail_run < self::FAILURE_LIMIT * self::UNEXPLAINED_FAILURE_FACTOR ? $provider->check_connection() : null;

				if ( true === $check ) {
					$streak = 0;
					continue;
				}

				$reason  = is_wp_error( $check ) ? $check->get_error_message() . '. ' : '';
				$aborted = true;
				break;
			}
		}

		$progress->finish();

		Helpers::defer_media_stats_flush( false );
		$this->end_job();

		if ( $quota_hit ) {
			WP_CLI::error( sprintf( 'Stopped: the server ran out of disk space, or the hosting plan\'s storage quota is used up (%d restored first). Free up space, then run the command again.', $done ) );
		}

		if ( $no_room > 0 ) {
			WP_CLI::error( sprintf( 'Stopped: the server is running out of disk space (%s more is needed for the next attachment, %d restored first). Free up space, then run the command again.', size_format( $no_room ), $done ) );
		}

		if ( $aborted ) {
			WP_CLI::error( sprintf( 'Stopped after %d failures in a row (%d restored first). %sCheck the connection to your storage provider (see omnioffloader_error_log meta).', $fail_run, $done, $reason ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d restored, %d failed (see omnioffloader_error_log meta).', $done, $failed ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) restored.', $done ) );
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
						return 'attachment' === get_post_type( $id ) && self::is_offloaded( $id );
					}
				)
			);

			$this->warn_skipped_ids( $requested, $ids, 'not attachments, or not offloaded' );

			return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --all or --ids=<ids>.' );
		}

		global $wpdb;

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'omnioffloader_status' AND s.meta_value = 'failed'
			 WHERE p.post_type = 'attachment'
			 ORDER BY s.post_id IS NOT NULL, p.ID ASC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- safely prepared with absint.
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- CLI batch IDs query.
		$ids = $wpdb->get_col( $sql );

		return array_map( 'intval', $ids );
	}
}
