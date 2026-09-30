<?php
/**
 * Shared job handling for the write commands (offload, restore, retention).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\AbstractBulkProcessor;
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
	 * @var array{job: string, pid: int, host: string, started: int, total: int, processed: int, failed: int, background: bool, log: string}|null
	 */
	private $guard_run = null;

	/**
	 * Items between progress lines in a background run's log.
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
				$plugin->release_cli_job();
				Plugin::clear_cli_run();
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
	 * @param string                     $job    'offload', 'restore' or 'retention'.
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

		$this->guard_run = array(
			'job'        => (string) $job,
			'pid'        => (int) getmypid(),
			'host'       => (string) gethostname(),
			'started'    => time(),
			'total'      => (int) $total,
			'processed'  => 0,
			'failed'     => 0,
			'background' => self::in_background(),
			'log'        => (string) getenv( 'OMNIOFFLOADER_BACKGROUND_LOG' ),
		);
		Plugin::set_cli_run( $this->guard_run );

		if ( self::in_background() ) {
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
			// --background run ignores it: it must outlive the window.
			if ( defined( 'SIGHUP' ) ) {
				pcntl_signal(
					SIGHUP,
					self::in_background() ? SIG_IGN : static function () {
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
	 * Whether this process is a --background run.
	 *
	 * @return bool
	 */
	private static function in_background() {
		return '1' === getenv( 'OMNIOFFLOADER_BACKGROUND' );
	}

	/**
	 * Start this same command again, detached from the terminal, and return.
	 *
	 * The copy runs under nohup (and setsid where available), so closing
	 * the SSH window does not stop it; its output goes to a log file.
	 * Stop it with `wp omnioffloader stop`.
	 *
	 * @param string                $job        'offload', 'restore' or 'retention'.
	 * @param array<string, string> $assoc_args Named args.
	 * @param bool                  $confirms   Whether the run may ask for confirmation.
	 * @return void
	 */
	private function launch_background( $job, array $assoc_args, $confirms ) {
		if ( isset( $assoc_args['dry-run'] ) ) {
			WP_CLI::error( '--background cannot be combined with --dry-run.' );
		}

		// Nobody is there to answer a prompt.
		if ( $confirms && ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::error( 'This run asks for confirmation, which cannot be answered in the background. Add --yes.' );
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		if ( ! function_exists( 'proc_open' ) || in_array( 'proc_open', $disabled, true ) ) {
			WP_CLI::error( 'This server does not let PHP start processes (proc_open is disabled), so --background is not available. Run the command inside `screen`, or with `nohup … &`.' );
		}

		$argv = isset( $GLOBALS['argv'] ) && is_array( $GLOBALS['argv'] ) ? $GLOBALS['argv'] : array();
		$wp   = isset( $argv[0] ) ? (string) $argv[0] : '';
		$wp   = '' !== $wp && false !== realpath( $wp ) ? (string) realpath( $wp ) : $wp;

		if ( '' === $wp ) {
			WP_CLI::error( 'Could not work out how WP-CLI was started. Run the command inside `screen` instead.' );
		}

		$args = array();
		foreach ( array_slice( $argv, 1 ) as $arg ) {
			if ( '--background' !== $arg && 0 !== strpos( (string) $arg, '--log=' ) ) {
				$args[] = escapeshellarg( (string) $arg );
			}
		}

		$log = isset( $assoc_args['log'] ) && '' !== $assoc_args['log']
			? (string) $assoc_args['log']
			: trailingslashit( get_temp_dir() ) . 'omnioffloader-' . $job . '-' . wp_date( 'Ymd-His' ) . '.log';

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- only checks the log is writable.
		$handle = @fopen( $log, 'a' );
		if ( false === $handle ) {
			WP_CLI::error( sprintf( 'Cannot write the log file %s. Pass --log=<file> with a writable path.', $log ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$setsid  = is_executable( '/usr/bin/setsid' ) ? '/usr/bin/setsid ' : ( is_executable( '/bin/setsid' ) ? '/bin/setsid ' : '' );
		$command = sprintf(
			'nohup %s%s %s %s > %s 2>&1 < /dev/null & echo $!',
			$setsid,
			escapeshellarg( \WP_CLI\Utils\get_php_binary() ),
			escapeshellarg( $wp ),
			implode( ' ', $args ),
			escapeshellarg( $log )
		);

		$env                                 = getenv();
		$env['OMNIOFFLOADER_BACKGROUND']     = '1';
		$env['OMNIOFFLOADER_BACKGROUND_LOG'] = $log;

		$spec = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- WP-CLI only; availability is checked above.
		$process = proc_open( $command, $spec, $pipes, (string) getcwd(), $env );

		if ( ! is_resource( $process ) ) {
			WP_CLI::error( 'Could not start the background run. Run the command inside `screen` instead.' );
		}

		$pid = (int) trim( (string) stream_get_contents( $pipes[1] ) );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- process pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- process pipe.
		proc_close( $process );

		// Wait for it to take the lock, so a failure to start is reported here.
		for ( $i = 0; $i < 20; $i++ ) {
			usleep( 500000 );

			$run = Plugin::get_cli_run( true );
			if ( null !== $run && (int) $run['pid'] === $pid ) {
				WP_CLI::success( sprintf( 'Started in the background (process %d). You can close this window.', $pid ) );
				WP_CLI::log( 'Log:      ' . $log );
				WP_CLI::log( 'Progress: wp omnioffloader status' );
				WP_CLI::log( 'Stop:     wp omnioffloader stop' );

				return;
			}

			// Ended already: nothing to do, or it refused to start.
			if ( $pid > 0 && function_exists( 'posix_kill' ) && ! posix_kill( $pid, 0 ) ) {
				break;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local log file.
		$output = trim( (string) file_get_contents( $log ) );
		WP_CLI::log( '' !== $output ? $output : '(no output)' );
		WP_CLI::warning( sprintf( 'The background run did not start a job (see above). Log: %s', $log ) );
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
			Plugin::clear_cli_run();

			if ( $this->guard_run['background'] ) {
				WP_CLI::log( sprintf( '[%s] Ended.', wp_date( 'Y-m-d H:i:s' ) ) );
			}

			$this->guard_run = null;
		}
	}
}
