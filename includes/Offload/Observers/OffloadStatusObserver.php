<?php
/**
 * Offload status column and field in the WordPress Media Library.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OffloadStatusObserver
 */
class OffloadStatusObserver implements ObserverInterface {

	use AttachmentHelper;

	/**
	 * Cloud SVG icon.
	 */
	public const ICON_CLOUD = '<svg class="omnioffloader-icon omnioffloader-icon--cloud" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/></svg>';

	/**
	 * Spinner SVG icon.
	 */
	public const ICON_SPINNER = '<svg class="omnioffloader-icon omnioffloader-icon--spinner" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>';

	/**
	 * Warning SVG icon.
	 */
	public const ICON_WARNING = '<svg class="omnioffloader-icon omnioffloader-icon--warning" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

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

		// Attachment details edit field.
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_status_field' ), 10, 2 );

		// Media Library list view column.
		add_filter( 'manage_media_columns', array( $this, 'add_offload_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_offload_column' ), 10, 2 );

		// Media Library grid view attachment response for JS.
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_attachment_for_js' ), 10, 2 );

		// Enqueue styles and scripts for media library & editor.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Dedicated Meta Box on edit attachment screen (post.php).
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_meta_box' ) );
	}

	/**
	 * Register dedicated OmniOffloader meta box on the attachment edit screen.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_Post $post Attachment post object.
	 * @return void
	 */
	public function add_attachment_meta_box( $post ) {
		add_meta_box(
			'omnioffloader_meta_box',
			__( 'OmniOffloader', 'omnioffloader' ),
			array( $this, 'render_attachment_meta_box' ),
			'attachment',
			'normal',
			'default'
		);
	}

	/**
	 * Render the OmniOffloader meta box.
	 *
	 * @since 0.1.1
	 *
	 * @param \WP_Post $post Attachment post object.
	 * @return void
	 */
	public function render_attachment_meta_box( $post ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped HTML content.
		echo $this->render_meta_box_content( (int) $post->ID );
	}

	/**
	 * Add minimal offload status icon column header to media library list view.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string> Modified columns.
	 */
	public function add_offload_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;

			if ( 'title' === $key ) {
				$new_columns['omnioffloader_status'] = sprintf(
					'<span class="omnioffloader-col-head" title="%1$s">%2$s<span class="screen-reader-text">%1$s</span></span>',
					esc_attr__( 'Offload status', 'omnioffloader' ),
					self::ICON_CLOUD
				);
			}
		}

		if ( ! isset( $new_columns['omnioffloader_status'] ) ) {
			$new_columns['omnioffloader_status'] = sprintf(
				'<span class="omnioffloader-col-head" title="%1$s">%2$s<span class="screen-reader-text">%1$s</span></span>',
				esc_attr__( 'Offload status', 'omnioffloader' ),
				self::ICON_CLOUD
			);
		}

		return $new_columns;
	}

	/**
	 * Render minimal offload status icon in media library list view column.
	 *
	 * @since 0.1.1
	 *
	 * @param string $column_name Column name.
	 * @param int    $post_id     Attachment ID.
	 * @return void
	 */
	public function render_offload_column( $column_name, $post_id ) {
		if ( 'omnioffloader_status' !== $column_name ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped HTML icon.
		echo $this->render_column_icon( (int) $post_id );
	}

	/**
	 * Render the minimal status icon HTML for list view column cell.
	 *
	 * @since 0.1.1
	 *
	 * @param int $post_id Attachment ID.
	 * @return string HTML icon element with tooltip.
	 */
	public function render_column_icon( $post_id ) {
		$post_id = (int) $post_id;
		$status  = self::get_attachment_status( $post_id );
		$state   = (string) $status['status'];

		$html = sprintf(
			'<div class="omnioffloader-status-cell omnioffloader-status-cell--%s" data-attachment-id="%d" data-status="%s">',
			esc_attr( $state ),
			$post_id,
			esc_attr( $state )
		);

		switch ( $state ) {
			case 'offloaded':
				$provider = ! empty( $status['provider'] ) ? (string) $status['provider'] : $this->provider->get_key();
				$date     = ! empty( $status['date'] ) ? (string) $status['date'] : '';
				$tooltip  = sprintf(
					/* translators: 1: provider key, 2: formatted date */
					__( 'Offloaded — %1$s%2$s', 'omnioffloader' ),
					$provider,
					$date ? ' · ' . $date : ''
				);

				$html .= sprintf(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--offloaded" title="%s" aria-label="%s">%s<span class="screen-reader-text">%s</span></span>',
					esc_attr( $tooltip ),
					esc_attr( $tooltip ),
					self::ICON_CLOUD,
					esc_html( $tooltip )
				);
				break;

			case 'processing':
				$is_restoring = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );
				$tooltip      = $is_restoring ? __( 'Restoring in background...', 'omnioffloader' ) : __( 'Offloading in background...', 'omnioffloader' );
				$html        .= sprintf(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--processing" title="%s" aria-label="%s">%s<span class="screen-reader-text">%s</span></span>',
					esc_attr( $tooltip ),
					esc_attr( $tooltip ),
					self::ICON_SPINNER,
					esc_html( $tooltip )
				);
				break;

			case 'queued':
				$tooltip = __( 'Queued for background offload...', 'omnioffloader' );
				$html   .= sprintf(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--queued" title="%s" aria-label="%s">%s<span class="screen-reader-text">%s</span></span>',
					esc_attr( $tooltip ),
					esc_attr( $tooltip ),
					self::ICON_SPINNER,
					esc_html( $tooltip )
				);
				break;

			case 'failed':
				$error_text = ! empty( $status['error'] ) ? (string) $status['error'] : __( 'Upload failed', 'omnioffloader' );
				$tooltip    = sprintf(
					/* translators: %s: Error message */
					__( 'Offload failed: %s', 'omnioffloader' ),
					$error_text
				);

				$html .= sprintf(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--failed" title="%s" aria-label="%s">%s<span class="screen-reader-text">%s</span></span>',
					esc_attr( $tooltip ),
					esc_attr( $tooltip ),
					self::ICON_WARNING,
					esc_html( $tooltip )
				);
				break;

			case 'local':
			default:
				$tooltip = __( 'Not offloaded', 'omnioffloader' );
				$html   .= sprintf(
					'<span class="omnioffloader-status-icon omnioffloader-status-icon--local" title="%s" aria-label="%s">&mdash;<span class="screen-reader-text">%s</span></span>',
					esc_attr( $tooltip ),
					esc_attr( $tooltip ),
					esc_html( $tooltip )
				);
				break;
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Add offload status field to attachment edit form in modals.
	 * On post.php edit attachment screen, the dedicated meta box is used instead.
	 *
	 * @param array<string, mixed> $fields Form fields.
	 * @param \WP_Post             $post   Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_status_field( $fields, $post ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		// On post.php edit-attachment screen, suppress compat field so Meta Box is used.
		if ( $screen && 'attachment' === $screen->id ) {
			return $fields;
		}

		$attachment_id = (int) $post->ID;

		$fields['omnioffloader_status'] = array(
			'label' => __( 'Offload status', 'omnioffloader' ),
			'input' => 'html',
			'html'  => $this->render_details_badge( $attachment_id ),
		);

		return $fields;
	}

	/**
	 * Render the status badge and action button HTML for media modals.
	 *
	 * @since 0.1.1
	 *
	 * @param int $post_id Attachment ID.
	 * @return string HTML badge.
	 */
	public function render_details_badge( $post_id ) {
		$post_id    = (int) $post_id;
		$status     = self::get_attachment_status( $post_id );
		$state      = (string) $status['status'];
		$can_upload = self::current_user_can_manage( $post_id );

		$html = sprintf(
			'<div class="omnioffloader-details-badge omnioffloader-details-badge--%s" data-attachment-id="%d" data-status="%s">',
			esc_attr( $state ),
			$post_id,
			esc_attr( $state )
		);

		switch ( $state ) {
			case 'offloaded':
				$provider = ! empty( $status['provider'] ) ? (string) $status['provider'] : $this->provider->get_key();
				$date     = ! empty( $status['date'] ) ? (string) $status['date'] : '';
				$html    .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--offloaded">%s <span class="omnioffloader-badge__text"><strong>%s</strong> — %s%s</span></span>',
					self::ICON_CLOUD,
					esc_html__( 'Offloaded', 'omnioffloader' ),
					esc_html( $provider ),
					$date ? esc_html( ' · ' . $date ) : ''
				);

				if ( $can_upload ) {
					$restore_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=omnioffloader_restore_single&id=' . $post_id ),
						'omnioffloader_restore_single_' . $post_id
					);
					$html       .= sprintf(
						' <a href="%s" class="button button-small button-secondary omnioffloader-details-action" data-action="restore" data-attachment-id="%d" role="button">%s</a>',
						esc_url( $restore_url ),
						$post_id,
						esc_html__( 'Bring Back', 'omnioffloader' )
					);
				}
				break;

			case 'processing':
				$is_restoring = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );
				$label_text   = $is_restoring ? __( 'Restoring...', 'omnioffloader' ) : __( 'Offloading...', 'omnioffloader' );
				$html        .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--processing">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_SPINNER,
					esc_html( $label_text )
				);
				break;

			case 'queued':
				$html .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--queued">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_SPINNER,
					esc_html__( 'Queued for background offload', 'omnioffloader' )
				);
				break;

			case 'failed':
				$error_text = ! empty( $status['error'] ) ? (string) $status['error'] : __( 'Upload failed', 'omnioffloader' );
				$html      .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--failed">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_WARNING,
					/* translators: %s: Error message */
					esc_html( sprintf( __( 'Failed — %s', 'omnioffloader' ), $error_text ) )
				);

				if ( $can_upload ) {
					$offload_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=omnioffloader_offload_single&id=' . $post_id ),
						'omnioffloader_offload_single_' . $post_id
					);
					$html       .= sprintf(
						' <a href="%s" class="button button-small button-secondary omnioffloader-details-action" data-action="offload" data-attachment-id="%d" role="button">%s</a>',
						esc_url( $offload_url ),
						$post_id,
						esc_html__( 'Retry Offload', 'omnioffloader' )
					);
				}
				break;

			case 'local':
			default:
				$html .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--local"><span class="omnioffloader-badge__text">%s</span></span>',
					esc_html__( 'Local', 'omnioffloader' )
				);

				if ( $can_upload ) {
					$offload_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=omnioffloader_offload_single&id=' . $post_id ),
						'omnioffloader_offload_single_' . $post_id
					);
					$html       .= sprintf(
						' <a href="%s" class="button button-small button-secondary omnioffloader-details-action" data-action="offload" data-attachment-id="%d" role="button">%s</a>',
						esc_url( $offload_url ),
						$post_id,
						esc_html__( 'Offload now', 'omnioffloader' )
					);
				}
				break;
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Render the OmniOffloader meta box content for edit-attachment screen (post.php).
	 *
	 * @since 0.1.1
	 *
	 * @param int $post_id Attachment ID.
	 * @return string HTML meta box content.
	 */
	public function render_meta_box_content( $post_id ) {
		$post_id    = (int) $post_id;
		$status     = self::get_attachment_status( $post_id );
		$state      = (string) $status['status'];
		$can_upload = self::current_user_can_manage( $post_id );

		$html = sprintf(
			'<div class="omnioffloader-meta-box-wrap omnioffloader-details-badge omnioffloader-details-badge--%s" data-attachment-id="%d" data-status="%s">',
			esc_attr( $state ),
			$post_id,
			esc_attr( $state )
		);

		// Status Badge.
		$html .= '<div class="omnioffloader-meta-box-status" style="margin-bottom: 12px;">';
		switch ( $state ) {
			case 'offloaded':
				$html .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--offloaded">%s <span class="omnioffloader-badge__text"><strong>%s</strong></span></span>',
					self::ICON_CLOUD,
					esc_html__( 'Offloaded', 'omnioffloader' )
				);
				break;

			case 'processing':
				$is_restoring = ( ! empty( $status['label'] ) && false !== stripos( (string) $status['label'], 'restor' ) );
				$label_text   = $is_restoring ? __( 'Restoring to local server, please wait...', 'omnioffloader' ) : __( 'Offloading to cloud storage, please wait...', 'omnioffloader' );
				$html        .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--processing">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_SPINNER,
					esc_html( $label_text )
				);
				break;

			case 'queued':
				$html .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--queued">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_SPINNER,
					esc_html__( 'Queued for background offload', 'omnioffloader' )
				);
				break;

			case 'failed':
				$error_text = ! empty( $status['error'] ) ? (string) $status['error'] : __( 'Upload failed', 'omnioffloader' );
				$html      .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--failed">%s <span class="omnioffloader-badge__text">%s</span></span>',
					self::ICON_WARNING,
					/* translators: %s: Error message */
					esc_html( sprintf( __( 'Failed — %s', 'omnioffloader' ), $error_text ) )
				);
				break;

			case 'local':
			default:
				$html .= sprintf(
					'<span class="omnioffloader-badge omnioffloader-badge--local"><span class="omnioffloader-badge__text">%s</span></span>',
					esc_html__( 'Local (Not offloaded)', 'omnioffloader' )
				);
				break;
		}
		$html .= '</div>';

		// Information card only when offloaded.
		if ( 'offloaded' === $state ) {
			$provider_key  = ! empty( $status['provider'] ) ? (string) $status['provider'] : $this->provider->get_key();
			$provider_name = $this->provider->get_name();
			if ( '' !== $provider_key && $provider_key !== $this->provider->get_key() && ProviderRegistry::has( $provider_key ) ) {
				try {
					$provider_name = ProviderRegistry::create( $provider_key )->get_name();
				} catch ( \InvalidArgumentException $e ) {
					$provider_name = $provider_key;
				}
			}

			$bucket       = ! empty( $status['bucket'] ) ? (string) $status['bucket'] : $this->provider->get_bucket();
			$offloaded_at = (int) get_post_meta( $post_id, 'omnioffloader_offloaded_at', true );

			if ( $offloaded_at > 0 ) {
				$date_str = sprintf(
					/* translators: 1: formatted date, 2: formatted time */
					__( '%1$s at %2$s', 'omnioffloader' ),
					wp_date( (string) get_option( 'date_format' ), $offloaded_at ),
					wp_date( (string) get_option( 'time_format' ), $offloaded_at )
				);
			} elseif ( ! empty( $status['date'] ) ) {
				$date_str = (string) $status['date'];
			} else {
				$date_str = '';
			}

			// Determine 100% accurate local storage status.
			$retention     = get_post_meta( $post_id, 'omnioffloader_retention', true );
			$attached_file = (string) get_attached_file( $post_id );
			$file_exists   = ( '' !== $attached_file && file_exists( $attached_file ) );
			$retention_val = '' !== $retention ? (int) $retention : null;

			if ( 2 === $retention_val || ! $file_exists ) {
				$storage_status = __( 'Removed from server (Cloud only)', 'omnioffloader' );
			} elseif ( 1 === $retention_val ) {
				$storage_status = __( 'Original kept (Thumbnails cleaned)', 'omnioffloader' );
			} elseif ( 0 === $retention_val ) {
				$storage_status = __( 'Preserved on server (All files)', 'omnioffloader' );
			} else {
				$storage_status = __( 'Preserved on server', 'omnioffloader' );
			}

			$html .= '<div class="omnioffloader-meta-box-info">';
			$html .= '<div class="omnioffloader-meta-box-row"><span class="omnioffloader-meta-box-label">' . esc_html__( 'Provider:', 'omnioffloader' ) . '</span><span class="omnioffloader-meta-box-val">' . esc_html( $provider_name ) . '</span></div>';
			if ( '' !== $bucket && current_user_can( 'manage_options' ) ) {
				$html .= '<div class="omnioffloader-meta-box-row"><span class="omnioffloader-meta-box-label">' . esc_html__( 'Bucket:', 'omnioffloader' ) . '</span><span class="omnioffloader-meta-box-val"><code>' . esc_html( $bucket ) . '</code></span></div>';
			}
			if ( '' !== $date_str ) {
				$html .= '<div class="omnioffloader-meta-box-row"><span class="omnioffloader-meta-box-label">' . esc_html__( 'Offloaded on:', 'omnioffloader' ) . '</span><span class="omnioffloader-meta-box-val">' . esc_html( $date_str ) . '</span></div>';
			}
			$html .= '<div class="omnioffloader-meta-box-row"><span class="omnioffloader-meta-box-label">' . esc_html__( 'Local Storage:', 'omnioffloader' ) . '</span><span class="omnioffloader-meta-box-val">' . esc_html( $storage_status ) . '</span></div>';
			$html .= '</div>';
		}

		// Action buttons (container always present to prevent layout shift).
		$html .= '<div class="omnioffloader-meta-box-actions">';
		if ( $can_upload && 'processing' !== $state && 'queued' !== $state ) {
			if ( 'offloaded' === $state ) {
				$restore_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=omnioffloader_restore_single&id=' . $post_id ),
					'omnioffloader_restore_single_' . $post_id
				);
				$html       .= sprintf(
					'<a href="%s" class="button button-secondary omnioffloader-details-action" data-action="restore" data-attachment-id="%d" role="button">%s</a>',
					esc_url( $restore_url ),
					$post_id,
					esc_html__( 'Bring Back', 'omnioffloader' )
				);
			} elseif ( 'local' === $state ) {
				$offload_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=omnioffloader_offload_single&id=' . $post_id ),
					'omnioffloader_offload_single_' . $post_id
				);
				$html       .= sprintf(
					'<a href="%s" class="button button-secondary omnioffloader-details-action" data-action="offload" data-attachment-id="%d" role="button">%s</a>',
					esc_url( $offload_url ),
					$post_id,
					esc_html__( 'Offload now', 'omnioffloader' )
				);
			} elseif ( 'failed' === $state ) {
				$offload_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=omnioffloader_offload_single&id=' . $post_id ),
					'omnioffloader_offload_single_' . $post_id
				);
				$html       .= sprintf(
					'<a href="%s" class="button button-secondary omnioffloader-details-action" data-action="offload" data-attachment-id="%d" role="button">%s</a>',
					esc_url( $offload_url ),
					$post_id,
					esc_html__( 'Retry Offload', 'omnioffloader' )
				);
			}
		}
		$html .= '</div>';

		$html .= '</div>';

		return $html;
	}

	/**
	 * Expose offload status data to JS attachment models in grid view and modals.
	 *
	 * @since 0.1.1
	 *
	 * @param array<string, mixed> $response   Attachment JS data.
	 * @param \WP_Post             $attachment Attachment post.
	 * @return array<string, mixed> Modified JS data.
	 */
	public function prepare_attachment_for_js( $response, $attachment ) {
		$post_id = (int) $attachment->ID;
		$status  = self::get_attachment_status( $post_id );

		$response['omnioffloader'] = array(
			'status'       => $status['status'],
			'is_offloaded' => $status['is_offloaded'],
			'provider'     => $status['provider'] ?? '',
			'date'         => $status['date'] ?? '',
			'label'        => $status['label'],
			'column_html'  => $this->render_column_icon( $post_id ),
			'badge_html'   => $this->render_details_badge( $post_id ),
		);

		return $response;
	}

	/**
	 * Enqueue styles and scripts for media library and post editors.
	 *
	 * @since 0.1.1
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! is_string( $hook ) || '' === $hook ) {
			return;
		}

		$allowed_hooks = array( 'upload.php', 'post.php', 'post-new.php', 'media.php' );

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		$css_url = plugins_url( 'assets/css/media-status.css', OMNIOFFLOADER_FILE );
		$js_url  = plugins_url( 'assets/js/media-status.js', OMNIOFFLOADER_FILE );

		$css_file = OMNIOFFLOADER_DIR . 'assets/css/media-status.css';
		$js_file  = OMNIOFFLOADER_DIR . 'assets/js/media-status.js';

		$css_ver = file_exists( $css_file ) ? (string) filemtime( $css_file ) : OMNIOFFLOADER_VERSION;
		$js_ver  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : OMNIOFFLOADER_VERSION;

		wp_enqueue_style( 'omnioffloader-media-status', $css_url, array(), $css_ver );

		$deps = array( 'jquery' );
		if ( wp_script_is( 'media-views', 'enqueued' ) ) {
			$deps[] = 'media-views';
		}

		wp_enqueue_script(
			'omnioffloader-media-status',
			$js_url,
			$deps,
			$js_ver,
			true
		);

		wp_localize_script(
			'omnioffloader-media-status',
			'omnioffloaderMedia',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'omnioffloader/v1/offload/attachment-status' ) ),
				'bulkUrl'     => esc_url_raw( rest_url( 'omnioffloader/v1/bulk/action' ) ),
				'offloadUrl'  => esc_url_raw( rest_url( 'omnioffloader/v1/offload/attachment/' ) ),
				'restoreUrl'  => esc_url_raw( rest_url( 'omnioffloader/v1/bring-back/attachment/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'iconCloud'   => self::ICON_CLOUD,
				'iconWarning' => self::ICON_WARNING,
				'iconSpinner' => self::ICON_SPINNER,
				'i18n'        => array(
					'offloading' => __( 'Offloading...', 'omnioffloader' ),
					'restoring'  => __( 'Restoring...', 'omnioffloader' ),
					'offloaded'  => __( 'Offloaded', 'omnioffloader' ),
					'local'      => __( 'Not offloaded', 'omnioffloader' ),
					'failed'     => __( 'Offload failed', 'omnioffloader' ),
					'retry'      => __( 'Retry Offload', 'omnioffloader' ),
					'offloadNow' => __( 'Offload now', 'omnioffloader' ),
					'bringBack'  => __( 'Bring Back', 'omnioffloader' ),
					'queued'     => __( 'Queued...', 'omnioffloader' ),
				),
			)
		);
	}
}
