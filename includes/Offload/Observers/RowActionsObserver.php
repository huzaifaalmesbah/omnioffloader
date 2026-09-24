<?php
/**
 * "Offload now" / "Bring Back" row actions in the media list.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RowActionsObserver
 */
class RowActionsObserver implements ObserverInterface {

	use AttachmentHelper;

	/**
	 * Admin-post action name.
	 */
	private const ACTION = 'omnioffloader_offload_single';

	/**
	 * Admin-post action name for Bring Back.
	 */
	private const RESTORE_ACTION = 'omnioffloader_restore_single';

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'media_row_actions', array( $this, 'add_action_link' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::RESTORE_ACTION, array( $this, 'handle_restore' ) );
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
	}

	/**
	 * Add the "Offload now" link for local attachments.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    Attachment post.
	 * @return array<string, string>
	 */
	public function add_action_link( $actions, $post ) {
		$attachment_id = (int) $post->ID;

		// Same rule as editing the attachment: Authors act on their own media.
		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			return $actions;
		}

		$status = self::get_attachment_status( $attachment_id );
		$state  = (string) ( $status['status'] ?? '' );

		if ( 'processing' === $state ) {
			$is_restoring                        = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );
			$actions['omnioffloader_processing'] = sprintf(
				'<span class="omnioffloader-row-action is-busy" data-attachment-id="%d">%s</span>',
				$attachment_id,
				$is_restoring ? esc_html__( 'Restoring...', 'omnioffloader' ) : esc_html__( 'Offloading...', 'omnioffloader' )
			);

			return $actions;
		}

		if ( 'queued' === $state ) {
			$is_restoring                    = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );
			$actions['omnioffloader_queued'] = sprintf(
				'<span class="omnioffloader-row-action is-busy" data-attachment-id="%d">%s</span>',
				$attachment_id,
				$is_restoring ? esc_html__( 'Queued...', 'omnioffloader' ) : esc_html__( 'Queued...', 'omnioffloader' )
			);

			return $actions;
		}

		if ( self::is_offloaded( $attachment_id ) ) {
			$actions['omnioffloader_restore'] = sprintf(
				'<a href="%s" class="omnioffloader-row-action" data-action="restore" data-attachment-id="%d">%s</a>',
				esc_url( $this->action_url( self::RESTORE_ACTION, $attachment_id ) ),
				$attachment_id,
				esc_html__( 'Bring Back', 'omnioffloader' )
			);

			return $actions;
		}

		$actions['omnioffloader_offload'] = sprintf(
			'<a href="%s" class="omnioffloader-row-action" data-action="offload" data-attachment-id="%d">%s</a>',
			esc_url( $this->action_url( self::ACTION, $attachment_id ) ),
			$attachment_id,
			'failed' === $state ? esc_html__( 'Retry Offload', 'omnioffloader' ) : esc_html__( 'Offload now', 'omnioffloader' )
		);

		return $actions;
	}

	/**
	 * Render the action link HTML (used by AJAX responses).
	 *
	 * @since 0.1.1
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML link element.
	 */
	public function render_action_link( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			return '';
		}

		$status = self::get_attachment_status( $attachment_id );
		$state  = (string) ( $status['status'] ?? '' );

		if ( 'processing' === $state ) {
			$is_restoring = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );

			return sprintf(
				'<span class="omnioffloader_processing omnioffloader_action"><span class="omnioffloader-row-action is-busy" data-attachment-id="%d">%s</span></span>',
				$attachment_id,
				$is_restoring ? esc_html__( 'Restoring...', 'omnioffloader' ) : esc_html__( 'Offloading...', 'omnioffloader' )
			);
		}

		if ( 'queued' === $state ) {
			$is_restoring = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );

			return sprintf(
				'<span class="omnioffloader_queued omnioffloader_action"><span class="omnioffloader-row-action is-busy" data-attachment-id="%d">%s</span></span>',
				$attachment_id,
				$is_restoring ? esc_html__( 'Queued...', 'omnioffloader' ) : esc_html__( 'Queued...', 'omnioffloader' )
			);
		}

		if ( self::is_offloaded( $attachment_id ) ) {
			return sprintf(
				'<span class="omnioffloader_restore omnioffloader_action"><a href="%s" class="omnioffloader-row-action" data-action="restore" data-attachment-id="%d">%s</a></span>',
				esc_url( $this->action_url( self::RESTORE_ACTION, $attachment_id ) ),
				$attachment_id,
				esc_html__( 'Bring Back', 'omnioffloader' )
			);
		}

		return sprintf(
			'<span class="omnioffloader_offload omnioffloader_action"><a href="%s" class="omnioffloader-row-action" data-action="offload" data-attachment-id="%d">%s</a></span>',
			esc_url( $this->action_url( self::ACTION, $attachment_id ) ),
			$attachment_id,
			'failed' === $state ? esc_html__( 'Retry Offload', 'omnioffloader' ) : esc_html__( 'Offload now', 'omnioffloader' )
		);
	}

	/**
	 * Build a nonced admin-post URL for a row action.
	 *
	 * @since 0.1.1
	 *
	 * @param string $action        Admin-post action name.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function action_url( $action, $attachment_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => $action,
					'id'     => $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			$action . '_' . $attachment_id
		);
	}

	/**
	 * Handle the row-action request.
	 *
	 * @return void
	 */
	public function handle() {
		$attachment_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION . '_' . $attachment_id ) ) {
			wp_die( esc_html__( 'The link you followed has expired.', 'omnioffloader' ) );
		}

		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ) );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe timeout extension for large file transfers.
			@set_time_limit( 0 );
		}

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		$redirect = remove_query_arg( array( 'omnioffloader_offloaded', 'omnioffloader_restored' ), $redirect );

		wp_safe_redirect(
			add_query_arg(
				'omnioffloader_offloaded',
				$ok ? '1' : '0',
				$redirect
			)
		);
		exit;
	}

	/**
	 * Handle the Bring Back row-action request.
	 *
	 * Uses the "also delete from cloud" default from settings (OFF unless
	 * the site owner opted in); the Bring Back page offers a per-run choice.
	 *
	 * @return void
	 */
	public function handle_restore() {
		$attachment_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::RESTORE_ACTION . '_' . $attachment_id ) ) {
			wp_die( esc_html__( 'The link you followed has expired.', 'omnioffloader' ) );
		}

		if ( ! self::current_user_can_manage( $attachment_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ) );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe timeout extension for large file transfers.
			@set_time_limit( 0 );
		}

		// See BringBackController: never restore alongside a retention run.
		if ( 'retention' === \OmniOffloader\Core\Plugin::get_instance()->get_active_bulk_job() ) {
			wp_die( esc_html__( 'A retention policy update is running — wait for it to finish first.', 'omnioffloader' ) );
		}

		$settings = \OmniOffloader\Core\Plugin::get_settings();

		// The cloud copy is only deleted for users who may delete the media.
		$delete_cloud = ! empty( $settings['bring_back_delete_cloud'] ) && self::current_user_can_delete_cloud( $attachment_id );

		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, $delete_cloud );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' );
		$redirect = remove_query_arg( array( 'omnioffloader_offloaded', 'omnioffloader_restored' ), $redirect );

		wp_safe_redirect(
			add_query_arg(
				'omnioffloader_restored',
				$ok ? '1' : '0',
				$redirect
			)
		);
		exit;
	}

	/**
	 * Register bulk actions in the Media Library list view.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public function register_bulk_actions( $actions ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}

		$actions['omnioffloader_bulk_offload'] = __( 'Offload to Cloud', 'omnioffloader' );
		$actions['omnioffloader_bulk_restore'] = __( 'Bring Back to Server', 'omnioffloader' );

		return $actions;
	}

	/**
	 * Handle bulk actions from the Media Library list view.
	 *
	 * @since 0.1.1
	 *
	 * @param string                 $redirect_to URL to redirect to.
	 * @param string                 $doaction    Action name.
	 * @param array<int|string, int> $post_ids   Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return $redirect_to;
		}

		// Drop media the user may not edit (core's bulk handler only checks upload_files).
		$post_ids = self::filter_manageable_ids( (array) $post_ids );

		if ( empty( $post_ids ) ) {
			return $redirect_to;
		}

		$redirect_to = remove_query_arg(
			array( 'omnioffloader_bulk_started', 'omnioffloader_bulk_count', 'omnioffloader_bulk_blocked', 'omnioffloader_offloaded', 'omnioffloader_restored' ),
			$redirect_to
		);

		$job = 'omnioffloader_bulk_offload' === $doaction ? 'offload' : ( 'omnioffloader_bulk_restore' === $doaction ? 'restore' : '' );

		// Appending to a running queue of the same kind is fine; starting a
		// different kind of job alongside it is not (Safety Invariant #8).
		if ( '' !== $job ) {
			$active = \OmniOffloader\Core\Plugin::get_instance()->get_active_bulk_job();

			if ( '' !== $active && $active !== $job ) {
				return add_query_arg( 'omnioffloader_bulk_blocked', rawurlencode( $active ), $redirect_to );
			}
		}

		if ( 'omnioffloader_bulk_offload' === $doaction ) {
			$bulk = \OmniOffloader\Core\Plugin::get_instance()->get_bulk_offload();
			if ( null !== $bulk ) {
				$queued = $bulk->start( $post_ids );

				return add_query_arg(
					array(
						'omnioffloader_bulk_started' => 'offload',
						'omnioffloader_bulk_count'   => $queued,
					),
					$redirect_to
				);
			}
		} elseif ( 'omnioffloader_bulk_restore' === $doaction ) {
			$bulk = \OmniOffloader\Core\Plugin::get_instance()->get_bulk_restore();
			if ( null !== $bulk ) {
				$queued = $bulk->start( false, $post_ids );

				return add_query_arg(
					array(
						'omnioffloader_bulk_started' => 'restore',
						'omnioffloader_bulk_count'   => $queued,
					),
					$redirect_to
				);
			}
		}

		return $redirect_to;
	}

	/**
	 * Show the result notice after redirect.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function maybe_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if ( isset( $_GET['omnioffloader_bulk_blocked'] ) ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html__( 'Another OmniOffloader job is running. Wait for it to finish, then try again.', 'omnioffloader' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if ( isset( $_GET['omnioffloader_bulk_started'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			$action = sanitize_key( wp_unslash( $_GET['omnioffloader_bulk_started'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			$count = isset( $_GET['omnioffloader_bulk_count'] ) ? absint( $_GET['omnioffloader_bulk_count'] ) : 0;

			if ( 'offload' === $action ) {
				$message = sprintf(
					/* translators: %d: number of media items */
					_n(
						'Bulk offload started for %d item. Progress is updating live in real time.',
						'Bulk offload started for %d items. Progress is updating live in real time.',
						$count,
						'omnioffloader'
					),
					$count
				);
			} else {
				$message = sprintf(
					/* translators: %d: number of media items */
					_n(
						'Bulk restore started for %d item. Progress is updating live in real time.',
						'Bulk restore started for %d items. Progress is updating live in real time.',
						$count,
						'omnioffloader'
					),
					$count
				);
			}

			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html( $message )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flags.
		if ( isset( $_GET['omnioffloader_offloaded'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			$ok = '1' === sanitize_key( wp_unslash( $_GET['omnioffloader_offloaded'] ) );

			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok
					? esc_html__( 'Attachment offloaded to the cloud.', 'omnioffloader' )
					: esc_html__( 'Offload failed — see the attachment details for the error.', 'omnioffloader' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if ( ! isset( $_GET['omnioffloader_restored'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		$ok = '1' === sanitize_key( wp_unslash( $_GET['omnioffloader_restored'] ) );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			$ok
				? esc_html__( 'Attachment restored to the server.', 'omnioffloader' )
				: esc_html__( 'Bring Back failed — see the attachment details for the error.', 'omnioffloader' )
		);
	}
}
