<?php
/**
 * PSR-4 style autoloader for the plugin's own classes.
 *
 * Composer's vendor/ autoloader is only used for third-party
 * dependencies (AWS SDK); plugin classes load through this file.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader;

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Class Autoloader
 */
class Autoloader {

	/**
	 * Namespace prefix for all plugin classes.
	 *
	 * @var string
	 */
	private const PREFIX = 'SmartMediaOffloader\\';

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function run() {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Autoload a plugin class from the includes/ directory.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$path     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}
