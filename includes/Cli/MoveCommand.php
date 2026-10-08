<?php
/**
 * WP-CLI: move offloaded media into the current bucket folder.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Services\AttachmentMover;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves media offloaded under an earlier Path Prefix into the current folder.
 */
class MoveCommand {

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
	 * Move offloaded media into the current bucket folder.
	 *
	 * Media keeps the folder it was offloaded to, so after a Path Prefix
	 * change it is split across folders. Within the same bucket, the storage
	 * provider copies each attachment's files into the current folder itself
	 * (nothing is downloaded), the attachment switches to the new folder, and
	 * the old copies are deleted. A file that cannot be copied that way (over
	 * 5 GB, or in another bucket) is moved through the server instead: Bring
	 * Back, then a fresh offload.
	 *
	 * Every step is verified: the old copies are deleted only after the new
	 * ones are confirmed, and an attachment whose new upload fails stays on
	 * the server, served from there, until the next offload.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Move every offloaded attachment that is outside the current folder.
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment IDs to move.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments.
	 *
	 * [--keep-old]
	 * : Keep the old copies in the old folder.
	 *
	 * [--yes]
	 * : Answer yes to the confirmation prompt.
	 *
	 * [--dry-run]
	 * : List what would be moved without changing anything.
	 *
	 * [--force]
	 * : Run even when a bulk job started from the admin screen is active.
	 *
	 * [--concurrency=<number>]
	 * : Files copied, downloaded or uploaded at the same time, 1 to 64,
	 * instead of the Transfer Speed setting (at most 10). For a VPS or
	 * dedicated server.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader move --all --dry-run
	 *     wp omnioffloader move --all
	 *     wp omnioffloader move --ids=12,15,42 --keep-old
	 *     nohup wp omnioffloader move --all --yes --concurrency=32 > ~/move.log 2>&1 &
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$plugin   = Plugin::get_instance();
		$provider = $plugin->get_provider();
		$is_dry   = isset( $assoc_args['dry-run'] );

		if ( null === $provider ) {
			WP_CLI::error( 'No cloud provider is configured, so there is nowhere to move media to.' );
		}

		// Covers both halves of a move: the download and the new upload.
		$this->apply_concurrency( $assoc_args );

		if ( ! $is_dry ) {
			FolderClaim::refresh();
		}

		if ( ! $is_dry && SafeMode::is_active() ) {
			WP_CLI::error( SafeMode::message() );
		}

		if ( ! $is_dry ) {
			$this->refuse_if_busy( $plugin, $assoc_args );
		}

		$prefix = FolderClaim::prefix();
		$folder = '' !== $prefix ? $prefix : 'the bucket root';
		$ids    = $this->resolve_ids( $assoc_args, $prefix );

		if ( empty( $ids ) ) {
			WP_CLI::success( sprintf( 'Nothing to move: every offloaded attachment is already in %s.', $folder ) );

			return;
		}

		$keep_old = isset( $assoc_args['keep-old'] );

		if ( $is_dry ) {
			WP_CLI::log( sprintf( 'Would move %d attachment(s) into %s%s:', count( $ids ), $folder, $keep_old ? ', keeping the old copies' : '' ) );

			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				_prime_post_caches( $chunk, false, true );

				foreach ( $chunk as $id ) {
					WP_CLI::log( sprintf( '  %d  %s  (now in %s)', $id, get_the_title( $id ), self::get_offload_path( $id ) ) );
					clean_post_cache( $id );
				}
			}

			return;
		}

		if ( ! $keep_old ) {
			$this->require_yes_when_detached( $assoc_args );
			WP_CLI::confirm(
				sprintf( 'Move %d attachment(s) into %s and delete their old copies once each move is verified?', count( $ids ), $folder ),
				$assoc_args
			);
		}

		// Holds the move queue's lock and the CLI claim, so no admin job of
		// any type touches the same files meanwhile.
		$this->begin_job( $plugin, $plugin->get_bulk_move(), 'move', count( $ids ) );

		Helpers::defer_media_stats_flush( true );

		$mover    = new AttachmentMover( $provider );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Moving', count( $ids ) );

		if ( Helpers::transfer_concurrency_override() > 0 ) {
			WP_CLI::log( sprintf( 'Moving up to %d files at once.', Helpers::transfer_concurrency_override() ) );
		}

		$done      = 0;
		$failed    = 0;
		$streak    = 0;
		$fail_run  = 0;
		$processed = 0;
		$aborted   = false;
		$reason    = '';
		$capped    = false;
		$no_room   = 0;
		$quota_hit = false;
		$stopped   = false;

		foreach ( $ids as $id ) {
			// Moved through the server, the attachment comes back for a
			// moment; a copy inside the bucket downloads nothing.
			$no_room = $mover->can_copy_in_bucket( $id ) ? 0 : AttachmentRestorer::disk_shortfall( $id );

			if ( $no_room > 0 ) {
				break;
			}

			$this->begin_items( array( $id ) );
			$moved = $mover->move( $id, ! $keep_old );
			$this->end_items();

			if ( ! $moved && $mover->ran_out_of_disk() ) {
				$quota_hit = true;
				break;
			}

			if ( $moved ) {
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

			$this->keep_job_alive( $processed, $failed );

			if ( $this->stop_requested() ) {
				$stopped = true;
				break;
			}

			// An unreachable bucket fails every attachment the same way.
			if ( $streak >= self::FAILURE_LIMIT ) {
				$check = $fail_run < self::FAILURE_LIMIT * self::UNEXPLAINED_FAILURE_FACTOR ? $provider->check_connection() : null;

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

		if ( $quota_hit ) {
			WP_CLI::error( sprintf( 'Stopped: the server ran out of disk space, or the hosting plan\'s storage quota is used up (%d moved first). Free up space, then run the command again.', $done ) );
		}

		if ( $no_room > 0 ) {
			WP_CLI::error( sprintf( 'Stopped: the server is running out of disk space (%s more is needed for the next attachment, %d moved first). Free up space, then run the command again.', size_format( $no_room ), $done ) );
		}

		if ( $aborted ) {
			WP_CLI::error(
				$capped
					? sprintf( 'Stopped after %d failures in a row (%d moved first). The storage provider answers, so the attachments themselves are failing; see omnioffloader_error_log meta.', $fail_run, $done )
					: sprintf( 'Stopped after %d failures in a row (%d moved first). %sCheck the connection to your storage provider (see omnioffloader_error_log meta).', $fail_run, $done, $reason )
			);
		}

		if ( $stopped ) {
			$this->report_stopped( sprintf( '%d moved, %d failed', $done, $failed ) );
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d moved, %d failed (see omnioffloader_error_log meta). A failed attachment stays offloaded where it was, or on the server if only its new upload failed; run `wp omnioffloader offload --all` for those.', $done, $failed ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) moved into %s.', $done, $folder ) );
	}

	/**
	 * Resolve the attachments to move from CLI args.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @param string                $prefix     Current folder.
	 * @return int[]
	 */
	private function resolve_ids( array $assoc_args, $prefix ) {
		$limit     = $this->parse_limit( $assoc_args );
		$requested = $this->parse_ids( $assoc_args );

		if ( null !== $requested ) {
			_prime_post_caches( $requested, false, true );

			$ids = array_values(
				array_filter(
					$requested,
					static function ( $id ) use ( $prefix ) {
						return 'attachment' === get_post_type( $id ) && Helpers::is_offloaded_elsewhere( $id, $prefix );
					}
				)
			);

			$this->warn_skipped_ids( $requested, $ids, 'not offloaded, or already in the current folder' );

			return $limit > 0 ? array_slice( $ids, 0, $limit ) : $ids;
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --all or --ids=<ids>.' );
		}

		return Helpers::offloaded_elsewhere_ids( $prefix, $limit );
	}
}
