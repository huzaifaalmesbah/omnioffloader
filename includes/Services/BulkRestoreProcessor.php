<?php
/**
 * Background bulk-restore (Bring Back) queue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkRestoreProcessor
 *
 * Processes queued attachment IDs in the background (async loopback requests +
 * cron healthcheck, via WP_Background_Process). Progress is heartbeat-written
 * to the `omnioffloader_bulk_restore_state` option, which the React Bring Back
 * page polls. The per-run "also delete from cloud" choice is stored in the
 * state so every batch honors it. See AbstractBulkProcessor for the queue
 * hardening.
 */
class BulkRestoreProcessor extends AbstractBulkProcessor {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'omnioffloader_bulk_restore_state';

	/**
	 * Action name — hooks become omnioffloader_bulk_restore(_cron).
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
	 * Baseline progress state plus the per-run cloud-delete choice.
	 *
	 * @return array<string, mixed>
	 */
	protected static function default_state() {
		return array_merge(
			parent::default_state(),
			array( 'delete_cloud' => false )
		);
	}

	/**
	 * Queue offloaded attachments and start processing.
	 *
	 * @since 0.1.0
	 * @since 0.1.1 Added $ids parameter for partial/selected restore.
	 * @since 0.1.2 Added $limit parameter for custom batch restore.
	 *
	 * @param bool       $delete_cloud Also delete cloud objects after each verified restore.
	 * @param int[]|null $ids          Optional specific attachment IDs to queue.
	 * @param int        $limit        Optional maximum number of attachments to queue. 0 for all.
	 * @return int Number of queued attachments.
	 */
	public function start( $delete_cloud = false, ?array $ids = null, int $limit = 0 ) {
		if ( null !== $ids ) {
			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}

			$valid_ids = array();
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( 'attachment' === get_post_type( $id ) && self::is_offloaded( $id ) ) {
					update_post_meta( $id, 'omnioffloader_status', 'queued' );
					$valid_ids[] = $id;
				}
			}

			return $this->start_queue( array( 'delete_cloud' => (bool) $delete_cloud ), $valid_ids );
		}

		if ( $limit > 0 ) {
			$all_ids    = $this->queue_ids();
			$sliced_ids = array_slice( $all_ids, 0, $limit );
			if ( count( $sliced_ids ) <= 500 ) {
				foreach ( $sliced_ids as $id ) {
					update_post_meta( $id, 'omnioffloader_status', 'queued' );
				}
			}

			return $this->start_queue( array( 'delete_cloud' => (bool) $delete_cloud ), $sliced_ids );
		}

		return $this->start_queue( array( 'delete_cloud' => (bool) $delete_cloud ) );
	}

	/**
	 * Every offloaded attachment.
	 *
	 * Trashed attachments are included on purpose: skipping them would strand
	 * their objects in the bucket after a full Bring Back.
	 *
	 * @return int[]
	 */
	protected function queue_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk queue population query.
		$ids = $wpdb->get_col(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 WHERE p.post_type = 'attachment'
			 ORDER BY p.ID ASC"
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Restore one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function process_item( $attachment_id ) {
		$state    = static::get_state();
		$restorer = new AttachmentRestorer();

		return $restorer->restore( $attachment_id, ! empty( $state['delete_cloud'] ) );
	}
}
