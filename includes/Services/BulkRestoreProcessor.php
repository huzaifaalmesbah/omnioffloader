<?php
/**
 * Background bulk-restore (Bring Back) queue.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Services;

use SmartMediaOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use SmartMediaOffloader\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkRestoreProcessor
 *
 * Processes queued attachment IDs in the background (async loopback
 * requests + cron healthcheck, via WP_Background_Process). Progress is
 * heartbeat-written to the `smowp_bulk_restore_state` option, which the
 * React Bring Back page polls. The per-run "also delete from cloud"
 * choice is stored in the state so every batch honors it.
 */
class BulkRestoreProcessor extends WP_Background_Process {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'smowp_bulk_restore_state';

	/**
	 * Hook prefix.
	 *
	 * @var string
	 */
	protected $prefix = 'smowp';

	/**
	 * Action name — hooks become smowp_bulk_restore(_cron).
	 *
	 * @var string
	 */
	protected $action = 'bulk_restore';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array() );
	}

	/**
	 * Read the current progress state.
	 *
	 * @return array{status: string, total: int, processed: int, errors: int, delete_cloud: bool, updated_at: int}
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge(
			array(
				'status'       => 'idle',
				'total'        => 0,
				'processed'    => 0,
				'errors'       => 0,
				'delete_cloud' => false,
				'updated_at'   => 0,
			),
			$state
		);
	}

	/**
	 * Merge values into the progress state.
	 *
	 * @param array<string, int|string|bool> $values Values to merge.
	 * @return void
	 */
	public static function update_state( array $values ) {
		$state               = array_merge( self::get_state(), $values );
		$state['updated_at'] = time();

		// Progress heartbeat — never autoloaded.
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Queue every offloaded attachment and start processing.
	 *
	 * @param bool $delete_cloud Also delete cloud objects after each verified restore.
	 * @return int Number of queued attachments.
	 */
	public function start( $delete_cloud = false ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk queue population query.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'smowp_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			$this->push_to_queue( (int) $id );
		}

		self::update_state(
			array(
				'status'       => 'running',
				'total'        => count( $ids ),
				'processed'    => 0,
				'errors'       => 0,
				'delete_cloud' => (bool) $delete_cloud,
			)
		);

		$this->save()->dispatch();

		return count( $ids );
	}

	/**
	 * Process part of the queue synchronously.
	 *
	 * Self-healing for hosts where the async loopback request fails
	 * (local SSL, blocked loopbacks): the REST progress endpoint calls
	 * this on every poll, so the UI's polling drives the queue whenever
	 * the background dispatch cannot.
	 *
	 * @return void
	 */
	public function process_queue_now() {
		if ( $this->is_processing() || $this->is_queue_empty() ) {
			return;
		}

		// Keep inline batches short — this runs inside a poll request.
		$shorten = static function () {
			return 8;
		};

		// handle() ends in wp_die() by default (it expects an ajax
		// context); the poll request must survive it.
		add_filter( $this->identifier . '_wp_die', '__return_false' );
		add_filter( $this->identifier . '_default_time_limit', $shorten );

		$this->handle();

		remove_filter( $this->identifier . '_default_time_limit', $shorten );
		remove_filter( $this->identifier . '_wp_die', '__return_false' );
	}

	/**
	 * Process one queued attachment.
	 *
	 * @param mixed $item Queued attachment ID.
	 * @return false Always false — item is never requeued.
	 */
	protected function task( $item ) {
		$attachment_id = (int) $item;
		$state         = self::get_state();

		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, ! empty( $state['delete_cloud'] ) );

		self::update_state(
			array(
				'processed' => (int) $state['processed'] + 1,
				'errors'    => (int) $state['errors'] + ( $ok ? 0 : 1 ),
			)
		);

		return false;
	}

	/**
	 * Queue finished.
	 *
	 * @return void
	 */
	protected function completed() {
		parent::completed();

		self::update_state( array( 'status' => 'done' ) );
		Helpers::flush_media_stats();
	}

	/**
	 * Queue cancelled.
	 *
	 * @return void
	 */
	protected function cancelled() {
		parent::cancelled();

		self::update_state( array( 'status' => 'cancelled' ) );
		Helpers::flush_media_stats();
	}
}
