<?php
/**
 * Rewrites hardcoded local URLs inside rendered content media tags.
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
 * them to the cloud domain at render time for offloaded attachments
 * across images, videos, audio, and Gutenberg media blocks.
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
		// Images via WordPress core content tags filter.
		add_filter( 'wp_content_img_tag', array( $this, 'rewrite' ), 99, 3 );

		// Gutenberg media blocks (video, audio, file, media-text, cover, etc.).
		add_filter( 'render_block', array( $this, 'rewrite_block' ), 99, 2 );

		// Post content fallback for Classic Editor / raw HTML media tags.
		add_filter( 'the_content', array( $this, 'rewrite_content_media' ), 99 );

		// Shortcodes.
		add_filter( 'wp_video_shortcode', array( $this, 'rewrite_shortcode' ), 99 );
		add_filter( 'wp_audio_shortcode', array( $this, 'rewrite_shortcode' ), 99 );
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

		return $this->replace_attachment_url_in_content( $filtered_image, $attachment_id );
	}

	/**
	 * Rewrite offloaded media URLs inside Gutenberg blocks.
	 *
	 * @param string               $block_content The block HTML markup.
	 * @param array<string, mixed> $block         The parsed block array.
	 * @return string
	 */
	public function rewrite_block( $block_content, $block ) {
		if ( ! is_array( $block ) || empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return $block_content;
		}

		$attachment_id = 0;
		if ( isset( $block['attrs']['id'] ) && is_numeric( $block['attrs']['id'] ) ) {
			$attachment_id = (int) $block['attrs']['id'];
		} elseif ( isset( $block['attrs']['mediaId'] ) && is_numeric( $block['attrs']['mediaId'] ) ) {
			$attachment_id = (int) $block['attrs']['mediaId'];
		}

		if ( 0 === $attachment_id || ! self::is_offloaded( $attachment_id ) ) {
			return $block_content;
		}

		return $this->replace_attachment_url_in_content( $block_content, $attachment_id );
	}

	/**
	 * Fallback for raw HTML or Classic Editor video/audio tags in post content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function rewrite_content_media( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Fast bail if no media tags exist.
		if ( false === stripos( $content, '<video' ) && false === stripos( $content, '<audio' ) && false === stripos( $content, '<source' ) ) {
			return $content;
		}

		$upload_dir = wp_get_upload_dir();
		$base_url   = $upload_dir['baseurl'];

		// Match src attributes inside video, audio, or source tags.
		return (string) preg_replace_callback(
			'/<(video|audio|source)\b([^>]*?)(\bsrc=["\'])([^"\']+)(["\'])/i',
			function ( $matches ) use ( $base_url ) {
				$url = $matches[4];

				// Only process local uploads URLs.
				if ( 0 !== strpos( set_url_scheme( $url, 'http' ), set_url_scheme( $base_url, 'http' ) ) ) {
					return $matches[0];
				}

				$attachment_id = attachment_url_to_postid( $url );
				if ( $attachment_id > 0 && self::is_offloaded( $attachment_id ) ) {
					$cloud_url = wp_get_attachment_url( $attachment_id );
					if ( is_string( $cloud_url ) && '' !== $cloud_url ) {
						return '<' . $matches[1] . $matches[2] . $matches[3] . esc_url( $cloud_url ) . $matches[5];
					}
				}

				return $matches[0];
			},
			$content
		);
	}

	/**
	 * Rewrite video or audio shortcode HTML.
	 *
	 * @param string $html Shortcode output.
	 * @return string
	 */
	public function rewrite_shortcode( $html ) {
		return $this->rewrite_content_media( $html );
	}

	/**
	 * Replace local attachment URLs with cloud URLs in a content snippet.
	 *
	 * Supports both http and https local URL variants.
	 *
	 * @param string $content       Content markup.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function replace_attachment_url_in_content( $content, $attachment_id ) {
		$domain = $this->provider->get_domain();
		if ( '' === $domain ) {
			return $content;
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached_file ) {
			return $content;
		}

		$upload_dir = wp_get_upload_dir();
		$dirname    = dirname( $attached_file );

		$local_base = trailingslashit( $upload_dir['baseurl'] );
		if ( '.' !== $dirname && '' !== $dirname ) {
			$local_base .= trailingslashit( $dirname );
		}

		$cloud_base = trailingslashit( $domain ) . self::get_offload_path( $attachment_id );

		// Handle both http:// and https:// variations in stored content.
		$searches = array(
			set_url_scheme( $local_base, 'http' ),
			set_url_scheme( $local_base, 'https' ),
		);

		return str_replace( $searches, $cloud_base, $content );
	}
}
