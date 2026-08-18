<?php
/**
 * "Offload now" / "Bring Back" row actions in the media list.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload\Observers;

use SmartMediaOffloader\Offload\ObserverInterface;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Services\AttachmentRestorer;
use SmartMediaOffloader\Services\AttachmentUploader;
use SmartMediaOffloader\Traits\AttachmentHelper;

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
	private const ACTION = 'smowp_offload_single';

	/**
	 * Admin-post action name for Bring Back.
	 */
	private const RESTORE_ACTION = 'smowp_restore_single';

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
			$actions['smowp_offload'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $this->action_url( self::ACTION, $attachment_id ) ),
				esc_html__( 'Offload now', 'smart-media-offloader' )
			);

			return $actions;
		}

		$actions['smowp_restore'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->action_url( self::RESTORE_ACTION, $attachment_id ) ),
			esc_html__( 'Bring Back', 'smart-media-offloader' )
		);

		return $actions;
	}

	/**
	 * Build a nonced admin-post URL for a row action.
	 *
	 * @param string $action        Admin-post action name.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function action_url( $action, $attachment_id ) {
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

		check_admin_referer( self::ACTION . '_' . $attachment_id );

		if ( ! current_user_can( 'upload_files' ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smart-media-offloader' ) );
		}

		$uploader = new AttachmentUploader( $this->provider );
		$ok       = $uploader->upload( $attachment_id );

		wp_safe_redirect(
			add_query_arg(
				'smowp_offloaded',
				$ok ? '1' : '0',
				wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' )
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

		check_admin_referer( self::RESTORE_ACTION . '_' . $attachment_id );

		if ( ! current_user_can( 'upload_files' ) || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'smart-media-offloader' ) );
		}

		$settings = \SmartMediaOffloader\Core\Plugin::get_settings();

		$restorer = new AttachmentRestorer();
		$ok       = $restorer->restore( $attachment_id, ! empty( $settings['bring_back_delete_cloud'] ) );

		wp_safe_redirect(
			add_query_arg(
				'smowp_restored',
				$ok ? '1' : '0',
				wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' )
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
		if ( isset( $_GET['smowp_offloaded'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
			$ok = '1' === sanitize_key( wp_unslash( $_GET['smowp_offloaded'] ) );

			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok
					? esc_html__( 'Attachment offloaded to the cloud.', 'smart-media-offloader' )
					: esc_html__( 'Offload failed — see the attachment details for the error.', 'smart-media-offloader' )
			);

			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		if ( ! isset( $_GET['smowp_restored'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag.
		$ok = '1' === sanitize_key( wp_unslash( $_GET['smowp_restored'] ) );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			$ok ? 'success' : 'error',
			$ok
				? esc_html__( 'Attachment restored to the server.', 'smart-media-offloader' )
				: esc_html__( 'Bring Back failed — see the attachment details for the error.', 'smart-media-offloader' )
		);
	}
}
