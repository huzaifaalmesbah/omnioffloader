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
	 * Process lock lifetime. The parent's 60 seconds expire during one large
	 * upload, letting a second worker start on the same item; the lock is
	 * also renewed while bytes move (see handle()).
	 *
	 * @var int
	 */
	protected $queue_lock_time = 600;

	/**
	 * Tries before an upload whose worker keeps being killed (timeout,
	 * memory) is given up and marked failed, instead of retried forever.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Seconds without queue activity before an admin poll takes the queue
	 * over (process_queue_now()). A healthy loopback starts within a second
	 * or two of an upload and touches the queue after every item, so polls
	 * only drive the queue when the loopback really is not arriving.
	 *
	 * @since 0.1.8
	 */
	public const STALL_SECONDS = 20;

	/**
	 * Whether this request holds the worker mutex.
	 *
	 * @var bool
	 */
	private $holds_mutex = false;

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
	 * One worker at a time, with the lock renewed during long transfers.
	 *
	 * The process lock is a transient: checking and setting it are two
	 * steps, so the loopback worker, the cron healthcheck and an admin poll
	 * could all start on the same upload. A database lock is atomic.
	 *
	 * @since 0.1.4
	 *
	 * @return void|mixed
	 */
	protected function handle() {
		if ( ! $this->acquire_worker_mutex() ) {
			return $this->maybe_wp_die();
		}

		$renew = function () {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the parent's own filter name.
			set_site_transient( $this->identifier . '_process_lock', microtime(), (int) apply_filters( $this->identifier . '_queue_lock_time', $this->queue_lock_time ) );
		};
		add_action( 'omnioffloader_transfer_heartbeat', $renew );

		$this->touch_activity();

		try {
			// The parent ends in wp_die() on a loopback request; the mutex is
			// session-scoped, so it is released with the connection then.
			return parent::handle();
		} finally {
			remove_action( 'omnioffloader_transfer_heartbeat', $renew );
			$this->release_worker_mutex();
		}
	}

	/**
	 * Release the worker mutex together with the process lock.
	 *
	 * The parent unlocks, then dispatches the next pass and ends in
	 * wp_die(); the mutex would otherwise be held until the connection
	 * closes, and a fast next pass would find it taken and leave.
	 *
	 * @since 0.1.4
	 *
	 * @return $this
	 */
	protected function unlock_process() {
		parent::unlock_process();
		$this->release_worker_mutex();

		return $this;
	}

	/**
	 * Take the worker mutex without waiting; true where GET_LOCK() is missing.
	 *
	 * @return bool
	 */
	private function acquire_worker_mutex() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, must hit the server.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $this->worker_mutex_name() ) );

		if ( null === $got ) {
			return true;
		}

		$this->holds_mutex = '1' === (string) $got;

		return $this->holds_mutex;
	}

	/**
	 * Release the worker mutex if this request holds it.
	 *
	 * @return void
	 */
	private function release_worker_mutex() {
		global $wpdb;

		if ( ! $this->holds_mutex ) {
			return;
		}

		$this->holds_mutex = false;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- advisory lock, must hit the server.
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $this->worker_mutex_name() ) );
	}

	/**
	 * Name of this queue's worker mutex.
	 *
	 * @return string
	 */
	private function worker_mutex_name() {
		global $wpdb;

		return 'omnioffloader:' . md5( $wpdb->prefix . $this->identifier );
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
		if ( SafeMode::is_active() ) {
			delete_post_meta( $attachment_id, 'omnioffloader_status' );

			return false;
		}

		// Already offloaded, queued again by a later metadata pass (a
		// client-side upload's finalize, resumed sub-sizes): upload only the
		// files that reached the server since.
		if ( self::is_offloaded( $attachment_id ) ) {
			( new AttachmentUploader( $this->provider ) )->upload_missing( $attachment_id );
			delete_post_meta( $attachment_id, 'omnioffloader_status' );

			return false;
		}

		// The parent removes an item only after it finishes, so a worker
		// killed mid-upload leaves it queued; cap the retries.
		$attempts = (int) get_post_meta( $attachment_id, 'omnioffloader_async_attempts', true ) + 1;

		if ( $attempts > self::MAX_ATTEMPTS ) {
			delete_post_meta( $attachment_id, 'omnioffloader_async_attempts' );
			self::log_attachment_error(
				$attachment_id,
				__( 'The background upload was stopped by the server several times (time or memory limit). Offload it again from the Media Library, or with WP-CLI.', 'omnioffloader' )
			);
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );

			return false;
		}

		update_post_meta( $attachment_id, 'omnioffloader_async_attempts', $attempts );
		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		$this->touch_activity();

		// One stats flush per worker pass instead of one per upload. A
		// shutdown function, because the parent's handle() ends in wp_die().
		if ( ! $this->deferring_stats ) {
			$this->deferring_stats = true;
			Helpers::defer_media_stats_flush( true );
			register_shutdown_function( array( Helpers::class, 'defer_media_stats_flush' ), false );
		}

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		delete_post_meta( $attachment_id, 'omnioffloader_async_attempts' );

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
		// A new item gives its loopback STALL_SECONDS to arrive.
		$this->touch_activity();

		return parent::save();
	}

	/**
	 * Record that the queue moved just now (see process_queue_now()).
	 *
	 * @since 0.1.8
	 *
	 * @return void
	 */
	private function touch_activity() {
		set_site_transient( $this->identifier . '_last_activity', time(), HOUR_IN_SECONDS );
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
	 * Whether nothing is queued or running, read from the queue itself
	 * rather than the _has_queue flag.
	 *
	 * @since 0.1.4
	 *
	 * @return bool
	 */
	public function is_idle() {
		return ! $this->is_processing() && $this->is_queue_empty();
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

		// Between a worker's passes (and before the first loopback arrives)
		// the queue looks idle; taking it over then would hold this poll
		// request for seconds on every poll. Wait until it has gone quiet.
		$last = (int) get_site_transient( $this->identifier . '_last_activity' );
		if ( $last > 0 && time() - $last < self::STALL_SECONDS ) {
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
		delete_site_transient( $this->identifier . '_last_activity' );
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
		delete_site_transient( $identifier . '_last_activity' );
		// Flag name used before 0.1.3.
		delete_site_transient( 'omnioffloader_async_has_queue' );
		wp_clear_scheduled_hook( $identifier . '_cron' );
		// Client-side uploads waiting for their finalize request (UploadObserver::FINALIZE_FALLBACK_HOOK).
		wp_unschedule_hook( 'omnioffloader_client_upload_fallback' );
	}
}
