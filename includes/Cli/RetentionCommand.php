<?php
/**
 * WP-CLI: apply a retention policy to already-offloaded media.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Services\RetentionApplier;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brings offloaded attachments in line with a retention policy chosen after
 * they were offloaded.
 *
 * @since 0.1.3
 */
class RetentionCommand {

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
	 * Attachments primed per chunk in dry runs and counts.
	 */
	private const PRIME_CHUNK = 200;

	/**
	 * Policy labels for output.
	 *
	 * @var array<int, string>
	 */
	private const POLICY_LABELS = array(
		0 => 'Retain Local Files',
		1 => 'Smart Local Cleanup',
		2 => 'Full Cloud Migration',
	);

	/**
	 * Apply a retention policy to media that is already offloaded.
	 *
	 * Changing the retention setting only affects new uploads. This command
	 * updates what was offloaded before: a stricter policy deletes local
	 * files (each one is verified in cloud storage first, and uploaded if it
	 * is missing there); a looser policy downloads local copies again. The
	 * attachments stay offloaded either way.
	 *
	 * A stricter policy is refused while "Serve Media from Cloud" is off,
	 * since the site still loads the files it would delete.
	 *
	 * ## OPTIONS
	 *
	 * [--policy=<policy>]
	 * : Target policy. Defaults to the policy saved in Settings.
	 * ---
	 * options:
	 *   - 0
	 *   - 1
	 *   - 2
	 * ---
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs. Defaults to every offloaded attachment
	 * that follows a different policy.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments.
	 *
	 * [--dry-run]
	 * : List what would change without deleting or downloading anything.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt before local files are deleted.
	 *
	 * [--force]
	 * : Run even when a bulk job started from the admin screen is active.
	 *
	 * [--format=<format>]
	 * : Output format for the --dry-run table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader retention --dry-run
	 *     wp omnioffloader retention
	 *     wp omnioffloader retention --policy=1 --limit=500
	 *     wp omnioffloader retention --policy=2 --yes
	 *     wp omnioffloader retention --policy=0
	 *     nohup wp omnioffloader retention --yes > ~/retention.log 2>&1 &
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$plugin = Plugin::get_instance();

		if ( null === $plugin->get_provider() ) {
			WP_CLI::error( self::engine_off_reason() );
		}

		$settings = Plugin::get_settings();
		$saved    = Plugin::sanitize_retention_policy( $settings['retention_policy'] );
		$policy   = isset( $assoc_args['policy'] ) ? Plugin::sanitize_retention_policy( $assoc_args['policy'] ) : $saved;
		$is_dry   = isset( $assoc_args['dry-run'] );
		$queue    = $plugin->get_bulk_retention();

		// Serving from the server needs the files there: deleting them would
		// break every page that loads them until the attachment is marked.
		if ( ! $is_dry && $policy > 0 && empty( $settings['serve_from_cloud'] ) ) {
			WP_CLI::error( 'Serve Media from Cloud is off, so your site loads media from this server. Choose a cleanup retention policy in Settings (it turns serving on), or turn serving on first, then run this again.' );
		}

		// With a cleanup policy this command deletes files an admin-started
		// offload or restore may be reading at the same moment.
		if ( ! $is_dry ) {
			$this->refuse_if_busy( $plugin, $assoc_args );
		}

		if ( $policy !== $saved ) {
			WP_CLI::warning(
				sprintf(
					'Settings use "%s"; new uploads will keep following it. Update the setting too if this change should be permanent.',
					self::POLICY_LABELS[ $saved ]
				)
			);
		}

		$ids = $this->resolve_ids( $assoc_args, $policy );

		if ( empty( $ids ) ) {
			WP_CLI::success( sprintf( 'Every offloaded attachment already follows "%s".', self::POLICY_LABELS[ $policy ] ) );

			return;
		}

		if ( $is_dry ) {
			$this->dry_run( $ids, $policy, $assoc_args['format'] ?? 'table' );

			return;
		}

		$to_clean = $this->count_to_clean( $ids, $policy );

		if ( $to_clean > 0 ) {
			$this->require_yes_when_detached( $assoc_args );
			WP_CLI::confirm(
				sprintf(
					'Apply "%s" to %d attachment(s)? Local files of %d attachment(s) will be deleted after each is verified in cloud storage.',
					self::POLICY_LABELS[ $policy ],
					count( $ids ),
					$to_clean
				),
				$assoc_args
			);
		}

		// Hold the retention queue's lock so its loopback worker, the cron
		// healthcheck and the admin poll all stand down for the duration.
		// Hold the queue's process lock and the CLI claim for the duration, so
		// the loopback worker, cron healthcheck, admin poll and admin-started
		// jobs of every type all stand down.
		$this->begin_job( $plugin, $queue, 'retention', count( $ids ) );

		Helpers::defer_media_stats_flush( true );

		$applier  = new RetentionApplier();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Applying retention policy', count( $ids ) );

		$done      = 0;
		$failed    = 0;
		$freed     = 0;
		$repaired  = 0;
		$processed = 0;
		$stopped   = false;
		$streak    = 0;
		$fail_run  = 0;
		$aborted   = false;
		$reason    = '';
		$capped    = false;
		$quota_hit = false;

		foreach ( $ids as $id ) {
			if ( $applier->apply( $id, $policy ) ) {
				++$done;
				$streak   = 0;
				$fail_run = 0;
			} else {
				++$failed;
				++$streak;
				++$fail_run;

				// Every later download would fail the same way.
				if ( $applier->ran_out_of_disk() ) {
					$quota_hit = true;
					break;
				}
			}

			$freed    += $applier->get_freed_bytes();
			$repaired += $applier->get_repaired();

			$progress->tick();
			clean_post_cache( $id );
			++$processed;

			$this->keep_job_alive( $processed, $failed );

			if ( $this->stop_requested() ) {
				$stopped = true;
				break;
			}

			// An unreachable bucket fails every attachment the same way; when
			// it still answers, the attachments are at fault: carry on, up to
			// a hard cap (as restore and offload do).
			if ( $streak >= self::FAILURE_LIMIT ) {
				$provider = AttachmentRestorer::provider_for( $id );
				$check    = null !== $provider && $fail_run < self::FAILURE_LIMIT * self::UNEXPLAINED_FAILURE_FACTOR ? $provider->check_connection() : null;

				if ( true === $check ) {
					$streak = 0;
					continue;
				}

				$reason = is_wp_error( $check ) ? $check->get_error_message() . '. ' : '';
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

		if ( $repaired > 0 ) {
			WP_CLI::log( sprintf( '%d file(s) were missing from cloud storage and were uploaded before cleanup.', $repaired ) );
		}

		if ( $freed > 0 ) {
			WP_CLI::log( sprintf( 'Freed %s of local disk space.', size_format( $freed, 1 ) ) );
		}

		if ( $quota_hit ) {
			WP_CLI::error( sprintf( 'Stopped: the server ran out of disk space, or the hosting plan\'s storage quota is used up (%d updated first). Free up space, then run the command again.', $done ) );
		}

		if ( $aborted ) {
			WP_CLI::error(
				$capped
					? sprintf( 'Stopped after %d failures in a row (%d updated first). The storage provider answers, so the attachments themselves are failing; see omnioffloader_error_log meta.', $fail_run, $done )
					: sprintf( 'Stopped after %d failures in a row (%d updated first). %sCheck the connection to your storage provider (see omnioffloader_error_log meta).', $fail_run, $done, $reason )
			);
		}

		if ( $stopped ) {
			$this->report_stopped( sprintf( '%d updated, %d failed', $done, $failed ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d updated, %d failed (see omnioffloader_error_log meta). Failed items keep their local files and are retried on the next run.', $done, $failed ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) now follow "%s".', $done, self::POLICY_LABELS[ $policy ] ) );
	}

	/**
	 * Print what a run would do, without touching files or the network.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param int    $policy Target policy.
	 * @param string $format Output format (table, json, csv).
	 * @return void
	 */
	private function dry_run( array $ids, $policy, $format = 'table' ) {
		$rows        = array();
		$total_bytes = 0;

		// Prime posts + meta a chunk at a time and drop them again, so a large
		// library is neither one query per attachment nor all held in memory.
		foreach ( array_chunk( $ids, self::PRIME_CHUNK ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );

			foreach ( $chunk as $id ) {
				$rows[] = $this->dry_run_row( $id, $policy, $total_bytes );
				clean_post_cache( $id );
			}
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'title', 'from', 'to', 'action', 'detail' ) );

		// Machine-readable output stays parseable: no trailing summary line.
		if ( 'table' !== $format ) {
			return;
		}

		WP_CLI::log(
			sprintf(
				'Would apply "%s" to %d attachment(s)%s.',
				self::POLICY_LABELS[ $policy ],
				count( $ids ),
				$total_bytes > 0 ? sprintf( ', freeing about %s', size_format( $total_bytes, 1 ) ) : ''
			)
		);
	}

	/**
	 * How many of $ids would lose local files under $policy.
	 *
	 * @param int[] $ids    Attachment IDs.
	 * @param int   $policy Target policy.
	 * @return int
	 */
	private function count_to_clean( array $ids, $policy ) {
		$count = 0;

		foreach ( array_chunk( $ids, self::PRIME_CHUNK ) as $chunk ) {
			update_meta_cache( 'post', $chunk );

			foreach ( $chunk as $id ) {
				if ( RetentionApplier::stored_policy( $id ) < $policy ) {
					++$count;
				}

				wp_cache_delete( $id, 'post_meta' );
			}
		}

		return $count;
	}

	/**
	 * One dry-run table row. Caches for $id must already be primed.
	 *
	 * @param int $id          Attachment ID.
	 * @param int $policy      Target policy.
	 * @param int $total_bytes Running total of bytes to free, updated in place.
	 * @return array<string, int|string>
	 */
	private function dry_run_row( $id, $policy, &$total_bytes ) {
		$stored = RetentionApplier::stored_policy( $id );

		if ( $stored < $policy ) {
			$files  = RetentionApplier::files_to_delete( $id, $policy );
			$bytes  = 0;
			$action = 'delete local';

			foreach ( $files as $entry ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is treated as 0.
				$bytes += (int) @filesize( $entry['file'] );
			}

			$total_bytes += $bytes;
			$detail       = sprintf( '%d file(s), %s', count( $files ), size_format( $bytes, 1 ) );
		} else {
			$action = 'download';
			$detail = 0 === $policy ? 'original + sizes' : 'original';
		}

		return array(
			'ID'     => $id,
			'title'  => get_the_title( $id ),
			'from'   => $stored,
			'to'     => $policy,
			'action' => $action,
			'detail' => $detail,
		);
	}


	/**
	 * Resolve the target attachment IDs from CLI args.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @param int                   $policy     Target policy.
	 * @return int[]
	 */
	private function resolve_ids( array $assoc_args, $policy ) {
		$limit     = $this->parse_limit( $assoc_args );
		$requested = $this->parse_ids( $assoc_args );

		if ( null === $requested ) {
			return RetentionApplier::eligible_ids( $policy, $limit );
		}

		_prime_post_caches( $requested, false, true );

		$ids = array_values(
			array_filter(
				$requested,
				static function ( $id ) use ( $policy ) {
					return 'attachment' === get_post_type( $id )
						&& self::is_offloaded( $id )
						&& RetentionApplier::stored_policy( $id ) !== $policy;
				}
			)
		);

		$this->warn_skipped_ids( $requested, $ids, 'not offloaded, or already at this policy' );

		return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
	}
}
