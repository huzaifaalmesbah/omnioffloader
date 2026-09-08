<?php
/**
 * Core REST controller: settings, provider list, stats.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Traits\OptionsHelper;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CoreController
 */
class CoreController extends BaseController {

	use OptionsHelper;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_get_route( '/core/settings', array( $this, 'get_settings_response' ) );

		$this->register_post_route(
			'/core/settings',
			array( $this, 'update_settings_response' ),
			array(
				'settings' => array(
					'type'     => 'object',
					'required' => true,
				),
			)
		);

		$this->register_get_route( '/core/providers', array( $this, 'get_providers_response' ) );

		$this->register_get_route( '/core/stats', array( $this, 'get_stats_response' ) );
	}

	/**
	 * GET /core/settings
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings_response() {
		return rest_ensure_response( self::get_settings() );
	}

	/**
	 * POST /core/settings
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_settings_response( WP_REST_Request $request ) {
		$settings = $request->get_param( 'settings' );

		if ( ! is_array( $settings ) ) {
			return new \WP_Error(
				'omnioffloader_invalid_settings',
				__( 'Settings must be an object.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $settings['provider'] ) && '' !== $settings['provider'] && ! ProviderRegistry::has( (string) $settings['provider'] ) ) {
			return new \WP_Error(
				'omnioffloader_invalid_provider',
				__( 'Unknown cloud provider.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		if ( isset( $settings['secondary_provider'] ) && '' !== $settings['secondary_provider'] && ! ProviderRegistry::has( (string) $settings['secondary_provider'] ) ) {
			return new \WP_Error(
				'omnioffloader_invalid_secondary_provider',
				__( 'Unknown secondary cloud provider.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		$saved = self::update_settings( $settings );

		return rest_ensure_response( $saved );
	}

	/**
	 * GET /core/providers
	 *
	 * Registry list with each provider's credential field definitions —
	 * the React UI renders credential forms generically from this.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_providers_response() {
		$providers = array();

		foreach ( ProviderRegistry::get_providers() as $key => $class ) {
			try {
				$provider = ProviderRegistry::create( $key );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}

			$providers[] = array(
				'key'    => $provider->get_key(),
				'name'   => $provider->get_name(),
				'fields' => $provider->credential_fields(),
			);
		}

		return rest_ensure_response( $providers );
	}

	/**
	 * GET /core/stats
	 *
	 * @return \WP_REST_Response
	 */
	public function get_stats_response() {
		$stats    = Helpers::get_media_stats();
		$settings = self::get_settings();

		$stats['configured']           = self::is_configured();
		$stats['provider']             = self::get_provider_key();
		$stats['secondary_configured'] = self::is_secondary_configured();
		$stats['secondary_active']     = ! empty( $settings['secondary_active'] );
		$stats['secondary_provider']   = self::get_secondary_provider_key();

		return rest_ensure_response( $stats );
	}
}
