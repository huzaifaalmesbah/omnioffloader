<?php
/**
 * Fired during plugin activation.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

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
		if ( null === get_option( 'omnioffloader_first_activated_version', null ) ) {
			update_option( 'omnioffloader_first_activated_version', OMNIOFFLOADER_VERSION, false );
		}

		// Seed the stored version so Migrator has a baseline.
		if ( null === get_option( 'omnioffloader_version', null ) ) {
			update_option( 'omnioffloader_version', OMNIOFFLOADER_VERSION, true );
		}

		// One-time redirect to the settings page after interactive activation.
		set_transient( 'omnioffloader_activation_redirect', true, 30 );
	}
}
