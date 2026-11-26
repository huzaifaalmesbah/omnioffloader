<?php
/**
 * Integration test for `wp omnioffloader offload --concurrency` (run via
 * `wp eval-file`): the per-run override, batch sizing, and that a big
 * pool still offloads every attachment completely.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

// Safe mode would treat a local test site (*.test, localhost) as a copy.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Core\Helpers;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-concurrency.php\n";
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

$original_settings = get_option( 'omnioffloader_settings', array() );

update_option(
	'omnioffloader_settings',
	array_merge( $original_settings, array( 'retention_policy' => 0, 'object_versioning' => false, 'path_prefix_active' => false, 'transfer_speed' => 'custom', 'transfer_concurrency' => 10 ) ),
	true
);

// ---------------------------------------------------------------------------
// Test 1 — Override and batch sizing.
// ---------------------------------------------------------------------------
echo "Test 1: override\n";

omnioffloader_check( 10 === AttachmentUploader::files_at_once() && 3 === AttachmentUploader::batch_size(), 'setting: 10 files, 3 attachments per batch' );

Helpers::set_transfer_concurrency_override( 48 );
omnioffloader_check( 48 === AttachmentUploader::files_at_once(), 'override 48 wins over the setting' );
omnioffloader_check( 12 === AttachmentUploader::batch_size(), 'batch grows to 12 attachments' );

Helpers::set_transfer_concurrency_override( 500 );
omnioffloader_check( Helpers::MAX_CLI_CONCURRENCY === AttachmentUploader::files_at_once() && 16 === AttachmentUploader::batch_size(), 'clamped to 64 files, 16 attachments' );

Helpers::set_transfer_concurrency_override( 0 );
omnioffloader_check( 10 === AttachmentUploader::files_at_once(), 'override 0 follows the setting again' );

// ---------------------------------------------------------------------------
// Test 2 — A big batch offloads every attachment completely.
// ---------------------------------------------------------------------------
echo "\nTest 2: big batch\n";

require_once ABSPATH . 'wp-admin/includes/image.php';

$bucket_dir = get_temp_dir() . 'omnioffloader-mock-conc-' . time();
$provider   = new MockProvider( $bucket_dir );
$ids        = array();

for ( $i = 1; $i <= 12; $i++ ) {
	$image = imagecreatetruecolor( 1200, 800 );
	imagefilledrectangle( $image, 0, 0, 1199, 799, imagecolorallocate( $image, 20 * $i, 80, 140 ) );
	ob_start();
	imagejpeg( $image, null, 80 );
	$bits = ob_get_clean();

	$upload = wp_upload_bits( "omnioffloader-conc-{$i}.jpg", null, $bits );
	$id     = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => "omnioffloader-conc-{$i}",
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	$ids[] = $id;
}

Helpers::set_transfer_concurrency_override( 48 );

$uploader = new AttachmentUploader( $provider );
$results  = $uploader->upload_many( $ids );

omnioffloader_check( count( $ids ) === count( array_filter( $results ) ), '12 attachments in one batch all offloaded' );

$complete = true;
foreach ( $ids as $id ) {
	$meta = wp_get_attachment_metadata( $id );
	$path = (string) get_post_meta( $id, 'omnioffloader_path', true );

	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		if ( ! $provider->object_exists( $path . $size['file'] ) ) {
			$complete = false;
		}
	}
}
omnioffloader_check( $complete, 'every generated size is in the bucket' );

Helpers::set_transfer_concurrency_override( 0 );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
foreach ( $ids as $id ) {
	wp_delete_attachment( $id, true );
}
update_option( 'omnioffloader_settings', $original_settings, true );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
