<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Cleanup only runs when the site owner opted in via the
 * "Remove all data on uninstall" setting.
 *
 * @package SmartMediaOffloader
 */

// If uninstall not called from WordPress, abort.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die;
}

define( 'SMOWP_DIR', plugin_dir_path( __FILE__ ) );

if ( file_exists( SMOWP_DIR . 'vendor/autoload.php' ) ) {
	require_once SMOWP_DIR . 'vendor/autoload.php';
}

SmartMediaOffloader\Core\Uninstaller::uninstall();
