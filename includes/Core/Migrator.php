<?php
/**
 * Version-gated data migrations.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrator
 *
 * Each migration runs once, when upgrading from a version below its gate.
 * Migrations must be idempotent.
 */
class Migrator {

	/**
	 * Run all migrations applicable when coming from $from_version.
	 *
	 * @param string $from_version The previously stored plugin version.
	 * @return void
	 */
	public static function run( $from_version ) {
		if ( version_compare( $from_version, '0.1.1', '<' ) ) {
			self::migrate_to_0_1_1();
		}

		if ( version_compare( $from_version, '0.1.2', '<' ) ) {
			self::migrate_to_0_1_2();
		}

		if ( version_compare( $from_version, '0.1.3', '<' ) ) {
			self::migrate_to_0_1_3();
		}
	}

	/**
	 * 0.1.1 — drop queue rows stranded by an interrupted 0.1.0 bulk run.
	 *
	 * Deactivating 0.1.0 during a bulk job left one wp_options row per fifty
	 * queued attachments behind, plus a run status and a process lock, with
	 * nothing left to consume them. Deactivation cleans up after itself now,
	 * but that does nothing for rows already sitting in existing installs.
	 *
	 * A job that is genuinely mid-run is left alone: this also executes inside
	 * the queue's own worker requests.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	private static function migrate_to_0_1_1() {
		$queues = array(
			'bulk_offload' => BulkOffloadProcessor::class,
			'bulk_restore' => BulkRestoreProcessor::class,
		);

		foreach ( $queues as $action => $processor ) {
			$state = $processor::get_state();

			if ( 'running' === $state['status'] ) {
				continue;
			}

			AbstractBulkProcessor::purge_stored_data( $action, $processor::STATE_OPTION );
		}
	}

	/**
	 * 0.1.2 — flush stale stats and preflight transients.
	 *
	 * @since 0.1.2
	 *
	 * @return void
	 */
	private static function migrate_to_0_1_2() {
		Helpers::flush_media_stats();
	}

	/**
	 * 0.1.3 — keep credentials out of autoload, drop a stale option.
	 *
	 * Autoloaded options live in the shared alloptions cache on every request;
	 * secrets should not. This also undoes a pre-release 0.1.3 build that
	 * briefly autoloaded them. `omnioffloader_secondary_credentials` was
	 * written by pre-release builds with secondary storage and is unused.
	 *
	 * Idempotent and safe inside a queue worker: neither touches queue data.
	 *
	 * @since 0.1.3
	 *
	 * @return void
	 */
	private static function migrate_to_0_1_3() {
		if ( false !== get_option( 'omnioffloader_credentials', false ) ) {
			wp_set_option_autoload( 'omnioffloader_credentials', false );
		}

		delete_option( 'omnioffloader_secondary_credentials' );
	}
}
