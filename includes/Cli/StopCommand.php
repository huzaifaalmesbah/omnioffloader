<?php
/**
 * WP-CLI: stop the running bulk job.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Plugin;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class StopCommand
 *
 * @since 0.1.4
 */
class StopCommand {

	/**
	 * Stop the running offload, restore or retention job.
	 *
	 * Works from any terminal. A WP-CLI run — in a terminal or started with
	 * `nohup … &` — finishes the file it is transferring, then exits
	 * and releases its lock. A bulk job started from the admin screen is
	 * cancelled, as its Cancel button does.
	 *
	 * ## OPTIONS
	 *
	 * [--wait=<seconds>]
	 * : How long to wait for a WP-CLI run to finish its current file.
	 * ---
	 * default: 120
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader stop
	 *     wp omnioffloader stop --wait=0
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$wait = trim( (string) ( $assoc_args['wait'] ?? '120' ) );

		if ( '' === $wait || ! ctype_digit( $wait ) ) {
			WP_CLI::error( sprintf( '--wait must be a whole number of seconds, got "%s".', $wait ) );
		}

		$plugin  = Plugin::get_instance();
		$cli_job = get_site_transient( Plugin::cli_job_lock_key() );

		if ( is_string( $cli_job ) && '' !== $cli_job ) {
			$this->stop_cli_run( $plugin, $cli_job, (int) $wait );

			return;
		}

		$queues = array(
			'offload'   => array( $plugin->get_bulk_offload(), 'Bulk Offload' ),
			'restore'   => array( $plugin->get_bulk_restore(), 'Bring Back' ),
			'retention' => array( $plugin->get_bulk_retention(), 'retention update' ),
		);

		foreach ( $queues as $queue ) {
			if ( null !== $queue[0] && $queue[0]->is_active() ) {
				$queue[0]->cancel_now();
				WP_CLI::success( sprintf( 'Cancelled the %s started from the admin screen. Attachments already done stay done.', $queue[1] ) );

				return;
			}
		}

		// A record without a lock: the run died before it could clean up.
		Plugin::clear_cli_run();

		WP_CLI::success( 'Nothing is running.' );
	}

	/**
	 * Ask a WP-CLI run to stop and wait for it.
	 *
	 * @param Plugin $plugin  Plugin instance.
	 * @param string $job     Job holding the lock.
	 * @param int    $wait    Seconds to wait.
	 * @return void
	 */
	private function stop_cli_run( Plugin $plugin, $job, $wait ) {
		$run = Plugin::get_cli_run( true );

		// Runs from before 0.1.4 have no record and never check for a stop.
		if ( null === $run ) {
			WP_CLI::error( sprintf( 'A `wp omnioffloader %s` run holds the lock, but it cannot be stopped from here (it was started by an older version). Press Ctrl+C in its terminal, or wait: the lock expires 10 minutes after it ends.', $job ) );
		}

		if ( false === Plugin::cli_run_alive( $run ) ) {
			$plugin->clear_dead_cli_run( $job );
			WP_CLI::success( sprintf( 'The %s run (process %d) had already ended; its lock is released.', $job, (int) $run['pid'] ) );

			return;
		}

		Plugin::request_cli_stop();
		WP_CLI::log( sprintf( 'Asked the %s run (process %d, %d of %d done) to stop after the file it is transferring.', $job, (int) $run['pid'], (int) $run['processed'], (int) $run['total'] ) );

		for ( $waited = 0; $waited < $wait; $waited += 2 ) {
			sleep( 2 );

			if ( null === Plugin::get_cli_run( true ) ) {
				WP_CLI::success( 'Stopped. Run the same command again to continue where it left off.' );

				return;
			}
		}

		WP_CLI::warning( 'Still finishing its current file (a large file can take a while). It stops on its own right after; check with `wp omnioffloader status`.' );
	}
}
