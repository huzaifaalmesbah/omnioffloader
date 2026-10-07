<?php
/**
 * Integration test for the AsyncUploadProcessor and background offload pipeline.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

// Local test sites (*.test) are in Safe mode; this test exercises writes.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Tests\MockProvider;

// The real offload engine must be off for a hermetic run.
if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-async-upload.php\n";
	exit( 1 );
}

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_async_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) {
		echo "  ✓ {$label}\n";
		++$passed;
	} else {
		echo "  ✗ FAIL: {$label}\n";
		++$failed;
	}
}

function omnioffloader_make_async_test_attachment( $name ) {
	$image = imagecreatetruecolor( 800, 600 );
	imagefilledrectangle( $image, 0, 0, 799, 599, imagecolorallocate( $image, 50, 100, 150 ) );
	ob_start();
	imagejpeg( $image, null, 85 );
	$bits = ob_get_clean();

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

	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return $attachment_id;
}

echo "Testing Async Upload Pipeline\n\n";

$bucket_dir      = get_temp_dir() . 'omnioffloader-async-mock-bucket-' . time();
$provider        = new MockProvider( $bucket_dir );
$async_processor = new AsyncUploadProcessor( $provider );

$offloader = new Offloader( $provider, $async_processor );
$offloader->register_observers();

// Force async mode in CLI test context.
add_filter( 'omnioffloader_use_async_upload', '__return_true' );

$original_settings = get_option( 'omnioffloader_settings', array() );

update_option(
	'omnioffloader_settings',
	array(
		'provider'                 => 'mock',
		'retention_policy'         => 0,
		'mirror_delete'            => true,
		'path_prefix_active'       => false,
		'path_prefix'              => '',
		'object_versioning'        => false,
		'async_upload'             => true,
		'bring_back_delete_cloud'  => false,
		'remove_data_on_uninstall' => false,
	),
	true
);

// ---------------------------------------------------------------------------
// Test 1: Upload is local-first (not offloaded synchronously)
// ---------------------------------------------------------------------------
echo "Test 1: Upload completes locally with zero blocking\n";

$id = omnioffloader_make_async_test_attachment( 'async-test-1.jpg' );
$file = get_attached_file( $id );

omnioffloader_async_check( ! (bool) get_post_meta( $id, 'omnioffloader_offloaded', true ), 'attachment is NOT offloaded yet synchronously' );

$local_url = wp_get_attachment_url( $id );
omnioffloader_async_check( false !== strpos( $local_url, 'wp-content/uploads' ), "serves local URL immediately: {$local_url}" );

// ---------------------------------------------------------------------------
// Test 2: Background worker processes the attachment
// ---------------------------------------------------------------------------
echo "\nTest 2: Background worker offloads the queued attachment\n";

$reflection = new ReflectionClass( $async_processor );
$task_method = $reflection->getMethod( 'task' );
$task_method->setAccessible( true );

$result = $task_method->invoke( $async_processor, $id );
omnioffloader_async_check( false === $result, 'task() returns false (dequeued)' );
omnioffloader_async_check( (bool) get_post_meta( $id, 'omnioffloader_offloaded', true ), 'attachment is now marked offloaded' );

$cloud_url = wp_get_attachment_url( $id );
omnioffloader_async_check( 0 === strpos( $cloud_url, 'https://cdn.example.test/' ), "URL now automatically serves from cloud CDN: {$cloud_url}" );

$subdir = get_post_meta( $id, 'omnioffloader_path', true );
omnioffloader_async_check( $provider->object_exists( $subdir . wp_basename( $file ) ), 'file exists in cloud bucket' );

// ---------------------------------------------------------------------------
// Test 3: Purge stored queue data
// ---------------------------------------------------------------------------
echo "\nTest 3: Purge queue data on uninstaller\n";
AsyncUploadProcessor::purge_stored_data();
omnioffloader_async_check( true, 'purge_stored_data completed cleanly' );

// Cleanup
echo "\nCleanup…\n";
wp_delete_attachment( $id, true );
remove_filter( 'omnioffloader_use_async_upload', '__return_true' );
update_option( 'omnioffloader_settings', $original_settings, true );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
