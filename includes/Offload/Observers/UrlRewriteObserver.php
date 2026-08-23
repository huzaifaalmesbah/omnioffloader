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

		if ( ! self::is_offloaded( $attachment_id ) ) {
			return $url;
		}

		$domain = $this->provider->get_domain();

		if ( '' === $domain ) {
			return $url;
		}

		$filename = wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );

		return trailingslashit( $domain ) . self::get_offload_path( $attachment_id ) . $filename;
	}
}
