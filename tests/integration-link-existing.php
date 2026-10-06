<?php
/**
 * Integration test for linking media already copied to the bucket
 * (`wp omnioffloader offload --link-existing`), run via `wp eval-file`.
 *
 * Simulates an rclone copy into a filesystem mock bucket, then checks that
 * matching files are linked without an upload, missing or different files
 * are uploaded, the retention policy still only runs once everything is
 * in the bucket, and the per-file fallback works without a folder listing.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

// Safe mode would treat a local test site (*.test, localhost) as a copy.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Tests\MockProvider;
use OmniOffloader\Traits\AttachmentHelper;

// The real offload engine must be off: attachments are created local-only.
if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-link-existing.php\n";
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
 * Create a local-only image attachment with generated sizes.
 *
 * @param string $name File name.
 * @return int Attachment ID.
 */
function omnioffloader_make_local_attachment( $name ) {
	$image = imagecreatetruecolor( 1400, 900 );
	imagefilledrectangle( $image, 0, 0, 1399, 899, imagecolorallocate( $image, 30, 90, 200 ) );
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
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	return $attachment_id;
}

/**
 * The attachment's local files and the keys they offload to.
 *
 * @param int $attachment_id Attachment ID.
 * @return array<int, array{file: string, key: string}>
 */
function omnioffloader_entries( $attachment_id ) {
	$helper = new class() {
		use AttachmentHelper;
	};
	$files  = $helper::collect_attachment_files( $attachment_id );

	return array_merge( $files['main'], $files['sizes'] );
}

/**
 * Copy files into the mock bucket, as rclone would.
 *
 * @param string                                       $bucket_dir Mock bucket.
 * @param array<int, array{file: string, key: string}> $entries    Files to copy.
 * @return void
 */
function omnioffloader_seed( $bucket_dir, array $entries ) {
	foreach ( $entries as $entry ) {
		$dest = trailingslashit( $bucket_dir ) . $entry['key'];
		wp_mkdir_p( dirname( $dest ) );
		copy( $entry['file'], $dest );
	}
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

update_option(
	'omnioffloader_settings',
	array_merge( $original_settings, array( 'retention_policy' => 0, 'object_versioning' => false, 'path_prefix_active' => false ) ),
	true
);

$bucket_dir = get_temp_dir() . 'omnioffloader-mock-link-' . time();
$provider   = new MockProvider( $bucket_dir );

echo "Mock bucket: {$bucket_dir}\n\n";

$full    = omnioffloader_make_local_attachment( 'omnioffloader-link-full.jpg' );
$partial = omnioffloader_make_local_attachment( 'omnioffloader-link-partial.jpg' );
$empty   = omnioffloader_make_local_attachment( 'omnioffloader-link-none.jpg' );

$full_entries    = omnioffloader_entries( $full );
$partial_entries = omnioffloader_entries( $partial );
$empty_entries   = omnioffloader_entries( $empty );

// "rclone": everything of $full; for $partial the main file, one size with
// different contents (an optimizer shrank it later), the rest missing.
omnioffloader_seed( $bucket_dir, $full_entries );
omnioffloader_seed( $bucket_dir, array( $partial_entries[0] ) );
$changed_key = $partial_entries[1]['key'];
wp_mkdir_p( dirname( $bucket_dir . '/' . $changed_key ) );
file_put_contents( $bucket_dir . '/' . $changed_key, 'not the same file' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

// ---------------------------------------------------------------------------
// Test 1 — Dry-run preview reads the bucket and changes nothing.
// ---------------------------------------------------------------------------
echo "Test 1: preview\n";

$uploader = new AttachmentUploader( $provider );
$preview  = $uploader->link_preview( array( $full, $partial, $empty ) );

omnioffloader_check( $preview[ $full ]['present'] === $preview[ $full ]['files'] && 0 === $preview[ $full ]['upload_bytes'], 'fully copied attachment: every file present, nothing to upload' );
omnioffloader_check( 1 === $preview[ $partial ]['present'] && $preview[ $partial ]['upload_bytes'] > 0, 'partly copied attachment: only the main file counts as present' );
omnioffloader_check( 0 === $preview[ $empty ]['present'], 'attachment not copied: nothing present' );
omnioffloader_check( 0 === $provider->uploads && ! get_post_meta( $full, 'omnioffloader_offloaded', true ), 'preview uploaded nothing and marked nothing' );
omnioffloader_check( 1 === $provider->list_calls, 'one folder listing served all three attachments' );

// ---------------------------------------------------------------------------
// Test 2 — Linking.
// ---------------------------------------------------------------------------
echo "\nTest 2: link existing\n";

$uploader = new AttachmentUploader( $provider );
$uploader->link_existing();
$results = $uploader->upload_many( array( $full, $partial, $empty ) );
$counts  = $uploader->file_counts();

omnioffloader_check( true === $results[ $full ] && true === $results[ $partial ] && true === $results[ $empty ], 'all three attachments offloaded' );

$expected_uploads = count( $partial_entries ) - 1 + count( $empty_entries );
omnioffloader_check( $expected_uploads === $provider->uploads, "only missing or different files uploaded ({$provider->uploads} of " . ( count( $full_entries ) + count( $partial_entries ) + count( $empty_entries ) ) . ')' );
omnioffloader_check( count( $full_entries ) + 1 === $counts['linked'] && $expected_uploads === $counts['uploaded'], "file counts: {$counts['linked']} linked, {$counts['uploaded']} uploaded" );
omnioffloader_check( filesize( $partial_entries[1]['file'] ) === $provider->object_size( $changed_key ), 'the different cloud copy was replaced by the server file' );
omnioffloader_check( 'mock' === get_post_meta( $full, 'omnioffloader_provider', true ) && '' !== (string) get_post_meta( $full, 'omnioffloader_path', true ), 'linked attachment has full offload meta' );
omnioffloader_check( file_exists( $full_entries[0]['file'] ), 'Retain Local Files: local files kept' );

// ---------------------------------------------------------------------------
// Test 3 — Cleanup policy runs only after the bucket is complete; per-file
// fallback when the folder cannot be listed.
// ---------------------------------------------------------------------------
echo "\nTest 3: Full Cloud Migration, no folder listing\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 2 ) ), true );

$migrate         = omnioffloader_make_local_attachment( 'omnioffloader-link-migrate.jpg' );
$migrate_entries = omnioffloader_entries( $migrate );
omnioffloader_seed( $bucket_dir, $migrate_entries );

$provider->listing = false;
$provider->uploads = 0;

$uploader = new AttachmentUploader( $provider );
$uploader->link_existing();

omnioffloader_check( true === $uploader->upload( $migrate ), 'attachment linked through the per-file fallback' );
omnioffloader_check( 0 === $provider->uploads, 'no file uploaded again' );

$all_gone = true;
foreach ( $migrate_entries as $entry ) {
	if ( file_exists( $entry['file'] ) ) {
		$all_gone = false;
	}
}
omnioffloader_check( $all_gone, 'local files removed after every file was verified in the bucket' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
foreach ( array( $full, $partial, $empty, $migrate ) as $id ) {
	wp_delete_attachment( $id, true );
}
update_option( 'omnioffloader_settings', $original_settings, true );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
