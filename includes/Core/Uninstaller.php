<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Core;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Class Uninstaller
 */
class Uninstaller {

	/**
	 * Remove plugin data — only when the site owner opted in.
	 *
	 * @return void
	 */
	public static function uninstall() {
		$settings = get_option( 'smowp_settings', array() );

		$opted_in = is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );

		if ( ! $opted_in ) {
			return;
		}

		// Options.
		delete_option( 'smowp_settings' );
		delete_option( 'smowp_credentials' );
		delete_option( 'smowp_version' );
		delete_option( 'smowp_first_activated_version' );
		delete_option( 'smowp_bulk_offload_state' );
		delete_option( 'smowp_bulk_restore_state' );

		// Transients.
		delete_transient( 'smowp_activation_redirect' );
		delete_transient( 'smowp_media_stats' );

		// Attachment meta (offload state markers).
		delete_post_meta_by_key( 'smowp_offloaded' );
		delete_post_meta_by_key( 'smowp_offloaded_at' );
		delete_post_meta_by_key( 'smowp_provider' );
		delete_post_meta_by_key( 'smowp_bucket' );
		delete_post_meta_by_key( 'smowp_path' );
		delete_post_meta_by_key( 'smowp_object_version' );
		delete_post_meta_by_key( 'smowp_retention' );
		delete_post_meta_by_key( 'smowp_error_log' );
	}
}
