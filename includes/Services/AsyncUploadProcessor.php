<?php
/**
 * Asynchronous background processor for single/new media uploads.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AsyncUploadProcessor
 *
 * Offloads newly uploaded media attachments to the cloud in the background.
 *
 * @since 0.1.1
 */
class AsyncUploadProcessor extends WP_Background_Process {

	use AttachmentHelper;

	/**
	 * Prefix for identifier.
	 *
	 * @var string
	 */
	protected $prefix = 'omnioffloader';

	/**
	 * Action identifier — hooks become omnioffloader_async_upload(_cron).
	 *
	 * @var string
	 */
	protected $action = 'async_upload';

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
		parent::__construct( array() );
	}

	/**
	 * Process one attachment ID from the queue.
	 *
	 * @param mixed $item Queue item (attachment ID).
	 * @return false Always return false so the item is dequeued.
	 */
	protected function task( $item ) {
		$attachment_id = (int) $item;

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return false;
		}

		if ( self::is_offloaded( $attachment_id ) ) {
			delete_post_meta( $attachment_id, 'omnioffloader_status' );

			return false;
		}

		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		if ( ! $ok ) {
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );
		}

		return false;
	}

	/**
	 * Queue an attachment and dispatch background processing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function queue_and_dispatch( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 || self::is_offloaded( $attachment_id ) ) {
			return;
		}

		update_post_meta( $attachment_id, 'omnioffloader_status', 'queued' );

		$this->push_to_queue( $attachment_id );
		$this->save();
		$this->dispatch();
	}

	/**
	 * Save the queued items and flag the queue as active.
	 *
	 * @return $this
	 */
	public function save() {
		set_site_transient( 'omnioffloader_async_has_queue', 1, DAY_IN_SECONDS );

		return parent::save();
	}

	/**
	 * Whether the async processor has pending items in queue.
	 *
	 * Uses a transient flag to avoid querying the database with LIKE queries
	 * during routine REST status checks.
	 *
	 * @return bool
	 */
	public function has_pending_items() {
		if ( ! get_site_transient( 'omnioffloader_async_has_queue' ) ) {
			return false;
		}

		if ( $this->is_queue_empty() ) {
			delete_site_transient( 'omnioffloader_async_has_queue' );
			return false;
		}

		return true;
	}

	/**
	 * Process pending items inline if loopback dispatch is delayed.
	 *
	 * @return void
	 */
	public function process_queue_now() {
		if ( ! $this->has_pending_items() || $this->is_processing() ) {
			return;
		}

		$this->handle();
	}

	/**
	 * Complete processing and clean up active flag.
	 *
	 * @return void
	 */
	protected function complete() {
		delete_site_transient( 'omnioffloader_async_has_queue' );
		parent::complete();
	}

	/**
	 * Purge all stored queue batches, locks, and cron healthchecks.
	 *
	 * @return void
	 */
	public static function purge_stored_data() {
		global $wpdb;

		$identifier = 'omnioffloader_async_upload';
		$like       = $wpdb->esc_like( $identifier . '_batch_' ) . '%';

		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of orphaned queue rows.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of orphaned queue rows.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		}

		delete_site_transient( $identifier . '_process_lock' );
		delete_site_transient( 'omnioffloader_async_has_queue' );
		wp_clear_scheduled_hook( $identifier . '_cron' );
	}
}
