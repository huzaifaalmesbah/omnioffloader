<?php
/**
 * WP-CLI: list, retry and clear failed media.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Traits\AttachmentHelper;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists attachments with an error log, retries failed offloads, and clears
 * error logs once a problem is fixed.
 *
 * @since 0.1.6
 */
class ErrorsCommand {

	use AttachmentHelper;

	/**
	 * List media with errors, retry failed offloads, or clear error logs.
	 *
	 * Without --retry or --clear, lists them: the attachment, whether it is
	 * offloaded, when the last error happened and what it said. An item that
	 * is not offloaded is a failed offload; an offloaded one logged its error
	 * during a Bring Back, retention update or move.
	 *
	 * ## OPTIONS
	 *
	 * [--state=<state>]
	 * : Which items. `local`: failed offloads (what --retry offloads).
	 * `offloaded`: in the cloud, with errors from another job.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - local
	 *   - offloaded
	 * ---
	 *
	 * [--retry]
	 * : Offload the failed offloads again (runs `wp omnioffloader offload`
	 * with their IDs). Accepts offload's --dry-run, --force and --concurrency.
	 *
	 * [--clear]
	 * : Delete the error logs of the listed items, e.g. after fixing the
	 * cause. Changes no files.
	 *
	 * [--limit=<number>]
	 * : At most this many items, newest first.
	 *
	 * [--format=<format>]
	 * : Output format for the list.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 *   - count
	 * ---
	 *
	 * [--dry-run]
	 * : With --retry or --clear, show what would happen without changing
	 * anything.
	 *
	 * [--force]
	 * : With --retry, run even when a bulk job started from the admin screen
	 * is active.
	 *
	 * [--concurrency=<number>]
	 * : With --retry, files uploaded at the same time (1 to 64).
	 *
	 * [--yes]
	 * : With --clear, skip the confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader errors
	 *     wp omnioffloader errors --state=local --format=json
	 *     wp omnioffloader errors --retry
	 *     wp omnioffloader errors --retry --dry-run
	 *     wp omnioffloader errors --state=offloaded --clear --yes
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		if ( isset( $assoc_args['retry'] ) && isset( $assoc_args['clear'] ) ) {
			WP_CLI::error( 'Pass either --retry or --clear, not both.' );
		}

		$state = (string) ( $assoc_args['state'] ?? 'all' );
		$limit = $this->parse_limit( $assoc_args );

		if ( isset( $assoc_args['retry'] ) ) {
			$this->retry( $state, $limit, $assoc_args );
			return;
		}

		$ids = Helpers::failed_ids( $state, $limit );

		if ( isset( $assoc_args['clear'] ) ) {
			$this->clear( $ids, $assoc_args );
			return;
		}

		$this->print_list( $ids, (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * Print the list in the chosen format.
	 *
	 * @param int[]  $ids    Attachment IDs.
	 * @param string $format Output format.
	 * @return void
	 */
	private function print_list( array $ids, $format ) {
		if ( 'count' === $format ) {
			WP_CLI::line( (string) count( $ids ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		if ( empty( $ids ) ) {
			if ( 'table' === $format ) {
				WP_CLI::success( 'No media with errors.' );
			} else {
				\WP_CLI\Utils\format_items( $format, array(), array( 'id', 'title', 'offloaded', 'time', 'error', 'errors' ) );
			}
			return;
		}

		update_meta_cache( 'post', $ids );
		_prime_post_caches( $ids, false, false );

		$items = array();

		foreach ( $ids as $id ) {
			$log  = self::error_log_of( $id );
			$last = end( $log );
			$time = is_array( $last ) && ! empty( $last['time'] ) ? (int) $last['time'] : 0;

			$items[] = array(
				'id'        => $id,
				'title'     => get_the_title( $id ),
				'offloaded' => self::is_offloaded( $id ) ? 'yes' : 'no',
				'time'      => $time > 0 ? wp_date( 'Y-m-d H:i', $time ) : '',
				'error'     => is_array( $last ) ? (string) ( $last['message'] ?? '' ) : '',
				'errors'    => count( $log ),
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'id', 'title', 'offloaded', 'time', 'error', 'errors' ) );

		if ( 'table' === $format ) {
			$local = count( array_filter( $items, static fn( $item ) => 'no' === $item['offloaded'] ) );

			if ( $local > 0 ) {
				WP_CLI::log( sprintf( '%d failed offload(s). Retry them with: wp omnioffloader errors --retry', $local ) );
			}
		}
	}

	/**
	 * Offload the failed offloads again through the offload command, so they
	 * get its locks, Safe mode checks, progress and failure handling.
	 *
	 * @param string                $state      Requested --state.
	 * @param int                   $limit      Most items, 0 for all.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function retry( $state, $limit, array $assoc_args ) {
		if ( 'offloaded' === $state ) {
			WP_CLI::error( '--retry offloads failed offloads; offloaded items are already in the cloud. Use --state=local or leave --state out.' );
		}

		$ids = Helpers::failed_ids( 'local', $limit );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No failed offloads to retry.' );
			return;
		}

		WP_CLI::log( sprintf( 'Retrying %d failed offload(s).', count( $ids ) ) );

		$offload_args = array( 'ids' => implode( ',', $ids ) );

		foreach ( array( 'dry-run', 'force', 'concurrency' ) as $flag ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$offload_args[ $flag ] = $assoc_args[ $flag ];
			}
		}

		WP_CLI::run_command( array( 'omnioffloader', 'offload' ), $offload_args );
	}

	/**
	 * Delete the error logs of the given attachments.
	 *
	 * @param int[]                 $ids        Attachment IDs.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function clear( array $ids, array $assoc_args ) {
		if ( empty( $ids ) ) {
			WP_CLI::success( 'No media with errors.' );
			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::success( sprintf( 'Dry run: would clear the error log of %d attachment(s): %s', count( $ids ), implode( ',', $ids ) ) );
			return;
		}

		// The Media Library shows "Offload failed" while the log exists; a
		// running job may be writing to it.
		$active = Plugin::get_instance()->get_active_bulk_job();

		if ( '' !== $active ) {
			WP_CLI::error( sprintf( 'A %s job is running. Clear the error logs once it ends.', $active ) );
		}

		WP_CLI::confirm( sprintf( 'Clear the error log of %d attachment(s)?', count( $ids ) ), $assoc_args );

		foreach ( $ids as $id ) {
			delete_post_meta( $id, 'omnioffloader_error_log' );

			if ( 'failed' === get_post_meta( $id, 'omnioffloader_status', true ) ) {
				delete_post_meta( $id, 'omnioffloader_status' );
			}
		}

		Helpers::flush_media_stats();

		WP_CLI::success( sprintf( 'Cleared the error log of %d attachment(s).', count( $ids ) ) );
	}

	/**
	 * Parse --limit; a typo must never mean "all".
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return int 0 when not given.
	 */
	private function parse_limit( array $assoc_args ) {
		if ( ! isset( $assoc_args['limit'] ) ) {
			return 0;
		}

		$raw = trim( (string) $assoc_args['limit'] );

		if ( '' === $raw || ! ctype_digit( $raw ) || (int) $raw < 1 ) {
			WP_CLI::error( sprintf( '--limit must be a positive whole number, got "%s".', $raw ) );
		}

		return (int) $raw;
	}
}
