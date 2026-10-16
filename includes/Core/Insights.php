<?php
/**
 * Opt-in usage insights (Appsero).
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boots the Appsero client, which asks an administrator before sending
 * anything. Nothing is collected until they allow it in the admin notice.
 *
 * @since 0.1.6
 */
class Insights {

	/**
	 * Appsero project hash.
	 *
	 * @var string
	 */
	const HASH = '7ab27794-3606-4112-885b-93ca830f4ed0';

	/**
	 * The Appsero insights instance, once booted.
	 *
	 * @var \Appsero\Insights|null
	 */
	private static $insights = null;

	/**
	 * Start the client in wp-admin and cron only.
	 *
	 * The front end is left alone: the client reads the plugin header on
	 * construction, and visitors' requests never need it. Cron is kept so the
	 * weekly report runs once an administrator opted in.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		if ( ! class_exists( \Appsero\Client::class ) ) {
			return;
		}

		$client = new \Appsero\Client( self::HASH, 'OmniOffloader', OMNIOFFLOADER_FILE );

		/**
		 * The SDK's own docblock names a non-existent class.
		 *
		 * @var \Appsero\Insights $insights
		 */
		$insights = $client->insights();
		$insights->init();
		self::$insights = $insights;

		// The SDK has no opt-out control of its own once the notice is gone.
		add_filter( 'plugin_action_links_' . OMNIOFFLOADER_BASENAME, array( self::class, 'add_optout_link' ), 20 );
	}

	/**
	 * Add a "Stop sharing usage data" link to the plugin row while sharing is on.
	 *
	 * @param array<string, string> $links Plugin action links.
	 * @return array<string, string>
	 */
	public static function add_optout_link( $links ) {
		if ( null === self::$insights || ! self::$insights->tracking_allowed() || ! current_user_can( 'manage_options' ) ) {
			return $links;
		}

		$url = wp_nonce_url( add_query_arg( 'omnioffloader_tracker_optout', 'true', admin_url( 'plugins.php' ) ), '_wpnonce' );

		$links['omnioffloader_optout'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Stop sharing usage data', 'omnioffloader' )
		);

		return $links;
	}
}
