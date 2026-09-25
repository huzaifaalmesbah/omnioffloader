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
		delete_option( 'omnioffloader_live_site' );
		// Cached folder check only; the marker in the bucket stays, so a
		// reinstall on the same address finds its own folder again.
		delete_option( 'omnioffloader_folder_claims' );
		delete_option( 'omnioffloader_first_activated_version' );
		// Left behind by pre-release builds with secondary storage.
		delete_option( 'omnioffloader_secondary_credentials' );

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

		delete_post_meta_by_key( 'omnioffloader_status' );
		delete_post_meta_by_key( 'omnioffloader_error_log' );

		// Media whose local files were removed (retention 1/2) now exists
		// only in the bucket. Its offload markers are the only record of
		// where it lives, and what lets Bring Back recover it after a
		// reinstall — so they are kept for as long as such media exists.
		if ( self::has_cloud_only_media() ) {
			return;
		}

		// Attachment meta (offload state markers).
		delete_post_meta_by_key( 'omnioffloader_offloaded' );
		delete_post_meta_by_key( 'omnioffloader_offloaded_at' );
		delete_post_meta_by_key( 'omnioffloader_provider' );
		delete_post_meta_by_key( 'omnioffloader_bucket' );
		delete_post_meta_by_key( 'omnioffloader_path' );
		delete_post_meta_by_key( 'omnioffloader_object_version' );
		delete_post_meta_by_key( 'omnioffloader_retention' );
	}

	/**
	 * Whether any offloaded attachment has had local files removed.
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	private static function has_cloud_only_media() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off uninstall check.
		$found = $wpdb->get_var(
			"SELECT m.post_id FROM {$wpdb->postmeta} m
			 INNER JOIN {$wpdb->postmeta} r ON r.post_id = m.post_id AND r.meta_key = 'omnioffloader_retention' AND r.meta_value IN ( '1', '2' )
			 WHERE m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 LIMIT 1"
		);

		return null !== $found;
	}
}
