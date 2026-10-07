<?php
/**
 * Move REST controller: move offloaded media into the current folder.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Services\BulkMoveProcessor;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MoveController
 *
 * The admin side of `wp omnioffloader move` (see BulkMoveProcessor). The
 * target is always the saved Path Prefix, never a folder sent by the client.
 *
 * @since 0.1.4
 */
class MoveController extends BaseController {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_get_route( '/move/preflight', array( $this, 'preflight_response' ) );
		$this->register_post_route(
			'/move/bulk',
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
		$this->register_get_route( '/move/bulk/progress', array( $this, 'progress_response' ) );
		$this->register_post_route( '/move/bulk/cancel', array( $this, 'cancel_bulk_response' ) );
	}

	/**
	 * The bulk move engine, or an error when unconfigured.
	 *
	 * @return BulkMoveProcessor|\WP_Error
	 */
	private function require_engine() {
		$processor = Plugin::get_instance()->get_bulk_move();

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
	 * GET /move/preflight
	 *
	 * How many offloaded attachments are outside the current folder. One
	 * count query; the Tools screen asks once per visit.
	 *
	 * @return \WP_REST_Response
	 */
	public function preflight_response() {
		$folder = FolderClaim::prefix();

		return rest_ensure_response(
			array(
				'folder'    => $folder,
				'elsewhere' => Helpers::count_offloaded_elsewhere( $folder ),
			)
		);
	}

	/**
	 * POST /move/bulk
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_bulk_response( WP_REST_Request $request ) {
		FolderClaim::refresh();

		if ( SafeMode::is_active() ) {
			return SafeMode::refusal();
		}

		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$conflict = Plugin::get_instance()->bulk_job_conflict( 'move' );

		if ( null !== $conflict ) {
			return $conflict;
		}

		$queued = $engine->start( null, (int) $request->get_param( 'limit' ) );

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'state'  => BulkMoveProcessor::get_state(),
			)
		);
	}

	/**
	 * GET /move/bulk/progress
	 *
	 * Also self-heals stalled queues, like the other progress routes.
	 *
	 * @return \WP_REST_Response
	 */
	public function progress_response() {
		$engine = Plugin::get_instance()->get_bulk_move();

		if ( null !== $engine && 'running' === BulkMoveProcessor::get_state()['status'] ) {
			$engine->process_queue_now();
		}

		return rest_ensure_response( BulkMoveProcessor::get_state() );
	}

	/**
	 * POST /move/bulk/cancel
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_bulk_response() {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$engine->cancel_now();

		return rest_ensure_response( BulkMoveProcessor::get_state() );
	}
}
