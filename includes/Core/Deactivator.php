<?php
/**
 * Fired during plugin deactivation.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

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
		// Bulk-queue cron healthcheck (WP_Background_Process).
		wp_clear_scheduled_hook( 'omnioffloader_bulk_offload_cron' );
		wp_clear_scheduled_hook( 'omnioffloader_bulk_restore_cron' );

		// Transient state.
		delete_transient( 'omnioffloader_activation_redirect' );
		delete_transient( 'omnioffloader_media_stats' );

		// Bulk progress heartbeats.
		delete_option( 'omnioffloader_bulk_offload_state' );
		delete_option( 'omnioffloader_bulk_restore_state' );
	}
}
