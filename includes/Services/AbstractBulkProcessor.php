<?php
/**
 * Shared background-queue behaviour for the bulk offload and bulk restore jobs.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractBulkProcessor
 *
 * Both bulk queues move one attachment's worth of files across the network per
 * task, which makes them a poor fit for WP_Background_Process's defaults (built
 * for short CPU-bound tasks). This class hardens the queue for that workload:
 *
 *  - A 10 minute process lock, renewed after every item, instead of 60 seconds.
 *    A lock that expires mid-transfer lets the cron healthcheck and the admin
 *    poll start a second worker on the same batch, which on a small PHP-FPM
 *    pool fans out into duplicate uploads and can exhaust the pool.
 *  - Items are claimed out of the batch *before* they run, so a task killed by
 *    the host time limit is dropped instead of retried forever.
 *  - The claimed item is recorded in the progress state, so a killed worker is
 *    detected on the next run and reported to the user rather than silently
 *    stalling the progress bar below the total.
 *  - A consecutive-failure circuit breaker, so a bad bucket or bad credentials
 *    stop the run in seconds instead of grinding through the whole library.
 *  - The inline "self-heal" pass only runs once the queue has actually gone
 *    quiet, so polling stays cheap on hosts where the loopback works.
 */
abstract class AbstractBulkProcessor extends WP_Background_Process {

	use AttachmentHelper;

	/**
	 * Option holding the progress state. Overridden by every subclass.
	 */
	public const STATE_OPTION = '';

	/**
	 * Attachment IDs stored per queued batch option.
	 */
	protected const QUEUE_BATCH_SIZE = 50;

	/**
	 * How many finished items the activity log keeps.
	 */
	protected const RECENT_LIMIT = 50;

	/**
	 * Seconds of no progress before a poll takes over the queue itself.
	 */
	protected const STALL_SECONDS = 2;

	/**
	 * Seconds an inline (poll-driven) pass may spend working.
	 */
	protected const INLINE_TIME_LIMIT = 8;

	/**
	 * Consecutive failures that trip the circuit breaker.
	 */
	protected const FAILURE_LIMIT = 10;

	/**
	 * Hook prefix — hooks become omnioffloader_{action}(_cron).
	 *
	 * @var string
	 */
	protected $prefix = 'omnioffloader';

	/**
	 * Process lock lifetime, in seconds.
	 *
	 * Deliberately far above the parent's 60 second default: one task here is
	 * one cloud transfer, which routinely outlives a minute on a large file or
	 * a slow uplink. Renewed after every item by renew_lock().
	 *
	 * @var int
	 */
	protected $queue_lock_time = 600;

	/**
	 * The attachment IDs this run should process.
	 *
	 * @return int[]
	 */
	abstract protected function queue_ids();

	/**
	 * Do the actual work for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True on success.
	 */
	abstract protected function process_item( $attachment_id );

	/**
	 * Baseline progress state. Subclasses add their own keys.
	 *
	 * @return array<string, mixed>
	 */
	protected static function default_state() {
		return array(
			'status'       => 'idle',
			'total'        => 0,
			'processed'    => 0,
			'errors'       => 0,
			'updated_at'   => 0,
			'message'      => '',
			// Attachment currently in flight, 0 when idle. Used to detect a
			// worker that was killed mid-transfer.
			'current'      => 0,
			'current_item' => null,
			'recent'       => array(),
		);
	}

	/**
	 * Read the current progress state.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state() {
		$state = get_option( static::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge( static::default_state(), $state );
	}

	/**
	 * Merge values into the progress state.
	 *
	 * @param array<string, mixed> $values Values to merge.
	 * @return void
	 */
	public static function update_state( array $values ) {
		$state               = array_merge( static::get_state(), $values );
		$state['updated_at'] = time();

		// Progress heartbeat — never autoloaded.
		update_option( static::STATE_OPTION, $state, false );
	}

	/**
	 * Whether a bulk process is currently active.
	 *
	 * A run is active only if its state is 'running' and has items in queue
	 * or is actively processing.
	 *
	 * @return bool
	 */
	public function is_active() {
		$state = static::get_state();

		if ( 'running' !== ( $state['status'] ?? 'idle' ) ) {
			return false;
		}

		return ! $this->is_queue_empty() || $this->is_processing();
	}

	/**
	 * Queue every matching attachment and start processing.
	 *
	 * @param array<string, mixed> $extra_state Extra state keys for this run.
	 * @param int[]|null           $custom_ids  Optional specific attachment IDs to queue.
	 * @return int Number of queued attachments.
	 */
	protected function start_queue( array $extra_state = array(), ?array $custom_ids = null ) {
		$ids   = ( null !== $custom_ids ) ? array_values( array_unique( array_filter( array_map( 'intval', $custom_ids ) ) ) ) : $this->queue_ids();
		$total = count( $ids );

		if ( 0 === $total ) {
			static::update_state(
				array_merge(
					static::default_state(),
					$extra_state,
					array(
						'status'    => 'idle',
						'total'     => 0,
						'processed' => 0,
					)
				)
			);

			return 0;
		}

		// If a bulk queue is already active, append these items to the queue.
		if ( $this->is_active() ) {
			$current_state          = static::get_state();
			$current_state['total'] = (int) ( $current_state['total'] ?? 0 ) + $total;
			static::update_state( $current_state );

			$count = 0;

			foreach ( $ids as $id ) {
				$this->push_to_queue( (int) $id );
				++$count;

				if ( 0 === $count % static::QUEUE_BATCH_SIZE ) {
					$this->save();
				}
			}

			$this->save();
			$this->dispatch();

			return $total;
		}

		// Publish the run before writing the queue. Queueing a large library
		// is hundreds of option writes; if that request dies part-way, a
		// half-written queue with no state leaves the UI unable to show
		// progress *or* cancel, because is_active() already returns true.
		// A fresh run must always start with an empty log and zero counts.
		$initial_state = array_merge(
			static::default_state(),
			$extra_state,
			array(
				'status'       => 'running',
				'total'        => $total,
				'processed'    => 0,
				'errors'       => 0,
				'current'      => 0,
				'current_item' => null,
				'recent'       => array(),
				'updated_at'   => time(),
			)
		);
		update_option( static::STATE_OPTION, $initial_state, false );

		$count = 0;

		foreach ( $ids as $id ) {
			$this->push_to_queue( (int) $id );
			++$count;

			if ( 0 === $count % static::QUEUE_BATCH_SIZE ) {
				$this->save();
			}
		}

		$this->save();

		$this->dispatch();

		return $total;
	}

	/**
	 * Process part of the queue inside the current request.
	 *
	 * Self-healing for hosts where the async loopback request never arrives
	 * (local SSL, HTTP auth, Cloudflare Access, blocked loopbacks, WP-Cron
	 * disabled without a system cron): the REST progress endpoint calls this
	 * so the admin page's polling drives the queue when nothing else can.
	 *
	 * @return void
	 */
	public function process_queue_now() {
		if ( $this->is_processing() || $this->is_queue_empty() ) {
			return;
		}

		$state    = static::get_state();
		$idle_for = time() - (int) $state['updated_at'];

		// On a healthy host the background worker heartbeats the state after
		// every item, so polling stays a cheap read. Only take the queue over
		// once it has genuinely gone quiet — otherwise every 2 second poll
		// would hold a PHP worker for seconds and starve a small FPM pool.
		if ( $idle_for < static::STALL_SECONDS ) {
			return;
		}

		$inline_limit = (int) static::INLINE_TIME_LIMIT;

		$shorten = static function () use ( $inline_limit ) {
			return $inline_limit;
		};

		// handle() ends in wp_die() by default (it expects an ajax context);
		// the poll request must survive it.
		add_filter( $this->identifier . '_wp_die', '__return_false' );
		add_filter( $this->identifier . '_default_time_limit', $shorten );

		try {
			$this->handle();
		} finally {
			// handle() normally clears these itself; restore them here too so
			// a task that throws cannot leave the request in a state where
			// later stats flushes are silently swallowed.
			Helpers::defer_media_stats_flush( false );
			remove_filter( $this->identifier . '_default_time_limit', $shorten );
			remove_filter( $this->identifier . '_wp_die', '__return_false' );
		}
	}

	/**
	 * Work through the queue within the host's time and memory limits.
	 *
	 * Reimplemented from the parent so that items are claimed before they run
	 * and the lock is renewed as work proceeds.
	 *
	 * @return void|mixed
	 */
	protected function handle() {
		$this->lock_process();

		// A one-shot flush at the end of the pass is enough; busting the stats
		// cache per attachment costs eight queries an item for a value nobody
		// reads mid-run.
		Helpers::defer_media_stats_flush( true );

		$this->recover_lost_item();

		$streak  = 0;
		$limit   = $this->failure_limit();
		$aborted = false;

		do {
			$batch = $this->get_batch();

			// get_batch() yields an empty array, not an object, once nothing is
			// queued. Another worker draining the last batch — or a cancel
			// landing mid-pass — can put us here between the loop condition and
			// this read.
			if ( ! is_object( $batch ) || ! isset( $batch->data ) || ! is_array( $batch->data ) ) {
				break;
			}

			foreach ( $batch->data as $key => $value ) {
				// Claim the item: drop it from the persisted batch *before*
				// running it. Otherwise a task killed by max_execution_time
				// leaves the item queued and every later pass retries it, so a
				// single oversized file wedges the run permanently.
				unset( $batch->data[ $key ] );

				if ( empty( $batch->data ) ) {
					$this->delete( $batch->key );
				} else {
					$this->update( $batch->key, $batch->data );
				}

				$ok     = $this->run_item( (int) $value );
				$streak = $ok ? 0 : $streak + 1;

				if ( $limit > 0 && $streak >= $limit ) {
					$this->trip_breaker( $streak );
					$aborted = true;
					break;
				}

				$this->renew_lock();

				if ( $this->time_exceeded() || $this->memory_exceeded() || $this->is_paused() || $this->is_cancelled() ) {
					break;
				}
			}

			if ( empty( $batch->data ) ) {
				$this->delete( $batch->key );
			}
		} while ( ! $aborted && ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() && ! $this->is_paused() && ! $this->is_cancelled() );

		$this->unlock_process();

		Helpers::defer_media_stats_flush( false );

		if ( $aborted ) {
			return $this->maybe_wp_die();
		}

		if ( $this->is_cancelled() ) {
			// Tear the run down here rather than falling through to
			// complete(), which would report a cancelled run as finished.
			$this->clear_scheduled_event();
			$this->purge_queue();
			$this->cancelled();

			return $this->maybe_wp_die();
		}

		// Start the next pass, or finish.
		if ( ! $this->is_queue_empty() ) {
			$this->dispatch();
		} else {
			$this->complete();
		}

		return $this->maybe_wp_die();
	}

	/**
	 * Run one claimed item and record the outcome.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function run_item( $attachment_id ) {
		// Record the in-flight item so a worker killed mid-transfer is
		// detectable on the next pass (see recover_lost_item()).
		$attached_file = get_attached_file( $attachment_id );
		$filename      = $attached_file ? wp_basename( $attached_file ) : '';

		if ( '' === $filename ) {
			$post_title = get_the_title( $attachment_id );
			$filename   = '' !== $post_title ? $post_title : sprintf( 'Attachment #%d', $attachment_id );
		}

		$thumb_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		static::update_state(
			array(
				'current'      => $attachment_id,
				'current_item' => array(
					'id'        => $attachment_id,
					'title'     => $filename,
					'thumb'     => $thumb_url ? $thumb_url : '',
					'status'    => 'processing',
					'timestamp' => time(),
				),
			)
		);

		$ok = $this->process_item( $attachment_id );

		$this->record_result( $attachment_id, $ok );

		return $ok;
	}

	/**
	 * Process one queued item.
	 *
	 * Items are claimed out of the batch by handle(), which calls run_item()
	 * directly, so this only serves the parent's contract for callers that
	 * still route through it.
	 *
	 * @param mixed $item Queued attachment ID.
	 * @return false Always false — the item is never requeued.
	 */
	protected function task( $item ) {
		$this->run_item( (int) $item );

		return false;
	}

	/**
	 * Account for an item whose worker died before it reported a result.
	 *
	 * The item was already claimed out of the queue, so without this the run
	 * would finish with `processed` permanently short of `total` and no clue
	 * as to which file caused it.
	 *
	 * @return void
	 */
	private function recover_lost_item() {
		$state = static::get_state();
		$lost  = isset( $state['current'] ) ? (int) $state['current'] : 0;

		if ( $lost <= 0 ) {
			return;
		}

		$message = __( 'The server stopped part-way through this file — it is probably larger than the host allows in one request.', 'omnioffloader' );

		self::log_attachment_error( $lost, $message );
		$this->record_result( $lost, false, $message );
	}

	/**
	 * Append one finished item to the progress state.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param bool   $ok            Whether the item succeeded.
	 * @param string $message       Failure message; read from the attachment's
	 *                              error log when empty.
	 * @return void
	 */
	protected function record_result( $attachment_id, $ok, $message = '' ) {
		$state  = static::get_state();
		$recent = isset( $state['recent'] ) && is_array( $state['recent'] ) ? $state['recent'] : array();

		$attached_file = get_attached_file( $attachment_id );
		$filename      = $attached_file ? wp_basename( $attached_file ) : '';

		if ( '' === $filename ) {
			$post_title = get_the_title( $attachment_id );
			$filename   = '' !== $post_title ? $post_title : sprintf( 'Attachment #%d', $attachment_id );
		}

		if ( ! $ok && '' === $message ) {
			$message = self::last_error_message( $attachment_id );
		}

		$thumb_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		array_unshift(
			$recent,
			array(
				'id'        => $attachment_id,
				'title'     => $filename,
				'status'    => $ok ? 'success' : 'error',
				'timestamp' => time(),
				'thumb'     => $thumb_url ? $thumb_url : '',
				'error'     => $ok ? '' : $message,
			)
		);

		static::update_state(
			array(
				'processed'    => (int) $state['processed'] + 1,
				'errors'       => (int) $state['errors'] + ( $ok ? 0 : 1 ),
				'current'      => 0,
				'current_item' => null,
				'recent'       => array_slice( $recent, 0, static::RECENT_LIMIT ),
			)
		);

		clean_post_cache( $attachment_id );
	}

	/**
	 * The most recent message from an attachment's error log.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	protected static function last_error_message( $attachment_id ) {
		$log = get_post_meta( $attachment_id, 'omnioffloader_error_log', true );

		if ( ! is_array( $log ) || empty( $log ) ) {
			return '';
		}

		$last = end( $log );

		return is_array( $last ) && isset( $last['message'] ) ? (string) $last['message'] : '';
	}

	/**
	 * Consecutive failures allowed before the run gives up.
	 *
	 * @return int Zero disables the circuit breaker.
	 */
	protected function failure_limit() {
		/**
		 * Filter how many consecutive failures stop a bulk run.
		 *
		 * @param int    $limit  Consecutive failures. 0 disables the breaker.
		 * @param string $action Queue action ('bulk_offload' or 'bulk_restore').
		 */
		return (int) apply_filters( 'omnioffloader_bulk_failure_limit', static::FAILURE_LIMIT, $this->action );
	}

	/**
	 * Stop the run after too many consecutive failures.
	 *
	 * Misconfigured credentials or an unreachable bucket fail identically for
	 * every attachment; without this the user waits out the whole library to
	 * be told so, and every item leaves an error-log meta row behind.
	 *
	 * @param int $streak Consecutive failures observed.
	 * @return void
	 */
	protected function trip_breaker( $streak ) {
		static::update_state(
			array(
				'status'       => 'failed',
				'current'      => 0,
				'current_item' => null,
				'message'      => sprintf(
					/* translators: %d: number of consecutive failures. */
					_n(
						'Stopped after %d failure in a row. Check the connection to your storage provider, then try again.',
						'Stopped after %d failures in a row. Check the connection to your storage provider, then try again.',
						$streak,
						'omnioffloader'
					),
					$streak
				),
			)
		);

		// Purge directly rather than via delete_all(), which would fire
		// cancelled() and overwrite the 'failed' status.
		$this->purge_queue();
		$this->clear_scheduled_event();

		Helpers::flush_media_stats();
	}

	/**
	 * Drop every queued batch without signalling a cancellation.
	 *
	 * @return void
	 */
	protected function purge_queue() {
		foreach ( $this->get_batches() as $batch ) {
			$this->delete( $batch->key );
		}

		delete_site_option( $this->get_status_key() );
	}

	/**
	 * Hold the queue's process lock from outside a queue pass.
	 *
	 * Lets a long-running WP-CLI job stop the loopback worker, the cron
	 * healthcheck and the admin poll from processing the same attachments
	 * alongside it. Call repeatedly to keep the claim alive.
	 *
	 * @return void
	 */
	public function claim_lock() {
		$this->renew_lock();
	}

	/**
	 * Release a lock taken with claim_lock().
	 *
	 * @return void
	 */
	public function release_lock() {
		$this->unlock_process();
	}

	/**
	 * Extend the process lock without restarting the batch clock.
	 *
	 * The parent's lock_process() also resets $start_time, which would defeat
	 * time_exceeded() and let a pass run until the host kills it.
	 *
	 * @return void
	 */
	protected function renew_lock() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the parent's own filter name.
		$duration = (int) apply_filters( $this->identifier . '_queue_lock_time', $this->queue_lock_time );

		set_site_transient( $this->identifier . '_process_lock', microtime(), $duration );
	}

	/**
	 * Queue finished.
	 *
	 * @return void
	 */
	protected function completed() {
		parent::completed();

		$state = static::get_state();

		// A cancellation or a circuit-breaker abort has already had the last
		// word on this run; an in-flight worker finding an emptied queue must
		// not overwrite that with "done".
		if ( 'running' !== $state['status'] ) {
			return;
		}

		static::update_state(
			array(
				'status'       => 'done',
				'current'      => 0,
				'current_item' => null,
			)
		);

		Helpers::flush_media_stats();
	}

	/**
	 * Cancel the run and tear it down inside this request.
	 *
	 * The parent's cancel() only flags the run and fires a loopback request to
	 * do the cleanup. Where loopbacks are blocked that request never arrives,
	 * so the batches survive, is_active() stays true and the user can never
	 * start another run. Doing the teardown here removes that dependency.
	 *
	 * @return void
	 */
	public function cancel_now() {
		// Flag first, so a worker holding the lock right now sees the cancel
		// and stops instead of carrying on through the batch.
		update_site_option( $this->get_status_key(), self::STATUS_CANCELLED );

		$this->clear_scheduled_event();
		$this->purge_queue();
		$this->cancelled();

		delete_site_option( $this->get_status_key() );
		delete_site_transient( $this->identifier . '_process_lock' );
	}

	/**
	 * Queue cancelled.
	 *
	 * @return void
	 */
	protected function cancelled() {
		parent::cancelled();

		static::update_state(
			array(
				'status'       => 'cancelled',
				'current'      => 0,
				'current_item' => null,
			)
		);

		Helpers::flush_media_stats();
	}

	/**
	 * Delete everything a queue stores: batches, run status, process lock,
	 * healthcheck event and progress state.
	 *
	 * Static so deactivation and uninstall can clean up without booting the
	 * engine (which only loads when a provider is configured). Without it a
	 * plugin deactivated mid-run leaves its batch rows in wp_options forever.
	 *
	 * @param string $action       Queue action ('bulk_offload' or 'bulk_restore').
	 * @param string $state_option Progress state option name.
	 * @return void
	 */
	public static function purge_stored_data( $action, $state_option ) {
		global $wpdb;

		$identifier = 'omnioffloader_' . $action;
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
		wp_clear_scheduled_hook( $identifier . '_cron' );
		delete_option( $state_option );
	}
}
