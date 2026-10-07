<?php
/**
 * Offload REST controller: single item, bulk queue, progress, errors.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\Plugin;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;
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
	 * Age after which an attachment's queued/processing status is stale
	 * when no job is running (see clear_stale_statuses()).
	 */
	private const STALE_STATUS_SECONDS = 600;

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
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		$this->register_post_route(
			'/offload/bulk',
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
					'validate_callback' => 'rest_validate_request_arg',
				),
				'page'     => array(
					'type'              => 'integer',
					'default'           => 1,
					'minimum'           => 1,
					'sanitize_callback' => 'absint',
					'validate_callback' => 'rest_validate_request_arg',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bulk/action',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_action_response' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => array(
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array( 'offload', 'restore' ),
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'ids'    => array(
						'type'              => 'array',
						'required'          => true,
						// One Media Library screen at most; bounds the per-ID checks.
						'maxItems'          => 1000,
						'validate_callback' => 'rest_validate_request_arg',
						'items'             => array(
							'type' => 'integer',
						),
					),
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
					'ids'   => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'known' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => 'rest_validate_request_arg',
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
		FolderClaim::refresh();

		if ( SafeMode::is_active() ) {
			return SafeMode::refusal();
		}

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

		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			return new \WP_Error(
				'omnioffloader_forbidden',
				__( 'Sorry, you are not allowed to manage this media item.', 'omnioffloader' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$provider = Plugin::get_instance()->get_provider();

		// require_engine() above guarantees this; keeps the type exact.
		if ( null === $provider ) {
			return new \WP_Error(
				'omnioffloader_not_configured',
				__( 'No cloud provider is configured.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

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
				// Always an array, never '' when the attachment has no errors.
				'errors'          => self::error_log_of( $attachment_id ),
			)
		);
	}

	/**
	 * POST /offload/bulk
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

		// One bulk pipeline at a time — offload, restore and retention runs
		// would race each other over the same files.
		$conflict = Plugin::get_instance()->bulk_job_conflict( 'offload' );

		if ( null !== $conflict ) {
			return $conflict;
		}

		$limit  = (int) $request->get_param( 'limit' );
		$queued = $engine->start( null, $limit );

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
		$page     = max( 1, (int) $request->get_param( 'page' ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'meta_key'       => 'omnioffloader_error_log', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin query.
				'fields'         => 'ids',
			)
		);

		// 'fields' => 'ids' yields IDs, but WP_Query types posts as int|WP_Post.
		$ids = array_map(
			static function ( $post ) {
				return $post instanceof \WP_Post ? $post->ID : (int) $post;
			},
			$query->posts
		);

		_prime_post_caches( $ids, false, true );

		$items = array();

		foreach ( $ids as $post_id ) {
			$log = get_post_meta( $post_id, 'omnioffloader_error_log', true );

			$items[] = array(
				'id'     => $post_id,
				'title'  => get_the_title( $post_id ),
				'errors' => is_array( $log ) ? $log : array(),
			);
		}

		// Standard REST paging headers, like core collections.
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * POST /bulk/action
	 *
	 * Triggers bulk offload or bulk restore on selected attachment IDs.
	 *
	 * @since 0.1.1
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function bulk_action_response( WP_REST_Request $request ) {
		$action = (string) $request->get_param( 'action' );

		$requested = array_values( array_filter( array_map( 'intval', (array) $request->get_param( 'ids' ) ) ) );

		// Posts and meta for up to 1,000 IDs in a few queries, instead of
		// several per ID in the capability and status checks below.
		if ( ! empty( $requested ) ) {
			_prime_post_caches( $requested, false, true );
		}

		// Only attachments the user may manage (see AttachmentHelper).
		$ids = self::filter_manageable_ids( $requested );

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'omnioffloader_no_ids',
				__( 'No attachment IDs provided.', 'omnioffloader' ),
				array( 'status' => 400 )
			);
		}

		$conflict = Plugin::get_instance()->bulk_job_conflict( $action );

		// Appending to a running queue of the same kind is supported.
		if ( null !== $conflict && Plugin::get_instance()->get_active_bulk_job() !== $action ) {
			return $conflict;
		}

		if ( 'offload' === $action ) {
			FolderClaim::refresh();

			if ( SafeMode::is_active() ) {
				return SafeMode::refusal();
			}

			$engine = $this->require_engine();
			if ( is_wp_error( $engine ) ) {
				return $engine;
			}

			$queued = $engine->start( $ids );

			return rest_ensure_response(
				array(
					'success' => true,
					'action'  => 'offload',
					'queued'  => $queued,
					'ids'     => $ids,
				)
			);
		}

		if ( SafeMode::blocks_bring_back() ) {
			return SafeMode::bring_back_refusal();
		}

		$restore_engine = Plugin::get_instance()->get_bulk_restore();
		if ( null === $restore_engine ) {
			return new \WP_Error(
				'omnioffloader_not_configured',
				__( 'Bulk restore engine is not available.', 'omnioffloader' ),
				array( 'status' => 409 )
			);
		}

		// Appending to a running restore inherits its "delete from cloud"
		// choice, so only append media this user may also delete.
		$restore_state = BulkRestoreProcessor::get_state();

		if ( ! empty( $restore_state['delete_cloud'] ) && $restore_engine->is_active() ) {
			$ids = array_values( array_filter( $ids, array( self::class, 'current_user_can_delete_cloud' ) ) );

			if ( empty( $ids ) ) {
				return new \WP_Error(
					'omnioffloader_forbidden',
					__( 'Sorry, you are not allowed to delete these media items from the cloud.', 'omnioffloader' ),
					array( 'status' => rest_authorization_required_code() )
				);
			}
		}

		$queued = $restore_engine->start( false, $ids );

		return rest_ensure_response(
			array(
				'success' => true,
				'action'  => 'restore',
				'queued'  => $queued,
				'ids'     => $ids,
			)
		);
	}

	/**
	 * GET /offload/attachment-status?ids=139,140
	 *
	 * Returns current offload status for requested attachment IDs and active bulk state.
	 *
	 * @since 0.1.1
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function attachment_status_response( WP_REST_Request $request ) {
		// Self-healing: if loopback was delayed, process queued items inline during poll.
		// Only administrators drive the upload queue from a poll; otherwise any
		// Author's polling tab would pull network transfers into its requests.
		$async = Plugin::get_instance()->get_async_upload();
		if ( null !== $async && current_user_can( 'manage_options' ) && $async->has_pending_items() ) {
			$async->process_queue_now();
		}

		// Each state is read once; the queue tables are only consulted when a
		// run is actually marked running. This route is polled every couple of
		// seconds per open Media Library tab, so idle polls stay at two reads.
		$bulk_offload      = Plugin::get_instance()->get_bulk_offload();
		$offload_state     = BulkOffloadProcessor::get_state();
		$is_offload_active = null !== $bulk_offload && 'running' === $offload_state['status'] && $bulk_offload->is_active();

		// Only administrators' polls drive a stalled queue (see the async
		// queue above); everyone else just reads the state.
		$can_drive = current_user_can( 'manage_options' );

		if ( $is_offload_active && $can_drive ) {
			$bulk_offload->process_queue_now();
			$offload_state     = BulkOffloadProcessor::get_state();
			$is_offload_active = 'running' === $offload_state['status'];
		}

		$bulk_restore      = Plugin::get_instance()->get_bulk_restore();
		$restore_state     = BulkRestoreProcessor::get_state();
		$is_restore_active = null !== $bulk_restore && 'running' === $restore_state['status'] && $bulk_restore->is_active();

		if ( $is_restore_active && $can_drive ) {
			$bulk_restore->process_queue_now();
			$restore_state     = BulkRestoreProcessor::get_state();
			$is_restore_active = 'running' === $restore_state['status'];
		}

		$bulk_active = $is_offload_active || $is_restore_active;
		$bulk_type   = $is_offload_active ? 'offload' : ( $is_restore_active ? 'restore' : '' );
		$current_id  = $is_offload_active ? (int) $offload_state['current'] : ( $is_restore_active ? (int) $restore_state['current'] : 0 );

		$raw_ids = (string) $request->get_param( 'ids' );
		$id_list = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

		// Cap requested IDs to 100 per request.
		$id_list = array_slice( array_unique( $id_list ), 0, 100 );

		// Prime post and postmeta caches in a single batch query to avoid N+1 queries.
		if ( ! empty( $id_list ) ) {
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $id_list, false, true );
			} else {
				update_postmeta_cache( $id_list );
			}
		}

		// Parse known status map if supplied by client (e.g. known=123:offloaded,124:local).
		// When known status matches current status for a non-transitional item, HTML snippets
		// are omitted to reduce response payload by ~95% and avoid redundant rendering.
		$raw_known = (string) $request->get_param( 'known' );
		$known_map = array();
		if ( '' !== $raw_known ) {
			foreach ( explode( ',', $raw_known ) as $pair ) {
				$parts = explode( ':', trim( $pair ), 2 );
				if ( 2 === count( $parts ) ) {
					$known_map[ (int) $parts[0] ] = sanitize_key( $parts[1] );
				}
			}
		}

		// Only media this user may edit is cleaned up; a running bulk job is
		// working on its items, so nothing is stale then.
		if ( ! $bulk_active ) {
			self::clear_stale_statuses(
				array_filter(
					$id_list,
					static function ( $id ) {
						return 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
					}
				)
			);
		}

		$statuses     = array();
		$provider     = Plugin::get_instance()->get_provider();
		$observer     = null;
		$row_observer = null;

		$is_admin = current_user_can( 'manage_options' );

		foreach ( $id_list as $attachment_id ) {
			// Only media the user may edit — never a way to enumerate others'.
			if ( 'attachment' !== get_post_type( $attachment_id ) || ! current_user_can( 'edit_post', $attachment_id ) ) {
				continue;
			}

			$status_data = self::get_attachment_status( $attachment_id );

			// Storage details are for administrators.
			if ( ! $is_admin ) {
				unset( $status_data['bucket'] );
			}

			if ( $bulk_active && $current_id > 0 && $current_id === $attachment_id && 'processing' !== ( $status_data['status'] ?? '' ) ) {
				$status_data['status'] = 'processing';
				$status_data['label']  = 'restore' === $bulk_type ? __( 'Restoring...', 'omnioffloader' ) : __( 'Offloading...', 'omnioffloader' );
			}

			$current_status = (string) ( $status_data['status'] ?? '' );
			$known_status   = $known_map[ $attachment_id ] ?? null;

			// If client already knows this exact status, and item is not in an active
			// transitional state (processing/queued), omit HTML snippets.
			$is_unchanged = ( null !== $known_status && $known_status === $current_status );
			$is_active    = ( 'processing' === $current_status || 'queued' === $current_status );

			if ( ! $is_unchanged || $is_active ) {
				if ( null === $observer && null !== $provider ) {
					$observer     = new \OmniOffloader\Offload\Observers\OffloadStatusObserver( $provider );
					$row_observer = new \OmniOffloader\Offload\Observers\RowActionsObserver( $provider );
				}

				if ( null !== $observer && null !== $row_observer ) {
					$status_data['column_html']     = $observer->render_column_icon( $attachment_id );
					$status_data['badge_html']      = $observer->render_details_badge( $attachment_id );
					$status_data['meta_box_html']   = $observer->render_meta_box_content( $attachment_id );
					$status_data['row_action_html'] = $row_observer->render_action_link( $attachment_id );
				}
			}

			$statuses[ (string) $attachment_id ] = $status_data;
		}

		return rest_ensure_response(
			array(
				'bulk'     => array(
					'active'     => $bulk_active,
					'type'       => $bulk_type,
					'current_id' => $current_id,
					// Zero when idle, never a finished run's numbers.
					'total'      => $is_offload_active ? (int) $offload_state['total'] : ( $is_restore_active ? (int) $restore_state['total'] : 0 ),
					'processed'  => $is_offload_active ? (int) $offload_state['processed'] : ( $is_restore_active ? (int) $restore_state['processed'] : 0 ),
				),
				// Always a JSON object, even when empty.
				'statuses' => (object) $statuses,
			)
		);
	}

	/**
	 * Clear a "queued" / "processing" status nothing will ever resolve.
	 *
	 * An upload is marked queued before its thumbnails are generated; if that
	 * fails, or the upload queue is cleared or never runs, the mark stayed
	 * forever. Only cleared while no job could be working on the item, and
	 * for attachments older than STALE_STATUS_SECONDS, so an upload still
	 * generating thumbnails keeps its badge.
	 *
	 * @since 0.1.4
	 *
	 * @param int[] $ids Attachment IDs being polled that the user may edit (meta already primed).
	 * @return void
	 */
	private static function clear_stale_statuses( array $ids ) {
		$candidates = array();

		foreach ( $ids as $id ) {
			$status = (string) get_post_meta( $id, 'omnioffloader_status', true );

			if ( 'queued' !== $status && 'processing' !== $status ) {
				continue;
			}

			$created = (int) get_post_time( 'U', true, $id );

			if ( $created > 0 && time() - $created > self::STALE_STATUS_SECONDS ) {
				$candidates[] = (int) $id;
			}
		}

		if ( empty( $candidates ) ) {
			return;
		}

		// Cheap checks first: while any job runs, nothing here is stale.
		$plugin = Plugin::get_instance();
		$async  = $plugin->get_async_upload();

		if ( '' !== $plugin->get_active_bulk_job() || ( null !== $async && ! $async->is_idle() ) ) {
			return;
		}

		// A single action in another request is still on it.
		$pending = array_values(
			array_filter(
				$candidates,
				static function ( $id ) {
					return ! Helpers::is_working( $id );
				}
			)
		);

		if ( empty( $pending ) ) {
			return;
		}

		AbstractBulkProcessor::clear_pending_status( $pending );
	}
}
