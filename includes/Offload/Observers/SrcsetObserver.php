<?php
/**
 * Rewrites responsive srcset source URLs to the cloud domain.
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

		$serving = self::cloud_serving( $attachment_id );

		if ( ! is_array( $sources ) || 'none' === $serving ) {
			return $sources;
		}

		$domain = self::cloud_domain( $attachment_id, $this->provider );

		if ( '' === $domain ) {
			return $sources;
		}

		$base = trailingslashit( $domain ) . self::get_offload_path( $attachment_id );
		$keep = 'sizes' === $serving ? self::main_filenames( $attachment_id ) : array();

		foreach ( $sources as $width => $source ) {
			$filename = wp_basename( (string) wp_parse_url( $source['url'], PHP_URL_PATH ) );

			// Still on the server and not served from the cloud.
			if ( in_array( $filename, $keep, true ) ) {
				continue;
			}

			$sources[ $width ]['url'] = $base . $filename;
		}

		return $sources;
	}
}
