<?php
/**
 * Bring Back: restores an offloaded attachment from the cloud to the server.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttachmentRestorer
 *
 * Safety invariants:
 *  - The provider is resolved from the attachment's own `omnioffloader_provider`
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

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe timeout extension for large file transfers.
			@set_time_limit( 0 );
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
		do_action( 'omnioffloader_before_restore', $attachment_id );

		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		$files = self::collect_attachment_files( $attachment_id );

		if ( empty( $files['main'] ) ) {
			self::log_attachment_error( $attachment_id, __( 'No attached file found.', 'omnioffloader' ) );
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );

			return false;
		}

		$upload_dir = wp_get_upload_dir();
		$base_real  = realpath( $upload_dir['basedir'] );

		if ( false === $base_real ) {
			self::log_attachment_error( $attachment_id, __( 'Uploads directory not found.', 'omnioffloader' ) );

			return false;
		}

		$all_files = array_merge( $files['main'], $files['sizes'] );
		$all_ok    = true;

		foreach ( $all_files as $entry ) {
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
						__( 'Main file "%s" is missing locally and in cloud storage.', 'omnioffloader' ),
						wp_basename( $entry['file'] )
					)
				);
			}
		}

		if ( ! $all_ok ) {
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );

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
		do_action( 'omnioffloader_after_restore', $attachment_id, $delete_cloud );

		Helpers::flush_media_stats();

		if ( $delete_cloud && ! empty( $delete_keys ) ) {
			// Only after the local restore is verified (invariant #2). A
			// failed delete leaves orphaned objects, never a broken library.
			if ( ! $provider->delete_objects( $delete_keys ) ) {
				self::log_attachment_error(
					$attachment_id,
					__( 'Restored locally, but some cloud objects could not be deleted.', 'omnioffloader' )
				);
			}
		}

		return true;
	}

	/**
	 * Download the local copies a looser retention policy expects, while the
	 * attachment stays offloaded.
	 *
	 * Used when the retention policy is relaxed after the fact (e.g. Full
	 * Cloud Migration → Retain Local Files): the cloud copy stays the one
	 * that is served, the offload meta is left alone and only the files the
	 * new policy keeps on the server are brought down. Same safety as a
	 * restore — atomic downloads, uploads-dir confinement, skip-if-identical.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $policy        Target retention policy (0 or 1).
	 * @return bool True when every file the policy keeps locally is present.
	 */
	public function restore_local_copies( $attachment_id, $policy ) {
		$attachment_id = (int) $attachment_id;
		$policy        = (int) $policy;

		if ( ! self::is_offloaded( $attachment_id ) || $policy >= 2 ) {
			return false;
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe timeout extension for large file transfers.
			@set_time_limit( 0 );
		}

		$provider = $this->resolve_provider( $attachment_id );

		if ( null === $provider ) {
			return false;
		}

		$files = self::collect_attachment_files( $attachment_id );

		if ( empty( $files['main'] ) ) {
			self::log_attachment_error( $attachment_id, __( 'No attached file found.', 'omnioffloader' ) );

			return false;
		}

		$upload_dir = wp_get_upload_dir();
		$base_real  = realpath( $upload_dir['basedir'] );

		if ( false === $base_real ) {
			self::log_attachment_error( $attachment_id, __( 'Uploads directory not found.', 'omnioffloader' ) );

			return false;
		}

		// Smart Cleanup keeps originals only; Retain Local keeps everything.
		$wanted = 0 === $policy ? array_merge( $files['main'], $files['sizes'] ) : $files['main'];
		$all_ok = true;

		foreach ( $wanted as $entry ) {
			if ( ! $this->restore_file( $attachment_id, $provider, $entry['key'], $entry['file'], $base_real ) ) {
				$all_ok = false;
			}
		}

		foreach ( $files['main'] as $entry ) {
			if ( ! file_exists( $entry['file'] ) ) {
				$all_ok = false;
				self::log_attachment_error(
					$attachment_id,
					sprintf(
						/* translators: %s: file name. */
						__( 'Main file "%s" is missing locally and in cloud storage.', 'omnioffloader' ),
						wp_basename( $entry['file'] )
					)
				);
			}
		}

		if ( ! $all_ok ) {
			// The stored policy stays as it was, so the next run retries.
			return false;
		}

		update_post_meta( $attachment_id, 'omnioffloader_retention', $policy );

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

		// Refuse ".." segments before anything touches the filesystem, so
		// crafted metadata cannot even create directories outside uploads.
		if ( self::path_has_traversal( $dest ) ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: file name. */
					__( 'Refused to restore outside the uploads directory: "%s".', 'omnioffloader' ),
					wp_basename( $dest )
				)
			);

			return false;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: directory path relative to the uploads directory. */
					__( 'Cannot create directory "%s".', 'omnioffloader' ),
					self::relative_to_uploads( $dir )
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
					/* translators: %s: file name. */
					__( 'Refused to restore outside the uploads directory: "%s".', 'omnioffloader' ),
					wp_basename( $dest )
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
				__( 'Download failed for "%s".', 'omnioffloader' ),
				$key
			)
		);

		return false;
	}

	/**
	 * A path relative to the uploads directory, for user-visible messages.
	 *
	 * Error logs are shown to anyone who can manage the media, so they never
	 * carry absolute server paths.
	 *
	 * @since 0.1.3
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative_to_uploads( $path ) {
		$base = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		$path = wp_normalize_path( $path );

		return 0 === strpos( $path, $base ) ? substr( $path, strlen( $base ) ) : wp_basename( $path );
	}

	/**
	 * Resolve the provider the attachment was offloaded with.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ProviderInterface|null
	 */
	private function resolve_provider( $attachment_id ) {
		$provider_key = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );

		if ( '' === $provider_key ) {
			self::log_attachment_error( $attachment_id, __( 'No provider recorded for this attachment.', 'omnioffloader' ) );

			return null;
		}

		try {
			return ProviderRegistry::create( $provider_key );
		} catch ( \InvalidArgumentException $e ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: provider key. */
					__( 'Provider "%s" is not available.', 'omnioffloader' ),
					$provider_key
				)
			);

			return null;
		}
	}
}
