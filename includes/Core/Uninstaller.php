<?php
/**
 * Fired when the plugin is uninstalled (deleted).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;
use OmniOffloader\Services\BulkRetentionProcessor;

if ( ! defined( 'ABSPATH' ) ) {
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

		// Queues: batch rows, run status, process lock, cron and state.
		AsyncUploadProcessor::purge_stored_data();
		AbstractBulkProcessor::purge_stored_data( 'bulk_offload', BulkOffloadProcessor::STATE_OPTION );
		AbstractBulkProcessor::purge_stored_data( 'bulk_restore', BulkRestoreProcessor::STATE_OPTION );
		AbstractBulkProcessor::purge_stored_data( 'bulk_retention', BulkRetentionProcessor::STATE_OPTION );

		// Transients.
		delete_transient( 'omnioffloader_activation_redirect' );
		delete_transient( 'omnioffloader_media_stats' );
		delete_transient( 'omnioffloader_restore_preflight' );
		delete_transient( 'omnioffloader_retention_preflight' );

		// Attachment meta (offload state markers).
		delete_post_meta_by_key( 'omnioffloader_offloaded' );
		delete_post_meta_by_key( 'omnioffloader_offloaded_at' );
		delete_post_meta_by_key( 'omnioffloader_provider' );
		delete_post_meta_by_key( 'omnioffloader_bucket' );
		delete_post_meta_by_key( 'omnioffloader_path' );
		delete_post_meta_by_key( 'omnioffloader_object_version' );
		delete_post_meta_by_key( 'omnioffloader_retention' );
		delete_post_meta_by_key( 'omnioffloader_status' );
		delete_post_meta_by_key( 'omnioffloader_error_log' );
	}
}
