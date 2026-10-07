<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleanup only runs when the site owner opted in via the
 * "Remove all data on uninstall" setting.
 *
 * @package OmniOffloader
 */

// If uninstall not called from WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

// Already defined when the plugin was loaded in this request (for example
// `wp plugin uninstall --deactivate`).
if ( ! defined( 'OMNIOFFLOADER_DIR' ) ) {
	define( 'OMNIOFFLOADER_DIR', plugin_dir_path( __FILE__ ) );
}

if ( file_exists( OMNIOFFLOADER_DIR . 'vendor/autoload.php' ) ) {
	require_once OMNIOFFLOADER_DIR . 'vendor/autoload.php';
}

OmniOffloader\Core\Uninstaller::uninstall();
