<?php
/**
 * WP-CLI: plugin status overview.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
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

				// Read this site's folder marker now rather than report a
				// cached (or never-made) check.
				FolderClaim::refresh();
			} else {
				$healthy = false;
				WP_CLI::warning( 'Connection: ' . $connection->get_error_message() );
			}
		}

		$safe = SafeMode::status();

		if ( $safe['active'] ) {
			$why = array(
				'constant'    => 'OMNIOFFLOADER_SAFE_MODE is true in wp-config.php',
				'environment' => 'WP_ENVIRONMENT_TYPE is "' . $safe['environment'] . '"',
				'local'       => 'the site address is a local one',
				'address'     => 'the site address changed from ' . $safe['live_address'] . ' to ' . $safe['current_address'],
				'folder'      => ( '' !== $safe['folder'] ? 'the bucket folder "' . $safe['folder'] . '"' : 'the bucket root' ) . ' is used by another site (' . $safe['folder_owner'] . '); give this site its own Path Prefix',
			);
			WP_CLI::log( 'Safe mode:  ON — ' . ( $why[ $safe['reason'] ] ?? $safe['reason'] ) . '. Nothing is uploaded to or deleted from the cloud.' );
		} else {
			WP_CLI::log( 'Safe mode:  off (live site)' );
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

		$run = Plugin::get_cli_run();
		if ( null !== $run ) {
			$alive = Plugin::cli_run_alive( $run );

			WP_CLI::log(
				sprintf(
					'WP-CLI %s: %s — %d of %d done, %d failed, started %s ago (process %d%s)',
					$run['job'],
					false === $alive ? 'NOT RUNNING (ended without cleaning up; the next run clears it)' : 'running',
					(int) $run['processed'],
					(int) $run['total'],
					(int) $run['failed'],
					human_time_diff( (int) $run['started'] ),
					(int) $run['pid'],
					! empty( $run['background'] ) ? ', background' : ''
				)
			);

			if ( ! empty( $run['log'] ) ) {
				WP_CLI::log( '  Log: ' . $run['log'] );
			}

			if ( false !== $alive ) {
				WP_CLI::log( '  Stop it with: wp omnioffloader stop' );
			}
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
