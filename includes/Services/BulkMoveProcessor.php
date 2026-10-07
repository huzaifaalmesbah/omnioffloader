<?php
/**
 * Background queue that moves offloaded media into the current folder.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Providers\ProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BulkMoveProcessor
 *
 * The admin side of `wp omnioffloader move`: each attachment stored outside
 * the current bucket folder goes through AttachmentMover (restore, offload
 * again, delete the old copies). The target folder is fixed in the state
 * when the run starts, and a Path Prefix change mid-run stops it. See
 * AbstractBulkProcessor for the queue hardening.
 *
 * @since 0.1.4
 */
class BulkMoveProcessor extends AbstractBulkProcessor {

	/**
	 * Option name holding the progress state.
	 */
	public const STATE_OPTION = 'omnioffloader_bulk_move_state';

	/**
	 * Action name — hooks become omnioffloader_bulk_move(_cron).
	 *
	 * @var string
	 */
	protected $action = 'bulk_move';

	/**
	 * Active provider: where media moves to.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Mover reused across items so provider clients are built once.
	 *
	 * @var AttachmentMover|null
	 */
	private $mover = null;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Active provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		parent::__construct( array() );
		$this->provider = $provider;
	}

	/**
	 * Baseline progress state plus the run's target folder.
	 *
	 * @return array<string, mixed>
	 */
	protected static function default_state() {
		return array_merge(
			parent::default_state(),
			array( 'folder' => '' )
		);
	}

	/**
	 * Queue the attachments outside the current folder and start.
	 *
	 * @param int[]|null $ids   Optional specific attachment IDs to queue.
	 * @param int        $limit Optional maximum number of attachments. 0 for all.
	 * @return int Number of queued attachments.
	 */
	public function start( ?array $ids = null, int $limit = 0 ) {
		$folder = FolderClaim::prefix();

		if ( null !== $ids ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', $ids ),
					static fn( $id ) => 'attachment' === get_post_type( $id ) && Helpers::is_offloaded_elsewhere( $id, $folder )
				)
			);

			if ( $limit > 0 ) {
				$ids = array_slice( $ids, 0, $limit );
			}
		} else {
			$ids = Helpers::offloaded_elsewhere_ids( $folder, $limit );
		}

		return $this->start_queue( array( 'folder' => $folder ), $ids );
	}

	/**
	 * Attachments still outside the run's folder.
	 *
	 * @return int[]
	 */
	protected function queue_ids() {
		return Helpers::offloaded_elsewhere_ids( (string) static::get_state()['folder'] );
	}

	/**
	 * Move one attachment into the run's folder.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	protected function process_item( $attachment_id ) {
		$folder = (string) static::get_state()['folder'];

		// Safe mode turned on mid-run, or the current folder is now another
		// site's: nothing may be written.
		if ( SafeMode::is_active() || FolderClaim::blocks( $this->provider ) ) {
			$this->item_messages[ $attachment_id ] = __( 'Safe mode is on.', 'omnioffloader' );
			$this->halt_run( SafeMode::message() );

			return false;
		}

		// The rest of the run would move media into a folder nobody chose.
		if ( FolderClaim::prefix() !== $folder ) {
			$this->item_messages[ $attachment_id ] = __( 'The Path Prefix changed.', 'omnioffloader' );
			$this->halt_run( __( 'Stopped: the Path Prefix changed during the move. Start the move again to use the new folder.', 'omnioffloader' ) );

			return false;
		}

		// Moved or brought back some other way since it was queued.
		if ( ! Helpers::is_offloaded_elsewhere( $attachment_id, $folder ) ) {
			return true;
		}

		$short = AttachmentRestorer::disk_shortfall( $attachment_id );

		if ( $short > 0 ) {
			$this->item_messages[ $attachment_id ] = __( 'Not enough free disk space.', 'omnioffloader' );
			$this->halt_run(
				sprintf(
					/* translators: %s: amount of disk space, e.g. "120 MB". */
					__( 'Stopped: the server is running out of disk space (%s more is needed for the next attachment). Free up space, then start the move again.', 'omnioffloader' ),
					size_format( $short )
				)
			);

			return false;
		}

		if ( null === $this->mover ) {
			$this->mover = new AttachmentMover( $this->provider );
		}

		$ok = $this->mover->move( $attachment_id );

		if ( ! $ok && $this->mover->ran_out_of_disk() ) {
			$this->item_messages[ $attachment_id ] = __( 'Out of disk space or storage quota.', 'omnioffloader' );
			$this->halt_run( BulkRestoreProcessor::out_of_disk_message() );
		}

		return $ok;
	}

	/**
	 * Whether the active provider still answers.
	 *
	 * @param int $attachment_id The last attachment that failed (unused).
	 * @return true|\WP_Error
	 */
	protected function connection_check( $attachment_id ) {
		unset( $attachment_id );

		return $this->provider->check_connection();
	}

	/**
	 * Drop partial downloads a killed worker left next to the attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	protected function clean_up_lost_item( $attachment_id ) {
		AttachmentRestorer::remove_partial_downloads( $attachment_id );
	}
}
