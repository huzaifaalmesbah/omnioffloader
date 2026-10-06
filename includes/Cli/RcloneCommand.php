<?php
/**
 * WP-CLI: rclone setup for copying a large library.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\RcloneSetup;
use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RcloneCommand
 *
 * @since 0.1.4
 */
class RcloneCommand {

	/**
	 * Show the rclone setup for this site, built from its settings.
	 *
	 * Prints an rclone remote for the configured bucket, the command that
	 * copies this site's media into its folder (Path Prefix included), and
	 * the command that links the copied files afterwards. The secret key is
	 * never printed: the shown remote has a placeholder, and --write-config
	 * saves the real key to rclone's config file instead.
	 *
	 * ## OPTIONS
	 *
	 * [--write-config]
	 * : Save the remote, with your secret key, to rclone's config file
	 * (readable only by your user) instead of printing it.
	 *
	 * [--config-file=<file>]
	 * : rclone config file for --write-config. Default: $RCLONE_CONFIG, or
	 * ~/.config/rclone/rclone.conf.
	 *
	 * [--force]
	 * : Replace an "omnioffloader" remote that is already in the config file.
	 *
	 * [--file-list=<file>]
	 * : Write the exact list of media files not offloaded yet, and copy with
	 * rclone --files-from instead of the year/month folders. Use it when
	 * uploads are not sorted into year/month folders.
	 *
	 * ## EXAMPLES
	 *
	 *     wp omnioffloader rclone
	 *     wp omnioffloader rclone --write-config
	 *     wp omnioffloader rclone --write-config --file-list=media-files.txt
	 *
	 * @param string[]              $args       Positional args (unused).
	 * @param array<string, string> $assoc_args Named args.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		unset( $args );

		$provider = Plugin::get_instance()->get_provider();
		$refusal  = RcloneSetup::refusal( $provider );

		if ( '' !== $refusal || null === $provider ) {
			WP_CLI::error( '' !== $refusal ? $refusal : 'No storage provider is configured.' );
		}

		foreach ( RcloneSetup::warnings() as $warning ) {
			WP_CLI::warning( $warning );
		}

		WP_CLI::log( '' );

		if ( isset( $assoc_args['write-config'] ) ) {
			$path = $this->write_config( $provider, $assoc_args );
			WP_CLI::success( sprintf( 'Saved the rclone remote "%s" to %s, with your secret key. Only your user can read the file.', RcloneSetup::REMOTE, $path ) );
		} else {
			WP_CLI::log( sprintf( '1. Add this remote to your rclone config file (`rclone config file` shows where it is). Replace %s with your Secret Access Key, or run this command again with --write-config to save it with the key for you:', RcloneSetup::SECRET_PLACEHOLDER ) );
			WP_CLI::log( '' );
			WP_CLI::log( RcloneSetup::config( $provider ) );
		}

		$list = '';

		if ( ! empty( $assoc_args['file-list'] ) ) {
			$list  = (string) $assoc_args['file-list'];
			$count = RcloneSetup::write_file_list( $list );

			if ( false === $count ) {
				WP_CLI::error( sprintf( 'Cannot write the file list %s.', $list ) );
			}

			$list = (string) realpath( $list );
			WP_CLI::log( sprintf( 'Listed %d media file(s) not offloaded yet in %s.', $count, $list ) );
			WP_CLI::log( '' );
		}

		WP_CLI::log( '2. Check the connection, then copy (first with --dry-run, which copies nothing):' );
		WP_CLI::log( '' );
		WP_CLI::log( 'rclone ls ' . RcloneSetup::destination( $provider ) . ' | head' );
		WP_CLI::log( '' );
		WP_CLI::log( RcloneSetup::copy_command( $provider, $list, true ) );
		WP_CLI::log( '' );
		WP_CLI::log( RcloneSetup::copy_command( $provider, $list ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'For a long copy, put `nohup` in front and `> rclone.log 2>&1 &` at the end, so it keeps going after you close the terminal.' );
		WP_CLI::log( '' );
		WP_CLI::log( '3. Link the copied files (only missing or different files are uploaded):' );
		WP_CLI::log( '' );
		WP_CLI::log( 'wp omnioffloader offload --all --link-existing --dry-run' );
		WP_CLI::log( 'wp omnioffloader offload --all --link-existing' );
	}

	/**
	 * Save the remote, with the secret key, to rclone's config file.
	 *
	 * @param \OmniOffloader\Providers\ProviderInterface $provider   Provider.
	 * @param array<string, string>                      $assoc_args Named args.
	 * @return string The config file.
	 */
	private function write_config( $provider, array $assoc_args ) {
		$config = RcloneSetup::config( $provider, true );

		if ( false !== strpos( $config, RcloneSetup::SECRET_PLACEHOLDER ) ) {
			WP_CLI::error( 'No Secret Access Key is saved for this provider. Enter it in Settings first.' );
		}

		$path = $this->config_path( $assoc_args );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the user's own rclone config file.
		$current = is_file( $path ) ? (string) file_get_contents( $path ) : '';
		$header  = '/^\[' . preg_quote( RcloneSetup::REMOTE, '/' ) . '\]\s*$/m';

		if ( preg_match( $header, $current ) ) {
			if ( ! isset( $assoc_args['force'] ) ) {
				WP_CLI::error( sprintf( '%s already has a remote named "%s". Pass --force to replace it.', $path, RcloneSetup::REMOTE ) );
			}

			// Drop the old section: its header up to the next one.
			$current = (string) preg_replace( '/^\[' . preg_quote( RcloneSetup::REMOTE, '/' ) . '\]\s*\R(?:(?!\[).*(?:\R|$))*/m', '', $current );
		}

		$dir = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				WP_CLI::error( sprintf( 'Cannot create %s.', $dir ) );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- private config folder.
			chmod( $dir, 0700 );
		}

		$current = rtrim( $current );
		$content = ( '' !== $current ? $current . "\n\n" : '' ) . $config;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the user's own rclone config file, outside WordPress.
		if ( false === file_put_contents( $path, $content ) ) {
			WP_CLI::error( sprintf( 'Cannot write %s. Pass --config-file=<file> with a writable path.', $path ) );
		}

		// It holds the secret key.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- private config file.
		chmod( $path, 0600 );

		return $path;
	}

	/**
	 * Where rclone reads its config from.
	 *
	 * @param array<string, string> $assoc_args Named args.
	 * @return string
	 */
	private function config_path( array $assoc_args ) {
		if ( ! empty( $assoc_args['config-file'] ) ) {
			return (string) $assoc_args['config-file'];
		}

		$env = (string) getenv( 'RCLONE_CONFIG' );

		if ( '' !== $env ) {
			return $env;
		}

		$xdg = (string) getenv( 'XDG_CONFIG_HOME' );

		if ( '' !== $xdg ) {
			return rtrim( $xdg, '/' ) . '/rclone/rclone.conf';
		}

		$home = (string) getenv( 'HOME' );

		if ( '' === $home ) {
			WP_CLI::error( 'Cannot find your home folder. Pass --config-file=<file>.' );
		}

		return rtrim( $home, '/' ) . '/.config/rclone/rclone.conf';
	}
}
