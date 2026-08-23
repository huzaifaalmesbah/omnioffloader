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

define( 'OMNIOFFLOADER_DIR', plugin_dir_path( __FILE__ ) );

if ( file_exists( OMNIOFFLOADER_DIR . 'vendor/autoload.php' ) ) {
	require_once OMNIOFFLOADER_DIR . 'vendor/autoload.php';
}

OmniOffloader\Core\Uninstaller::uninstall();
