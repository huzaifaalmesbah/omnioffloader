<?php
/**
 * Retention REST controller: apply the retention policy to existing media.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\BulkRetentionProcessor;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RetentionController
 *
 * The run always targets the *saved* retention policy — never a value sent
 * by the client — so what the Settings screen shows is what gets applied.
 *
 * @since 0.1.3
 */
class RetentionController extends BaseController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_get_route(
			'/retention/preflight',
			array( $this, 'preflight_response' ),
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

		$this->register_post_route(
			'/retention/bulk',
			array( $this, 'start_bulk_response' ),
			array(
				'limit' => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);
		$this->register_get_route( '/retention/bulk/progress', array( $this, 'progress_response' ) );
		$this->register_post_route( '/retention/bulk/cancel', array( $this, 'cancel_bulk_response' ) );
	}

	/**
	 * The bulk retention engine, or an error when unconfigured.
	 *
	 * @return BulkRetentionProcessor|\WP_Error
	 */
	private function require_engine() {
		$processor = Plugin::get_instance()->get_bulk_retention();

		if ( null === $processor ) {
			return new \WP_Error(
				'omnioffloader_not_configured',
				__( 'No cloud provider is configured.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

		return $processor;
	}

	/**
	 * GET /retention/preflight
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preflight_response( WP_REST_Request $request ) {
		return rest_ensure_response( Helpers::get_retention_preflight( (bool) $request->get_param( 'refresh' ) ) );
	}

	/**
	 * POST /retention/bulk
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_bulk_response( WP_REST_Request $request ) {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$conflict = Plugin::get_instance()->bulk_job_conflict( 'retention' );

		if ( null !== $conflict ) {
			return $conflict;
		}

		$settings = Plugin::get_settings();
		$policy   = Plugin::sanitize_retention_policy( $settings['retention_policy'] );
		$queued   = $engine->start( $policy, null, (int) $request->get_param( 'limit' ) );

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'state'  => BulkRetentionProcessor::get_state(),
			)
		);
	}

	/**
	 * GET /retention/bulk/progress
	 *
	 * Also self-heals stalled queues, like the other progress routes.
	 *
	 * @return \WP_REST_Response
	 */
	public function progress_response() {
		$engine = Plugin::get_instance()->get_bulk_retention();

		if ( null !== $engine && 'running' === BulkRetentionProcessor::get_state()['status'] ) {
			$engine->process_queue_now();
		}

		return rest_ensure_response( BulkRetentionProcessor::get_state() );
	}

	/**
	 * POST /retention/bulk/cancel
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_bulk_response() {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$engine->cancel_now();

		return rest_ensure_response( BulkRetentionProcessor::get_state() );
	}
}
