<?php
/**
 * Bring Back REST controller: preflight, single restore, bulk queue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Services\BulkRestoreProcessor;
use OmniOffloader\Traits\AttachmentHelper;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BringBackController
 */
class BringBackController extends BaseController {

	use AttachmentHelper;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$this->register_get_route(
			'/bring-back/preflight',
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

		register_rest_route(
			$this->namespace,
			'/bring-back/attachment/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_attachment_response' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id'           => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					// Omitted: use the "delete from cloud" setting, like the row action.
					'delete_cloud' => array(
						'type'              => 'boolean',
						'sanitize_callback' => 'rest_sanitize_boolean',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		$this->register_post_route(
			'/bring-back/bulk',
			array( $this, 'start_bulk_response' ),
			array(
				'delete_cloud' => array(
					'type'              => 'boolean',
					'default'           => false,
					'sanitize_callback' => 'rest_sanitize_boolean',
					'validate_callback' => 'rest_validate_request_arg',
				),
				'limit'        => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);
		$this->register_get_route( '/bring-back/bulk/progress', array( $this, 'progress_response' ) );
		$this->register_post_route( '/bring-back/bulk/cancel', array( $this, 'cancel_bulk_response' ) );
	}

	/**
	 * The bulk restore engine, or an error when unconfigured.
	 *
	 * @return BulkRestoreProcessor|\WP_Error
	 */
	private function require_engine() {
		$processor = Plugin::get_instance()->get_bulk_restore();

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
	 * GET /bring-back/preflight
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function preflight_response( WP_REST_Request $request ) {
		$force = (bool) $request->get_param( 'refresh' );
		return rest_ensure_response( Helpers::get_restore_preflight( $force ) );
	}

	/**
	 * POST /bring-back/attachment/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function restore_attachment_response( WP_REST_Request $request ) {
		if ( SafeMode::blocks_bring_back() ) {
			return SafeMode::bring_back_refusal();
		}

		$attachment_id = (int) $request->get_param( 'id' );

		if ( 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error(
				'omnioffloader_not_attachment',
				__( 'Not a media attachment.', 'omnioffloader' ),
				array( 'status' => 404 )
			);
		}

		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			return new \WP_Error(
				'omnioffloader_forbidden',
				__( 'Sorry, you are not allowed to manage this media item.', 'omnioffloader' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( ! self::is_offloaded( $attachment_id ) ) {
			return new \WP_Error(
				'omnioffloader_not_offloaded',
				__( 'This attachment is not offloaded.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

		// A retention run may be deleting this attachment's local files right
		// now; restoring (and maybe deleting cloud copies) alongside it could
		// leave no copy at all.
		if ( 'retention' === Plugin::get_instance()->get_active_bulk_job() ) {
			return new \WP_Error(
				'omnioffloader_bulk_running',
				__( 'A retention policy update is running — wait for it to finish first.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

		$requested    = $request->get_param( 'delete_cloud' );
		$delete_cloud = null === $requested
			? ! empty( Plugin::get_settings()['bring_back_delete_cloud'] )
			: (bool) $requested;

		// Deleting cloud copies also needs permission to delete the media.
		if ( $delete_cloud && ! self::current_user_can_delete_cloud( $attachment_id ) ) {
			if ( null !== $requested ) {
				return new \WP_Error(
					'omnioffloader_forbidden',
					__( 'Sorry, you are not allowed to delete this media item from the cloud.', 'omnioffloader' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}

			$delete_cloud = false;
		}

		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, $delete_cloud );
		$provider = Plugin::get_instance()->get_provider();

		// The Media Library HTML needs the engine; without it (provider since
		// removed) the restore still succeeds and the page reloads the row.
		$html = array(
			'column_html'     => '',
			'badge_html'      => '',
			'meta_box_html'   => '',
			'row_action_html' => '',
		);

		if ( null !== $provider ) {
			$observer     = new \OmniOffloader\Offload\Observers\OffloadStatusObserver( $provider );
			$row_observer = new \OmniOffloader\Offload\Observers\RowActionsObserver( $provider );
			$html         = array(
				'column_html'     => $observer->render_column_icon( $attachment_id ),
				'badge_html'      => $observer->render_details_badge( $attachment_id ),
				'meta_box_html'   => $observer->render_meta_box_content( $attachment_id ),
				'row_action_html' => $row_observer->render_action_link( $attachment_id ),
			);
		}

		return rest_ensure_response(
			array(
				'restored'        => $ok,
				'status'          => self::get_attachment_status( $attachment_id ),
				'column_html'     => $html['column_html'],
				'badge_html'      => $html['badge_html'],
				'meta_box_html'   => $html['meta_box_html'],
				'row_action_html' => $html['row_action_html'],
				'url'             => (string) wp_get_attachment_url( $attachment_id ),
				// Always an array, never '' when the attachment has no errors.
				'errors'          => self::error_log_of( $attachment_id ),
			)
		);
	}

	/**
	 * POST /bring-back/bulk
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function start_bulk_response( WP_REST_Request $request ) {
		if ( SafeMode::blocks_bring_back() ) {
			return SafeMode::bring_back_refusal();
		}

		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		// One bulk pipeline at a time — offload, restore and retention runs
		// would race each other over the same files.
		$conflict = Plugin::get_instance()->bulk_job_conflict( 'restore' );

		if ( null !== $conflict ) {
			return $conflict;
		}

		$delete_cloud = (bool) $request->get_param( 'delete_cloud' );
		$limit        = (int) $request->get_param( 'limit' );
		$queued       = $engine->start( $delete_cloud, null, $limit );

		return rest_ensure_response(
			array(
				'queued' => $queued,
				'state'  => BulkRestoreProcessor::get_state(),
			)
		);
	}

	/**
	 * GET /bring-back/bulk/progress
	 *
	 * Also self-heals stalled queues: when the async loopback dispatch
	 * cannot reach the site (local SSL, blocked loopbacks), each poll
	 * processes a short batch inline instead.
	 *
	 * @return \WP_REST_Response
	 */
	public function progress_response() {
		$engine = Plugin::get_instance()->get_bulk_restore();

		if ( null !== $engine && 'running' === BulkRestoreProcessor::get_state()['status'] ) {
			$engine->process_queue_now();
		}

		return rest_ensure_response( BulkRestoreProcessor::get_state() );
	}

	/**
	 * POST /bring-back/bulk/cancel
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel_bulk_response() {
		$engine = $this->require_engine();

		if ( is_wp_error( $engine ) ) {
			return $engine;
		}

		$engine->cancel_now();

		return rest_ensure_response( BulkRestoreProcessor::get_state() );
	}
}
