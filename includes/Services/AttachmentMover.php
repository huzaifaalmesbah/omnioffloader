<?php
/**
 * Moves an offloaded attachment into the current bucket folder.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttachmentMover
 *
 * Media keeps the folder it was offloaded to, so after a Path Prefix change
 * it is split across folders. Moving an attachment is Bring Back followed by
 * a fresh offload, then removing the old copies:
 *
 * 1. Restore it (verified), keeping the old cloud copy.
 * 2. Offload it again (verified) into the current folder. The retention
 *    policy then cleans up the local files as for any offload.
 * 3. Delete the old copies of the files that are now in the new folder.
 *
 * The attachment has a working copy at every step: if the offload fails it
 * stays on the server, and the next offload picks it up.
 *
 * @since 0.1.4
 */
class AttachmentMover {

	use AttachmentHelper;

	/**
	 * Bring Back service.
	 *
	 * @var AttachmentRestorer
	 */
	private $restorer;

	/**
	 * Offload service for the active provider.
	 *
	 * @var AttachmentUploader
	 */
	private $uploader;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Active provider (the new location).
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->restorer = new AttachmentRestorer();
		$this->uploader = new AttachmentUploader( $provider );
	}

	/**
	 * Move one attachment into the current folder.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $delete_old    Delete the old copies once the move is verified.
	 * @return bool True when the attachment is offloaded in the current folder.
	 */
	public function move( $attachment_id, $delete_old = true ) {
		$attachment_id = (int) $attachment_id;

		if ( ! self::is_offloaded( $attachment_id ) ) {
			return false;
		}

		// Read before the restore clears the offload meta.
		$old_keys     = self::collect_delete_keys( $attachment_id );
		$old_provider = AttachmentRestorer::provider_for( $attachment_id );

		if ( ! $this->restorer->restore( $attachment_id, false ) ) {
			return false;
		}

		if ( ! $this->uploader->upload( $attachment_id ) ) {
			// Restored and served from the server; the error is logged.
			return false;
		}

		if ( $delete_old && null !== $old_provider ) {
			$stale = self::moved_keys( $old_keys, $attachment_id );

			if ( ! empty( $stale ) && ! $old_provider->delete_objects( $stale ) ) {
				self::log_attachment_error(
					$attachment_id,
					__( 'Moved to the current folder, but its old copies could not be deleted.', 'omnioffloader' )
				);
			}
		}

		return true;
	}

	/**
	 * Whether the last move failed because the server ran out of disk space.
	 *
	 * @return bool
	 */
	public function ran_out_of_disk() {
		return $this->restorer->ran_out_of_disk();
	}

	/**
	 * Old keys whose file is now in the new folder, so deleting them loses
	 * nothing. Edit backups and sidecar files are not uploaded again, so
	 * their old copies stay.
	 *
	 * @param string[] $old_keys      Keys in the old folder.
	 * @param int      $attachment_id Attachment ID, offloaded again.
	 * @return string[]
	 */
	private static function moved_keys( array $old_keys, $attachment_id ) {
		$files    = self::collect_attachment_files( $attachment_id );
		$new_keys = wp_list_pluck( array_merge( $files['main'], $files['sizes'] ), 'key' );
		$names    = array_flip( array_map( 'wp_basename', $new_keys ) );

		return array_values(
			array_filter(
				$old_keys,
				static function ( $key ) use ( $names, $new_keys ) {
					return isset( $names[ wp_basename( $key ) ] ) && ! in_array( $key, $new_keys, true );
				}
			)
		);
	}
}
