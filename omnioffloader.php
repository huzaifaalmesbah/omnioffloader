<?php
/**
 * Plugin Name: OmniOffloader — Multi-Cloud Media Offload & CDN
 * Plugin URI: https://wordpress.org/plugins/omnioffloader/
 * Description: Offload your media library to Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi or any S3-compatible storage, serve it from a CDN, and restore it anytime with Bring Back.
 * Author: Huzaifa Al Mesbah
 * Author URI: https://profiles.wordpress.org/huzaifaalmesbah/
 * Text Domain: omnioffloader
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Version: 0.1.4
 *
 * @package OmniOffloader
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
define( 'OMNIOFFLOADER_VERSION', '0.1.4' );
define( 'OMNIOFFLOADER_DIR', plugin_dir_path( __FILE__ ) );
define( 'OMNIOFFLOADER_URL', plugin_dir_url( __FILE__ ) );
define( 'OMNIOFFLOADER_ASSETS_URL', OMNIOFFLOADER_URL . 'assets/' );
define( 'OMNIOFFLOADER_BASENAME', plugin_basename( __FILE__ ) );
define( 'OMNIOFFLOADER_FILE', __FILE__ );
define( 'OMNIOFFLOADER_DOCS_URL', 'https://omnioffloader.vercel.app/' );

/**
 * Admin notice shown when the bundled libraries are missing.
 *
 * @return void
 */
function omnioffloader_missing_vendor_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'OmniOffloader is missing its bundled libraries (the vendor folder). Reinstall the plugin from a complete download.', 'omnioffloader' )
	);
}

// Load Composer autoloader (includes PSR-4 plugin classes and AWS SDK).
// Without it every class is missing, so explain instead of a fatal error.
if ( ! file_exists( OMNIOFFLOADER_DIR . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', 'omnioffloader_missing_vendor_notice' );
	return;
}

require_once OMNIOFFLOADER_DIR . 'vendor/autoload.php';

/**
 * The code that runs during plugin activation.
 *
 * @return void
 */
function omnioffloader_activate_plugin() {
	OmniOffloader\Core\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 *
 * @return void
 */
function omnioffloader_deactivate_plugin() {
	OmniOffloader\Core\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'omnioffloader_activate_plugin' );
register_deactivation_hook( __FILE__, 'omnioffloader_deactivate_plugin' );

/**
 * Initialize the plugin.
 *
 * @return void
 */
function omnioffloader_init() {
	OmniOffloader\Core\Plugin::get_instance()->run();
}
add_action( 'plugins_loaded', 'omnioffloader_init' );

// Register WP-CLI commands.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	OmniOffloader\Cli\CommandRegistrar::register();
}
