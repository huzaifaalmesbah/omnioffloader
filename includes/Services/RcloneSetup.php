<?php
/**
 * Setup for rclone, generated from this site's settings.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\SafeMode;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Traits\AttachmentHelper;
use OmniOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RcloneSetup
 *
 * Builds the rclone remote, copy command and link command that let a large
 * library be copied with rclone and then linked with
 * `wp omnioffloader offload --link-existing`. Used by the Tools screen and
 * `wp omnioffloader rclone`.
 *
 * The secret key never leaves the server: the shown config carries a
 * placeholder, and only `wp omnioffloader rclone --write-config` puts the
 * real secret into a private file.
 *
 * @since 0.1.4
 */
class RcloneSetup {

	use AttachmentHelper;
	use OptionsHelper;

	/**
	 * Name of the rclone remote.
	 */
	public const REMOTE = 'omnioffloader';

	/**
	 * Placeholder shown instead of the secret key.
	 */
	public const SECRET_PLACEHOLDER = 'PASTE_YOUR_SECRET_ACCESS_KEY_HERE';

	/**
	 * Only the year/month folders: uploads also holds logs, backups and
	 * caches that must never land in a public bucket.
	 */
	public const MEDIA_FILTER = '/[0-9][0-9][0-9][0-9]/**';

	/**
	 * Why rclone setup can't be offered right now, or '' when it can.
	 *
	 * @param ProviderInterface|null $provider Active provider.
	 * @return string
	 */
	public static function refusal( $provider ) {
		if ( null === $provider ) {
			return __( 'Connect a cloud storage provider in Settings first.', 'omnioffloader' );
		}

		// Optional provider method (see AbstractS3Provider::rclone_settings()).
		if ( ! method_exists( $provider, 'rclone_settings' ) ) {
			return __( 'This storage provider has no rclone setup.', 'omnioffloader' );
		}

		if ( null === $provider->get_bucket() ) {
			return __( 'Enter a bucket name in Settings first.', 'omnioffloader' );
		}

		// A copy of the site, or a folder another site uses: copying into
		// the bucket from here would write into someone else's files.
		if ( SafeMode::is_active() ) {
			return SafeMode::message();
		}

		return '';
	}

	/**
	 * The rclone config section for the provider.
	 *
	 * @param ProviderInterface $provider    Provider (see refusal()).
	 * @param bool              $with_secret Include the real secret key —
	 *                                       only for a private file on the server.
	 * @return string
	 */
	public static function config( ProviderInterface $provider, $with_secret = false ) {
		$settings = method_exists( $provider, 'rclone_settings' ) ? $provider->rclone_settings( $with_secret ) : array();

		if ( ! $with_secret || ! isset( $settings['secret_access_key'] ) ) {
			$settings['secret_access_key'] = self::SECRET_PLACEHOLDER;
		}

		// rclone reads the keys in any order; keep the usual one.
		$order = array( 'type', 'provider', 'access_key_id', 'secret_access_key', 'region', 'endpoint', 'force_path_style', 'no_check_bucket' );
		$lines = array( '[' . self::REMOTE . ']' );

		foreach ( array_unique( array_merge( $order, array_keys( $settings ) ) ) as $option ) {
			if ( isset( $settings[ $option ] ) ) {
				$lines[] = $option . ' = ' . $settings[ $option ];
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * This site's folder in the bucket: the Path Prefix, or '' for the root.
	 *
	 * @return string No leading or trailing slash.
	 */
	public static function folder() {
		$settings = self::get_settings();

		if ( empty( $settings['path_prefix_active'] ) || '' === (string) $settings['path_prefix'] ) {
			return '';
		}

		return trim( \OmniOffloader\Core\Helpers::sanitize_path( (string) $settings['path_prefix'] ), '/' );
	}

	/**
	 * Where rclone copies to: "omnioffloader:bucket[/prefix]".
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return string
	 */
	public static function destination( ProviderInterface $provider ) {
		$folder = self::folder();

		return self::REMOTE . ':' . (string) $provider->get_bucket() . ( '' !== $folder ? '/' . $folder : '' );
	}

	/**
	 * This site's uploads folder on the server (per site on multisite).
	 *
	 * @return string
	 */
	public static function source() {
		$uploads = wp_upload_dir( null, false );

		return untrailingslashit( (string) $uploads['basedir'] );
	}

	/**
	 * The rclone copy command.
	 *
	 * @param ProviderInterface $provider   Provider.
	 * @param string            $files_from File with the exact list of files
	 *                                      to copy, or '' for the year/month folders.
	 * @param bool              $dry_run    Add --dry-run.
	 * @return string
	 */
	public static function copy_command( ProviderInterface $provider, $files_from = '', $dry_run = false ) {
		$lines = array(
			'rclone copy ' . self::quote( self::source() ) . ' ' . self::quote( self::destination( $provider ) ),
			'' !== $files_from ? '--files-from ' . self::quote( $files_from ) : '--include ' . self::quote( self::MEDIA_FILTER ),
			'--transfers 32 --checkers 32 --progress' . ( $dry_run ? ' --dry-run' : '' ),
		);

		return implode( " \\\n  ", $lines );
	}

	/**
	 * Things to fix or know before copying.
	 *
	 * @return string[]
	 */
	public static function warnings() {
		$warnings = array();
		$settings = self::get_settings();

		if ( ! empty( $settings['object_versioning'] ) ) {
			$warnings[] = __( 'Object Versioning is on: files go to a new timestamped folder, so files copied with rclone can never be linked. Turn it off in Settings → Behavior first.', 'omnioffloader' );
		}

		if ( ! get_option( 'uploads_use_yearmonth_folders' ) ) {
			$warnings[] = __( 'This site does not sort uploads into year/month folders, so the copy command below would miss files saved at the top of the uploads folder. Run “wp omnioffloader rclone --file-list=<file>” on the server to copy an exact list of your media files instead.', 'omnioffloader' );
		}

		return $warnings;
	}

	/**
	 * Write the uploads-relative path of every file of every attachment not
	 * offloaded yet, one per line — rclone's --files-from format.
	 *
	 * Only files of media library attachments, so nothing else in the
	 * uploads folder can end up in the bucket.
	 *
	 * @param string $path File to write.
	 * @return int|false Files listed, or false when the file can't be written.
	 */
	public static function write_file_list( $path ) {
		global $wpdb;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- WP-CLI writes a local list for rclone.
		$handle = @fopen( $path, 'w' );

		if ( false === $handle ) {
			return false;
		}

		$base  = trailingslashit( wp_normalize_path( self::source() ) );
		$count = 0;
		$last  = 0;

		do {
			$ids = array_map(
				'intval',
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- paged CLI scan.
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.ID AND f.meta_key = '_wp_attached_file'
						 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded'
						 WHERE p.post_type = 'attachment' AND p.post_status != 'trash' AND m.post_id IS NULL AND p.ID > %d
						 ORDER BY p.ID ASC LIMIT 500",
						$last
					)
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			update_meta_cache( 'post', $ids );

			foreach ( $ids as $id ) {
				$files = self::collect_attachment_files( $id );

				foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
					$file = wp_normalize_path( $entry['file'] );

					if ( 0 === strpos( $file, $base ) && file_exists( $file ) ) {
						fwrite( $handle, substr( $file, strlen( $base ) ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						++$count;
					}
				}

				wp_cache_delete( $id, 'post_meta' );
			}

			$last  = (int) end( $ids );
			$batch = count( $ids );
		} while ( 500 === $batch );

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $count;
	}

	/**
	 * Everything the Tools screen shows (GET /provider/rclone).
	 *
	 * @param ProviderInterface|null $provider Active provider.
	 * @return array{available: bool, message: string, remote: string, config: string, copy_dry_run: string, copy: string, link_dry_run: string, link: string, source: string, destination: string, warnings: string[]}
	 */
	public static function snapshot( $provider ) {
		$refusal = self::refusal( $provider );
		$empty   = array(
			'available'    => false,
			'message'      => $refusal,
			'remote'       => self::REMOTE,
			'config'       => '',
			'copy_dry_run' => '',
			'copy'         => '',
			'link_dry_run' => '',
			'link'         => '',
			'source'       => '',
			'destination'  => '',
			'warnings'     => array(),
		);

		if ( '' !== $refusal || null === $provider ) {
			return $empty;
		}

		return array(
			'available'    => true,
			'message'      => '',
			'remote'       => self::REMOTE,
			'config'       => self::config( $provider ),
			'copy_dry_run' => self::copy_command( $provider, '', true ),
			'copy'         => self::copy_command( $provider ),
			'link_dry_run' => 'wp omnioffloader offload --all --link-existing --dry-run',
			'link'         => 'wp omnioffloader offload --all --link-existing',
			'source'       => self::source(),
			'destination'  => self::destination( $provider ),
			'warnings'     => self::warnings(),
		);
	}

	/**
	 * Single-quote a value for a POSIX shell, for display.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function quote( $value ) {
		return "'" . str_replace( "'", "'\\''", (string) $value ) . "'";
	}
}
