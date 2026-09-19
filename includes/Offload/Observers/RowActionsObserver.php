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
		if ( ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}

		$attachment_id = (int) $post->ID;

		if ( ! self::is_offloaded( $attachment_id ) ) {
			$actions['omnioffloader_offload'] = sprintf(
				'<a href="%s" class="omnioffloader-row-action" data-action="offload" data-attachment-id="%d">%s</a>',
				esc_url( $this->action_url( self::ACTION, $attachment_id ) ),
				$attachment_id,
				esc_html__( 'Offload now', 'omnioffloader' )
			);

			return $actions;
		}

		$actions['omnioffloader_restore'] = sprintf(
			'<a href="%s" class="omnioffloader-row-action" data-action="restore" data-attachment-id="%d">%s</a>',
			esc_url( $this->action_url( self::RESTORE_ACTION, $attachment_id ) ),
			$attachment_id,
			esc_html__( 'Bring Back', 'omnioffloader' )
		);

		return $actions;
	}

	/**
	 * Render the action link HTML (used by AJAX responses).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML link element.
	 */
	public function render_action_link( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( self::is_offloaded( $attachment_id ) ) {
			return sprintf(
				'<span class="omnioffloader_restore"><a href="%s" class="omnioffloader-row-action" data-action="restore" data-attachment-id="%d">%s</a></span>',
				esc_url( $this->action_url( self::RESTORE_ACTION, $attachment_id ) ),
				$attachment_id,
				esc_html__( 'Bring Back', 'omnioffloader' )
			);
		}

		return sprintf(
			'<span class="omnioffloader_offload"><a href="%s" class="omnioffloader-row-action" data-action="offload" data-attachment-id="%d">%s</a></span>',
			esc_url( $this->action_url( self::ACTION, $attachment_id ) ),
			$attachment_id,
			esc_html__( 'Offload now', 'omnioffloader' )
		);
	}

	/**
	 * Build a nonced admin-post URL for a row action.
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

		if ( ! current_user_can( 'upload_files' ) || 'attachment' !== get_post_type( $attachment_id ) ) {
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

		if ( ! current_user_can( 'upload_files' ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ) );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- safe timeout extension for large file transfers.
			@set_time_limit( 0 );
		}

		$settings = \OmniOffloader\Core\Plugin::get_settings();

		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, ! empty( $settings['bring_back_delete_cloud'] ) );

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
	 * Show the result notice after redirect.
	 *
	 * @return void
	 */
	public function maybe_notice() {
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
