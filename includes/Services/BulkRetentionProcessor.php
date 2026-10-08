<?php
/**
 * Background queue that applies the retention policy to existing media.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\SafeMode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkRetentionProcessor
 *
 * Brings already-offloaded attachments in line with a retention policy
 * chosen after they were offloaded (see RetentionApplier). The target policy
 * is fixed in the state when the run starts, so changing the setting mid-run
 * cannot flip the direction of later batches. See AbstractBulkProcessor for
 * the queue hardening.
 *
 * @since 0.1.3
 */
class BulkRetentionProcessor extends AbstractBulkProcessor {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'omnioffloader_bulk_retention_state';

	/**
	 * Action name — hooks become omnioffloader_bulk_retention(_cron).
	 *
	 * @var string
	 */
	protected $action = 'bulk_retention';

	/**
	 * Applier reused across items so provider clients are built once.
	 *
	 * @var RetentionApplier|null
	 */
	private $applier = null;

	/**
	 * The run's target policy, for runs_alone().
	 *
	 * @var int|null
	 */
	private $group_policy = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array() );
	}

	/**
	 * Baseline progress state plus the run's target policy and totals.
	 *
	 * @return array<string, mixed>
	 */
	protected static function default_state() {
		return array_merge(
			parent::default_state(),
			array(
				'policy'      => 0,
				'freed_bytes' => 0,
				'repaired'    => 0,
			)
		);
	}

	/**
	 * Queue attachments whose stored policy differs from $policy and start.
	 *
	 * @param int        $policy Target retention policy (0, 1 or 2).
	 * @param int[]|null $ids    Optional specific attachment IDs to queue.
	 * @param int        $limit  Optional maximum number of attachments. 0 for all.
	 * @return int Number of queued attachments.
	 */
	public function start( $policy, ?array $ids = null, int $limit = 0 ) {
		$policy = max( 0, min( 2, (int) $policy ) );
		$extra  = array(
			'policy'      => $policy,
			'freed_bytes' => 0,
			'repaired'    => 0,
		);

		if ( null !== $ids ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) use ( $policy ) {
						return 'attachment' === get_post_type( $id )
							&& self::is_offloaded( $id )
							&& RetentionApplier::stored_policy( $id ) !== $policy;
					}
				)
			);

			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}

			return $this->start_queue( $extra, $ids );
		}

		return $this->start_queue( $extra, RetentionApplier::eligible_ids( $policy, $limit ) );
	}

	/**
	 * Attachments that still differ from the run's policy.
	 *
	 * @return int[]
	 */
	protected function queue_ids() {
		$state = static::get_state();

		return RetentionApplier::eligible_ids( (int) $state['policy'] );
	}

	/**
	 * Apply the run's policy to one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function process_item( $attachment_id ) {
		return (bool) ( $this->process_items( array( (int) $attachment_id ) )[ (int) $attachment_id ] ?? false );
	}

	/**
	 * Several attachments per step, so their bucket listings go out together.
	 *
	 * @since 0.1.4
	 *
	 * @return int
	 */
	protected function group_size() {
		return RetentionApplier::group_size();
	}

	/**
	 * Attachments that download files (a looser policy) run alone: a worker
	 * killed mid-group reports the whole group as lost.
	 *
	 * @since 0.1.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function runs_alone( $attachment_id ) {
		// Read once per request: it only shapes groups, never what is deleted.
		if ( null === $this->group_policy ) {
			$this->group_policy = (int) static::get_state()['policy'];
		}

		return RetentionApplier::downloads( $attachment_id, $this->group_policy );
	}

	/**
	 * A lost item counts as done when it already follows the run's policy:
	 * a worker killed mid-group may have finished it before the kill.
	 *
	 * @since 0.1.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function was_completed( $attachment_id ) {
		return RetentionApplier::stored_policy( $attachment_id ) === (int) static::get_state()['policy'];
	}

	/**
	 * Apply the run's policy to a group: list the bucket for all of it first
	 * (one round trip), then apply each attachment, with one state write.
	 *
	 * @since 0.1.4
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array<int, bool|null>
	 */
	protected function process_items( array $ids ) {
		// Another site sharing the bucket owns this site's folder: its
		// objects can no longer be trusted as this site's copies.
		$active = FolderClaim::provider();

		if ( null !== $active && FolderClaim::blocks( $active ) ) {
			foreach ( $ids as $id ) {
				$this->item_messages[ $id ] = __( 'This bucket folder is used by another site.', 'omnioffloader' );
			}
			$this->halt_run( SafeMode::message() );

			return array_fill_keys( $ids, false );
		}

		if ( null === $this->applier ) {
			$this->applier = new RetentionApplier();
		}

		$policy   = (int) static::get_state()['policy'];
		$results  = array();
		$freed    = 0;
		$repaired = 0;

		$this->applier->prefetch( $ids, $policy );

		foreach ( $ids as $id ) {
			$ok             = $this->applier->apply( $id, $policy );
			$results[ $id ] = $ok;
			$freed         += $this->applier->get_freed_bytes();
			$repaired      += $this->applier->get_repaired();

			// Downloads run alone (runs_alone()), so this ends a group of one.
			if ( ! $ok && $this->applier->ran_out_of_disk() ) {
				$this->item_messages[ $id ] = __( 'Out of disk space or storage quota.', 'omnioffloader' );
				$this->halt_run( BulkRestoreProcessor::out_of_disk_message() );
			}
		}

		if ( $freed > 0 || $repaired > 0 ) {
			$state = static::get_state();

			static::update_state(
				array(
					'freed_bytes' => (int) $state['freed_bytes'] + $freed,
					'repaired'    => (int) $state['repaired'] + $repaired,
				)
			);
		}

		return $results;
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
}
