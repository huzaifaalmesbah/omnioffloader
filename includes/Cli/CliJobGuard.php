<?php
/**
 * Shared job handling for the write commands (offload, restore, retention).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\AttachmentUploader;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait CliJobGuard
 *
 * Argument validation, the one-job-at-a-time check (Safety Invariant #8)
 * and lock lifetime for a WP-CLI run.
 *
 * @since 0.1.3
 */
trait CliJobGuard {

	/**
	 * Items between object-cache clears in long loops.
	 *
	 * @var int
	 */
	private static $clear_cache_every = 100;

	/**
	 * Plugin instance holding the CLI job claim, while a job runs.
	 *
	 * @var Plugin|null
	 */
	private $guard_plugin = null;

	/**
	 * Queue whose process lock this run holds, while a job runs.
	 *
	 * @var AbstractBulkProcessor|null
	 */
	private $guard_queue = null;

	/**
	 * Job name for the CLI claim.
	 *
	 * @var string
	 */
	private $guard_job = '';

	/**
	 * Heartbeat listener renewing the claim mid-transfer, while a job runs.
	 *
	 * @var \Closure|null
	 */
	private $guard_heartbeat = null;

	/**
	 * This run's record (see Plugin::set_cli_run()), while a job runs.
	 *
	 * @var array{job: string, pid: int, host: string, started: int, total: int, processed: int, failed: int, background: bool, log: string, files_at_once?: int, in_flight?: int[]}|null
	 */
	private $guard_run = null;

	/**
	 * Items between progress lines in a detached run's log.
	 *
	 * @var int
	 */
	private static $log_progress_every = 25;

	/**
	 * Why the offload engine is off, for error messages.
	 *
	 * @return string
	 */
	private static function engine_off_reason() {
		if ( defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) && OMNIOFFLOADER_DISABLE_ENGINE ) {
			return 'The offload engine is disabled by OMNIOFFLOADER_DISABLE_ENGINE in wp-config.php.';
		}

		return 'No cloud provider is configured (or the AWS SDK is missing).';
	}

	/**
	 * The --limit value, validated.
	 *
	 * A typo must never silently mean "the whole library", so anything but a
	 * positive whole number is an error.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return int 0 when --limit was not given.
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

	/**
	 * The --ids list, or null when not given. Refuses --ids together with --all.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return int[]|null
	 */
	private function parse_ids( array $assoc_args ) {
		if ( ! isset( $assoc_args['ids'] ) ) {
			return null;
		}

		if ( isset( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass either --ids or --all, not both.' );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $assoc_args['ids'] ) ) ) ) );

		if ( empty( $ids ) ) {
			WP_CLI::error( '--ids needs a comma-separated list of attachment IDs.' );
		}

		return $ids;
	}

	/**
	 * Tell the user which requested IDs were left out, and why.
	 *
	 * @param int[]  $requested IDs asked for.
	 * @param int[]  $kept      IDs that will be processed.
	 * @param string $reason    Why the others were skipped.
	 * @return void
	 */
	private function warn_skipped_ids( array $requested, array $kept, $reason ) {
		$skipped = array_diff( $requested, $kept );

		if ( ! empty( $skipped ) ) {
			WP_CLI::warning( sprintf( 'Skipped %d ID(s) (%s): %s', count( $skipped ), $reason, implode( ',', $skipped ) ) );
		}
	}

	/**
	 * Stop unless no other job runs (or --force was passed).
	 *
	 * @param Plugin                $plugin     Plugin instance.
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function refuse_if_busy( Plugin $plugin, array $assoc_args ) {
		$active = $plugin->get_active_bulk_job();

		if ( '' === $active || isset( $assoc_args['force'] ) ) {
			return;
		}

		$cli_job = get_site_transient( Plugin::cli_job_lock_key() );

		if ( is_string( $cli_job ) && '' !== $cli_job ) {
			// The lock outlived its process (killed, or the server rebooted):
			// clear it rather than make the user wait out the 10 minutes.
			$run = Plugin::get_cli_run( true );

			if ( null !== $run && false === Plugin::cli_run_alive( $run ) ) {
				$plugin->clear_dead_cli_run( $cli_job );
				WP_CLI::log( sprintf( 'Cleared the lock of a `wp omnioffloader %s` run that is no longer running (process %d).', $cli_job, (int) $run['pid'] ) );

				if ( '' === $plugin->get_active_bulk_job() ) {
					return;
				}
			}

			WP_CLI::error(
				sprintf(
					'Another `wp omnioffloader %s` run holds the lock. If that run was interrupted, the lock expires within 10 minutes; pass --force if you are sure nothing else is running.',
					$cli_job
				)
			);
		}

		WP_CLI::error( sprintf( 'A bulk %s started from the admin screen is running. Wait for it to finish, cancel it there, or pass --force.', $active ) );
	}

	/**
	 * Claim the job: the queue's process lock and the CLI claim, released
	 * on any exit — including Ctrl-C, where PHP skips shutdown functions
	 * unless the signal is turned into a normal exit.
	 *
	 * @param Plugin                     $plugin Plugin instance.
	 * @param AbstractBulkProcessor|null $queue  Queue to hold, if the engine is on.
	 * @param string                     $job    'offload', 'restore', 'retention' or 'move'.
	 * @param int                        $total  Items this run will process.
	 * @return void
	 */
	private function begin_job( Plugin $plugin, $queue, $job, $total = 0 ) {
		$this->guard_plugin = $plugin;
		$this->guard_queue  = $queue;
		$this->guard_job    = $job;

		$this->renew_job();

		// A stop request left from an earlier run must not end this one.
		delete_option( Plugin::CLI_STOP_OPTION );

		$detached = self::is_detached();

		$this->guard_run = array(
			'job'           => (string) $job,
			'pid'           => (int) getmypid(),
			'host'          => (string) gethostname(),
			'started'       => time(),
			'total'         => (int) $total,
			'processed'     => 0,
			'failed'        => 0,
			'background'    => $detached,
			'log'           => $detached ? self::output_file() : '',
			// Includes offload --concurrency, set before the job starts.
			'files_at_once' => AttachmentUploader::files_at_once(),
		);
		Plugin::set_cli_run( $this->guard_run );

		if ( $detached ) {
			WP_CLI::log( sprintf( '[%s] Started %s of %d attachment(s), process %d.', wp_date( 'Y-m-d H:i:s' ), $job, $total, getmypid() ) );
		}

		// A single upload or download can outlast the 10 minute claim (a
		// multi-GB video on a slow link); renew it while bytes are moving.
		$this->guard_heartbeat = function () {
			$this->renew_job();
		};
		add_action( 'omnioffloader_transfer_heartbeat', $this->guard_heartbeat );

		register_shutdown_function( array( $this, 'end_job' ) );

		if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) && defined( 'SIGINT' ) && defined( 'SIGTERM' ) ) {
			pcntl_async_signals( true );
			pcntl_signal(
				SIGINT,
				static function () {
					exit( 130 );
				}
			);
			pcntl_signal(
				SIGTERM,
				static function () {
					exit( 143 );
				}
			);

			// Closing the SSH window sends SIGHUP; without this the claim
			// stayed behind and blocked the next run for 10 minutes. A
			// detached run (`nohup … &`) must outlive the window, so its
			// SIGHUP disposition is left as nohup set it.
			if ( defined( 'SIGHUP' ) && ! $detached ) {
				pcntl_signal(
					SIGHUP,
					static function () {
						exit( 129 );
					}
				);
			}
		}
	}

	/**
	 * Keep the claim alive; call after every item.
	 *
	 * Renewed per item rather than every N items: one transient write is
	 * nothing next to a network transfer, while N large files could outlast
	 * the 10 minute lock and let an admin job start alongside this one.
	 *
	 * @param int $processed Items processed so far.
	 * @param int $failed    Items failed so far.
	 * @return void
	 */
	private function keep_job_alive( $processed, $failed = 0 ) {
		$this->renew_job();

		if ( null !== $this->guard_run ) {
			$this->guard_run['processed'] = (int) $processed;
			$this->guard_run['failed']    = (int) $failed;
			Plugin::set_cli_run( $this->guard_run );

			// No progress bar without a terminal; the log gets a line instead.
			if ( $this->guard_run['background'] && 0 === $processed % self::$log_progress_every ) {
				WP_CLI::log( sprintf( '[%s] %d of %d done, %d failed.', wp_date( 'Y-m-d H:i:s' ), $processed, $this->guard_run['total'], $failed ) );
			}
		}

		// Long runs: stop the runtime object cache (and SAVEQUERIES log) growing.
		if ( 0 === $processed % self::$clear_cache_every && function_exists( '\WP_CLI\Utils\wp_clear_object_cache' ) ) {
			\WP_CLI\Utils\wp_clear_object_cache();
		}
	}

	/**
	 * Record the attachments about to be transferred, before their status
	 * becomes "processing".
	 *
	 * Kept in the run record, so whoever ends the run — end_job() on any
	 * exit, or the next command finding the process gone — can clear a
	 * status the interrupted run never got to resolve.
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	private function begin_items( array $ids ) {
		if ( null !== $this->guard_run ) {
			$this->guard_run['in_flight'] = array_values( array_map( 'intval', $ids ) );
			Plugin::set_cli_run( $this->guard_run );
		}
	}

	/**
	 * The attachments from begin_items() have their final status.
	 *
	 * Written with the next keep_job_alive() rather than straight away.
	 *
	 * @return void
	 */
	private function end_items() {
		if ( null !== $this->guard_run ) {
			unset( $this->guard_run['in_flight'] );
		}
	}

	/**
	 * Whether `wp omnioffloader stop` asked this run to stop.
	 *
	 * Checked between items, so the item in progress always finishes and
	 * no file is left half transferred.
	 *
	 * @return bool
	 */
	private function stop_requested() {
		return Plugin::cli_stop_requested();
	}

	/**
	 * Report a run ended by `wp omnioffloader stop`, and exit.
	 *
	 * @param string $summary What got done, e.g. "120 offloaded, 2 failed".
	 * @return void
	 */
	private function report_stopped( $summary ) {
		WP_CLI::warning( sprintf( 'Stopped by `wp omnioffloader stop`: %s. Run the same command again to continue.', $summary ) );
		WP_CLI::halt( 0 );
	}

	/**
	 * Whether this run has no terminal to draw on: started with `nohup …&`,
	 * from cron, or with its output redirected to a file.
	 *
	 * Such a run writes progress lines instead of a progress bar, and leaves
	 * SIGHUP alone so `nohup` keeps it running after the window closes.
	 *
	 * @return bool
	 */
	private static function is_detached() {
		return ! defined( 'STDOUT' ) || ! stream_isatty( STDOUT );
	}

	/**
	 * Refuse a question nobody can answer: a detached run would hang
	 * waiting for input (or stop on SIGTTIN) instead of asking.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	private function require_yes_when_detached( array $assoc_args ) {
		if ( self::is_detached() && ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'This run asks for confirmation, which cannot be answered without a terminal (nohup, cron or redirected output). Add --yes.' );
		}
	}

	/**
	 * The file this run's output goes to, where the system can tell (Linux).
	 *
	 * @return string Empty when unknown or not a file.
	 */
	private static function output_file() {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- /proc is missing outside Linux.
		$target = @readlink( '/proc/self/fd/1' );

		return is_string( $target ) && 0 === strpos( $target, '/' ) && is_file( $target ) ? $target : '';
	}

	/**
	 * Refresh the queue lock and the CLI claim.
	 *
	 * @return void
	 */
	private function renew_job() {
		if ( null !== $this->guard_queue ) {
			$this->guard_queue->claim_lock();
		}

		if ( null !== $this->guard_plugin ) {
			$this->guard_plugin->claim_cli_job( $this->guard_job );
		}
	}

	/**
	 * Release the job's locks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function end_job() {
		if ( null !== $this->guard_heartbeat ) {
			remove_action( 'omnioffloader_transfer_heartbeat', $this->guard_heartbeat );
			$this->guard_heartbeat = null;
		}

		if ( null !== $this->guard_queue ) {
			$this->guard_queue->release_lock();
			$this->guard_queue = null;
		}

		if ( null !== $this->guard_plugin ) {
			$this->guard_plugin->release_cli_job();
			$this->guard_plugin = null;
		}

		if ( null !== $this->guard_run ) {
			// A second run started with --force rewrote the record; it is
			// that run's to clear, not this one's.
			$record = Plugin::get_cli_run( true );

			if ( null === $record || (int) ( $record['pid'] ?? 0 ) === (int) $this->guard_run['pid'] ) {
				Plugin::clear_cli_run();
			}

			if ( $this->guard_run['background'] ) {
				WP_CLI::log( sprintf( '[%s] Ended.', wp_date( 'Y-m-d H:i:s' ) ) );
			}

			$this->guard_run = null;
		}
	}
}
