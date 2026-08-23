<?php
/**
 * WP-CLI: Bring Back — restore media from the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

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
	 * [--delete-cloud]
	 * : Also delete the cloud objects after each verified restore.
	 *   Destructive — asks for confirmation unless --yes is passed.
	 *
	 * [--yes]
	 * : Answer yes to the --delete-cloud confirmation prompt.
	 *
	 * [--dry-run]
	 * : List what would be restored without downloading anything.
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
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '  %d  %s', $id, get_the_title( $id ) ) );
			}

			return;
		}

		if ( $delete_cloud ) {
			WP_CLI::confirm(
				sprintf( 'Delete the cloud objects of %d attachment(s) after restoring them?', count( $ids ) ),
				$assoc_args
			);
		}

		$restorer = new AttachmentRestorer();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Restoring', count( $ids ) );

		$done   = 0;
		$failed = 0;

		foreach ( $ids as $id ) {
			if ( $restorer->restore( $id, $delete_cloud ) ) {
				++$done;
			} else {
				++$failed;
			}
			$progress->tick();
		}

		$progress->finish();

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
		if ( isset( $assoc_args['ids'] ) ) {
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) ) );

			return array_values(
				array_filter(
					$ids,
					static function ( $id ) {
						return 'attachment' === get_post_type( $id ) && self::is_offloaded( $id );
					}
				)
			);
		}

		if ( ! isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass --all or --ids=<ids>.' );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI batch IDs query.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		);

		return array_map( 'intval', $ids );
	}
}
