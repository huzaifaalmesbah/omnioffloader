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

echo "\nCleanup…\n";
wp_delete_attachment( $a, true );
wp_delete_attachment( $b, true );
update_option( 'omnioffloader_settings', $original, true );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket, true );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
