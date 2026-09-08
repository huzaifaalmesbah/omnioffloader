<?php
/**
 * Integration test for the offload pipeline (run via `wp eval-file`).
 *
 * Exercises: auto-offload through the real wp_generate_attachment_metadata
 * filter chain, URL/srcset rewriting, retention policies 0/1/2, mirror
 * delete, and filename collision protection — using a filesystem mock
 * provider, no cloud credentials needed.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Offload\Offloader;
use OmniOffloader\Tests\MockProvider;

// The real offload engine must be off for a hermetic run.
if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-offload.php\n";
	exit( 1 );
}

// eval-file runs in function scope; counters must live in globals.
global $omnioffloader_pass, $omnioffloader_fail;
$omnioffloader_pass = 0;
$omnioffloader_fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Test label.
 */
function omnioffloader_check( $condition, $label ) {
	global $omnioffloader_pass, $omnioffloader_fail;
	if ( $condition ) {
		++$omnioffloader_pass;
		echo "  ✓ {$label}\n";
	} else {
		++$omnioffloader_fail;
		echo "  ✗ FAIL: {$label}\n";
	}
}

/**
 * Create a test image attachment through the real upload flow.
 *
 * @param string $name File name.
 * @return int Attachment ID.
 */
function omnioffloader_make_attachment( $name ) {
	$image = imagecreatetruecolor( 1400, 900 );
	imagefilledrectangle( $image, 0, 0, 1399, 899, imagecolorallocate( $image, 40, 160, 120 ) );
	ob_start();
	imagejpeg( $image, null, 85 );
	$bits = ob_get_clean();
	imagedestroy( $image );

	$upload = wp_upload_bits( $name, null, $bits );
	if ( ! empty( $upload['error'] ) ) {
		echo "Cannot create upload: {$upload['error']}\n";
		exit( 1 );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Real flow: generate (fires our UploadObserver at 999) then persist.
	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return $attachment_id;
}

// ---------------------------------------------------------------------------
// Setup: mock provider + engine, isolated settings.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

$bucket_dir = get_temp_dir() . 'omnioffloader-mock-bucket-' . time();
$provider   = new MockProvider( $bucket_dir );

$offloader = new Offloader( $provider );
$offloader->register_observers();

echo "Mock bucket: {$bucket_dir}\n\n";

// ---------------------------------------------------------------------------
// Test 1 — Retention 0 (retain local): offload + URL rewriting.
// ---------------------------------------------------------------------------
echo "Test 1: retention 0 — offload, keep local, rewrite URLs\n";

update_option(
	'omnioffloader_settings',
	array_merge( $original_settings, array( 'retention_policy' => 0, 'mirror_delete' => true, 'object_versioning' => false, 'path_prefix_active' => false ) ),
	true
);

$id1   = omnioffloader_make_attachment( 'omnioffloader-test-retain.jpg' );
$file1 = get_attached_file( $id1 );
$meta1 = wp_get_attachment_metadata( $id1 );

omnioffloader_check( (bool) get_post_meta( $id1, 'omnioffloader_offloaded', true ), 'attachment marked offloaded' );
omnioffloader_check( 'mock' === get_post_meta( $id1, 'omnioffloader_provider', true ), 'provider meta recorded' );

$subdir = get_post_meta( $id1, 'omnioffloader_path', true );
omnioffloader_check( $provider->object_exists( $subdir . wp_basename( $file1 ) ), 'main file in bucket' );

$sizes_in_bucket = true;
foreach ( $meta1['sizes'] as $size ) {
	if ( ! $provider->object_exists( $subdir . $size['file'] ) ) {
		$sizes_in_bucket = false;
	}
}
omnioffloader_check( $sizes_in_bucket, 'all ' . count( $meta1['sizes'] ) . ' size files in bucket' );

if ( ! empty( $meta1['original_image'] ) ) {
	omnioffloader_check( $provider->object_exists( $subdir . $meta1['original_image'] ), 'pre-scaled original in bucket' );
}

omnioffloader_check( file_exists( $file1 ), 'local main file retained (policy 0)' );

$url1 = wp_get_attachment_url( $id1 );
omnioffloader_check( 0 === strpos( $url1, 'https://cdn.example.test/' ), "URL rewritten: {$url1}" );

$srcset = wp_get_attachment_image_srcset( $id1, 'large' );
omnioffloader_check( is_string( $srcset ) && false !== strpos( $srcset, 'https://cdn.example.test/' ) && false === strpos( $srcset, 'wp-content/uploads' ), 'srcset rewritten to cloud domain' );

// ---------------------------------------------------------------------------
// Test 2 — Retention 1 (smart cleanup): sizes deleted, original kept.
// ---------------------------------------------------------------------------
echo "\nTest 2: retention 1 — smart cleanup\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 1 ) ), true );

$id2   = omnioffloader_make_attachment( 'omnioffloader-test-smart.jpg' );
$file2 = get_attached_file( $id2 );
$meta2 = wp_get_attachment_metadata( $id2 );

omnioffloader_check( file_exists( $file2 ), 'main file kept locally' );

$sizes_gone = true;
foreach ( $meta2['sizes'] as $size ) {
	if ( file_exists( trailingslashit( dirname( $file2 ) ) . $size['file'] ) ) {
		$sizes_gone = false;
	}
}
omnioffloader_check( $sizes_gone, 'all local size files deleted' );
omnioffloader_check( is_string( wp_get_attachment_image_srcset( $id2, 'large' ) ), 'srcset still served (from cloud)' );

// ---------------------------------------------------------------------------
// Test 3 — Retention 2 (full migration): everything local deleted.
// ---------------------------------------------------------------------------
echo "\nTest 3: retention 2 — full cloud migration\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 2 ) ), true );

$id3   = omnioffloader_make_attachment( 'omnioffloader-test-full.jpg' );
$file3 = get_attached_file( $id3 );
$meta3 = wp_get_attachment_metadata( $id3 );
$sub3  = get_post_meta( $id3, 'omnioffloader_path', true );

omnioffloader_check( ! file_exists( $file3 ), 'local main file deleted' );
omnioffloader_check( $provider->object_exists( $sub3 . wp_basename( $file3 ) ), 'cloud copy exists' );
omnioffloader_check( 0 === strpos( (string) wp_get_attachment_url( $id3 ), 'https://cdn.example.test/' ), 'URL serves from cloud' );

// Filename collision protection: same name must get a suffix now.
$unique = wp_unique_filename( dirname( $file3 ), 'omnioffloader-test-full.jpg' );
omnioffloader_check( 'omnioffloader-test-full.jpg' !== $unique, "new upload with same name gets suffix: {$unique}" );

// ---------------------------------------------------------------------------
// Test 4 — Mirror delete: cloud objects removed with the attachment.
// ---------------------------------------------------------------------------
echo "\nTest 4: mirror delete\n";

$keys3 = OmniOffloader\Services\LocalCleaner::collect_delete_keys( $id3 );
wp_delete_attachment( $id3, true );

$all_deleted = true;
foreach ( $keys3 as $key ) {
	if ( $provider->object_exists( $key ) ) {
		$all_deleted = false;
	}
}
omnioffloader_check( $all_deleted, count( $keys3 ) . ' cloud objects deleted with attachment' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id1, true );
wp_delete_attachment( $id2, true );
update_option( 'omnioffloader_settings', $original_settings, true );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
