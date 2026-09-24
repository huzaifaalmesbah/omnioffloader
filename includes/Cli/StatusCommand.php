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
use OmniOffloader\Services\BulkRetentionProcessor;
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
	 * Exits with status 1 when the offload engine is off or the bucket
	 * cannot be reached, so it can be used in monitoring.
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

		// Non-zero exit when the engine is off or the bucket is unreachable,
		// so monitoring scripts can rely on the exit code.
		$healthy = true;

		if ( null === $provider ) {
			$healthy = false;
			WP_CLI::warning(
				defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) && OMNIOFFLOADER_DISABLE_ENGINE
					? 'Offload engine is OFF: disabled by OMNIOFFLOADER_DISABLE_ENGINE in wp-config.php.'
					: 'No cloud provider configured (or AWS SDK missing) — offload engine is OFF.'
			);
		} else {
			WP_CLI::log( 'Provider:   ' . $provider->get_name() . ' (' . $provider->get_key() . ')' );
			WP_CLI::log( 'Bucket:     ' . (string) $provider->get_bucket() );
			WP_CLI::log( 'CDN domain: ' . $provider->get_domain() );

			$connection = $provider->check_connection();
			if ( true === $connection ) {
				WP_CLI::log( 'Connection: OK' );
			} else {
				$healthy = false;
				WP_CLI::warning( 'Connection: ' . $connection->get_error_message() );
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

		$retention = BulkRetentionProcessor::get_state();
		if ( 'idle' !== $retention['status'] ) {
			WP_CLI::log( sprintf( 'Retention update: %s (%d/%d, %d errors)', $retention['status'], $retention['processed'], $retention['total'], $retention['errors'] ) );
		}

		if ( null !== $provider ) {
			$preflight = Helpers::get_retention_preflight( true );

			if ( $preflight['eligible'] > 0 ) {
				WP_CLI::log( '' );
				WP_CLI::log(
					sprintf(
						'%d offloaded attachment(s) follow an older retention policy. Run `wp omnioffloader retention --dry-run` to review.',
						$preflight['eligible']
					)
				);
			}
		}

		if ( ! $healthy ) {
			WP_CLI::halt( 1 );
		}
	}
}
