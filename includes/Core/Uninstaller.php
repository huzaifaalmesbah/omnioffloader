<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

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
		$settings = get_option( 'omnioffloader_settings', array() );

		$opted_in = is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );

		if ( ! $opted_in ) {
			return;
		}

		// Options.
		delete_option( 'omnioffloader_settings' );
		delete_option( 'omnioffloader_credentials' );
		delete_option( 'omnioffloader_version' );
		delete_option( 'omnioffloader_first_activated_version' );
		delete_option( 'omnioffloader_bulk_offload_state' );
		delete_option( 'omnioffloader_bulk_restore_state' );

		// Transients.
		delete_transient( 'omnioffloader_activation_redirect' );
		delete_transient( 'omnioffloader_media_stats' );

		// Attachment meta (offload state markers).
		delete_post_meta_by_key( 'omnioffloader_offloaded' );
		delete_post_meta_by_key( 'omnioffloader_offloaded_at' );
		delete_post_meta_by_key( 'omnioffloader_provider' );
		delete_post_meta_by_key( 'omnioffloader_bucket' );
		delete_post_meta_by_key( 'omnioffloader_path' );
		delete_post_meta_by_key( 'omnioffloader_object_version' );
		delete_post_meta_by_key( 'omnioffloader_retention' );
		delete_post_meta_by_key( 'omnioffloader_error_log' );
	}
}
