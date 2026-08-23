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
	 * @param string      $filename Proposed unique filename.
	 * @param string      $ext      File extension including the dot.
	 * @param string      $dir      Destination directory.
	 * @param string|null $unique_filename_callback Custom callback (unused).
	 * @return string
	 */
	public function ensure_unique( $filename, $ext, $dir, $unique_filename_callback ) {
		unset( $unique_filename_callback );

		$upload_dir = wp_get_upload_dir();
		$base_dir   = $upload_dir['basedir'];

		// Relative subdir of the destination, e.g. "2026/08".
		$relative = ltrim( str_replace( $base_dir, '', $dir ), '/' );

		$name   = pathinfo( $filename, PATHINFO_FILENAME );
		$number = 0;

		while ( $this->attached_file_exists( ( '' !== $relative ? trailingslashit( $relative ) : '' ) . $filename ) ) {
			++$number;
			$filename = $name . '-' . $number . $ext;
		}

		return $filename;
	}

	/**
	 * Whether any attachment already uses this relative file path.
	 *
	 * @param string $relative_path Path relative to the uploads basedir.
	 * @return bool
	 */
	private function attached_file_exists( $relative_path ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- fast single-record indexed lookup during upload.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				$relative_path
			)
		);

		return null !== $found;
	}
}
