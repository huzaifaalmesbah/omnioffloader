<?php
/**
 * Background bulk-restore (Bring Back) queue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\SafeMode;

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
	 * their objects in the bucket after a full Bring Back. Attachments that
	 * failed before go last, so a new run is not held up by them.
	 *
	 * @return int[]
	 */
	protected function queue_ids() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk queue population query.
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = 'omnioffloader_status' AND s.meta_value = 'failed'
			 WHERE p.post_type = 'attachment'
			 ORDER BY s.post_id IS NOT NULL, p.ID ASC"
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
		// Safe mode turned on (or Bring Back disallowed) mid-run.
		if ( SafeMode::blocks_bring_back() ) {
			$this->item_messages[ $attachment_id ] = __( 'Bring Back is turned off in Safe mode.', 'omnioffloader' );
			$this->halt_run( SafeMode::bring_back_message() );

			return false;
		}

		// Brought back some other way since it was queued (single Bring
		// Back, or queued twice): nothing left to do.
		if ( ! self::is_offloaded( $attachment_id ) ) {
			return true;
		}

		$short = AttachmentRestorer::disk_shortfall( $attachment_id );

		if ( $short > 0 ) {
			// Every later download would fail the same way; stop with the
			// real reason instead of tripping the breaker on "connection".
			// The attachment stays offloaded, so the next run retries it.
			$this->item_messages[ $attachment_id ] = __( 'Not enough free disk space.', 'omnioffloader' );
			$this->halt_run(
				sprintf(
					/* translators: %s: amount of disk space, e.g. "120 MB". */
					__( 'Stopped: the server is running out of disk space (%s more is needed for the next attachment). Free up space, then start Bring Back again.', 'omnioffloader' ),
					size_format( $short )
				)
			);

			return false;
		}

		$state    = static::get_state();
		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, ! empty( $state['delete_cloud'] ) );

		// The free space checked above can be the whole server disk while a
		// hosting quota is already used up; a refused write is the real signal.
		if ( ! $ok && $restorer->ran_out_of_disk() ) {
			$this->item_messages[ $attachment_id ] = __( 'Out of disk space or storage quota.', 'omnioffloader' );
			$this->halt_run( self::out_of_disk_message() );
		}

		return $ok;
	}

	/**
	 * Why a run stopped when the server refused to write a download.
	 *
	 * @since 0.1.3
	 *
	 * @return string
	 */
	public static function out_of_disk_message() {
		return __( 'Stopped: the server ran out of disk space, or the hosting plan\'s storage quota is used up. Free up space (or ask your host for more), then start again. Nothing was lost: the attachments that were not brought back are still served from the cloud.', 'omnioffloader' );
	}

	/**
	 * Whether the storage the attachment was offloaded to still answers.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id The last attachment that failed.
	 * @return true|\WP_Error|null Null when its provider is not available.
	 */
	protected function connection_check( $attachment_id ) {
		$provider = AttachmentRestorer::provider_for( $attachment_id );

		return null === $provider ? null : $provider->check_connection();
	}

	/**
	 * Drop partial downloads a killed worker left next to the attachment.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	protected function clean_up_lost_item( $attachment_id ) {
		AttachmentRestorer::remove_partial_downloads( $attachment_id );
	}

	/**
	 * A lost item counts as done when it did get restored.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function was_completed( $attachment_id ) {
		return ! self::is_offloaded( $attachment_id );
	}
}
