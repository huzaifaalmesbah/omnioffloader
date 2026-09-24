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
				// Mirrors OptionsHelper::get_default_settings(); unknown keys and
				// wrong types are refused here rather than silently coerced.
				'settings' => array(
					'type'                 => 'object',
					'required'             => true,
					'properties'           => array(
						'provider'                 => array( 'type' => 'string' ),
						'retention_policy'         => array(
							'type' => 'integer',
							'enum' => array( 0, 1, 2 ),
						),
						'mirror_delete'            => array( 'type' => 'boolean' ),
						'path_prefix_active'       => array( 'type' => 'boolean' ),
						'path_prefix'              => array( 'type' => 'string' ),
						'object_versioning'        => array( 'type' => 'boolean' ),
						'async_upload'             => array( 'type' => 'boolean' ),
						'transfer_speed'           => array(
							'type' => 'string',
							'enum' => array( 'low', 'balanced', 'fast', 'custom' ),
						),
						'transfer_concurrency'     => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 10,
						),
						'bring_back_delete_cloud'  => array( 'type' => 'boolean' ),
						'remove_data_on_uninstall' => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
					'validate_callback'    => 'rest_validate_request_arg',
				),
			)
		);

		$this->register_get_route( '/core/providers', array( $this, 'get_providers_response' ) );

		$this->register_get_route(
			'/core/stats',
			array( $this, 'get_stats_response' ),
			array(
				'refresh' => array(
					'type'              => 'boolean',
					'required'          => false,
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);
	}

	/**
	 * GET /core/settings
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings_response() {
		// Known keys only: the app posts this object back, and the POST schema
		// refuses leftovers from older versions still in the stored option.
		return rest_ensure_response( array_intersect_key( self::get_settings(), self::get_default_settings() ) );
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
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_stats_response( WP_REST_Request $request ) {
		$force = (bool) $request->get_param( 'refresh' );
		$stats = Helpers::get_media_stats( $force );

		$stats['configured'] = self::is_configured();
		$stats['provider']   = self::get_provider_key();

		return rest_ensure_response( $stats );
	}
}
