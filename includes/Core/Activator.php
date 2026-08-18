<?php
/**
 * Fired during plugin activation.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 */
class Activator {

	/**
	 * Plugin activation tasks.
	 *
	 * @return void
	 */
	public static function activate() {
		// Record the first activated version once.
		if ( null === get_option( 'smowp_first_activated_version', null ) ) {
			update_option( 'smowp_first_activated_version', SMOWP_VERSION, false );
		}

		// Seed the stored version so Migrator has a baseline.
		if ( null === get_option( 'smowp_version', null ) ) {
			update_option( 'smowp_version', SMOWP_VERSION, true );
		}

		// One-time redirect to the settings page after interactive activation.
		set_transient( 'smowp_activation_redirect', true, 30 );
	}
}
