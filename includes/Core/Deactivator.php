<?php
/**
 * Fired during plugin deactivation.
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
 * Class Deactivator
 */
class Deactivator {

	/**
	 * Plugin deactivation tasks.
	 *
	 * Clears transient state and scheduled events. Settings and
	 * credentials are intentionally preserved.
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Everything the bulk queues store: batch rows, run status, process
		// lock, cron healthcheck and progress heartbeat. Deactivating during a
		// run used to strand one option row per fifty queued attachments in
		// wp_options with nothing left to consume them.
		AbstractBulkProcessor::purge_stored_data( 'bulk_offload', BulkOffloadProcessor::STATE_OPTION );
		AbstractBulkProcessor::purge_stored_data( 'bulk_restore', BulkRestoreProcessor::STATE_OPTION );

		// Transient state.
		delete_transient( 'omnioffloader_activation_redirect' );
		delete_transient( 'omnioffloader_media_stats' );
		delete_transient( 'omnioffloader_restore_preflight' );
	}
}
