<?php
/**
 * Core REST controller: settings, provider list, stats.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
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
						'serve_from_cloud'         => array( 'type' => 'boolean' ),
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
						'protect_copies'           => array( 'type' => 'boolean' ),
						'safe_mode_bring_back'     => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
					'validate_callback'    => 'rest_validate_request_arg',
				),
			)
		);

		$this->register_get_route( '/core/providers', array( $this, 'get_providers_response' ) );

		$this->register_get_route( '/core/cli-job', array( $this, 'get_cli_job_response' ) );

		$this->register_post_route( '/core/safe-mode/live', array( $this, 'confirm_live_site_response' ) );

		$this->register_post_route( '/core/folder-claim/take-over', array( $this, 'take_over_folder_response' ) );

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

		$before = self::get_settings();
		$after  = array_merge( $before, $settings );

		$old_folder = FolderClaim::prefix( $before );
		$new_folder = FolderClaim::prefix( $after );

		$moved = $new_folder !== $old_folder
			|| (string) ( $before['provider'] ?? '' ) !== (string) ( $after['provider'] ?? '' );

		// Refuse a folder another site sharing the bucket already uses, before
		// a single upload lands in it. Not on a copy of the site: the marker
		// names the live site, and a copy never writes anyway.
		$provider = $moved && FolderClaim::enabled() && ! SafeMode::is_copy() ? FolderClaim::provider( (string) ( $after['provider'] ?? '' ) ) : null;

		if ( null !== $provider ) {
			$prefix = FolderClaim::prefix( $after );
			$found  = FolderClaim::inspect( $provider, $prefix );

			if ( FolderClaim::OTHER === $found['status'] ) {
				return FolderClaim::refusal( $found['owner'], '' !== $prefix ? untrailingslashit( $prefix ) : '' );
			}
		}

		$saved = self::update_settings( $settings );

		// Claim the new folder right away, so another site sees it is taken.
		if ( null !== $provider ) {
			SafeMode::reset();
			FolderClaim::ensure( $provider, true );
		}

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
	 * GET /core/cli-job — the WP-CLI job running now, so the admin screens
	 * can say so and keep their Start buttons off (the start routes refuse
	 * anyway while it runs).
	 *
	 * Reported only while the run's claim is alive: it is renewed every
	 * 30 seconds and expires on its own when the process dies, so a killed
	 * run never shows as running. Two small reads, no media queries.
	 *
	 * @since 0.1.4
	 *
	 * @return \WP_REST_Response
	 */
	public function get_cli_job_response() {
		$claim = get_site_transient( Plugin::cli_job_lock_key() );
		$job   = is_string( $claim ) ? $claim : '';
		$run   = '' !== $job ? Plugin::get_cli_run( true ) : null;

		// The record is this run's only when it names the same job.
		if ( null !== $run && $job !== (string) $run['job'] ) {
			$run = null;
		}

		return rest_ensure_response(
			array(
				'running'   => '' !== $job,
				'job'       => $job,
				'processed' => (int) ( $run['processed'] ?? 0 ),
				'total'     => (int) ( $run['total'] ?? 0 ),
				'failed'    => (int) ( $run['failed'] ?? 0 ),
				'started'   => (int) ( $run['started'] ?? 0 ),
			)
		);
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
		// Never cached: the address check is one comparison.
		$stats['safe_mode'] = SafeMode::status();

		return rest_ensure_response( $stats );
	}

	/**
	 * POST /core/safe-mode/live — "This is the live site".
	 *
	 * Records the current address as the live site's, which ends an
	 * address-based Safe mode (a real domain move). The constant and the
	 * environment type keep Safe mode on regardless: they are explicit.
	 *
	 * @since 0.1.3
	 *
	 * @return \WP_REST_Response
	 */
	public function confirm_live_site_response() {
		$old = SafeMode::status()['live_address'];

		SafeMode::record_live_site();

		// The bucket folder's marker still names the old address: move the
		// claim along with the site instead of reporting a conflict.
		$provider = FolderClaim::provider();

		if ( null !== $provider && '' !== $old && ! SafeMode::is_copy() ) {
			FolderClaim::follow_move( $provider, $old );
		}

		return rest_ensure_response( SafeMode::status() );
	}

	/**
	 * POST /core/folder-claim/take-over — "This folder belongs to this site".
	 *
	 * Rewrites the bucket folder's marker with this site's address, ending a
	 * folder conflict. For a site that moved, or whose other site is gone.
	 *
	 * @since 0.1.3
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function take_over_folder_response() {
		if ( SafeMode::is_copy() ) {
			return SafeMode::refusal();
		}

		$provider = FolderClaim::provider();

		if ( null === $provider ) {
			return new \WP_Error(
				'omnioffloader_not_configured',
				__( 'No cloud storage provider is configured.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		if ( ! FolderClaim::take_over( $provider ) ) {
			return new \WP_Error(
				'omnioffloader_folder_claim_failed',
				__( 'Could not write to the bucket. Check the connection in Settings and try again.', 'omnioffloader' ),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response( SafeMode::status() );
	}
}
