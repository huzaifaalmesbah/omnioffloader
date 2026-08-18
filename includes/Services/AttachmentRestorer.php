<?php
/**
 * Bring Back: restores an offloaded attachment from the cloud to the server.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Services;

use SmartMediaOffloader\Core\Helpers;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Providers\ProviderRegistry;
use SmartMediaOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttachmentRestorer
 *
 * Safety invariants:
 *  - The provider is resolved from the attachment's own `smowp_provider`
 *    meta, not the active provider — restores keep working after switching
 *    providers as long as the old credentials remain stored.
 *  - Every destination is confined to the uploads basedir (realpath check).
 *  - Downloads are atomic (temp file + rename, in the provider layer).
 *  - Offload meta is only cleared after every expected file is verified
 *    locally; cloud objects are only deleted (opt-in) after that.
 *  - On any failure the attachment keeps its cloud copy and truthful meta.
 */
class AttachmentRestorer {

	use AttachmentHelper;

	/**
	 * Restore one attachment from the cloud back to the server.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $delete_cloud  Also delete the cloud objects after a verified restore.
	 * @return bool True when the attachment ended up fully local again.
	 */
	public function restore( $attachment_id, $delete_cloud = false ) {
		$attachment_id = (int) $attachment_id;

		if ( ! self::is_offloaded( $attachment_id ) ) {
			return false;
		}

		$provider = $this->resolve_provider( $attachment_id );

		if ( null === $provider ) {
			return false;
		}

		/**
		 * Fires before an attachment is restored from the cloud.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'smowp_before_restore', $attachment_id );

		$files = self::collect_attachment_files( $attachment_id );

		if ( empty( $files['main'] ) ) {
			self::log_attachment_error( $attachment_id, __( 'No attached file found.', 'smart-media-offloader' ) );

			return false;
		}

		$upload_dir = wp_get_upload_dir();
		$base_real  = realpath( $upload_dir['basedir'] );

		if ( false === $base_real ) {
			self::log_attachment_error( $attachment_id, __( 'Uploads directory not found.', 'smart-media-offloader' ) );

			return false;
		}

		$all_ok = true;

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			if ( ! $this->restore_file( $attachment_id, $provider, $entry['key'], $entry['file'], $base_real ) ) {
				$all_ok = false;
			}
		}

		// Verify: the main file(s) must exist locally before any meta changes.
		foreach ( $files['main'] as $entry ) {
			if ( ! file_exists( $entry['file'] ) ) {
				$all_ok = false;
				self::log_attachment_error(
					$attachment_id,
					sprintf(
						/* translators: %s: file name. */
						__( 'Main file "%s" is missing locally and in the cloud.', 'smart-media-offloader' ),
						wp_basename( $entry['file'] )
					)
				);
			}
		}

		if ( ! $all_ok ) {
			// Keep cloud copy and offload meta — the attachment still serves
			// from the cloud, and the error log tells the user what failed.
			return false;
		}

		// Collect delete keys while the offload meta (stored path) is intact.
		$delete_keys = $delete_cloud ? self::collect_delete_keys( $attachment_id ) : array();

		self::clear_offload_meta( $attachment_id );

		/**
		 * Fires after an attachment was fully restored to the server.
		 *
		 * @param int  $attachment_id Attachment ID.
		 * @param bool $delete_cloud  Whether cloud objects will also be deleted.
		 */
		do_action( 'smowp_after_restore', $attachment_id, $delete_cloud );

		Helpers::flush_media_stats();

		if ( $delete_cloud && ! empty( $delete_keys ) ) {
			// Only after the local restore is verified (invariant #2). A
			// failed delete leaves orphaned objects, never a broken library.
			if ( ! $provider->delete_objects( $delete_keys ) ) {
				self::log_attachment_error(
					$attachment_id,
					__( 'Restored locally, but some cloud objects could not be deleted.', 'smart-media-offloader' )
				);
			}
		}

		return true;
	}

	/**
	 * Restore a single file of the attachment.
	 *
	 * @param int               $attachment_id Attachment ID (for error logging).
	 * @param ProviderInterface $provider      Provider the attachment was offloaded to.
	 * @param string            $key           Object key.
	 * @param string            $dest          Absolute local destination path.
	 * @param string            $base_real     realpath() of the uploads basedir.
	 * @return bool
	 */
	private function restore_file( $attachment_id, ProviderInterface $provider, $key, $dest, $base_real ) {
		$dir = dirname( $dest );

		if ( ! wp_mkdir_p( $dir ) ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: directory path. */
					__( 'Cannot create directory "%s".', 'smart-media-offloader' ),
					$dir
				)
			);

			return false;
		}

		// Path-traversal guard: the destination directory must resolve
		// inside the uploads basedir (invariant #3).
		$dir_real = realpath( $dir );

		if ( false === $dir_real || 0 !== strpos( trailingslashit( $dir_real ), trailingslashit( $base_real ) ) ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: file path. */
					__( 'Refused to restore outside the uploads directory: "%s".', 'smart-media-offloader' ),
					$dest
				)
			);

			return false;
		}

		// Skip files already present with the same size (retention 0/1 —
		// no wasted bandwidth). Size unknown remotely → keep the local copy.
		if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
			$remote_size = $provider->object_size( $key );

			if ( false === $remote_size || (int) filesize( $dest ) === $remote_size ) {
				return true;
			}
		}

		if ( $provider->download_file( $key, $dest ) ) {
			return true;
		}

		// The offload side quietly skips size files that never existed on
		// disk, so their objects were never uploaded. Mirror that: a missing
		// *object* with no local copy is only fatal for main files — and
		// main files are verified separately by the caller.
		if ( ! $provider->object_exists( $key ) ) {
			return true;
		}

		self::log_attachment_error(
			$attachment_id,
			sprintf(
				/* translators: %s: object key. */
				__( 'Download failed for "%s".', 'smart-media-offloader' ),
				$key
			)
		);

		return false;
	}

	/**
	 * Resolve the provider the attachment was offloaded with.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ProviderInterface|null
	 */
	private function resolve_provider( $attachment_id ) {
		$provider_key = (string) get_post_meta( $attachment_id, 'smowp_provider', true );

		if ( '' === $provider_key ) {
			self::log_attachment_error( $attachment_id, __( 'No provider recorded for this attachment.', 'smart-media-offloader' ) );

			return null;
		}

		try {
			return ProviderRegistry::create( $provider_key );
		} catch ( \InvalidArgumentException $e ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: provider key. */
					__( 'Provider "%s" is not available.', 'smart-media-offloader' ),
					$provider_key
				)
			);

			return null;
		}
	}
}
