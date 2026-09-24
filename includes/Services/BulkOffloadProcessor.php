<?php
/**
 * Background bulk-offload queue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkOffloadProcessor
 *
 * Processes queued attachment IDs in the background (async loopback requests +
 * cron healthcheck, via WP_Background_Process). Progress is heartbeat-written
 * to the `omnioffloader_bulk_offload_state` option, which the React Offload
 * page polls. See AbstractBulkProcessor for the queue hardening.
 */
class BulkOffloadProcessor extends AbstractBulkProcessor {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'omnioffloader_bulk_offload_state';

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
	 * Queue attachments and start processing.
	 *
	 * @since 0.1.0
	 * @since 0.1.1 Added $ids parameter for partial/selected offload.
	 * @since 0.1.2 Added $limit parameter for custom batch offload.
	 *
	 * @param int[]|null $ids   Optional specific attachment IDs to queue.
	 * @param int        $limit Optional maximum number of attachments to queue. 0 for all.
	 * @return int Number of queued attachments.
	 */
	public function start( ?array $ids = null, int $limit = 0 ) {
		if ( null !== $ids ) {
			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}

			$valid_ids = array();
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( 'attachment' === get_post_type( $id ) && ! self::is_offloaded( $id ) ) {
					update_post_meta( $id, 'omnioffloader_status', 'queued' );
					$valid_ids[] = $id;
				}
			}

			return $this->start_queue( array(), $valid_ids );
		}

		if ( $limit > 0 ) {
			$all_ids    = $this->queue_ids();
			$sliced_ids = array_slice( $all_ids, 0, $limit );
			if ( count( $sliced_ids ) <= 500 ) {
				foreach ( $sliced_ids as $id ) {
					update_post_meta( $id, 'omnioffloader_status', 'queued' );
				}
			}

			return $this->start_queue( array(), $sliced_ids );
		}

		return $this->start_queue();
	}

	/**
	 * Attachments that still need offloading.
	 *
	 * Trashed attachments and rows with no file on disk are skipped — each one
	 * would otherwise burn a task and land in the error log.
	 *
	 * @return int[]
	 */
	protected function queue_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk queue population query.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
			 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND m.post_id IS NULL
			 ORDER BY p.ID ASC"
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Offload one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function process_item( $attachment_id ) {
		$uploader = new AttachmentUploader( $this->provider );

		return $uploader->upload( $attachment_id );
	}

	/**
	 * Attachments claimed and offloaded together.
	 *
	 * @since 0.1.3
	 *
	 * @return int
	 */
	protected function group_size() {
		return AttachmentUploader::batch_size();
	}

	/**
	 * Attachments with a file big enough for a multipart upload run alone.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function runs_alone( $attachment_id ) {
		return ( new AttachmentUploader( $this->provider ) )->has_large_file( $attachment_id );
	}

	/**
	 * A lost item counts as done when it did get offloaded.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function was_completed( $attachment_id ) {
		return self::is_offloaded( $attachment_id );
	}

	/**
	 * Offload a group of attachments through one shared transfer pool.
	 *
	 * @since 0.1.3
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array<int, bool>
	 */
	protected function process_items( array $ids ) {
		$uploader = new AttachmentUploader( $this->provider );

		return $uploader->upload_many( $ids );
	}
}
