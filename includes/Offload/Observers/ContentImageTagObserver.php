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
	 * Core blocks whose `id` / `mediaId` attribute is an attachment ID.
	 *
	 * Other blocks reuse those attribute names for unrelated IDs (a
	 * navigation link stores the linked post's ID in `id`), and looking them
	 * up would cost a meta query per block on every front-end page.
	 */
	private const MEDIA_BLOCKS = array(
		'core/image',
		'core/video',
		'core/audio',
		'core/file',
		'core/cover',
		'core/media-text',
		'core/gallery',
	);

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Local media URL => cloud URL ('' when not offloaded), per request.
	 *
	 * @var array<string, string>
	 */
	private $media_url_cache = array();

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
	 * @since 0.1.1
	 *
	 * @param string               $block_content The block HTML markup.
	 * @param array<string, mixed> $block         The parsed block array.
	 * @return string
	 */
	public function rewrite_block( $block_content, $block ) {
		if ( ! is_array( $block ) || empty( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
			return $block_content;
		}

		/**
		 * Filter the block names whose `id` / `mediaId` attribute is an
		 * attachment ID, for third-party media blocks.
		 *
		 * @since 0.1.3
		 *
		 * @param string[] $block_names Block names.
		 */
		$media_blocks = (array) apply_filters( 'omnioffloader_media_block_names', self::MEDIA_BLOCKS );

		if ( ! isset( $block['blockName'] ) || ! in_array( $block['blockName'], $media_blocks, true ) ) {
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
	 * @since 0.1.1
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

		$pattern = '/<(video|audio|source)\b([^>]*?)(\bsrc=["\'])([^"\']+)(["\'])/i';

		if ( ! preg_match_all( $pattern, $content, $found ) ) {
			return $content;
		}

		// Resolve every media URL in this content with one query instead of
		// an attachment_url_to_postid() lookup (an unindexed meta_value scan)
		// per src. Results are cached for the rest of the request, so
		// shortcode output that the_content scans again costs nothing.
		$this->resolve_media_urls( $found[4] );

		return (string) preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$cloud_url = $this->media_url_cache[ $matches[4] ] ?? '';

				if ( '' === $cloud_url ) {
					return $matches[0];
				}

				return '<' . $matches[1] . $matches[2] . $matches[3] . esc_url( $cloud_url ) . $matches[5];
			},
			$content
		);
	}

	/**
	 * Map local upload URLs to cloud URLs in one batched lookup.
	 *
	 * @since 0.1.3
	 *
	 * @param string[] $urls Media URLs found in content.
	 * @return void
	 */
	private function resolve_media_urls( array $urls ) {
		$base_url = set_url_scheme( trailingslashit( wp_get_upload_dir()['baseurl'] ), 'http' );
		$lookup   = array();

		foreach ( array_unique( $urls ) as $url ) {
			if ( isset( $this->media_url_cache[ $url ] ) ) {
				continue;
			}

			// Anything outside the uploads URL is never ours.
			$this->media_url_cache[ $url ] = '';

			$path = strtok( set_url_scheme( $url, 'http' ), '?#' );

			if ( is_string( $path ) && 0 === strpos( $path, $base_url ) ) {
				$lookup[ rawurldecode( substr( $path, strlen( $base_url ) ) ) ][] = $url;
			}
		}

		if ( empty( $lookup ) ) {
			return;
		}

		global $wpdb;

		$files        = array_keys( $lookup );
		$placeholders = implode( ', ', array_fill( 0, count( $files ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one batched lookup per content block, memoized per request; placeholders are generated above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ( {$placeholders} )", $files ) );

		if ( empty( $rows ) ) {
			return;
		}

		update_meta_cache( 'post', wp_list_pluck( $rows, 'post_id' ) );

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->post_id;

			if ( ! self::is_offloaded( $attachment_id ) ) {
				continue;
			}

			$cloud_url = wp_get_attachment_url( $attachment_id );

			if ( ! is_string( $cloud_url ) || '' === $cloud_url ) {
				continue;
			}

			foreach ( $lookup[ $row->meta_value ] ?? array() as $url ) {
				$this->media_url_cache[ $url ] = $cloud_url;
			}
		}
	}

	/**
	 * Rewrite video or audio shortcode HTML.
	 *
	 * @since 0.1.1
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
