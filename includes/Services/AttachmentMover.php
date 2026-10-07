<?php
/**
 * Moves an offloaded attachment into the current bucket folder.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttachmentMover
 *
 * Media keeps the folder it was offloaded to, so after a Path Prefix change
 * it is split across folders. Moving an attachment:
 *
 * - Same provider and bucket (a Path Prefix change): the provider copies
 *   each object into the current folder itself, nothing passes through this
 *   server. Once the copies are confirmed the attachment points to the new
 *   folder and the old copies are deleted. Local files are not touched.
 * - Otherwise, or when the copy is not possible (an object over 5 GB): Bring
 *   Back, then a fresh offload into the current folder (the retention
 *   policy cleans up the local files as for any offload), then the old
 *   copies are deleted.
 *
 * The attachment has a working copy at every step: old copies are deleted
 * only once the new ones are confirmed, and if a fresh offload fails the
 * attachment stays on the server until the next offload.
 *
 * @since 0.1.4
 */
class AttachmentMover {

	use AttachmentHelper;

	/**
	 * Active provider: where media moves to.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

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
		$this->provider = $provider;
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

		$copied = $this->move_in_bucket( $attachment_id, $delete_old );

		if ( null !== $copied ) {
			return $copied;
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
	 * Move by copying inside the bucket: no download, no upload.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $delete_old    Delete the old copies once the new ones are confirmed.
	 * @return bool|null Null when a server-side copy cannot do it (another
	 *                   bucket or provider, a key the plugin did not build,
	 *                   an object too large to copy); the caller then moves
	 *                   it through the server.
	 */
	private function move_in_bucket( $attachment_id, $delete_old ) {
		$provider = $this->provider;

		if ( ! method_exists( $provider, 'copy_objects' )
			|| (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true ) !== $provider->get_key()
			|| (string) get_post_meta( $attachment_id, 'omnioffloader_bucket', true ) !== (string) $provider->get_bucket() ) {
			return null;
		}

		$old_subdir = self::get_offload_path( $attachment_id );
		$new_subdir = self::current_subdir( $attachment_id );

		if ( $old_subdir === $new_subdir ) {
			return null;
		}

		$files = self::collect_attachment_files( $attachment_id );
		$main  = $files['main'][0]['key'] ?? '';
		$pairs = array();

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			// A key changed by the omnioffloader_attachment_key filter cannot be
			// mapped to the new folder safely.
			if ( '' !== $old_subdir && 0 !== strpos( $entry['key'], $old_subdir ) ) {
				return null;
			}

			$pairs[ $entry['key'] ] = array(
				'from' => $entry['key'],
				'to'   => $new_subdir . substr( $entry['key'], strlen( $old_subdir ) ),
			);
		}

		if ( '' === $main || empty( $pairs ) ) {
			return null;
		}

		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		$copied = $provider->copy_objects( array_values( $pairs ), AttachmentUploader::files_at_once() );
		$failed = array_values(
			array_filter(
				$pairs,
				static fn( $pair ) => empty( $copied[ $pair['to'] ] )
			)
		);

		// Offload skips files that were not on disk, so an object may never
		// have existed. Any other failed copy (an object over 5 GB, an
		// error) means this way does not work for the attachment.
		if ( ! empty( $failed ) ) {
			$missing = wp_list_pluck( $failed, 'from' );
			$exists  = method_exists( $provider, 'objects_exist' )
				? $provider->objects_exist( $missing, AttachmentUploader::files_at_once() )
				: array_combine( $missing, array_map( array( $provider, 'object_exists' ), $missing ) );

			if ( empty( $copied[ $pairs[ $main ]['to'] ] ) || in_array( true, $exists, true ) ) {
				return $this->undo_copies( $attachment_id, $copied );
			}
		}

		// The attachment points to the new folder only once its main file is there.
		if ( ! $provider->object_exists( $pairs[ $main ]['to'] ) ) {
			return $this->undo_copies( $attachment_id, $copied );
		}

		update_post_meta( $attachment_id, 'omnioffloader_path', $new_subdir );
		delete_post_meta( $attachment_id, 'omnioffloader_status' );
		Helpers::flush_media_stats();

		if ( $delete_old ) {
			$stale = array();

			foreach ( $pairs as $pair ) {
				if ( ! empty( $copied[ $pair['to'] ] ) && $pair['from'] !== $pair['to'] ) {
					$stale[] = $pair['from'];
				}
			}

			if ( ! empty( $stale ) && ! $provider->delete_objects( $stale ) ) {
				self::log_attachment_error(
					$attachment_id,
					__( 'Moved to the current folder, but its old copies could not be deleted.', 'omnioffloader' )
				);
			}
		}

		return true;
	}

	/**
	 * Remove the new copies of a copy that did not complete, so the move can
	 * go through the server instead. The attachment still points to its old
	 * folder, which is untouched.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param array<string, bool> $copied        Destination key => copied.
	 * @return null
	 */
	private function undo_copies( $attachment_id, array $copied ) {
		$made = array_keys( array_filter( $copied ) );

		if ( ! empty( $made ) ) {
			$this->provider->delete_objects( $made );
		}

		delete_post_meta( $attachment_id, 'omnioffloader_status' );

		return null;
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
