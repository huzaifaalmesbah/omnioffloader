<?php
/**
 * Attachment helper trait.
 *
 * Object key building, file collection and offload meta read/write.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Traits;

use OmniOffloader\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait AttachmentHelper
 */
trait AttachmentHelper {

	/**
	 * Whether an attachment is offloaded.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function is_offloaded( $attachment_id ) {
		return (bool) get_post_meta( $attachment_id, 'omnioffloader_offloaded', true );
	}

	/**
	 * Whether the current user may offload or bring back an attachment.
	 *
	 * Moving media between the server and the cloud is a site-management
	 * task, so it is limited to administrators (`manage_options`), like the
	 * plugin's settings and bulk screens. Other users keep what WordPress
	 * already lets them do with their media — upload and delete it (Mirror
	 * Delete then removes the cloud copy as part of that delete).
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function current_user_can_manage( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		return 'attachment' === get_post_type( $attachment_id )
			&& current_user_can( 'manage_options' )
			&& current_user_can( 'edit_post', $attachment_id );
	}

	/**
	 * Whether the current user may delete an attachment's cloud copies
	 * outside of deleting the attachment itself (Bring Back + delete cloud).
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function current_user_can_delete_cloud( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		return self::current_user_can_manage( $attachment_id ) && current_user_can( 'delete_post', $attachment_id );
	}

	/**
	 * Keep only the attachment IDs the current user may manage.
	 *
	 * @since 0.1.3
	 *
	 * @param array<int|string, mixed> $ids Attachment IDs.
	 * @return int[]
	 */
	public static function filter_manageable_ids( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}

		return array_values(
			array_filter(
				$ids,
				static function ( $id ) {
					return self::current_user_can_manage( $id );
				}
			)
		);
	}

	/**
	 * The stored cloud subdir (key prefix) for an offloaded attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string E.g. "2026/08/" — empty for bucket root.
	 */
	public static function get_offload_path( $attachment_id ) {
		return (string) get_post_meta( $attachment_id, 'omnioffloader_path', true );
	}

	/**
	 * Build the cloud subdir (key prefix) for an attachment.
	 *
	 * Structure: [path_prefix/][yyyy/mm/][object_version/].
	 * Once offloaded, the stored path is authoritative.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public static function build_subdir( $attachment_id ) {
		if ( self::is_offloaded( $attachment_id ) ) {
			return self::get_offload_path( $attachment_id );
		}

		$settings = \OmniOffloader\Core\Plugin::get_settings();

		$prefix = '';
		if ( ! empty( $settings['path_prefix_active'] ) && '' !== $settings['path_prefix'] ) {
			$prefix = trailingslashit( Helpers::sanitize_path( (string) $settings['path_prefix'] ) );
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		// Guard: dirname() returns '.' for files at the uploads root, which
		// would inject a broken "./" segment into every key and URL.
		$dirname  = dirname( $attached_file );
		$dir_part = ( '.' === $dirname || '' === $dirname ) ? '' : trailingslashit( $dirname );

		$version_part = '';
		if ( ! empty( $settings['object_versioning'] ) ) {
			$version = (string) get_post_meta( $attachment_id, 'omnioffloader_object_version', true );

			if ( '' === $version ) {
				$version = gmdate( 'YmdHis' );
				update_post_meta( $attachment_id, 'omnioffloader_object_version', $version );
			}

			$version_part = trailingslashit( $version );
		}

		return $prefix . $dir_part . $version_part;
	}

	/**
	 * Unique file names from a size-data entry, including modern image
	 * format sources (WebP/AVIF from plugins like webp-uploads).
	 *
	 * @param array<string, mixed> $size_data One entry of metadata['sizes'].
	 * @return string[]
	 */
	public static function files_from_size_data( array $size_data ) {
		$files = array();

		if ( ! empty( $size_data['file'] ) && is_string( $size_data['file'] ) ) {
			$files[] = $size_data['file'];
		}

		if ( ! empty( $size_data['sources'] ) && is_array( $size_data['sources'] ) ) {
			foreach ( $size_data['sources'] as $source ) {
				if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) && ! in_array( $source['file'], $files, true ) ) {
					$files[] = $source['file'];
				}
			}
		}

		return $files;
	}

	/**
	 * Root-level modern-format source files (excluding the main file).
	 *
	 * @param array<string, mixed> $metadata Attachment metadata.
	 * @return string[]
	 */
	public static function root_source_files( array $metadata ) {
		$files = array();

		if ( empty( $metadata['sources'] ) || ! is_array( $metadata['sources'] ) ) {
			return $files;
		}

		$main_file = isset( $metadata['file'] ) && is_string( $metadata['file'] ) ? wp_basename( $metadata['file'] ) : '';

		foreach ( $metadata['sources'] as $source ) {
			if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) && $source['file'] !== $main_file ) {
				$files[] = $source['file'];
			}
		}

		return $files;
	}

	/**
	 * Collect the files belonging to an attachment as local-path/object-key
	 * pairs, keyed by role for retention decisions.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata      Metadata override — pass the in-flight
	 *                                                 value inside wp_generate_attachment_metadata,
	 *                                                 where the DB copy may not be saved yet.
	 * @return array{main: array<int, array{file: string, key: string}>, sizes: array<int, array{file: string, key: string}>}
	 */
	public static function collect_attachment_files( $attachment_id, $metadata = null ) {
		$result = array(
			'main'  => array(),
			'sizes' => array(),
		);

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' === $attached_file ) {
			return $result;
		}

		$upload_dir = wp_get_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$subdir     = self::build_subdir( $attachment_id );

		$dirname   = dirname( $attached_file );
		$local_dir = ( '.' === $dirname || '' === $dirname ) ? $base_dir : trailingslashit( $base_dir . $dirname );

		/**
		 * Filter the object key for an attachment's main file.
		 *
		 * @param string $key           Object key.
		 * @param int    $attachment_id Attachment ID.
		 */
		$main_key = apply_filters( 'omnioffloader_attachment_key', $subdir . wp_basename( $attached_file ), $attachment_id );

		$result['main'][] = array(
			'file' => $local_dir . wp_basename( $attached_file ),
			'key'  => $main_key,
		);

		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}

		if ( ! is_array( $metadata ) ) {
			return $result;
		}

		// Core's stub shape omits keys like original_image and sources
		// (added by webp-uploads), so treat it as a generic array.
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var for PHPStan.
		/** @var array<string, mixed> $metadata */

		// Pre-scaled original (WP creates a "-scaled" main file for big images).
		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$result['main'][] = array(
				'file' => $local_dir . $metadata['original_image'],
				'key'  => $subdir . $metadata['original_image'],
			);
		}

		// Root-level modern-format sources belong with the main file.
		foreach ( self::root_source_files( $metadata ) as $file ) {
			$result['main'][] = array(
				'file' => $local_dir . $file,
				'key'  => $subdir . $file,
			);
		}

		// Generated sizes and their sources.
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$seen = array();

			foreach ( $metadata['sizes'] as $size_data ) {
				if ( ! is_array( $size_data ) ) {
					continue;
				}

				foreach ( self::files_from_size_data( $size_data ) as $file ) {
					if ( isset( $seen[ $file ] ) ) {
						continue;
					}
					$seen[ $file ] = true;

					$result['sizes'][] = array(
						'file' => $local_dir . $file,
						'key'  => $subdir . $file,
					);
				}
			}
		}

		return $result;
	}

	/**
	 * Collect every object key to delete for an attachment, including
	 * editor backup files.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	public static function collect_delete_keys( $attachment_id ) {
		$files = self::collect_attachment_files( $attachment_id );
		$keys  = array();

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			$keys[] = $entry['key'];
		}

		$subdir = self::build_subdir( $attachment_id );

		// Backup sizes from the image editor.
		$backup_sizes = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $size_data ) {
				if ( ! is_array( $size_data ) ) {
					continue;
				}
				foreach ( self::files_from_size_data( $size_data ) as $file ) {
					$keys[] = $subdir . $file;
				}
			}
		}

		// Backup full-size modern-format sources (webp-uploads).
		$backup_sources = get_post_meta( $attachment_id, '_wp_attachment_backup_sources', true );
		if ( is_array( $backup_sources ) ) {
			foreach ( $backup_sources as $sources_set ) {
				if ( ! is_array( $sources_set ) ) {
					continue;
				}
				foreach ( $sources_set as $source ) {
					if ( is_array( $source ) && ! empty( $source['file'] ) && is_string( $source['file'] ) ) {
						$keys[] = $subdir . $source['file'];
					}
				}
			}
		}

		/**
		 * Filter the object keys deleted for an attachment (mirror delete).
		 *
		 * Lets integrations append sidecar files (e.g. optimizer WebP copies).
		 *
		 * @param string[] $keys          Object keys collected so far.
		 * @param int      $attachment_id Attachment ID.
		 * @param string   $subdir        Key prefix for this attachment.
		 */
		$keys = apply_filters( 'omnioffloader_attachment_delete_keys', $keys, $attachment_id, $subdir );

		return array_values(
			array_unique(
				array_filter(
					$keys,
					static function ( $key ) {
						return is_string( $key ) && '' !== $key;
					}
				)
			)
		);
	}

	/**
	 * Write the offload meta for a successfully offloaded attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $provider_key  Provider key.
	 * @param string $bucket        Bucket name.
	 * @param string $subdir        Key prefix used.
	 * @param int    $retention     Retention policy applied.
	 * @return void
	 */
	public static function write_offload_meta( $attachment_id, $provider_key, $bucket, $subdir, $retention ) {
		update_post_meta( $attachment_id, 'omnioffloader_offloaded', 1 );
		update_post_meta( $attachment_id, 'omnioffloader_offloaded_at', time() );
		update_post_meta( $attachment_id, 'omnioffloader_provider', $provider_key );
		update_post_meta( $attachment_id, 'omnioffloader_bucket', $bucket );
		update_post_meta( $attachment_id, 'omnioffloader_path', $subdir );
		update_post_meta( $attachment_id, 'omnioffloader_retention', $retention );
		delete_post_meta( $attachment_id, 'omnioffloader_status' );
		delete_post_meta( $attachment_id, 'omnioffloader_error_log' );
	}

	/**
	 * Remove all offload meta after a verified restore (Bring Back).
	 *
	 * After this, URL rewrites stop and the attachment serves locally again.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function clear_offload_meta( $attachment_id ) {
		delete_post_meta( $attachment_id, 'omnioffloader_offloaded' );
		delete_post_meta( $attachment_id, 'omnioffloader_offloaded_at' );
		delete_post_meta( $attachment_id, 'omnioffloader_provider' );
		delete_post_meta( $attachment_id, 'omnioffloader_bucket' );
		delete_post_meta( $attachment_id, 'omnioffloader_path' );
		delete_post_meta( $attachment_id, 'omnioffloader_object_version' );
		delete_post_meta( $attachment_id, 'omnioffloader_retention' );
		delete_post_meta( $attachment_id, 'omnioffloader_status' );
		delete_post_meta( $attachment_id, 'omnioffloader_error_log' );
	}

	/**
	 * Append an error to the attachment's error log meta.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $message       Error message.
	 * @return void
	 */
	public static function log_attachment_error( $attachment_id, $message ) {
		$log = get_post_meta( $attachment_id, 'omnioffloader_error_log', true );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'time'    => time(),
			'message' => $message,
		);

		// Keep the log bounded.
		$log = array_slice( $log, -10 );

		update_post_meta( $attachment_id, 'omnioffloader_error_log', $log );
	}

	/**
	 * Get the current offload status and metadata for an attachment.
	 *
	 * @since 0.1.1
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{status: string, is_offloaded: bool, label: string, provider?: string, bucket?: string, offloaded_at?: int, date?: string, error?: string}
	 */
	public static function get_attachment_status( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( self::is_offloaded( $attachment_id ) ) {
			$status = (string) get_post_meta( $attachment_id, 'omnioffloader_status', true );
			if ( 'processing' === $status ) {
				return array(
					'status'       => 'processing',
					'is_offloaded' => true,
					'label'        => __( 'Restoring...', 'omnioffloader' ),
				);
			}

			if ( 'queued' === $status ) {
				return array(
					'status'       => 'queued',
					'is_offloaded' => true,
					'label'        => __( 'Queued for restore', 'omnioffloader' ),
				);
			}

			$provider_key = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );
			$bucket       = (string) get_post_meta( $attachment_id, 'omnioffloader_bucket', true );
			$offloaded_at = (int) get_post_meta( $attachment_id, 'omnioffloader_offloaded_at', true );
			$date_str     = $offloaded_at > 0 ? (string) wp_date( (string) get_option( 'date_format' ), $offloaded_at ) : '';

			$label = sprintf(
				/* translators: 1: provider key, 2: formatted date */
				__( 'Offloaded — %1$s%2$s', 'omnioffloader' ),
				'' !== $provider_key ? $provider_key : __( 'Cloud', 'omnioffloader' ),
				'' !== $date_str ? ' · ' . $date_str : ''
			);

			return array(
				'status'       => 'offloaded',
				'is_offloaded' => true,
				'provider'     => $provider_key,
				'bucket'       => $bucket,
				'offloaded_at' => $offloaded_at,
				'date'         => $date_str,
				'label'        => $label,
			);
		}

		$status = (string) get_post_meta( $attachment_id, 'omnioffloader_status', true );
		$errors = get_post_meta( $attachment_id, 'omnioffloader_error_log', true );

		if ( 'processing' === $status ) {
			return array(
				'status'       => 'processing',
				'is_offloaded' => false,
				'label'        => __( 'Offloading...', 'omnioffloader' ),
			);
		}

		if ( 'queued' === $status ) {
			return array(
				'status'       => 'queued',
				'is_offloaded' => false,
				'label'        => __( 'Queued for offload', 'omnioffloader' ),
			);
		}

		$has_errors = ( 'failed' === $status ) || ( is_array( $errors ) && ! empty( $errors ) );

		if ( $has_errors ) {
			$last_error = '';
			if ( is_array( $errors ) && ! empty( $errors ) ) {
				$last = end( $errors );
				if ( is_array( $last ) && ! empty( $last['message'] ) ) {
					$last_error = (string) $last['message'];
				}
			}

			return array(
				'status'       => 'failed',
				'is_offloaded' => false,
				'error'        => $last_error,
				'label'        => '' !== $last_error
					? sprintf(
						/* translators: %s: Error message */
						__( 'Failed — %s', 'omnioffloader' ),
						$last_error
					)
					: __( 'Offload failed', 'omnioffloader' ),
			);
		}

		return array(
			'status'       => 'local',
			'is_offloaded' => false,
			'label'        => __( 'Local', 'omnioffloader' ),
		);
	}
}
