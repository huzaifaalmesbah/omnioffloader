<?php
/**
 * Offload REST controller: single item, bulk queue, progress, errors.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Api;

use SmartMediaOffloader\Core\Plugin;
use SmartMediaOffloader\Services\AttachmentUploader;
use SmartMediaOffloader\Services\BulkOffloadProcessor;
use SmartMediaOffloader\Traits\AttachmentHelper;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OffloadController
 */
class OffloadController extends BaseController {

	use AttachmentHelper;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_post_route(
			'/offload/attachment/(?P<id>\d+)',
			array( $this, 'offload_attachment_response' ),
			array(
				'id' => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			)
		);

		$this->register_post_route( '/offload/bulk', array( $this, 'start_bulk_response' ) );
		$this->register_get_route( '/offload/bulk/progress', array( $this, 'progress_response' ) );
		$this->register_post_route( '/offload/bulk/cancel', array( $this, 'cancel_bulk_response' ) );

		$this->register_get_route(
			'/offload/errors',
			array( $this, 'errors_response' ),
			array(
				'per_page' => array(
					'type'              => 'integer',
					'default'           => 20,
					'minimum'           => 1,
					'maximum'           => 100,
					'sanitize_callback' => 'absint',
				),
			)
		);
	}

	/**
	 * The engine (provider + processor), or an error when unconfigured.
	 *
	 * @return BulkOffloadProcessor|\WP_Error
	 */
	private function require_engine() {
		$processor = Plugin::get_instance()->get_bulk_offload();

		if ( null === $processor ) {
			return new \WP_Error(
				'smowp_not_configured',
				__( 'No cloud provider is configured.', 'smart-media-offloader' ),
				array( 'status' => 409 )
			);
		}

		return $processor;
	}

	/**
	 * POST /offload/attachment/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function offload_attachment_response( WP_REST_Request $request ) {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$attachment_id = (int) $request->get_param( 'id' );

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error(
				'smowp_not_attachment',
				__( 'Not a media attachment.', 'smart-media-offloader' ),
				array( 'status' => 404 )
			);
		}

		$provider = Plugin::get_instance()->get_provider();
		$uploader = new AttachmentUploader( $provider );
		$ok       = $uploader->upload( $attachment_id );

		return rest_ensure_response(
			array(
				'offloaded' => $ok,
				'errors'    => get_post_meta( $attachment_id, 'smowp_error_log', true ),
			)
		);
	}

	/**
	 * POST /offload/bulk
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_bulk_response() {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		if ( $engine->is_active() ) {
			return new \WP_Error(
				'smowp_bulk_running',
				__( 'A bulk offload is already running.', 'smart-media-offloader' ),
				array( 'status' => 409 )
			);
		}

		// One bulk pipeline at a time — a simultaneous offload and restore
		// would race each other over the same files.
		$restore = Plugin::get_instance()->get_bulk_restore();

		if ( null !== $restore && $restore->is_active() ) {
			return new \WP_Error(
				'smowp_bulk_running',
				__( 'A bulk restore is running — wait for it to finish first.', 'smart-media-offloader' ),
				array( 'status' => 409 )
			);
		}

		$queued = $engine->start();

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'state'  => BulkOffloadProcessor::get_state(),
			)
		);
	}

	/**
	 * GET /offload/bulk/progress
	 *
	 * Also self-heals stalled queues: when the async loopback dispatch
	 * cannot reach the site (local SSL, blocked loopbacks), each poll
	 * processes a short batch inline instead.
	 *
	 * @return \WP_REST_Response
	 */
	public function progress_response() {
		$engine = Plugin::get_instance()->get_bulk_offload();

		if ( null !== $engine && 'running' === BulkOffloadProcessor::get_state()['status'] ) {
			$engine->process_queue_now();
		}

		return rest_ensure_response( BulkOffloadProcessor::get_state() );
	}

	/**
	 * POST /offload/bulk/cancel
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_bulk_response() {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$engine->cancel();

		BulkOffloadProcessor::update_state( array( 'status' => 'cancelled' ) );

		return rest_ensure_response( BulkOffloadProcessor::get_state() );
	}

	/**
	 * GET /offload/errors — attachments whose last offload attempt failed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function errors_response( WP_REST_Request $request ) {
		$per_page = (int) $request->get_param( 'per_page' );

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'meta_key'       => 'smowp_error_log', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin query.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$items = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$log     = get_post_meta( $post_id, 'smowp_error_log', true );

			$items[] = array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'errors' => is_array( $log ) ? $log : array(),
			);
		}

		return rest_ensure_response( $items );
	}
}
