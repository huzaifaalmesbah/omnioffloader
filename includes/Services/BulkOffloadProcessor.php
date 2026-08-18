<?php
/**
 * Background bulk-offload queue.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Services;

use SmartMediaOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use SmartMediaOffloader\Core\Helpers;
use SmartMediaOffloader\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkOffloadProcessor
 *
 * Processes queued attachment IDs in the background (async loopback
 * requests + cron healthcheck, via WP_Background_Process). Progress is
 * heartbeat-written to the `smowp_bulk_offload_state` option, which the
 * React Offload page polls.
 */
class BulkOffloadProcessor extends WP_Background_Process {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'smowp_bulk_offload_state';

	/**
	 * Hook prefix.
	 *
	 * @var string
	 */
	protected $prefix = 'smowp';

	/**
	 * Action name — hooks become smowp_bulk_offload(_cron).
	 *
	 * @var string
	 */
	protected $action = 'bulk_offload';

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
		parent::__construct( array() );
		$this->provider = $provider;
	}

	/**
	 * Read the current progress state.
	 *
	 * @return array{status: string, total: int, processed: int, errors: int, updated_at: int}
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		return array_merge(
			array(
				'status'     => 'idle',
				'total'      => 0,
				'processed'  => 0,
				'errors'     => 0,
				'updated_at' => 0,
			),
			$state
		);
	}

	/**
	 * Merge values into the progress state.
	 *
	 * @param array<string, int|string> $values Values to merge.
	 * @return void
	 */
	public static function update_state( array $values ) {
		$state               = array_merge( self::get_state(), $values );
		$state['updated_at'] = time();

		// Progress heartbeat — never autoloaded.
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Queue every non-offloaded attachment and start processing.
	 *
	 * @return int Number of queued attachments.
	 */
	public function start() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk queue population query.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'smowp_offloaded'
			 WHERE p.post_type = 'attachment' AND m.post_id IS NULL
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
				'status'    => 'running',
				'total'     => count( $ids ),
				'processed' => 0,
				'errors'    => 0,
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

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		$state = self::get_state();

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
