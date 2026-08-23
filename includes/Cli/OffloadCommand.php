<?php
/**
 * WP-CLI: offload media to the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

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
	 * [--dry-run]
	 * : List what would be offloaded without uploading anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader offload --all
	 *     wp omnioffloader offload --ids=12,15,42
	 *     wp omnioffloader offload --all --dry-run
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$provider = Plugin::get_instance()->get_provider();

		if ( null === $provider ) {
			WP_CLI::error( 'No cloud provider is configured (or the AWS SDK is missing).' );
		}

		$ids = $this->resolve_ids( $assoc_args );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to offload.' );

			return;
		}

		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::log( sprintf( 'Would offload %d attachment(s):', count( $ids ) ) );
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '  %d  %s', $id, get_the_title( $id ) ) );
			}

			return;
		}

		$uploader = new AttachmentUploader( $provider );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Offloading', count( $ids ) );

		$done   = 0;
		$failed = 0;

		foreach ( $ids as $id ) {
			if ( $uploader->upload( $id ) ) {
				++$done;
			} else {
				++$failed;
			}
			$progress->tick();
		}

		$progress->finish();

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d offloaded, %d failed (see omnioffloader_error_log meta).', $done, $failed ) );
			WP_CLI::halt( 1 );
		}

		WP_CLI::success( sprintf( '%d attachment(s) offloaded.', $done ) );
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
						return 'attachment' === get_post_type( $id ) && ! self::is_offloaded( $id );
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
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
			 WHERE p.post_type = 'attachment' AND m.post_id IS NULL
			 ORDER BY p.ID ASC"
		);

		return array_map( 'intval', $ids );
	}
}
