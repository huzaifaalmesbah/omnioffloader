<?php
/**
 * Prevents filename reuse when local copies were removed.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UniqueFilenameObserver
 *
 * WordPress makes upload filenames unique by checking the filesystem.
 * Under Full Cloud Migration the local copies are gone, so a new upload
 * could silently reuse an existing attachment's filename and overwrite
 * its cloud objects. This adds a database check against attached files.
 */
class UniqueFilenameObserver implements ObserverInterface {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'wp_unique_filename', array( $this, 'ensure_unique' ), 10, 4 );
	}

	/**
	 * Make the filename unique against the media library database too.
	 *
	 * A name is taken when an attachment in the same folder uses it for any
	 * file it has in the bucket: the attached file, the original of a scaled
	 * image, a size, an alternate format, or an edit backup. Core only finds
	 * those on disk, and a cleanup policy may have deleted them.
	 *
	 * @param string      $filename Proposed unique filename.
	 * @param string      $ext      File extension including the dot.
	 * @param string      $dir      Destination directory.
	 * @param string|null $unique_filename_callback Custom callback (unused).
	 * @return string
	 */
	public function ensure_unique( $filename, $ext, $dir, $unique_filename_callback ) {
		unset( $unique_filename_callback );

		$base_dir = trailingslashit( wp_normalize_path( wp_get_upload_dir()['basedir'] ) );
		$dir      = trailingslashit( wp_normalize_path( $dir ) );

		// Not a media upload: nothing of ours can collide.
		if ( 0 !== strpos( $dir, $base_dir ) ) {
			return $filename;
		}

		// Relative folder of the destination, e.g. "2026/08/".
		$prefix = ltrim( substr( $dir, strlen( $base_dir ) ), '/' );
		$name   = pathinfo( $filename, PATHINFO_FILENAME );
		$taken  = $this->names_in_use( $prefix, $name );
		$stem   = $name;
		$number = 0;

		while ( self::is_taken( $stem, $taken ) ) {
			++$number;
			$stem = $name . '-' . $number;
		}

		return 0 === $number ? $filename : $stem . $ext;
	}

	/**
	 * Whether a file name stem collides with a name in use: the same name
	 * in any format, or one of its generated variants.
	 *
	 * @param string              $stem  File name without extension.
	 * @param array<string, true> $taken File names in use in the folder.
	 * @return bool
	 */
	private static function is_taken( $stem, array $taken ) {
		$pattern = '/^' . preg_quote( $stem, '/' ) . '(?:-scaled|-rotated|-\d+x\d+)?\.[^.\/]+$/i';

		foreach ( array_keys( $taken ) as $file ) {
			if ( preg_match( $pattern, $file ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * File names in use in a folder that start with $name, as a lookup set.
	 *
	 * Two queries per upload, instead of one per "-N" suffix tried.
	 *
	 * @since 0.1.3
	 * @since 0.1.4 Includes the files listed in attachment metadata.
	 *
	 * @param string $prefix Relative folder with a trailing slash, or ''.
	 * @param string $name   File name without extension.
	 * @return array<string, true>
	 */
	private function names_in_use( $prefix, $name ) {
		global $wpdb;

		$taken = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single lookup during upload.
		$paths = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s",
				$wpdb->esc_like( $prefix . $name ) . '%'
			)
		);

		foreach ( $paths as $path ) {
			$taken[ wp_basename( (string) $path ) ] = true;
		}

		// Metadata of the folder's attachments that mention the name. The
		// folder filter runs on the attached file, the name filter on the
		// serialized metadata.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single lookup during upload.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.meta_value AS attached, m.meta_value AS meta
				 FROM {$wpdb->postmeta} f
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = f.post_id AND m.meta_key IN ( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' )
				 WHERE f.meta_key = '_wp_attached_file' AND f.meta_value LIKE %s AND m.meta_value LIKE %s",
				$wpdb->esc_like( $prefix ) . '%',
				'%' . $wpdb->esc_like( $name ) . '%'
			)
		);

		foreach ( $rows as $row ) {
			$folder = dirname( (string) $row->attached );

			// Same folder only, not a subfolder of it.
			if ( ( '.' === $folder ? '' : trailingslashit( $folder ) ) !== $prefix ) {
				continue;
			}

			foreach ( self::files_in( maybe_unserialize( $row->meta ) ) as $file ) {
				$taken[ wp_basename( $file ) ] = true;
			}
		}

		return $taken;
	}

	/**
	 * Every file name in attachment metadata or edit backups: the "file"
	 * and "original_image" values at any depth (sizes, sources).
	 *
	 * @param mixed $meta Unserialized meta value.
	 * @return string[]
	 */
	private static function files_in( $meta ) {
		if ( ! is_array( $meta ) ) {
			return array();
		}

		$files = array();

		foreach ( $meta as $key => $value ) {
			if ( is_array( $value ) ) {
				$files = array_merge( $files, self::files_in( $value ) );
			} elseif ( is_string( $value ) && '' !== $value && in_array( $key, array( 'file', 'original_image' ), true ) ) {
				$files[] = $value;
			}
		}

		return $files;
	}
}
