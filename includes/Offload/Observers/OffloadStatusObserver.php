<?php
/**
 * Offload status field in the attachment details.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Providers\ProviderInterface;
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

		add_filter( 'attachment_fields_to_edit', array( $this, 'add_status_field' ), 10, 2 );
	}

	/**
	 * Add a read-only offload status field.
	 *
	 * @param array<string, mixed> $fields Form fields.
	 * @param \WP_Post             $post   Attachment post.
	 * @return array<string, mixed>
	 */
	public function add_status_field( $fields, $post ) {
		$attachment_id = (int) $post->ID;

		if ( self::is_offloaded( $attachment_id ) ) {
			$offloaded_at = (int) get_post_meta( $attachment_id, 'omnioffloader_offloaded_at', true );
			$provider_key = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );

			$html = sprintf(
				'<span style="color:#0e4690;font-weight:600;">%s</span> — %s%s',
				esc_html__( 'Offloaded', 'omnioffloader' ),
				esc_html( '' !== $provider_key ? $provider_key : $this->provider->get_key() ),
				$offloaded_at > 0 ? esc_html( ' · ' . wp_date( get_option( 'date_format' ), $offloaded_at ) ) : ''
			);
		} else {
			$errors = get_post_meta( $attachment_id, 'omnioffloader_error_log', true );

			$html = esc_html__( 'Local', 'omnioffloader' );

			if ( is_array( $errors ) && ! empty( $errors ) ) {
				$last  = end( $errors );
				$html .= sprintf(
					' — <span style="color:#b32d2e;">%s</span>',
					esc_html( is_array( $last ) && isset( $last['message'] ) ? $last['message'] : __( 'last offload failed', 'omnioffloader' ) )
				);
			}
		}

		$fields['omnioffloader_status'] = array(
			'label' => __( 'Offload status', 'omnioffloader' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $fields;
	}
}
