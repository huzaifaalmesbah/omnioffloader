<?php
/**
 * Version-gated data migrations.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrator
 *
 * Each migration runs once, when upgrading from a version below its gate.
 * Migrations must be idempotent.
 */
class Migrator {

	/**
	 * Run all migrations applicable when coming from $from_version.
	 *
	 * @param string $from_version The previously stored plugin version.
	 * @return void
	 */
	public static function run( $from_version ) {
		// No migrations yet (pre-release line starts at 0.1.0). Pattern for
		// future use: gate on version_compare( $from_version, 'x.y.z', '<' )
		// and call an idempotent migrate_to_x_y_z() method.
		unset( $from_version );
	}
}
