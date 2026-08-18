<?php
/**
 * Rewrites responsive srcset source URLs to the cloud domain.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload\Observers;

use SmartMediaOffloader\Offload\ObserverInterface;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SrcsetObserver
 */
class SrcsetObserver implements ObserverInterface {

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
		add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite' ), 99, 5 );
	}

	/**
	 * Rewrite each srcset source URL for offloaded attachments.
	 *
	 * @param array<int|string, array{url: string, descriptor: string, value: int|string}> $sources       Srcset sources.
	 * @param array<int, int>                                                              $size_array    Requested width/height.
	 * @param string                                                                       $image_src     Image src.
	 * @param array<string, mixed>                                                         $image_meta    Attachment metadata.
	 * @param int                                                                          $attachment_id Attachment ID.
	 * @return array<int|string, array{url: string, descriptor: string, value: int|string}>
	 */
	public function rewrite( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! is_array( $sources ) || ! self::is_offloaded( $attachment_id ) ) {
			return $sources;
		}

		$domain = $this->provider->get_domain();

		if ( '' === $domain ) {
			return $sources;
		}

		$base = trailingslashit( $domain ) . self::get_offload_path( $attachment_id );

		foreach ( $sources as $width => $source ) {
			$filename = wp_basename( (string) wp_parse_url( $source['url'], PHP_URL_PATH ) );

			$sources[ $width ]['url'] = $base . $filename;
		}

		return $sources;
	}
}
