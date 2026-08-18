<?php
/**
 * WP-CLI command registration.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Cli;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CommandRegistrar
 */
class CommandRegistrar {

	/**
	 * Register all commands under `wp smowp`.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'smowp offload', OffloadCommand::class );
		WP_CLI::add_command( 'smowp restore', RestoreCommand::class );
		WP_CLI::add_command( 'smowp status', StatusCommand::class );
	}
}
