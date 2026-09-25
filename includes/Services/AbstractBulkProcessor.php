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
 *
 * @since 0.1.1
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
	 * Failures in a row, as a multiple of the failure limit, that stop a run
	 * even while the provider still answers a connection check.
	 *
	 * A key that may read the bucket but not write to it passes the check and
	 * still fails every item; this bounds how far such a run goes.
	 */
	protected const UNEXPLAINED_FAILURE_FACTOR = 10;

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
	 * Whether this request holds the worker mutex.
	 *
	 * @var bool
	 */
	private $holds_mutex = false;

	/**
	 * Whether the current pass is the short one an admin poll runs inline.
	 *
	 * @var bool
	 */
	private $inline_pass = false;

	/**
	 * Seconds one background pass may run, fixed when the pass starts.
	 *
	 * @var int
	 */
	private $pass_seconds = 20;

	/**
	 * Display title and thumbnail of the item in flight, looked up once.
	 *
	 * @var array{id: int, title: string, thumb: string}|null
	 */
	private $item_label = null;

	/**
	 * Why the current run must stop, set by a subclass through halt_run().
	 *
	 * @var string
	 */
	private $halt_message = '';

	/**
	 * Activity-log messages for failed items, by attachment ID, when the
	 * reason is not in the attachment's error log.
	 *
	 * @var array<int, string>
	 */
	protected $item_messages = array();

	/**
	 * Constructor.
	 *
	 * @param bool|string[] $allowed_batch_data_classes See WP_Background_Process.
	 */
	public function __construct( $allowed_batch_data_classes = true ) {
		// Per-site on multisite (see Helpers::queue_prefix()); set before the
		// parent builds the identifier, hooks and option names from it.
		$this->prefix = Helpers::queue_prefix();

		parent::__construct( $allowed_batch_data_classes );
	}

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
	 * How many queued items one step claims and runs together.
	 *
	 * @since 0.1.3
	 *
	 * @return int
	 */
	protected function group_size() {
		return 1;
	}

	/**
	 * Whether an item must be claimed and run on its own, never in a group.
	 *
	 * A worker killed mid-group reports every item of the group as lost, so
	 * an item likely to outlast the host's time limit gets a group of one.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function runs_alone( $attachment_id ) {
		return false;
	}

	/**
	 * Whether a lost item's work had in fact completed before the worker died.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function was_completed( $attachment_id ) {
		return false;
	}

	/**
	 * Whether the storage an item was transferred with still answers.
	 *
	 * Asked once the failure limit is reached: when the provider answers, the
	 * failures belong to the attachments themselves (files missing on disk or
	 * in the bucket) and the run carries on. Without this, a stretch of broken
	 * attachments stopped the run, and because they stay queued first, every
	 * restart stopped on them again.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id The last attachment that failed.
	 * @return true|\WP_Error|null True when reachable, WP_Error when not, null
	 *                             when this queue cannot tell (the run stops).
	 */
	protected function connection_check( $attachment_id ) {
		return null;
	}

	/**
	 * Stop the run after the current group, with a message for the user.
	 *
	 * @since 0.1.3
	 *
	 * @param string $message Why the run stopped.
	 * @return void
	 */
	protected function halt_run( $message ) {
		$this->halt_message = (string) $message;
	}

	/**
	 * Do the work for a group of attachments.
	 *
	 * Subclasses that can share work between items (one transfer pool for
	 * several attachments) override this; the default runs them one by one.
	 *
	 * @since 0.1.3
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array<int, bool> Attachment ID => success.
	 */
	protected function process_items( array $ids ) {
		$results = array();

		foreach ( $ids as $id ) {
			$results[ $id ] = (bool) $this->process_item( $id );
		}

		return $results;
	}

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
			// Every attachment of the group in flight (see group_size()).
			'current_ids'  => array(),
			'current_item' => null,
			'recent'       => array(),
			// Failures in a row, kept across passes for the circuit breaker.
			'streak'       => 0,
			// Failures since the last success, including ones a connection
			// check explained away.
			'fail_run'     => 0,
		);
	}

	/**
	 * Read the current progress state.
	 *
	 * Explicitly busts the options cache for this key so polling across
	 * separate PHP processes/workers always observes real-time progress
	 * under persistent object caching (Redis/Memcached).
	 *
	 * @return array<string, mixed>
	 */
	public static function get_state() {
		if ( '' !== static::STATE_OPTION ) {
			wp_cache_delete( static::STATE_OPTION, 'options' );
		}

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

		if ( '' !== static::STATE_OPTION ) {
			wp_cache_set( static::STATE_OPTION, $state, 'options' );
		}
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
				'current_ids'  => array(),
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

		// One item per step: a group would keep this poll request busy for
		// several attachments' transfers, past the inline time limit.
		$this->inline_pass = true;

		try {
			$this->handle();
		} finally {
			$this->inline_pass = false;
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
		// The process lock below is a transient: checking it and setting it
		// are two separate steps, so the loopback worker, the cron healthcheck
		// and an admin poll could all pass is_processing() together and run
		// the same batch twice. A database lock is atomic; whoever does not
		// get it leaves the queue to the worker that did.
		if ( ! $this->acquire_worker_mutex() ) {
			return $this->maybe_wp_die();
		}

		try {
			return $this->run_pass();
		} finally {
			$this->release_worker_mutex();
		}
	}

	/**
	 * One pass over the queue, run while holding the worker mutex.
	 *
	 * @return void|mixed
	 */
	private function run_pass() {
		// Read before the first item: the uploader calls set_time_limit(0),
		// which rewrites max_execution_time for the rest of the request.
		$this->pass_seconds = $this->pass_time_limit();

		$this->lock_process();

		// A one-shot flush at the end of the pass is enough; busting the stats
		// cache per attachment costs eight queries an item for a value nobody
		// reads mid-run.
		Helpers::defer_media_stats_flush( true );

		$this->recover_lost_item();

		$aborted = false;
		$stopped = false;

		do {
			$batch = $this->get_batch();

			// get_batch() yields an empty array, not an object, once nothing is
			// queued. Another worker draining the last batch — or a cancel
			// landing mid-pass — can put us here between the loop condition and
			// this read.
			if ( ! is_object( $batch ) || ! isset( $batch->data ) || ! is_array( $batch->data ) ) {
				break;
			}

			$group_size = $this->inline_pass ? 1 : max( 1, (int) $this->group_size() );

			while ( ! empty( $batch->data ) ) {
				// A cancel from another request has the last word. It is read
				// from the progress state, which bypasses the cache: the
				// queue's own status flag is cached for the whole request, so
				// a cancel sent after this pass started went unseen, and the
				// claim below re-created the batch row the cancel had deleted.
				if ( $this->run_stopped() ) {
					$stopped = true;
					break;
				}

				// Claim the items: drop them from the persisted batch *before*
				// running them. Otherwise a task killed by max_execution_time
				// leaves them queued and every later pass retries them, so a
				// single oversized file wedges the run permanently.
				$group       = $this->next_group( $batch->data, $group_size );
				$batch->data = array_slice( $batch->data, count( $group ), null, true );

				if ( empty( $batch->data ) ) {
					$this->delete( $batch->key );
				} else {
					$this->update( $batch->key, $batch->data );
				}

				$verdict = $this->breaker_verdict( $this->run_group( $group ) );

				if ( '' !== $this->halt_message ) {
					$verdict            = $this->halt_message;
					$this->halt_message = '';
				}

				if ( '' !== $verdict ) {
					$this->trip_breaker( $verdict );
					$aborted = true;
					break;
				}

				$this->renew_lock();

				if ( $this->time_exceeded() || $this->memory_exceeded() || $this->is_paused() || $this->is_cancelled() ) {
					break;
				}
			}

			if ( $stopped ) {
				break;
			}

			if ( empty( $batch->data ) ) {
				$this->delete( $batch->key );
			}
		} while ( ! $aborted && ! $this->time_exceeded() && ! $this->memory_exceeded() && ! $this->is_queue_empty() && ! $this->is_paused() && ! $this->is_cancelled() );

		$this->unlock_process();

		// Before dispatch(): the next pass's loopback request must be able to
		// take the mutex while this process is still shutting down.
		$this->release_worker_mutex();

		Helpers::defer_media_stats_flush( false );

		if ( $aborted ) {
			return $this->maybe_wp_die();
		}

		if ( $stopped ) {
			// The request that stopped the run has reported it already; only
			// make sure nothing of it is left for a later pass to pick up.
			$this->clear_scheduled_event();
			$this->purge_queue();

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
	 * Database lock name for this queue (MySQL caps names at 64 chars).
	 *
	 * @return string
	 */
	private function worker_mutex_name() {
		global $wpdb;

		return 'omnioffloader:' . md5( $wpdb->prefix . $this->identifier );
	}

	/**
	 * Take the queue's worker mutex without waiting.
	 *
	 * Session-scoped, so a worker killed mid-pass releases it with its
	 * database connection. Databases without GET_LOCK() fall back to the
	 * transient process lock alone.
	 *
	 * @return bool False when another worker holds it.
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
	 * Run one claimed item and record the outcome.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function run_item( $attachment_id ) {
		$results = $this->run_group( array( (int) $attachment_id ) );

		return ! empty( $results[ (int) $attachment_id ] );
	}

	/**
	 * The next items to claim from a batch.
	 *
	 * Up to $size items from the front of the batch; an item that must run
	 * alone (see runs_alone()) always forms a group of one.
	 *
	 * @since 0.1.3
	 *
	 * @param array<int|string, mixed> $data Batch items.
	 * @param int                      $size Maximum group size.
	 * @return int[] Non-empty when $data is.
	 */
	private function next_group( array $data, $size ) {
		$group = array();

		foreach ( $data as $value ) {
			$id = (int) $value;

			if ( empty( $group ) ) {
				$group[] = $id;

				if ( 1 === $size || $this->runs_alone( $id ) ) {
					break;
				}

				continue;
			}

			if ( count( $group ) >= $size || $this->runs_alone( $id ) ) {
				break;
			}

			$group[] = $id;
		}

		return $group;
	}

	/**
	 * Run a claimed group of items and record each outcome.
	 *
	 * @since 0.1.3
	 *
	 * @param int[] $ids Attachment IDs, already claimed out of the batch.
	 * @return array<int, bool> Attachment ID => success, in $ids order.
	 */
	private function run_group( array $ids ) {
		// Record the in-flight items so a worker killed mid-transfer is
		// detectable on the next pass (see recover_lost_item()). The
		// activity log shows the first of them as "processing".
		$label = $this->item_label( $ids[0] );

		static::update_state(
			array(
				'current'      => $ids[0],
				'current_ids'  => $ids,
				'current_item' => array(
					'id'        => $ids[0],
					'title'     => $label['title'],
					'thumb'     => $label['thumb'],
					'status'    => 'processing',
					'timestamp' => time(),
				),
			)
		);

		$results = $this->process_items( $ids );
		$ordered = array();

		foreach ( $ids as $id ) {
			$ordered[ $id ] = ! empty( $results[ $id ] );
		}

		// One state write for the whole group; it also clears current_ids,
		// so a worker dying before it leaves every item to recover_lost_item().
		$this->record_results( $ordered );

		return $ordered;
	}

	/**
	 * Whether the run was cancelled or stopped outside this worker.
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	private function run_stopped() {
		return 'running' !== static::get_state()['status'];
	}

	/**
	 * Decide whether the failures so far should stop the run.
	 *
	 * @since 0.1.3
	 *
	 * @param array<int, bool> $outcomes Attachment ID => success, of the group just recorded.
	 * @return string Why the run stops, or '' to carry on.
	 */
	private function breaker_verdict( array $outcomes ) {
		$limit = $this->failure_limit();

		if ( $limit <= 0 || ! in_array( false, $outcomes, true ) ) {
			return '';
		}

		$state    = static::get_state();
		$streak   = (int) $state['streak'];
		$fail_run = (int) $state['fail_run'];

		if ( $streak < $limit ) {
			return '';
		}

		if ( $fail_run < $limit * static::UNEXPLAINED_FAILURE_FACTOR ) {
			$last_failed = (int) array_search( false, array_reverse( $outcomes, true ), true );
			$check       = $this->connection_check( $last_failed );

			if ( true === $check ) {
				// The provider answers, so the attachments are at fault, not
				// the connection. Their errors are logged; keep going.
				static::update_state( array( 'streak' => 0 ) );

				return '';
			}

			if ( is_wp_error( $check ) ) {
				return sprintf(
					/* translators: 1: number of consecutive failures, 2: error from the storage provider. */
					_n(
						'Stopped after %1$d failure in a row: the storage provider could not be reached (%2$s). Check the connection, then try again.',
						'Stopped after %1$d failures in a row: the storage provider could not be reached (%2$s). Check the connection, then try again.',
						$fail_run,
						'omnioffloader'
					),
					$fail_run,
					$check->get_error_message()
				);
			}
		}

		return sprintf(
			/* translators: %d: number of consecutive failures. */
			_n(
				'Stopped after %d failure in a row. Check the connection to your storage provider, then try again.',
				'Stopped after %d failures in a row. Check the connection to your storage provider, then try again.',
				$fail_run,
				'omnioffloader'
			),
			$fail_run
		);
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
		$lost  = array_values( array_filter( array_map( 'intval', (array) $state['current_ids'] ) ) );

		// States written before groups existed only carry 'current'.
		if ( empty( $lost ) && (int) $state['current'] > 0 ) {
			$lost = array( (int) $state['current'] );
		}

		if ( empty( $lost ) ) {
			return;
		}

		$message  = __( 'The server stopped part-way through this file — it is probably larger than the host allows in one request.', 'omnioffloader' );
		$outcomes = array();

		foreach ( $lost as $id ) {
			// Finished before the worker died (e.g. killed while a later
			// item of its group was being recorded): count it as done.
			if ( $this->was_completed( $id ) ) {
				$outcomes[ $id ] = true;
				continue;
			}

			self::log_attachment_error( $id, $message );
			// Not "processing" any more, or the Media Library spins forever.
			update_post_meta( $id, 'omnioffloader_status', 'failed' );

			$outcomes[ $id ]            = false;
			$this->item_messages[ $id ] = $message;
		}

		// An oversized file says nothing about the connection.
		$this->record_results( $outcomes, false );
	}

	/**
	 * Append finished items to the progress state, in one write.
	 *
	 * @since 0.1.3 Replaces record_result(), which wrote the state per item.
	 *
	 * @param array<int, bool> $outcomes     Attachment ID => success.
	 * @param bool             $track_streak Whether the outcomes feed the circuit breaker.
	 * @return void
	 */
	protected function record_results( array $outcomes, $track_streak = true ) {
		$state    = static::get_state();
		$recent   = isset( $state['recent'] ) && is_array( $state['recent'] ) ? $state['recent'] : array();
		$streak   = (int) $state['streak'];
		$fail_run = (int) $state['fail_run'];
		$errors   = 0;

		foreach ( $outcomes as $attachment_id => $ok ) {
			$label   = $this->item_label( $attachment_id );
			$message = $this->item_messages[ $attachment_id ] ?? '';

			if ( ! $ok && '' === $message ) {
				$message = self::last_error_message( $attachment_id );
			}

			array_unshift(
				$recent,
				array(
					'id'        => $attachment_id,
					'title'     => $label['title'],
					'status'    => $ok ? 'success' : 'error',
					'timestamp' => time(),
					'thumb'     => $label['thumb'],
					'error'     => $ok ? '' : $message,
				)
			);

			if ( ! $ok ) {
				++$errors;
			}

			if ( $track_streak ) {
				$streak   = $ok ? 0 : $streak + 1;
				$fail_run = $ok ? 0 : $fail_run + 1;
			}

			clean_post_cache( $attachment_id );
		}

		// The next item needs its own lookup.
		$this->item_label    = null;
		$this->item_messages = array();

		static::update_state(
			array(
				'processed'    => (int) $state['processed'] + count( $outcomes ),
				'errors'       => (int) $state['errors'] + $errors,
				'streak'       => $streak,
				'fail_run'     => $fail_run,
				'current'      => 0,
				'current_ids'  => array(),
				'current_item' => null,
				'recent'       => array_slice( $recent, 0, static::RECENT_LIMIT ),
			)
		);
	}

	/**
	 * Display title and thumbnail URL for the activity log.
	 *
	 * Looked up once per item and reused for both the "processing" and the
	 * finished entry — building the thumbnail URL runs the URL filters.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{id: int, title: string, thumb: string}
	 */
	private function item_label( $attachment_id ) {
		if ( null !== $this->item_label && $attachment_id === $this->item_label['id'] ) {
			return $this->item_label;
		}

		$attached_file = get_attached_file( $attachment_id );
		$filename      = $attached_file ? wp_basename( $attached_file ) : '';

		if ( '' === $filename ) {
			$post_title = get_the_title( $attachment_id );
			$filename   = '' !== $post_title ? $post_title : sprintf(
				/* translators: %d: attachment ID. */
				__( 'Attachment #%d', 'omnioffloader' ),
				$attachment_id
			);
		}

		$thumb_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		$this->item_label = array(
			'id'    => $attachment_id,
			'title' => $filename,
			'thumb' => $thumb_url ? $thumb_url : '',
		);

		return $this->item_label;
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
		 * @param string $action Queue action ('bulk_offload', 'bulk_restore' or 'bulk_retention').
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
	 * @since 0.1.3 Takes the message instead of the failure count.
	 *
	 * @param string $message Why the run stopped.
	 * @return void
	 */
	protected function trip_breaker( $message ) {
		static::update_state(
			array(
				'status'       => 'failed',
				'current'      => 0,
				'current_ids'  => array(),
				'current_item' => null,
				'message'      => (string) $message,
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
		$ids = array_map( 'intval', (array) static::get_state()['current_ids'] );

		foreach ( $this->get_batches() as $batch ) {
			if ( is_array( $batch->data ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $batch->data ) );
			}

			$this->delete( $batch->key );
		}

		delete_site_option( $this->get_status_key() );

		self::clear_pending_status( $ids );
	}

	/**
	 * Drop the "queued" / "processing" status of items a stopped run will
	 * never reach, so the Media Library stops showing them as in progress.
	 *
	 * @since 0.1.3
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	protected static function clear_pending_status( array $ids ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one delete per 500 IDs instead of one per attachment; placeholders are generated above, the meta cache is cleared below.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'omnioffloader_status' AND meta_value IN ( 'queued', 'processing' ) AND post_id IN ( {$placeholders} )", $chunk ) );

			foreach ( $chunk as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
		}
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
	 * Seconds one background pass may run before handing over to the next.
	 *
	 * Every pass boots WordPress, the SDK and a fresh TLS connection again,
	 * so longer passes cost less CPU in total. The parent's fixed 20 seconds
	 * suits a 30 second host limit; where the host allows more, use up to 40
	 * while leaving a 20 second margin for the file in flight.
	 *
	 * @since 0.1.3
	 *
	 * @return int
	 */
	protected function pass_time_limit() {
		$max = (int) ini_get( 'max_execution_time' );

		if ( $max <= 0 ) {
			// No PHP limit, but the web server may still have one (nginx
			// and FPM commonly stop at 60 seconds).
			return 30;
		}

		return max( 20, min( 40, $max - 20 ) );
	}

	/**
	 * Whether the pass has used up its time.
	 *
	 * Same as the parent, with pass_time_limit() as the default. The
	 * `{identifier}_default_time_limit` filter still wins, which is how the
	 * inline pass in process_queue_now() stays short.
	 *
	 * @return bool
	 */
	protected function time_exceeded() {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the parent's own filter name.
		$limit = (int) apply_filters( $this->identifier . '_default_time_limit', $this->pass_seconds );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- the parent's own filter name.
		return (bool) apply_filters( $this->identifier . '_time_exceeded', time() >= $this->start_time + $limit );
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
				'current_ids'  => array(),
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
				'current_ids'  => array(),
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

		$identifier = Helpers::queue_prefix() . '_' . $action;
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
