<?php
/**
 * Rewrites hardcoded local URLs inside rendered content image tags.
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
 * Class ContentImageTagObserver
 *
 * Post content stores absolute local URLs at write time. This rewrites
 * them to the cloud domain at render time for offloaded attachments.
 */
class ContentImageTagObserver implements ObserverInterface {

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
		add_filter( 'wp_content_img_tag', array( $this, 'rewrite' ), 99, 3 );
	}

	/**
	 * Rewrite the local upload URLs inside one rendered <img> tag.
	 *
	 * @param string $filtered_image The full img tag markup.
	 * @param string $context        Rendering context.
	 * @param int    $attachment_id  Attachment ID (0 when unknown).
	 * @return string
	 */
	public function rewrite( $filtered_image, $context, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( 0 === $attachment_id || ! self::is_offloaded( $attachment_id ) ) {
			return $filtered_image;
		}

		$domain = $this->provider->get_domain();

		if ( '' === $domain ) {
			return $filtered_image;
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );

		if ( '' === $attached_file ) {
			return $filtered_image;
		}

		$upload_dir = wp_get_upload_dir();
		$dirname    = dirname( $attached_file );

		$local_base = trailingslashit( $upload_dir['baseurl'] );
		if ( '.' !== $dirname && '' !== $dirname ) {
			$local_base .= trailingslashit( $dirname );
		}

		$cloud_base = trailingslashit( $domain ) . self::get_offload_path( $attachment_id );

		return str_replace( $local_base, $cloud_base, $filtered_image );
	}
}
