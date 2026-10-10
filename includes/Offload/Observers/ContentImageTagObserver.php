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
	 * A whole video/audio shortcode tag or media element in stored content.
	 * Neither carries an attachment ID, so their URLs are looked up.
	 */
	private const MEDIA_TAG_PATTERN = '/\[(?:video|audio)\b[^\]]*\]|<(?:video|audio|source)\b[^>]*>/i';

	/**
	 * An absolute URL inside one of those tags.
	 */
	private const TAG_URL_PATTERN = '/https?:\/\/[^"\'\s\]>]+/i';

	/**
	 * Attachments looked up for one piece of stored content, and URLs looked
	 * up in its shortcodes and media tags. Content is user input: without a
	 * bound, a post naming a hundred thousand IDs would load them all.
	 */
	private const MAX_CONTENT_ATTACHMENTS = 500;
	private const MAX_CONTENT_MEDIA_URLS  = 200;

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Hosts media can be served from: every provider's domain. Per request.
	 *
	 * @var string[]|null
	 */
	private $cloud_hosts = null;

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

		// Editors show the stored content unrendered, so none of the filters
		// above run: point it at the cloud while editing, and store local
		// URLs again on save.
		add_filter( 'content_edit_pre', array( $this, 'rewrite_for_editor' ), 99 );
		add_action( 'rest_api_init', array( $this, 'register_rest_filters' ), 99 );
		add_filter( 'content_save_pre', array( $this, 'restore_local_urls' ), 5 );
	}

	/**
	 * Filter the REST response of every post type the block editor can open.
	 *
	 * @since 0.1.4
	 *
	 * @return void
	 */
	public function register_rest_filters() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $post_type ) {
			add_filter( "rest_prepare_{$post_type}", array( $this, 'rewrite_rest_content' ), 99, 3 );
		}

		// A restored autosave or revision replaces the editor's content.
		add_filter( 'rest_prepare_autosave', array( $this, 'rewrite_rest_content' ), 99, 3 );
		add_filter( 'rest_prepare_revision', array( $this, 'rewrite_rest_content' ), 99, 3 );
	}

	/**
	 * Rewrite the raw content the block editor loads.
	 *
	 * @since 0.1.4
	 *
	 * @param mixed            $response Response.
	 * @param mixed            $post     Post (or template) the response is for.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 *
	 * @phpstan-param \WP_REST_Request<array<string, mixed>> $request
	 */
	public function rewrite_rest_content( $response, $post, $request ) {
		if ( ! $response instanceof \WP_REST_Response || 'edit' !== $request['context'] ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || ! isset( $data['content']['raw'] ) || ! is_string( $data['content']['raw'] ) ) {
			return $response;
		}

		$data['content']['raw'] = $this->rewrite_for_editor( $data['content']['raw'] );
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Point stored content at the cloud for an editor, like the front end
	 * does at render time — the local files may be gone.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Stored post content.
	 * @return string
	 */
	public function rewrite_for_editor( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$pairs = array();

		foreach ( $this->content_attachment_ids( $content ) as $attachment_id ) {
			if ( 'none' !== self::cloud_serving( $attachment_id ) ) {
				$pairs += $this->attachment_url_pairs( $attachment_id, false );
			}
		}

		// One pass over the content, however many attachments it holds.
		if ( ! empty( $pairs ) ) {
			$content = strtr( $content, $pairs );
		}

		return $this->swap_media_tag_urls( $content, false );
	}

	/**
	 * Store local URLs again when content is saved.
	 *
	 * An editor was handed cloud URLs, and inserting offloaded media adds
	 * more. Stored content keeps local URLs, so it survives a Bring Back, a
	 * provider switch or a new CDN domain; rendering rewrites them.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Post content, slashed.
	 * @return string
	 */
	public function restore_local_urls( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		// Every save of every post type passes here (imports, cron, other
		// plugins). Without a cloud URL in it there is nothing to restore,
		// and nothing is queried.
		if ( ! $this->has_cloud_host( $content ) ) {
			return $content;
		}

		$plain = wp_unslash( $content );
		$pairs = array();

		foreach ( $this->content_attachment_ids( $plain ) as $attachment_id ) {
			if ( self::is_offloaded( $attachment_id ) ) {
				$pairs += $this->attachment_url_pairs( $attachment_id, true );
			}
		}

		$restored = empty( $pairs ) ? $plain : strtr( $plain, $pairs );
		$restored = $this->swap_media_tag_urls( $restored, true );

		return $restored === $plain ? $content : wp_slash( $restored );
	}

	/**
	 * Whether content mentions a host media is served from.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Content, or a single URL.
	 * @return bool
	 */
	private function has_cloud_host( $content ) {
		if ( null === $this->cloud_hosts ) {
			$domains = array( $this->provider->get_domain() );

			// Media offloaded before a provider switch keeps that provider's domain.
			foreach ( array_keys( \OmniOffloader\Providers\ProviderRegistry::get_providers() ) as $key ) {
				if ( $key === $this->provider->get_key() ) {
					continue;
				}

				try {
					$domains[] = \OmniOffloader\Providers\ProviderRegistry::create( (string) $key )->get_domain();
				} catch ( \InvalidArgumentException $e ) {
					continue;
				}
			}

			$hosts = array();

			foreach ( $domains as $domain ) {
				$host = (string) wp_parse_url( (string) $domain, PHP_URL_HOST );

				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}

			$this->cloud_hosts = array_values( array_unique( $hosts ) );
		}

		foreach ( $this->cloud_hosts as $host ) {
			if ( false !== stripos( $content, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Swap the URLs of video/audio shortcodes and media elements in stored
	 * content: local to cloud for an editor, cloud to local for a save.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content  Stored post content (unslashed).
	 * @param bool   $to_local Cloud URLs back to local ones.
	 * @return string
	 */
	private function swap_media_tag_urls( $content, $to_local ) {
		if ( ! preg_match_all( self::MEDIA_TAG_PATTERN, $content, $tags ) || ! preg_match_all( self::TAG_URL_PATTERN, implode( ' ', $tags[0] ), $urls ) ) {
			return $content;
		}

		$map   = array();
		$found = array_slice( array_unique( $urls[0] ), 0, self::MAX_CONTENT_MEDIA_URLS );

		if ( $to_local ) {
			$map = $this->resolve_cloud_urls( $found );

			if ( empty( $map ) ) {
				return $content;
			}
		} else {
			$this->resolve_media_urls( $found );
		}

		$swap_url = function ( $matches ) use ( $map, $to_local ) {
			$cut   = strcspn( $matches[0], '?#' );
			$plain = substr( $matches[0], 0, $cut );
			$new   = $to_local ? ( $map[ $plain ] ?? '' ) : ( $this->media_url_cache[ set_url_scheme( $plain, 'http' ) ] ?? '' );

			return '' === $new ? $matches[0] : $new . substr( $matches[0], $cut );
		};

		$swapped = preg_replace_callback(
			self::MEDIA_TAG_PATTERN,
			static function ( $tag ) use ( $swap_url ) {
				$swapped_tag = preg_replace_callback( self::TAG_URL_PATTERN, $swap_url, $tag[0] );

				// Never drop a tag over a regex failure.
				return is_string( $swapped_tag ) ? $swapped_tag : $tag[0];
			},
			$content
		);

		return is_string( $swapped ) ? $swapped : $content;
	}

	/**
	 * Map cloud URLs of offloaded attachments' main files to their local
	 * URLs in one batched lookup.
	 *
	 * A cloud URL ends in [yyyy/mm/][object_version/]file, so the attached
	 * file is one of its path's endings, with or without the folder right
	 * before the filename. Each match is confirmed against the attachment's
	 * real cloud URL.
	 *
	 * @since 0.1.4
	 *
	 * @param string[] $urls URLs found in content.
	 * @return array<string, string> Cloud URL (no query/fragment) => local URL.
	 */
	private function resolve_cloud_urls( array $urls ) {
		$base_url   = trailingslashit( wp_get_upload_dir()['baseurl'] );
		$local_base = set_url_scheme( $base_url, 'http' );
		$wanted     = array();
		$files      = array();

		foreach ( array_unique( $urls ) as $url ) {
			$plain = substr( $url, 0, strcspn( $url, '?#' ) );

			// Embeds from anywhere else are never ours, and cost no lookup.
			if ( 0 === strpos( set_url_scheme( $plain, 'http' ), $local_base ) || ! $this->has_cloud_host( $plain ) ) {
				continue;
			}

			$segments = explode( '/', ltrim( (string) wp_parse_url( $plain, PHP_URL_PATH ), '/' ) );
			$file     = (string) array_pop( $segments );

			if ( '' === $file ) {
				continue;
			}

			$wanted[ $plain ] = true;

			// Uploads folders are rarely deeper than yyyy/mm.
			$segments = array_slice( $segments, -4 );

			for ( $i = count( $segments ); $i >= 0; $i-- ) {
				$dir = array_slice( $segments, $i );

				$files[ implode( '/', array_merge( $dir, array( $file ) ) ) ] = true;

				if ( ! empty( $dir ) ) {
					array_pop( $dir );
					$files[ implode( '/', array_merge( $dir, array( $file ) ) ) ] = true;
				}
			}
		}

		if ( empty( $files ) ) {
			return array();
		}

		global $wpdb;

		$names        = array_map( 'strval', array_keys( $files ) );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one batched lookup per save; placeholders are generated above.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ( {$placeholders} )", $names ) );

		// The database compares loosely (case, trailing spaces); the path that
		// goes into the content must be exactly one taken from its own URL.
		$rows = array_filter(
			(array) $rows,
			static function ( $row ) use ( $files ) {
				return isset( $files[ $row->meta_value ] );
			}
		);

		if ( empty( $rows ) ) {
			return array();
		}

		_prime_post_caches( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ), false, true );

		$map = array();

		foreach ( $rows as $row ) {
			$attachment_id = (int) $row->post_id;

			if ( ! self::is_offloaded( $attachment_id ) ) {
				continue;
			}

			$domain = self::cloud_domain( $attachment_id, $this->provider );

			if ( '' === $domain ) {
				continue;
			}

			$cloud_url = trailingslashit( $domain ) . self::get_offload_path( $attachment_id ) . wp_basename( $row->meta_value );

			if ( isset( $wanted[ $cloud_url ] ) ) {
				$map[ $cloud_url ] = $base_url . $row->meta_value;
			}
		}

		return $map;
	}

	/**
	 * Attachments stored content refers to: media block IDs and the
	 * `wp-image-{id}` class of image tags. Primed in one batch.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Stored post content (unslashed).
	 * @return int[]
	 */
	private function content_attachment_ids( $content ) {
		$ids = self::block_attachment_ids( $content );

		if ( preg_match_all( '/\bwp-image-(\d+)/', $content, $found ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $found[1] ) );
		}

		$ids = array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, self::MAX_CONTENT_ATTACHMENTS );

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}

		return $ids;
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
		if ( ! is_string( $content ) ) {
			return $content;
		}

		$ids = self::block_attachment_ids( $content );

		if ( ! empty( $ids ) ) {
			_prime_post_caches( $ids, false, true );
		}

		return $content;
	}

	/**
	 * Attachment IDs of the media blocks in unrendered content.
	 *
	 * @since 0.1.4
	 *
	 * @param string $content Post content (unrendered blocks).
	 * @return int[]
	 */
	private static function block_attachment_ids( $content ) {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return array();
		}

		/** This filter is documented in includes/Offload/Observers/ContentImageTagObserver.php */
		$media_blocks = (array) apply_filters( 'omnioffloader_media_block_names', self::MEDIA_BLOCKS );
		$names        = array();

		foreach ( $media_blocks as $name ) {
			// Block comments omit the "core/" namespace.
			$names[] = preg_quote( 0 === strpos( (string) $name, 'core/' ) ? substr( (string) $name, 5 ) : (string) $name, '/' );
		}

		if ( empty( $names ) || ! preg_match_all( '/<!-- wp:(?:' . implode( '|', $names ) . ')\s+(\{[^\n]*?\})\s+\/?-->/', $content, $blocks ) ) {
			return array();
		}

		$ids = array();

		foreach ( $blocks[1] as $json ) {
			if ( preg_match_all( '/"(?:id|mediaId)":(\d+)/', $json, $found ) ) {
				foreach ( $found[1] as $id ) {
					$ids[] = (int) $id;
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
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
		$pairs = $this->attachment_url_pairs( $attachment_id, false );

		return empty( $pairs ) ? $content : str_replace( array_keys( $pairs ), array_values( $pairs ), $content );
	}

	/**
	 * The URL replacements for one attachment's own files.
	 *
	 * @since 0.1.4
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $to_local      Reverse: cloud URLs back to local ones.
	 * @return array<string, string> Search => replacement, longest search first.
	 */
	private function attachment_url_pairs( $attachment_id, $to_local ) {
		$domain = self::cloud_domain( $attachment_id, $this->provider );
		if ( '' === $domain ) {
			return array();
		}

		$attached_file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( '' === $attached_file ) {
			return array();
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
		// Every file on the way back: stored content is always local.
		$with_main = $to_local || 'all' === self::cloud_serving( $attachment_id );

		return $this->file_url_pairs( $attachment_id, $local_base, $cloud_base, $with_main, $to_local );
	}

	/**
	 * The replacements that point an attachment's own file URLs at the
	 * cloud, file by file.
	 *
	 * Without the main files, only the generated sizes move: Smart Cleanup
	 * media while "Serve media from cloud" is off keeps its main files on the
	 * server, under their local URLs.
	 *
	 * @since 0.1.4
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $local_base    Local directory URL, trailing slash.
	 * @param string $cloud_base    Cloud directory URL, trailing slash.
	 * @param bool   $with_main     Also the main file, original and root sources.
	 * @param bool   $to_local      Reverse: cloud URLs back to local ones.
	 * @return array<string, string> Search => replacement, longest search first.
	 */
	private function file_url_pairs( $attachment_id, $local_base, $cloud_base, $with_main, $to_local ) {
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
			return array();
		}

		// Longest first, so no filename is replaced as the prefix of another.
		$files = array_unique( $files );
		usort(
			$files,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$pairs = array();

		foreach ( $files as $file ) {
			if ( $to_local ) {
				$pairs[ $cloud_base . $file ] = $local_base . $file;
				continue;
			}

			foreach ( array( 'http', 'https' ) as $scheme ) {
				$pairs[ set_url_scheme( $local_base, $scheme ) . $file ] = $cloud_base . $file;
			}
		}

		return $pairs;
	}
}
