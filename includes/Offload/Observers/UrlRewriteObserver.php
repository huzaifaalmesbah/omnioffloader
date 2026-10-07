<?php
/**
 * Rewrites attachment URLs to the cloud domain.
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
 * Class UrlRewriteObserver
 */
class UrlRewriteObserver implements ObserverInterface {

	use AttachmentHelper;

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Set while downsize() runs image_downsize() itself, so it is not re-entered.
	 *
	 * @var bool
	 */
	private $in_downsize = false;

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
		add_filter( 'wp_get_attachment_url', array( $this, 'rewrite' ), 99, 2 );
		add_filter( 'image_downsize', array( $this, 'downsize' ), 99, 3 );
	}

	/**
	 * Rewrite an offloaded attachment's URL to the cloud domain.
	 *
	 * @param string $url           Local URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	public function rewrite( $url, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		$filename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		if ( ! self::is_served_from_cloud( $attachment_id, $filename ) ) {
			return $url;
		}

		$domain = self::cloud_domain( $attachment_id, $this->provider );

		if ( '' === $domain ) {
			return $url;
		}

		return trailingslashit( $domain ) . self::get_offload_path( $attachment_id ) . $filename;
	}

	/**
	 * Point a generated size at the cloud when only the sizes are served
	 * from it.
	 *
	 * Core builds a size URL by swapping the filename in the attachment URL.
	 * With "Serve media from cloud" off, a Smart Cleanup attachment's URL is
	 * local — its main file is still on the server — but its sizes were
	 * deleted, so they must come from the cloud.
	 *
	 * @since 0.1.4
	 *
	 * @param array<int, mixed>|false $downsize      Short-circuit value from an earlier filter.
	 * @param int                     $attachment_id Attachment ID.
	 * @param string|int[]            $size          Requested size.
	 * @return array<int, mixed>|false
	 */
	public function downsize( $downsize, $attachment_id, $size ) {
		$attachment_id = (int) $attachment_id;

		if ( false !== $downsize || $this->in_downsize || 'sizes' !== self::cloud_serving( $attachment_id ) ) {
			return $downsize;
		}

		$this->in_downsize = true;
		$image             = image_downsize( $attachment_id, $size );
		$this->in_downsize = false;

		if ( ! is_array( $image ) || ! is_string( $image[0] ) ) {
			return $downsize;
		}

		$filename = wp_basename( (string) wp_parse_url( $image[0], PHP_URL_PATH ) );
		$domain   = self::cloud_domain( $attachment_id, $this->provider );

		if ( '' !== $domain && self::is_served_from_cloud( $attachment_id, $filename ) ) {
			$image[0] = trailingslashit( $domain ) . self::get_offload_path( $attachment_id ) . $filename;
		}

		return $image;
	}
}
