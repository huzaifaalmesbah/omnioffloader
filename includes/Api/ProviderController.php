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
	 * On a multisite network, a site administrator may not point the
	 * plugin at a private or local address: the server would make
	 * requests into its own network and report what it found. A network
	 * administrator may (a self-hosted MinIO, for example). Single sites
	 * allow it, since their administrator owns the server.
	 *
	 * Without path-style addressing the SDK sends requests to
	 * `{bucket}.{endpoint host}`, not to the endpoint itself, so that host
	 * is checked too. A name that resolves differently by the time the
	 * request is made (DNS rebinding) is not covered.
	 *
	 * @since 0.1.4
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $values       Credential values from the request.
	 * @return \WP_Error|null
	 */
	private static function endpoint_refusal( $provider_key, array $values ) {
		if ( ! is_multisite() || current_user_can( 'manage_network_options' ) ) {
			return null;
		}

		$allowed = true;

		foreach ( self::request_urls( $provider_key, $values ) as $url ) {
			if ( false === wp_http_validate_url( $url ) ) {
				$allowed = false;
				break;
			}
		}

		if ( $allowed ) {
			return null;
		}

		return new \WP_Error(
			'omnioffloader_endpoint_not_allowed',
			__( 'On a multisite network, only a network administrator can use a private or local endpoint address, or a port other than 80, 443 or 8080. Use the provider\'s public endpoint, or ask a network administrator.', 'omnioffloader' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Addresses the S3 client will send requests to for these credential
	 * values: the custom endpoint, and `{bucket}.{endpoint host}` unless
	 * path-style addressing is on. Empty when the request sets neither an
	 * endpoint nor a bucket, or the provider has no custom endpoint.
	 *
	 * @since 0.1.4
	 *
	 * @param string               $provider_key Provider key.
	 * @param array<string, mixed> $values       Credential values from the request.
	 * @return string[]
	 */
	public static function request_urls( $provider_key, array $values ) {
		// Only a request that sets where requests go is checked (a change of
		// path_style alone moves requests to {bucket}.{host} too).
		if ( '' === trim( (string) ( $values['endpoint'] ?? '' ) ) && '' === trim( (string) ( $values['bucket'] ?? '' ) ) && ! array_key_exists( 'path_style', $values ) ) {
			return array();
		}

		$value = static function ( $field ) use ( $provider_key, $values ) {
			$given = trim( (string) ( $values[ $field ] ?? '' ) );

			return '' !== $given ? $given : trim( Helpers::get_credential( $provider_key, $field ) );
		};

		// The address the client is built from (see the providers).
		$endpoint = Helpers::normalize_url( $value( 'endpoint' ) );

		if ( '' === $endpoint ) {
			return array();
		}

		$urls   = array( $endpoint );
		$bucket = $value( 'bucket' );
		// Only S3-compatible honours path_style; R2, B2 and Spaces always
		// address the bucket as a subdomain whatever the field says.
		$path_style = 's3_compatible' === $provider_key && in_array( $value( 'path_style' ), array( '1', 'true' ), true );
		$parts      = wp_parse_url( $endpoint );

		if ( '' !== $bucket && ! $path_style && is_array( $parts ) && ! empty( $parts['host'] ) ) {
			$urls[] = ( $parts['scheme'] ?? 'https' ) . '://' . $bucket . '.' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		}

		return $urls;
	}

	/**
	 * A region is a short code such as "eu-central-1". Some providers build
	 * their endpoint from it (Wasabi: https://s3.{region}.wasabisys.com), so
	 * anything else could change the host the server connects to.
	 *
	 * @since 0.1.4
	 *
	 * @param string $region Region from the request.
	 * @return \WP_Error|null
	 */
	private static function region_refusal( $region ) {
		$region = trim( (string) $region );

		if ( '' === $region || 1 === preg_match( '/^[A-Za-z0-9-]+$/', $region ) ) {
			return null;
		}

		return new \WP_Error(
			'omnioffloader_invalid_region',
			__( 'The region may only contain letters, numbers and dashes, for example eu-central-1.', 'omnioffloader' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Which storage-location field a save changes, if any: the bucket, or
	 * the endpoint or region that says where it is.
	 *
	 * @since 0.1.4
	 *
	 * @param string                $provider_key Provider key.
	 * @param array<string, string> $to_save      Fields about to be saved.
	 * @return string The changed field, or '' when none.
	 */
	private static function changed_location( $provider_key, array $to_save ) {
		foreach ( array( 'bucket', 'endpoint', 'region' ) as $field ) {
			if ( ! isset( $to_save[ $field ] ) ) {
				continue;
			}

			$old = untrailingslashit( trim( Helpers::get_credential( $provider_key, $field ) ) );
			$new = untrailingslashit( trim( $to_save[ $field ] ) );

			if ( '' !== $old && $new !== $old ) {
				return $field;
			}
		}

		return '';
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

		$refused = self::endpoint_refusal( $provider_key, $to_save ) ?? self::region_refusal( $to_save['region'] ?? '' );

		if ( null !== $refused ) {
			return $refused;
		}

		// A provider keeps one storage location (bucket, plus the endpoint or
		// region that says where it is), so media offloaded to the old one
		// could no longer load or be brought back. Bring it back first. In
		// Safe mode a location of its own is how a copy stops sharing the
		// live one.
		$moved = self::changed_location( $provider_key, $to_save );

		if ( '' !== $moved && ! SafeMode::is_active() ) {
			$old_bucket = Helpers::get_credential( $provider_key, 'bucket' );
			$offloaded  = Helpers::count_offloaded_in_bucket( $provider_key, $old_bucket );

			if ( $offloaded > 0 ) {
				return new \WP_Error(
					'omnioffloader_bucket_locked',
					sprintf(
						/* translators: 1: number of offloaded attachments, 2: bucket name. */
						_n(
							'%1$s offloaded attachment is stored in the bucket "%2$s". Changing the bucket, its region or its endpoint would break its URL and Bring Back. First bring all media back to the server (Bring Back), then make the change and offload again.',
							'%1$s offloaded attachments are stored in the bucket "%2$s". Changing the bucket, its region or its endpoint would break their URLs and Bring Back. First bring all media back to the server (Bring Back), then make the change and offload again.',
							$offloaded,
							'omnioffloader'
						),
						number_format_i18n( $offloaded ),
						$old_bucket
					),
					array( 'status' => 409 )
				);
			}
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
	 * @return \WP_REST_Response|\WP_Error
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
			// Only this provider's own fields, as when saving credentials.
			$known = array_column( $provider->credential_fields(), 'name' );

			$sanitized = array();
			foreach ( $credentials as $field_name => $field_val ) {
				$field_name = sanitize_key( (string) $field_name );

				if ( in_array( $field_name, $known, true ) ) {
					$sanitized[ $field_name ] = sanitize_text_field( (string) $field_val );
				}
			}

			$refused = self::endpoint_refusal( $provider_key, $sanitized ) ?? self::region_refusal( $sanitized['region'] ?? '' );

			if ( null !== $refused ) {
				return $refused;
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
