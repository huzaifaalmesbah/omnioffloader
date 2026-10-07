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
	 * Show what is running, provider configuration, connection state and
	 * media stats.
	 *
	 * First what is running now: a WP-CLI job (progress, speed, time left,
	 * log file) or a bulk job started from the admin screen. When nothing
	 * runs, how the last WP-CLI job ended.
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

		$running = $this->report_jobs();
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

		// Counted now: the cached numbers can be minutes old mid-run.
		$stats = Helpers::get_media_stats( true );
		WP_CLI::log( '' );
		WP_CLI::log(
			sprintf(
				'Media: %s total — %s offloaded (%s), %s local',
				number_format_i18n( $stats['total'] ),
				number_format_i18n( $stats['offloaded'] ),
				self::percent( $stats['offloaded'], $stats['total'] ),
				number_format_i18n( $stats['local'] )
			)
		);

		// Running admin jobs are reported at the top; these are past runs.
		foreach ( self::admin_jobs() as $label => $state ) {
			if ( ! in_array( $state['status'], array( 'idle', 'running' ), true ) ) {
				WP_CLI::log(
					sprintf(
						'Last %s from the admin screen: %s (%d of %d, %d errors)%s',
						$label,
						$state['status'],
						$state['processed'],
						$state['total'],
						$state['errors'],
						! empty( $state['updated_at'] ) ? ', ' . human_time_diff( (int) $state['updated_at'] ) . ' ago' : ''
					)
				);
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

		if ( ! $running && null !== $provider && $stats['local'] > 0 && ! $safe['active'] ) {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%s attachment(s) are not offloaded yet. To offload them in the background:', number_format_i18n( $stats['local'] ) ) );
			WP_CLI::log( '  nohup wp omnioffloader offload --all --concurrency=32 > ~/offload.log 2>&1 &' );
		}

		if ( ! $healthy ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Report what is running now, or how the last WP-CLI job ended.
	 *
	 * @return bool Whether a job is running.
	 */
	private function report_jobs() {
		$run = Plugin::get_cli_run( true );

		if ( null !== $run ) {
			return $this->report_cli_run( $run );
		}

		foreach ( self::admin_jobs() as $label => $state ) {
			if ( 'running' === $state['status'] ) {
				WP_CLI::log( sprintf( 'Running now: %s started from the admin screen', $label ) );
				WP_CLI::log( sprintf( '  Progress:   %s of %s (%s), %d errors', number_format_i18n( $state['processed'] ), number_format_i18n( $state['total'] ), self::percent( $state['processed'], $state['total'] ), $state['errors'] ) );
				WP_CLI::log( '  Stop:       wp omnioffloader stop (or Cancel on the admin screen)' );

				return true;
			}
		}

		WP_CLI::log( 'Running now: nothing' );

		$last = Plugin::get_cli_last_run();

		if ( null !== $last ) {
			$this->report_last_run( $last );
		}

		return false;
	}

	/**
	 * Report a WP-CLI job that has a run record.
	 *
	 * @param array<string, mixed> $run Run record (see Plugin::set_cli_run()).
	 * @return bool Whether it is still running.
	 */
	private function report_cli_run( array $run ) {
		$job       = (string) $run['job'];
		$pid       = (int) $run['pid'];
		$alive     = Plugin::cli_run_alive( $run );
		$processed = (int) $run['processed'];
		$total     = (int) $run['total'];
		$started   = (int) $run['started'];
		// A run started before 0.1.4's status changes has no 'updated';
		// it reports after every attachment, so "now" is close enough.
		$updated = (int) ( $run['updated'] ?? time() );

		if ( false === $alive ) {
			WP_CLI::log( sprintf( 'Running now: nothing. The WP-CLI %s run (process %d) ended without cleaning up, for example a server restart or kill -9.', $job, $pid ) );
			WP_CLI::log( sprintf( '  Got to:     %s of %s (%s), %d failed, last progress %s ago', number_format_i18n( $processed ), number_format_i18n( $total ), self::percent( $processed, $total ), (int) $run['failed'], human_time_diff( $updated ) ) );
			WP_CLI::log( '  Continue:   run the same command again. It clears the old lock and skips attachments already done.' );

			return false;
		}

		$stopping = Plugin::cli_stop_requested();

		if ( $stopping ) {
			$title = sprintf( 'Stopping: WP-CLI %s (process %d) finishes the files in progress, then exits', $job, $pid );
		} elseif ( null === $alive ) {
			$title = sprintf( 'Running now: WP-CLI %s on server %s (process %d)', $job, (string) $run['host'], $pid );
		} else {
			$title = sprintf( 'Running now: WP-CLI %s (%s, process %d)', $job, ! empty( $run['background'] ) ? 'in the background' : 'in a terminal', $pid );
		}

		WP_CLI::log( $title );
		WP_CLI::log( sprintf( '  Progress:   %s of %s (%s), %d failed', number_format_i18n( $processed ), number_format_i18n( $total ), self::percent( $processed, $total ), (int) $run['failed'] ) );
		WP_CLI::log( sprintf( '  Started:    %s (%s ago)', wp_date( 'Y-m-d H:i', $started ), human_time_diff( $started ) ) );

		// The average since the start; a minute in, it means something.
		$elapsed = max( 1, $updated - $started );

		if ( $processed > 0 && $elapsed >= MINUTE_IN_SECONDS ) {
			$per_minute = $processed * MINUTE_IN_SECONDS / $elapsed;
			$left       = (int) round( ( $total - $processed ) / ( $processed / $elapsed ) );

			WP_CLI::log( sprintf( '  Speed:      %s attachments per minute', number_format_i18n( $per_minute, $per_minute < 10 ? 1 : 0 ) ) );

			if ( $total > $processed ) {
				WP_CLI::log( sprintf( '  Time left:  about %s (finishes around %s, site time)', self::duration( $left ), wp_date( 'Y-m-d H:i', $updated + $left ) ) );
			}
		}

		if ( ! empty( $run['files_at_once'] ) ) {
			$files = (int) $run['files_at_once'];
			WP_CLI::log( sprintf( '  Transfers:  %d %s at once', $files, 1 === $files ? 'file' : 'files' ) );
		}

		// A run reports after every attachment; a long gap is one big file.
		if ( time() - $updated > 2 * MINUTE_IN_SECONDS ) {
			WP_CLI::log( sprintf( '  Note:       no progress for %s; a large file may still be transferring', human_time_diff( $updated ) ) );
		}

		if ( ! empty( $run['log'] ) ) {
			WP_CLI::log( sprintf( '  Log:        %1$s (watch it: tail -f %1$s)', (string) $run['log'] ) );
		}

		if ( ! $stopping ) {
			WP_CLI::log( '  Stop:       wp omnioffloader stop' );
		}

		return true;
	}

	/**
	 * Report how the last WP-CLI job ended.
	 *
	 * @param array<string, mixed> $last Last run record (see Plugin::get_cli_last_run()).
	 * @return void
	 */
	private function report_last_run( array $last ) {
		$processed = (int) $last['processed'];
		$total     = (int) $last['total'];
		$started   = (int) $last['started'];
		$ended     = (int) ( $last['updated'] ?? $started );

		if ( ! empty( $last['stopped'] ) ) {
			$how = 'stopped with `wp omnioffloader stop`';
		} elseif ( $processed >= $total ) {
			$how = 'finished';
		} else {
			$how = 'ended early (Ctrl+C, a closed window, a server restart, or too many failures)';
		}

		WP_CLI::log( sprintf( 'Last WP-CLI run: %s, %s', (string) $last['job'], $how ) );
		WP_CLI::log( sprintf( '  Result:     %s of %s done, %d failed', number_format_i18n( $processed ), number_format_i18n( $total ), (int) $last['failed'] ) );
		WP_CLI::log( sprintf( '  When:       %s to %s (%s), ended %s ago', wp_date( 'Y-m-d H:i', $started ), wp_date( 'H:i', $ended ), self::duration( $ended - $started ), human_time_diff( $ended ) ) );

		if ( ! empty( $last['log'] ) && is_readable( (string) $last['log'] ) ) {
			WP_CLI::log( sprintf( '  Log:        %s', (string) $last['log'] ) );
		}

		if ( $processed < $total ) {
			WP_CLI::log( '  Continue:   run the same command again; attachments already done are skipped.' );
		}
	}

	/**
	 * The admin bulk queues' states, by label.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function admin_jobs() {
		return array(
			'Bulk Offload'     => BulkOffloadProcessor::get_state(),
			'Bring Back'       => BulkRestoreProcessor::get_state(),
			'retention update' => BulkRetentionProcessor::get_state(),
		);
	}

	/**
	 * A share as a percentage, e.g. "5.8%".
	 *
	 * @param int $part  Part.
	 * @param int $total Total.
	 * @return string
	 */
	private static function percent( $part, $total ) {
		return $total > 0 ? number_format_i18n( 100 * $part / $total, 1 ) . '%' : '0%';
	}

	/**
	 * Seconds as "6 hours 47 mins" / "12 mins" / "less than a minute".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function duration( $seconds ) {
		if ( $seconds < MINUTE_IN_SECONDS ) {
			return 'less than a minute';
		}

		$minutes = (int) round( $seconds / MINUTE_IN_SECONDS );
		$hours   = intdiv( $minutes, 60 );
		$minutes = $minutes % 60;

		if ( 0 === $hours ) {
			return sprintf( '%d min%s', $minutes, 1 === $minutes ? '' : 's' );
		}

		return sprintf( '%d hour%s %d min%s', $hours, 1 === $hours ? '' : 's', $minutes, 1 === $minutes ? '' : 's' );
	}
}
