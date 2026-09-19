<?php
/**
 * Offload REST controller: single item, bulk queue, progress, errors.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\Plugin;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Traits\AttachmentHelper;
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
		register_rest_route(
			$this->namespace,
			'/offload/attachment/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'offload_attachment_response' ),
				'permission_callback' => array( $this, 'media_permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
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

		register_rest_route(
			$this->namespace,
			'/offload/attachment-status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'attachment_status_response' ),
				'permission_callback' => array( $this, 'media_permissions_check' ),
				'args'                => array(
					'ids' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
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
				'omnioffloader_not_configured',
				__( 'No cloud provider is configured.', 'omnioffloader' ),
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
				'omnioffloader_not_attachment',
				__( 'Not a media attachment.', 'omnioffloader' ),
				array( 'status' => 404 )
			);
		}

		$provider = Plugin::get_instance()->get_provider();
		$uploader = new AttachmentUploader( $provider );
		$ok       = $uploader->upload( $attachment_id );

		$observer     = new \OmniOffloader\Offload\Observers\OffloadStatusObserver( $provider );
		$row_observer = new \OmniOffloader\Offload\Observers\RowActionsObserver( $provider );

		return rest_ensure_response(
			array(
				'offloaded'       => $ok,
				'status'          => self::get_attachment_status( $attachment_id ),
				'column_html'     => $observer->render_column_icon( $attachment_id ),
				'badge_html'      => $observer->render_details_badge( $attachment_id ),
				'meta_box_html'   => $observer->render_meta_box_content( $attachment_id ),
				'row_action_html' => $row_observer->render_action_link( $attachment_id ),
				'url'             => (string) wp_get_attachment_url( $attachment_id ),
				'errors'          => get_post_meta( $attachment_id, 'omnioffloader_error_log', true ),
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
				'omnioffloader_bulk_running',
				__( 'A bulk offload is already running.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

		// One bulk pipeline at a time — a simultaneous offload and restore
		// would race each other over the same files.
		$restore = Plugin::get_instance()->get_bulk_restore();

		if ( null !== $restore && $restore->is_active() ) {
			return new \WP_Error(
				'omnioffloader_bulk_running',
				__( 'A bulk restore is running — wait for it to finish first.', 'omnioffloader' ),
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

		$engine->cancel_now();

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
				'meta_key'       => 'omnioffloader_error_log', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin query.
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$items = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$log     = get_post_meta( $post_id, 'omnioffloader_error_log', true );

			$items[] = array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'errors' => is_array( $log ) ? $log : array(),
			);
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Authorization for media library status checks.
	 *
	 * Allows anyone who can upload files to check status.
	 *
	 * @return bool|\WP_Error
	 */
	public function media_permissions_check() {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}

		return new \WP_Error(
			'omnioffloader_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /offload/attachment-status?ids=139,140
	 *
	 * Returns current offload status for requested attachment IDs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function attachment_status_response( WP_REST_Request $request ) {
		$raw_ids = (string) $request->get_param( 'ids' );
		$id_list = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

		// Cap requested IDs to 100 per request.
		$id_list = array_slice( array_unique( $id_list ), 0, 100 );

		// Self-healing: if loopback was delayed, process queued item inline during poll.
		$async = Plugin::get_instance()->get_async_upload();
		if ( null !== $async ) {
			$async->process_queue_now();
		}

		$statuses     = array();
		$provider     = Plugin::get_instance()->get_provider();
		$observer     = new \OmniOffloader\Offload\Observers\OffloadStatusObserver( $provider );
		$row_observer = new \OmniOffloader\Offload\Observers\RowActionsObserver( $provider );

		foreach ( $id_list as $attachment_id ) {
			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				continue;
			}

			$status_data                    = self::get_attachment_status( $attachment_id );
			$status_data['column_html']     = $observer->render_column_icon( $attachment_id );
			$status_data['badge_html']      = $observer->render_details_badge( $attachment_id );
			$status_data['meta_box_html']   = $observer->render_meta_box_content( $attachment_id );
			$status_data['row_action_html'] = $row_observer->render_action_link( $attachment_id );

			$statuses[ (string) $attachment_id ] = $status_data;
		}

		return rest_ensure_response( $statuses );
	}
}
