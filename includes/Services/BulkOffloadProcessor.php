<?php
/**
 * Background bulk-offload queue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Abstracts\WP_Background_Processing\WP_Background_Process;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkOffloadProcessor
 *
 * Processes queued attachment IDs in the background (async loopback
 * requests + cron healthcheck, via WP_Background_Process). Progress is
 * heartbeat-written to the `omnioffloader_bulk_offload_state` option, which the
 * React Offload page polls.
 */
class BulkOffloadProcessor extends WP_Background_Process {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'omnioffloader_bulk_offload_state';

	/**
	 * Hook prefix.
	 *
	 * @var string
	 */
	protected $prefix = 'omnioffloader';

	/**
	 * Action name — hooks become omnioffloader_bulk_offload(_cron).
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
	 * @return array{status: string, total: int, processed: int, errors: int, updated_at: int, recent: array<int, array{id: int, title: string, status: string, timestamp: int, thumb: string, error: string}>}
	 */
	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		$merged = array_merge(
			array(
				'status'     => 'idle',
				'total'      => 0,
				'processed'  => 0,
				'errors'     => 0,
				'updated_at' => 0,
				'recent'     => array(),
			),
			$state
		);

		return $merged;
	}

	/**
	 * Merge values into the progress state.
	 *
	 * @param array<string, mixed> $values Values to merge.
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
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
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
				'recent'    => array(),
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

		$state  = self::get_state();
		$recent = isset( $state['recent'] ) && is_array( $state['recent'] ) ? $state['recent'] : array();

		$attached_file = get_attached_file( $attachment_id );
		$filename      = $attached_file ? basename( $attached_file ) : '';
		if ( empty( $filename ) ) {
			$post_title = get_the_title( $attachment_id );
			$filename   = ! empty( $post_title ) ? $post_title : sprintf( 'Attachment #%d', $attachment_id );
		}

		$thumb_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );

		$entry = array(
			'id'        => $attachment_id,
			'title'     => $filename,
			'status'    => $ok ? 'success' : 'error',
			'timestamp' => time(),
			'thumb'     => $thumb_url ? $thumb_url : '',
			'error'     => $ok ? '' : (string) get_post_meta( $attachment_id, '_omnioffloader_last_error', true ),
		);

		array_unshift( $recent, $entry );
		$recent = array_slice( $recent, 0, 50 );

		self::update_state(
			array(
				'processed' => (int) $state['processed'] + 1,
				'errors'    => (int) $state['errors'] + ( $ok ? 0 : 1 ),
				'recent'    => $recent,
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
