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
	 * Local media URL (scheme-less, no query/fragment) => cloud URL, or ''
	 * when not offloaded. Per request. Keyed without the query because the
	 * media shortcodes add a per-instance "?_=N" that would defeat the memo.
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

		// Prime every media block's attachment in one batch before do_blocks
		// (priority 9) renders them — otherwise each block's offload check is
		// a meta query of its own, since core only primes at priority 12.
		add_filter( 'the_content', array( $this, 'prime_block_attachments' ), 8 );

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

		if ( 0 === $attachment_id || 'none' === self::cloud_serving( $attachment_id ) ) {
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

		if ( 0 === $attachment_id || 'none' === self::cloud_serving( $attachment_id ) ) {
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
				list( $key, $suffix ) = self::split_media_url( $matches[4] );
				$cloud_url            = $this->media_url_cache[ $key ] ?? '';

				if ( '' === $cloud_url ) {
					return $matches[0];
				}

				// Keep the original query (e.g. the shortcode's "?_=1").
				return '<' . $matches[1] . $matches[2] . $matches[3] . esc_url( $cloud_url . $suffix ) . $matches[5];
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
			$key = self::split_media_url( $url )[0];

			if ( isset( $this->media_url_cache[ $key ] ) ) {
				continue;
			}

			// Anything outside the uploads URL is never ours.
			$this->media_url_cache[ $key ] = '';

			if ( 0 === strpos( $key, $base_url ) ) {
				$lookup[ rawurldecode( substr( $key, strlen( $base_url ) ) ) ][] = $key;
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

		// Posts and meta in two queries; wp_get_attachment_url() needs both.
		_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ), false, true );

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->post_id;

			if ( 'none' === self::cloud_serving( $attachment_id ) ) {
				continue;
			}

			$cloud_url = wp_get_attachment_url( $attachment_id );

			if ( ! is_string( $cloud_url ) || '' === $cloud_url ) {
				continue;
			}

			foreach ( $lookup[ $row->meta_value ] ?? array() as $key ) {
				$this->media_url_cache[ $key ] = $cloud_url;
			}
		}
	}

	/**
	 * Split a media URL into its memo key and its query/fragment suffix.
	 *
	 * @since 0.1.3
	 *
	 * @param string $url URL.
	 * @return array{0: string, 1: string} Scheme-normalised URL without query/fragment, and the suffix.
	 */
	private static function split_media_url( $url ) {
		$cut = strcspn( $url, '?#' );

		return array( set_url_scheme( substr( $url, 0, $cut ), 'http' ), substr( $url, $cut ) );
	}

	/**
	 * Prime the attachments referenced by media blocks in one batch.
	 *
	 * Runs on the_content before do_blocks(), so the per-block offload check
	 * in rewrite_block() reads warm caches instead of querying per block.
	 *
	 * @since 0.1.3
	 *
	 * @param string $content Post content (unrendered blocks).
	 * @return string Unchanged content.
	 */
	public function prime_block_attachments( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		/** This filter is documented in includes/Offload/Observers/ContentImageTagObserver.php */
		$media_blocks = (array) apply_filters( 'omnioffloader_media_block_names', self::MEDIA_BLOCKS );
		$names        = array();

		foreach ( $media_blocks as $name ) {
			// Block comments omit the "core/" namespace.
			$names[] = preg_quote( 0 === strpos( (string) $name, 'core/' ) ? substr( (string) $name, 5 ) : (string) $name, '/' );
		}

		if ( empty( $names ) || ! preg_match_all( '/<!-- wp:(?:' . implode( '|', $names ) . ')\s+(\{[^\n]*?\})\s+\/?-->/', $content, $blocks ) ) {
			return $content;
		}

		$ids = array();

		foreach ( $blocks[1] as $json ) {
			if ( preg_match_all( '/"(?:id|mediaId)":(\d+)/', $json, $found ) ) {
				foreach ( $found[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}

		return $content;
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
		$domain = self::cloud_domain( $attachment_id, $this->provider );
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

		// Only this attachment's own files: block markup (a Cover or Media &
		// Text block holds its inner blocks) can also link other files from
		// the same upload folder, which may be local or in another bucket
		// folder. Generated sizes only while the main files stay local.
		$with_main = 'all' === self::cloud_serving( $attachment_id );

		return $this->replace_file_urls_in_content( $content, $attachment_id, $local_base, $cloud_base, $with_main );
	}

	/**
	 * Point an attachment's own file URLs at the cloud, file by file.
	 *
	 * Without the main files, only the generated sizes move: Smart Cleanup
	 * media while "Serve media from cloud" is off keeps its main files on the
	 * server, under their local URLs.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content       Content markup.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $local_base    Local directory URL, trailing slash.
	 * @param string $cloud_base    Cloud directory URL, trailing slash.
	 * @param bool   $with_main     Also the main file, original and root sources.
	 * @return string
	 */
	private function replace_file_urls_in_content( $content, $attachment_id, $local_base, $cloud_base, $with_main ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$main     = self::main_filenames( $attachment_id );
		$files    = $with_main ? $main : array();

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_data ) {
				if ( is_array( $size_data ) ) {
					foreach ( self::files_from_size_data( $size_data ) as $file ) {
						if ( $with_main || ! in_array( $file, $main, true ) ) {
							$files[] = $file;
						}
					}
				}
			}
		}

		// Pre-edit sizes of an edited image: old post content may still link
		// them, and they were offloaded with the attachment.
		if ( $with_main ) {
			$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );

			if ( is_array( $backups ) ) {
				foreach ( $backups as $size_data ) {
					if ( is_array( $size_data ) ) {
						$files = array_merge( $files, self::files_from_size_data( $size_data ) );
					}
				}
			}
		}

		$files = array_filter( $files, static fn( $file ) => '' !== $file );

		if ( empty( $files ) ) {
			return $content;
		}

		// Longest first, so no filename is replaced as the prefix of another.
		$files = array_unique( $files );
		usort(
			$files,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$searches = array();
		$replaces = array();

		foreach ( $files as $file ) {
			foreach ( array( 'http', 'https' ) as $scheme ) {
				$searches[] = set_url_scheme( $local_base, $scheme ) . $file;
				$replaces[] = $cloud_base . $file;
			}
		}

		return str_replace( $searches, $replaces, $content );
	}
}
