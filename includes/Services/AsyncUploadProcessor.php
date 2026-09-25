<?php
/**
 * Asynchronous background processor for single/new media uploads.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\SafeMode;
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
	 * Whether this request already batches stats flushes.
	 *
	 * @var bool
	 */
	private $deferring_stats = false;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
		// Per-site on multisite (see Helpers::queue_prefix()).
		$this->prefix = Helpers::queue_prefix();
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

		// Queued before the site was recognised as a copy: stays local.
		if ( self::is_offloaded( $attachment_id ) || SafeMode::is_active() ) {
			delete_post_meta( $attachment_id, 'omnioffloader_status' );

			return false;
		}

		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		// One stats flush per worker pass instead of one per upload. A
		// shutdown function, because the parent's handle() ends in wp_die().
		if ( ! $this->deferring_stats ) {
			$this->deferring_stats = true;
			Helpers::defer_media_stats_flush( true );
			register_shutdown_function( array( Helpers::class, 'defer_media_stats_flush' ), false );
		}

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		if ( ! $ok ) {
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );
		}

		return false;
	}

	/**
	 * Save the queued items and flag the queue as active.
	 *
	 * @return $this
	 */
	public function save() {
		set_site_transient( $this->identifier . '_has_queue', 1, DAY_IN_SECONDS );

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
		if ( ! get_site_transient( $this->identifier . '_has_queue' ) ) {
			return false;
		}

		if ( $this->is_queue_empty() ) {
			delete_site_transient( $this->identifier . '_has_queue' );
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

		// A poll-driven pass is short, and must survive handle()'s wp_die().
		$shorten = static function () {
			return 8;
		};

		add_filter( $this->identifier . '_wp_die', '__return_false' );
		add_filter( $this->identifier . '_default_time_limit', $shorten );

		try {
			$this->handle();
		} finally {
			remove_filter( $this->identifier . '_default_time_limit', $shorten );
			remove_filter( $this->identifier . '_wp_die', '__return_false' );
		}
	}

	/**
	 * Complete processing and clean up active flag.
	 *
	 * @return void
	 */
	protected function complete() {
		delete_site_transient( $this->identifier . '_has_queue' );
		parent::complete();
	}

	/**
	 * Purge all stored queue batches, locks, and cron healthchecks.
	 *
	 * @return void
	 */
	public static function purge_stored_data() {
		global $wpdb;

		$identifier = Helpers::queue_prefix() . '_async_upload';
		$like       = $wpdb->esc_like( $identifier . '_batch_' ) . '%';

		if ( is_multisite() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of orphaned queue rows.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of orphaned queue rows.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );
		}

		delete_site_option( $identifier . '_status' );
		delete_site_transient( $identifier . '_process_lock' );
		delete_site_transient( $identifier . '_has_queue' );
		// Flag name used before 0.1.3.
		delete_site_transient( 'omnioffloader_async_has_queue' );
		wp_clear_scheduled_hook( $identifier . '_cron' );
	}
}
