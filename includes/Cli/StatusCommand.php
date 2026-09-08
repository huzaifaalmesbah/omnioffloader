<?php
/**
 * WP-CLI: plugin status overview.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows provider, connection and offload status.
 */
class StatusCommand {

	/**
	 * Show provider configuration, connection state and media stats.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader status
	 *
	 * @return void
	 */
	public function __invoke() {
		$plugin   = Plugin::get_instance();
		$provider = $plugin->get_provider();

		WP_CLI::log( 'OmniOffloader ' . OMNIOFFLOADER_VERSION );
		WP_CLI::log( '' );

		if ( null === $provider ) {
			WP_CLI::warning( 'No cloud provider configured (or AWS SDK missing) — offload engine is OFF.' );
		} else {
			WP_CLI::log( 'Provider:   ' . $provider->get_name() . ' (' . $provider->get_key() . ')' );
			WP_CLI::log( 'Bucket:     ' . (string) $provider->get_bucket() );
			WP_CLI::log( 'CDN domain: ' . $provider->get_domain() );

			$connection = $provider->check_connection();
			if ( true === $connection ) {
				WP_CLI::log( 'Connection: OK' );
			} else {
				WP_CLI::warning( 'Connection: ' . $connection->get_error_message() );
			}
		}

		$secondary = $plugin->get_secondary_provider();
		if ( null !== $secondary ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Secondary Backup Storage (Active Mirror):' );
			WP_CLI::log( 'Provider:   ' . $secondary->get_name() . ' (' . $secondary->get_key() . ')' );
			WP_CLI::log( 'Bucket:     ' . (string) $secondary->get_bucket() );

			$sec_connection = $secondary->check_connection();
			if ( true === $sec_connection ) {
				WP_CLI::log( 'Connection: OK' );
			} else {
				WP_CLI::warning( 'Connection: ' . $sec_connection->get_error_message() );
			}
		}

		$stats = Helpers::get_media_stats();
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Media: %d total — %d offloaded, %d local', $stats['total'], $stats['offloaded'], $stats['local'] ) );

		$bulk = BulkOffloadProcessor::get_state();
		if ( 'idle' !== $bulk['status'] ) {
			WP_CLI::log( sprintf( 'Bulk offload: %s (%d/%d, %d errors)', $bulk['status'], $bulk['processed'], $bulk['total'], $bulk['errors'] ) );
		}

		$restore = BulkRestoreProcessor::get_state();
		if ( 'idle' !== $restore['status'] ) {
			WP_CLI::log( sprintf( 'Bulk restore: %s (%d/%d, %d errors)', $restore['status'], $restore['processed'], $restore['total'], $restore['errors'] ) );
		}
	}
}
