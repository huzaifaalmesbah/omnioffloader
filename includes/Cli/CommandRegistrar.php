<?php
/**
 * WP-CLI command registration.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Cli;

use WP_CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CommandRegistrar
 */
class CommandRegistrar {

	/**
	 * Register all commands under `wp omnioffloader`.
	 *
	 * @return void
	 */
	public static function register() {
		WP_CLI::add_command( 'omnioffloader offload', OffloadCommand::class );
		WP_CLI::add_command( 'omnioffloader restore', RestoreCommand::class );
		WP_CLI::add_command( 'omnioffloader move', MoveCommand::class );
		WP_CLI::add_command( 'omnioffloader retention', RetentionCommand::class );
		WP_CLI::add_command( 'omnioffloader status', StatusCommand::class );
		WP_CLI::add_command( 'omnioffloader stop', StopCommand::class );
	}
}
