<?php
/**
 * Provider REST controller: credentials + connection test.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Traits\OptionsHelper;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProviderController
 *
 * Secrets are never returned raw: password fields come back empty with
 * an `is_set` flag, and saving a masked/empty password keeps the stored
 * secret intact.
 */
class ProviderController extends BaseController {

	use OptionsHelper;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$provider_arg = array(
			'provider' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => static function ( $value ) {
					if ( ProviderRegistry::has( (string) $value ) ) {
						return true;
					}

					return new \WP_Error(
						'omnioffloader_invalid_provider',
						__( 'Unknown cloud storage provider.', 'omnioffloader' ),
						array( 'status' => 400 )
					);
				},
			),
		);

		$this->register_get_route( '/provider/credentials', array( $this, 'get_credentials_response' ), $provider_arg );

		$this->register_post_route(
			'/provider/credentials',
			array( $this, 'update_credentials_response' ),
			$provider_arg + array(
				'values' => array(
					'type'                 => 'object',
					'required'             => true,
					'additionalProperties' => array( 'type' => 'string' ),
					'validate_callback'    => 'rest_validate_request_arg',
				),
			)
		);

		$this->register_post_route(
			'/provider/test-connection',
			array( $this, 'test_connection_response' ),
			$provider_arg + array(
				'credentials' => array(
					'type'                 => 'object',
					'required'             => false,
					'additionalProperties' => array( 'type' => 'string' ),
					'validate_callback'    => 'rest_validate_request_arg',
				),
			)
		);
	}

	/**
	 * GET /provider/credentials?provider=…
	 *
	 * Field definitions merged with per-site state: value (masked for
	 * passwords), locked (wp-config constant) and is_set.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_credentials_response( WP_REST_Request $request ) {
		$provider_key = (string) $request->get_param( 'provider' );
		$provider     = ProviderRegistry::create( $provider_key );

		$fields = array();

		foreach ( $provider->credential_fields() as $field ) {
			$name   = $field['name'];
			$value  = Helpers::get_credential( $provider_key, $name );
			$locked = Helpers::is_credential_locked( $provider_key, $name );
			$is_set = '' !== $value;

			// Never return secrets; text values locked by constants are
			// returned so the admin can see what applies.
			$exposed = 'password' === $field['type'] ? '' : $value;

			$fields[] = array_merge(
				$field,
				array(
					'value'  => $exposed,
					'locked' => $locked,
					'is_set' => $is_set,
				)
			);
		}

		return rest_ensure_response(
			array(
				'provider' => $provider_key,
				'fields'   => $fields,
			)
		);
	}

	/**
	 * POST /provider/credentials
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_credentials_response( WP_REST_Request $request ) {
		$provider_key = (string) $request->get_param( 'provider' );
		$values       = $request->get_param( 'values' );

		if ( ! is_array( $values ) ) {
			return new \WP_Error(
				'omnioffloader_invalid_credentials',
				__( 'Credential values must be an object.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		$provider    = ProviderRegistry::create( $provider_key );
		$definitions = array();

		foreach ( $provider->credential_fields() as $field ) {
			$definitions[ $field['name'] ] = $field;
		}

		$to_save = array();

		foreach ( $values as $name => $value ) {
			$name = sanitize_key( (string) $name );

			// Only known fields; fields locked by constants are immutable.
			if ( ! isset( $definitions[ $name ] ) || Helpers::is_credential_locked( $provider_key, $name ) ) {
				continue;
			}

			$value = (string) $value;

			// Empty password = "keep the stored secret" (masked round-trip).
			if ( 'password' === $definitions[ $name ]['type'] && '' === $value ) {
				continue;
			}

			$to_save[ $name ] = $value;
		}

		if ( ! empty( $to_save ) ) {
			self::update_provider_credentials( $provider_key, $to_save );

			// A new bucket: check (and claim) this site's folder in it now.
			$active = FolderClaim::enabled() && self::get_provider_key() === $provider_key ? FolderClaim::provider() : null;

			if ( null !== $active ) {
				SafeMode::reset();
				FolderClaim::ensure( $active, true );
			}
		}

		// Return fresh masked state (same shape as GET).
		return $this->get_credentials_response( $request );
	}

	/**
	 * POST /provider/test-connection
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_connection_response( WP_REST_Request $request ) {
		if ( ! class_exists( \Aws\S3\S3Client::class ) ) {
			return rest_ensure_response(
				array(
					'connected'  => false,
					'message'    => __( 'The AWS SDK is not installed. Run "composer install" in the plugin directory.', 'omnioffloader' ),
					'checked_at' => current_time( 'mysql', true ),
				)
			);
		}

		$provider_key = (string) $request->get_param( 'provider' );
		$provider     = ProviderRegistry::create( $provider_key );

		$credentials = $request->get_param( 'credentials' );
		if ( is_array( $credentials ) && ! empty( $credentials ) ) {
			$sanitized = array();
			foreach ( $credentials as $field_name => $field_val ) {
				$sanitized[ sanitize_key( (string) $field_name ) ] = sanitize_text_field( (string) $field_val );
			}
			$provider->set_credential_overrides( $sanitized );
		}

		$result = $provider->check_connection();

		if ( true === $result ) {
			// Read-only: whether this site's folder is free, its own, or
			// another site's (see Core\FolderClaim).
			$prefix = FolderClaim::prefix();
			// A copy of the site would only find the live site's own marker.
			$found  = FolderClaim::enabled() && ! SafeMode::is_copy() ? FolderClaim::inspect( $provider, $prefix ) : array(
				'status' => FolderClaim::UNKNOWN,
				'owner'  => '',
			);
			$folder = '' !== $prefix ? untrailingslashit( $prefix ) : '';

			return rest_ensure_response(
				array(
					'connected'  => true,
					'message'    => FolderClaim::OTHER === $found['status']
						? __( 'Connection successful, but see the warning below.', 'omnioffloader' )
						: __( 'Connection successful.', 'omnioffloader' ),
					'checked_at' => current_time( 'mysql', true ),
					'folder'     => array(
						'status'  => $found['status'],
						'owner'   => $found['owner'],
						'folder'  => $folder,
						'message' => FolderClaim::OTHER === $found['status'] ? FolderClaim::message( $found['owner'], $folder ) : '',
					),
				)
			);
		}

		return rest_ensure_response(
			array(
				'connected'  => false,
				'message'    => $result->get_error_message(),
				'checked_at' => current_time( 'mysql', true ),
				'folder'     => null,
			)
		);
	}
}
