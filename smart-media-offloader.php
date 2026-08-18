<?php
/**
 * Plugin Name: Smart Media Offloader
 * Plugin URI: https://wordpress.org/plugins/smart-media-offloader/
 * Description: Offload WordPress media to Cloudflare R2, serve it from your CDN, and bring files back to your server anytime.
 * Author: Huzaifa Al Mesbah
 * Author URI: https://profiles.wordpress.org/huzaifaalmesbah/
 * Text Domain: smart-media-offloader
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Version: 0.1.0
 *
 * @package SmartMediaOffloader
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current version of the plugin.
 *
 * @since 0.1.0
 */
define( 'SMOWP_VERSION', '0.1.0' );
define( 'SMOWP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMOWP_URL', plugin_dir_url( __FILE__ ) );
define( 'SMOWP_ASSETS_URL', SMOWP_URL . 'assets/' );
define( 'SMOWP_BASENAME', plugin_basename( __FILE__ ) );
define( 'SMOWP_FILE', __FILE__ );

// Load Composer autoloader (includes PSR-4 plugin classes and AWS SDK).
if ( file_exists( SMOWP_DIR . 'vendor/autoload.php' ) ) {
	require_once SMOWP_DIR . 'vendor/autoload.php';
}

/**
 * The code that runs during plugin activation.
 *
 * @return void
 */
function smowp_activate_plugin() {
	SmartMediaOffloader\Core\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @return void
 */
function smowp_deactivate_plugin() {
	SmartMediaOffloader\Core\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'smowp_activate_plugin' );
register_deactivation_hook( __FILE__, 'smowp_deactivate_plugin' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function smowp_init() {
	SmartMediaOffloader\Core\Plugin::get_instance()->run();
}
add_action( 'plugins_loaded', 'smowp_init' );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	SmartMediaOffloader\Cli\CommandRegistrar::register();
}
