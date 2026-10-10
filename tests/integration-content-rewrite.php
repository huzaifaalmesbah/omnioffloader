<?php
/**
 * Integration test for ContentImageTagObserver: a block rewrites only its
 * own attachment's file URLs, never other files from the same upload
 * folder (a Cover or Media & Text block holds its inner blocks).
 *
 * Usage: wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-content-rewrite.php
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Offload\Observers\ContentImageTagObserver;
use OmniOffloader\Tests\MockProvider;

/**
 * A provider with its own key and domain, for a provider switch.
 */
class OmnioffloaderRewriteOldProvider extends MockProvider {
	/**
	 * Bucket dir.
	 *
	 * @var string
	 */
	public static $dir = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( self::$dir );
	}

	/**
	 * Key.
	 *
	 * @return string
	 */
	public function get_key() {
		return 'mock_old';
	}

	/**
	 * Domain.
	 *
	 * @return string
	 */
	public function get_domain() {
		return 'https://old-cdn.example.test';
	}
}

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_rewrite_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) {
		echo "  ✓ {$label}\n";
		++$passed;
	} else {
		echo "  ✗ FAIL: {$label}\n";
		++$failed;
	}
}

function omnioffloader_rewrite_attachment( $name ) {
	$image = imagecreatetruecolor( 800, 600 );
	ob_start();
	imagejpeg( $image );
	$bits = ob_get_clean();

	$upload = wp_upload_bits( $name, null, $bits );
	$id     = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	return $id;
}

echo "Testing block URL rewriting\n\n";

$original = get_option( 'omnioffloader_settings', array() );
update_option( 'omnioffloader_settings', array_merge( $original, array( 'serve_from_cloud' => true ) ), true );

$bucket   = get_temp_dir() . 'omnioffloader-rewrite-bucket-' . time();
$observer = new ContentImageTagObserver( new MockProvider( $bucket ) );

// A: offloaded into a bucket folder. B: same upload folder, still local.
$a = omnioffloader_rewrite_attachment( 'rewrite-test-a.jpg' );
$b = omnioffloader_rewrite_attachment( 'rewrite-test-b.jpg' );

$subdir = trailingslashit( dirname( (string) get_post_meta( $a, '_wp_attached_file', true ) ) );
update_post_meta( $a, 'omnioffloader_offloaded', 1 );
update_post_meta( $a, 'omnioffloader_path', 'site-a/' . $subdir );
update_post_meta( $a, 'omnioffloader_retention', 0 );

$url_a       = wp_get_attachment_url( $a );
$thumb_a     = wp_get_attachment_image_src( $a, 'thumbnail' )[0];
$url_b       = wp_get_attachment_url( $b );
$cloud_a     = 'https://cdn.example.test/site-a/' . $subdir . wp_basename( $url_a );
$cloud_thumb = 'https://cdn.example.test/site-a/' . $subdir . wp_basename( $thumb_a );

$html = '<div class="wp-block-cover"><img class="wp-block-cover__image-background" src="' . $url_a . '" srcset="' . $thumb_a . ' 150w" />'
	. '<div class="wp-block-cover__inner-container"><p><a href="' . $url_b . '">Download B</a></p></div></div>';

$out = $observer->rewrite_block(
	$html,
	array(
		'blockName' => 'core/cover',
		'attrs'     => array( 'id' => $a ),
	)
);

echo "Test 1: a Cover block with another local file inside\n";
omnioffloader_rewrite_check( false !== strpos( $out, 'src="' . $cloud_a . '"' ), "A's image points to its cloud folder" );
omnioffloader_rewrite_check( false !== strpos( $out, $cloud_thumb . ' 150w' ), "A's size in srcset points to the cloud" );
omnioffloader_rewrite_check( false !== strpos( $out, 'href="' . $url_b . '"' ), "B's link stays local" );

echo "\nTest 2: Smart Cleanup with Serve Media from Cloud off (sizes only)\n";
update_option( 'omnioffloader_settings', array_merge( $original, array( 'serve_from_cloud' => false ) ), true );
update_post_meta( $a, 'omnioffloader_retention', 1 );
$out = $observer->rewrite_block(
	$html,
	array(
		'blockName' => 'core/cover',
		'attrs'     => array( 'id' => $a ),
	)
);
omnioffloader_rewrite_check( false !== strpos( $out, 'src="' . $url_a . '"' ), "A's main image stays local" );
omnioffloader_rewrite_check( false !== strpos( $out, $cloud_thumb . ' 150w' ), "A's size points to the cloud" );
omnioffloader_rewrite_check( false !== strpos( $out, 'href="' . $url_b . '"' ), "B's link stays local" );

echo "\nTest 3: after a provider switch\n";
update_option( 'omnioffloader_settings', array_merge( $original, array( 'serve_from_cloud' => true ) ), true );
OmnioffloaderRewriteOldProvider::$dir = $bucket;
add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock_old'] = OmnioffloaderRewriteOldProvider::class;

		return $providers;
	}
);
update_post_meta( $a, 'omnioffloader_provider', 'mock_old' );
update_post_meta( $a, 'omnioffloader_retention', 0 );
$url_rewrite = new OmniOffloader\Offload\Observers\UrlRewriteObserver( new MockProvider( $bucket ) );
omnioffloader_rewrite_check( 'https://old-cdn.example.test/site-a/' . $subdir . wp_basename( $url_a ) === $url_rewrite->rewrite( $url_a, $a ), 'A keeps the domain of the provider it was offloaded to' );
update_post_meta( $a, 'omnioffloader_provider', 'mock' );
omnioffloader_rewrite_check( $cloud_a === $url_rewrite->rewrite( $url_a, $a ), 'media on the active provider uses its domain' );

echo "\nTest 4: the editor gets cloud URLs, a save stores local ones\n";
$stored = '<!-- wp:image {"id":' . $a . ',"sizeSlug":"thumbnail"} -->' . "\n"
	. '<figure class="wp-block-image size-thumbnail"><img src="' . $thumb_a . '" alt="" class="wp-image-' . $a . '"/></figure>' . "\n"
	. '<!-- /wp:image -->' . "\n"
	. '<p><a href="' . $url_b . '">B</a> <img src="' . $url_a . '" class="wp-image-' . $a . '"/></p>';
$edit   = $observer->rewrite_for_editor( $stored );
omnioffloader_rewrite_check( false !== strpos( $edit, 'src="' . $cloud_thumb . '"' ), "the editor loads A's size from the cloud" );
omnioffloader_rewrite_check( false !== strpos( $edit, 'src="' . $cloud_a . '"' ), "a classic image tag of A too" );
omnioffloader_rewrite_check( false !== strpos( $edit, 'href="' . $url_b . '"' ), "B's link stays local" );
omnioffloader_rewrite_check( wp_slash( $stored ) === $observer->restore_local_urls( wp_slash( $edit ) ), 'a save stores the local URLs again' );
omnioffloader_rewrite_check( wp_slash( $stored ) === $observer->restore_local_urls( wp_slash( $stored ) ), 'local content is saved untouched' );

update_option( 'omnioffloader_settings', array_merge( $original, array( 'serve_from_cloud' => false ) ), true );
update_post_meta( $a, 'omnioffloader_retention', 1 );
$edit = $observer->rewrite_for_editor( $stored );
omnioffloader_rewrite_check( false !== strpos( $edit, 'src="' . $cloud_thumb . '"' ) && false !== strpos( $edit, 'src="' . $url_a . '"' ), 'sizes only: the size from the cloud, the main file local' );
update_post_meta( $a, 'omnioffloader_retention', 0 );
omnioffloader_rewrite_check( $stored === $observer->rewrite_for_editor( $stored ), 'nothing served from the cloud: content unchanged' );

echo "\nTest 5: video shortcodes and media tags, which carry no attachment ID\n";
update_option( 'omnioffloader_settings', array_merge( $original, array( 'serve_from_cloud' => true ) ), true );
$url_rewrite->register();
$cloud_a = wp_get_attachment_url( $a );
$stored  = '[video width="640" mp4="' . $url_a . '" poster="' . $url_b . '"][/video]' . "\n"
	. '<audio controls src="' . $url_a . '?_=1"></audio> [audio src="https://example.org/2026/10/else.mp3"]' . "\n"
	. '<a href="' . $url_a . '">plain link</a>';
$edit    = $observer->rewrite_for_editor( $stored );
omnioffloader_rewrite_check( false !== strpos( $edit, 'mp4="' . $cloud_a . '"' ), "the shortcode loads A from the cloud" );
omnioffloader_rewrite_check( false !== strpos( $edit, 'src="' . $cloud_a . '?_=1"' ), 'a media tag too, keeping its query' );
omnioffloader_rewrite_check( false !== strpos( $edit, 'poster="' . $url_b . '"' ) && false !== strpos( $edit, 'href="' . $url_a . '"' ), "B's poster and a plain link stay as stored" );
omnioffloader_rewrite_check( wp_slash( $stored ) === $observer->restore_local_urls( wp_slash( $edit ) ), 'a save stores the local URLs again' );

update_post_meta( $a, 'omnioffloader_path', 'site-a/' . $subdir . '20261010120000/' );
$versioned = wp_get_attachment_url( $a );
omnioffloader_rewrite_check( wp_slash( '[video mp4="' . $url_a . '"]' ) === $observer->restore_local_urls( wp_slash( '[video mp4="' . $versioned . '"]' ) ), 'a versioned cloud URL is stored local too' );
omnioffloader_rewrite_check( wp_slash( '[video mp4="https://other.example/' . $subdir . wp_basename( $url_a ) . '"]' ) === $observer->restore_local_urls( wp_slash( '[video mp4="https://other.example/' . $subdir . wp_basename( $url_a ) . '"]' ) ), "another site's URL with the same path is left alone" );

echo "\nTest 6: a save costs nothing unless the content holds a cloud URL\n";
global $wpdb;
wp_cache_flush();
$before  = $wpdb->num_queries;
$foreign = wp_slash( '<img src="' . $url_a . '" class="wp-image-' . $a . '"/> [video mp4="https://example.org/' . $subdir . wp_basename( $url_a ) . '"]' );
omnioffloader_rewrite_check( $foreign === $observer->restore_local_urls( $foreign ) && $before === $wpdb->num_queries, 'no query for content without a cloud URL' );

$many = '';
for ( $i = 1; $i <= 3000; $i++ ) {
	$many .= '<img class="wp-image-' . ( 900000 + $i ) . '" src="https://cdn.example.test/x.jpg"/>';
}
wp_cache_flush();
$before = $wpdb->num_queries;
$observer->restore_local_urls( wp_slash( $many ) );
omnioffloader_rewrite_check( $wpdb->num_queries - $before <= 3, 'thousands of IDs in content stay a bounded lookup (' . ( $wpdb->num_queries - $before ) . ' queries)' );
omnioffloader_rewrite_check( substr_count( $wpdb->last_query, ',' ) < 600, 'at most 500 attachments are looked up' );

echo "\nCleanup…\n";
wp_delete_attachment( $a, true );
wp_delete_attachment( $b, true );
update_option( 'omnioffloader_settings', $original, true );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket, true );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
