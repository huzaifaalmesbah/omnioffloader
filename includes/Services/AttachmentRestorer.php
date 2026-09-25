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
use OmniOffloader\Traits\OptionsHelper;

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
	use OptionsHelper;

	/**
	 * Providers resolved so far in this request, by key.
	 *
	 * Static so every restorer in a request — bulk queues build one per
	 * item — reuses one S3 client and its open connection.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private static $providers = array();

	/**
	 * Free space, in bytes, a restore always leaves on the uploads disk.
	 */
	private const DISK_RESERVE = 50 * MB_IN_BYTES;

	/**
	 * Age, in seconds, after which a leftover partial download is treated as
	 * abandoned when a restore starts. Old enough that no live download
	 * (only one job runs at a time) can still be writing to it.
	 */
	private const STALE_TEMP_SECONDS = 15 * MINUTE_IN_SECONDS;

	/**
	 * Whether the last restore() / restore_local_copies() failed because the
	 * server refused to write a download (disk full, or quota used up).
	 *
	 * @var bool
	 */
	private $out_of_disk = false;

	/**
	 * Restore one attachment from the cloud back to the server.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $delete_cloud  Also delete the cloud objects after a verified restore.
	 * @return bool True when the attachment ended up fully local again.
	 */
	public function restore( $attachment_id, $delete_cloud = false ) {
		$attachment_id     = (int) $attachment_id;
		$this->out_of_disk = false;

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

		$all_ok = $this->restore_files( $attachment_id, $provider, array_merge( $files['main'], $files['sizes'] ), $base_real );

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
		$attachment_id     = (int) $attachment_id;
		$policy            = (int) $policy;
		$this->out_of_disk = false;

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
		$all_ok = $this->restore_files( $attachment_id, $provider, $wanted, $base_real );

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
	 * Restore a set of an attachment's files.
	 *
	 * One listing request tells which local copies already match the cloud
	 * (retention 0/1: nothing to download), then everything else downloads
	 * side by side at the site's transfer speed. Each file still goes to a
	 * temp file first and is renamed into place only when complete.
	 *
	 * @since 0.1.3
	 *
	 * @param int                                          $attachment_id Attachment ID (for error logging).
	 * @param ProviderInterface                            $provider      Provider the attachment was offloaded to.
	 * @param array<int, array{file: string, key: string}> $entries       Files to restore.
	 * @param string                                       $base_real     realpath() of the uploads basedir.
	 * @return bool True when every file is in place (or legitimately absent).
	 */
	private function restore_files( $attachment_id, ProviderInterface $provider, array $entries, $base_real ) {
		$all_ok   = true;
		$ready    = array();
		$existing = array();

		// A worker killed mid-download (by the host, or Ctrl-C on WP-CLI)
		// leaves its temp file behind; this is the next chance to tidy up.
		self::remove_temp_files( $entries, self::STALE_TEMP_SECONDS );

		foreach ( $entries as $entry ) {
			if ( ! $this->prepare_destination( $attachment_id, $entry['file'], $base_real ) ) {
				$all_ok = false;
				continue;
			}

			$ready[] = $entry;

			if ( file_exists( $entry['file'] ) && filesize( $entry['file'] ) > 0 ) {
				$existing[] = $entry['key'];
			}
		}

		$remote   = empty( $existing ) ? array() : self::remote_sizes( $provider, $existing );
		$download = array();

		foreach ( $ready as $entry ) {
			// Skip files already present with the same size (retention 0/1 —
			// no wasted bandwidth). Size unknown remotely → keep the local copy.
			if ( in_array( $entry['key'], $existing, true ) ) {
				$remote_size = $remote[ $entry['key'] ] ?? $provider->object_size( $entry['key'] );

				if ( false === $remote_size || (int) filesize( $entry['file'] ) === (int) $remote_size ) {
					continue;
				}
			}

			$download[] = array(
				'key'  => $entry['key'],
				'dest' => $entry['file'],
			);
		}

		$downloaded = $this->download_all( $provider, $download );

		// Optional provider method (see AbstractS3Provider::download_hit_disk_limit()).
		if ( ! empty( $download ) && method_exists( $provider, 'download_hit_disk_limit' ) && $provider->download_hit_disk_limit() ) {
			$this->out_of_disk = true;
			self::log_attachment_error(
				$attachment_id,
				__( 'The server could not save the download: its disk is full, or the hosting plan\'s storage quota is used up.', 'omnioffloader' )
			);

			return false;
		}

		foreach ( $downloaded as $key => $ok ) {
			// The offload side quietly skips size files that never existed on
			// disk, so their objects were never uploaded. Mirror that: a missing
			// *object* with no local copy is only fatal for main files — and
			// main files are verified separately by the caller.
			if ( $ok || ! $provider->object_exists( $key ) ) {
				continue;
			}

			$all_ok = false;
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: object key. */
					__( 'Download failed for "%s".', 'omnioffloader' ),
					$key
				)
			);
		}

		return $all_ok;
	}

	/**
	 * Whether the last restore failed because the server ran out of disk
	 * space or storage quota. Every later restore would fail the same way,
	 * so bulk runs stop on it.
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	public function ran_out_of_disk() {
		return $this->out_of_disk;
	}

	/**
	 * Download objects, several at once when the provider can.
	 *
	 * @since 0.1.3
	 *
	 * @param ProviderInterface                            $provider Provider.
	 * @param array<int, array{key: string, dest: string}> $files    Objects to download.
	 * @return array<string, bool> Object key => whether it is now in place.
	 */
	private function download_all( ProviderInterface $provider, array $files ) {
		if ( empty( $files ) ) {
			return array();
		}

		// Optional provider method (see AbstractS3Provider::download_files()).
		if ( method_exists( $provider, 'download_files' ) ) {
			return $provider->download_files( $files, self::files_at_once() );
		}

		$results = array();

		foreach ( $files as $entry ) {
			$results[ $entry['key'] ] = $provider->download_file( $entry['key'], $entry['dest'] );
		}

		return $results;
	}

	/**
	 * Check a destination is inside uploads and create its directory.
	 *
	 * @since 0.1.3 Split out of the former restore_file().
	 *
	 * @param int    $attachment_id Attachment ID (for error logging).
	 * @param string $dest          Absolute local destination path.
	 * @param string $base_real     realpath() of the uploads basedir.
	 * @return bool
	 */
	private function prepare_destination( $attachment_id, $dest, $base_real ) {
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

		return true;
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

		$provider = self::provider_for( $attachment_id );

		if ( null === $provider ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: provider key. */
					__( 'Provider "%s" is not available.', 'omnioffloader' ),
					$provider_key
				)
			);
		}

		return $provider;
	}

	/**
	 * The provider an attachment was offloaded with, without logging.
	 *
	 * One provider (and S3 client) per key for the whole request: bulk
	 * restores create a restorer per item.
	 *
	 * @since 0.1.3 Split out of resolve_provider().
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ProviderInterface|null
	 */
	public static function provider_for( $attachment_id ) {
		$provider_key = (string) get_post_meta( (int) $attachment_id, 'omnioffloader_provider', true );

		if ( '' === $provider_key ) {
			return null;
		}

		if ( ! isset( self::$providers[ $provider_key ] ) ) {
			try {
				self::$providers[ $provider_key ] = ProviderRegistry::create( $provider_key );
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}
		}

		return self::$providers[ $provider_key ];
	}

	/**
	 * Delete partial downloads of an attachment's files, whatever their age.
	 *
	 * For a worker known to be dead (see AbstractBulkProcessor::recover_lost_item()).
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function remove_partial_downloads( $attachment_id ) {
		$files = self::collect_attachment_files( (int) $attachment_id );

		self::remove_temp_files( array_merge( $files['main'], $files['sizes'] ), 0 );
	}

	/**
	 * Delete the temp files downloads of these entries would have used.
	 *
	 * Downloads write to wp_tempnam( basename( $dest ), dirname( $dest ) ),
	 * i.e. "{name}-{6 random chars}.tmp" next to the destination (plus a
	 * "-N" suffix on a name clash). Only names of exactly that shape match.
	 *
	 * @since 0.1.3
	 *
	 * @param array<int, array{file: string, key: string}> $entries Attachment files.
	 * @param int                                          $min_age Only delete files at least this many seconds old.
	 * @return void
	 */
	private static function remove_temp_files( array $entries, $min_age ) {
		$seen = array();

		foreach ( $entries as $entry ) {
			$dir  = dirname( $entry['file'] );
			$stem = (string) preg_replace( '|\.[^.]*$|', '', wp_basename( $entry['file'] ) );

			if ( '' === $stem || isset( $seen[ $dir . '/' . $stem ] ) || ! is_dir( $dir ) || self::path_has_traversal( $entry['file'] ) ) {
				continue;
			}

			$seen[ $dir . '/' . $stem ] = true;

			$candidates = glob( trailingslashit( $dir ) . self::glob_escape( $stem ) . '-*.tmp' );

			if ( empty( $candidates ) ) {
				continue;
			}

			$pattern = '/^' . preg_quote( $stem, '/' ) . '-[A-Za-z0-9]{6}(-\d+)?\.tmp$/';

			foreach ( $candidates as $tmp ) {
				if ( ! preg_match( $pattern, wp_basename( $tmp ) ) ) {
					continue;
				}

				$mtime = (int) filemtime( $tmp );

				if ( $min_age > 0 && time() - $mtime < $min_age ) {
					continue;
				}

				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Escape glob() metacharacters in a literal file name.
	 *
	 * @since 0.1.3
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function glob_escape( $name ) {
		return (string) preg_replace( '/([*?\[\]])/', '[$1]', $name );
	}

	/**
	 * How many bytes short the uploads disk is for restoring an attachment.
	 *
	 * Counts only files missing locally, with sizes from the attachment's
	 * metadata, plus a fixed reserve so a restore never fills the disk to the
	 * last byte. A host that hides free space never reports a shortfall.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Zero when there is room (or it cannot be told).
	 */
	public static function disk_shortfall( $attachment_id ) {
		$needed = self::bytes_to_download( (int) $attachment_id );

		if ( $needed <= 0 ) {
			return 0;
		}

		// A quota figure cPanel has not refreshed for an hour may be out of
		// date either way; a refused write still stops the run if it is low.
		$free = Helpers::free_disk_space( HOUR_IN_SECONDS );

		if ( null === $free ) {
			return 0;
		}

		$wanted = $needed + self::DISK_RESERVE;

		return $free >= $wanted ? 0 : (int) ceil( $wanted - $free );
	}

	/**
	 * Bytes a restore would download: the attachment's files that are not on
	 * disk, sized from its metadata (files it has no size for count as 0).
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int
	 */
	private static function bytes_to_download( $attachment_id ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );

		if ( ! is_array( $metadata ) ) {
			return 0;
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var for PHPStan.
		/** @var array<string, mixed> $metadata */
		$sizes = array();

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( is_array( $size_data ) && isset( $size_data['file'], $size_data['filesize'] ) && is_string( $size_data['file'] ) && is_numeric( $size_data['filesize'] ) ) {
					$sizes[ $size_data['file'] ] = (int) $size_data['filesize'];
				}
			}
		}

		$files = self::collect_attachment_files( $attachment_id, $metadata );
		$bytes = 0;

		// The top-level filesize belongs to the attached file. Keyed by that
		// file, not by $metadata['file']: video, audio and document metadata
		// has a filesize but no 'file' key, and those are the big downloads.
		if ( ! empty( $files['main'] ) && isset( $metadata['filesize'] ) && is_numeric( $metadata['filesize'] ) ) {
			$sizes[ wp_basename( $files['main'][0]['file'] ) ] = (int) $metadata['filesize'];
		}

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			if ( ! file_exists( $entry['file'] ) ) {
				$bytes += $sizes[ wp_basename( $entry['file'] ) ] ?? 0;
			}
		}

		return $bytes;
	}
}
