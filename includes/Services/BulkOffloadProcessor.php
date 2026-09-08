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
	 * Secondary backup cloud provider.
	 *
	 * @var ProviderInterface|null
	 */
	private $secondary_provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface      $provider           Primary cloud provider.
	 * @param ProviderInterface|null $secondary_provider Optional secondary backup provider.
	 */
	public function __construct( ProviderInterface $provider, ?ProviderInterface $secondary_provider = null ) {
		parent::__construct( array() );
		$this->provider           = $provider;
		$this->secondary_provider = $secondary_provider;
	}

	/**
	 * Queue every non-offloaded attachment and start processing.
	 *
	 * @return int Number of queued attachments.
	 */
	public function start() {
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
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
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
		$uploader = new AttachmentUploader( $this->provider, $this->secondary_provider );

		return $uploader->upload( $attachment_id );
	}
}
