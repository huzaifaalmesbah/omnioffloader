<?php
/**
 * Integration test for Bring Back (run via `wp eval-file`).
 *
 * Proves the full round-trip: offload with Full Cloud Migration → restore →
 * files byte-identical to the cloud copies, URLs local again. Also covers
 * opt-in cloud deletion, skip-if-present, failure safety (meta stays
 * truthful when a download fails), and the disk-space preflight.
 *
 * @package SmartMediaOffloader
 */

require_once SMOWP_DIR . 'tests/MockProvider.php';

use SmartMediaOffloader\Core\Helpers;
use SmartMediaOffloader\Offload\Offloader;
use SmartMediaOffloader\Services\AttachmentRestorer;
use SmartMediaOffloader\Tests\MockProvider;

// The real offload engine must be off for a hermetic run.
if ( ! defined( 'SMOWP_DISABLE_ENGINE' ) || ! SMOWP_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'SMOWP_DISABLE_ENGINE', true );\" eval-file tests/integration-restore.php\n";
	exit( 1 );
}

/**
 * No-arg-constructible mock so ProviderRegistry::create() can build it —
 * AttachmentRestorer resolves the provider from attachment meta via the
 * registry, exactly as it would for a real provider.
 */
class SmowpRegisteredMockProvider extends MockProvider {
	/**
	 * Bucket dir shared with the test.
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
}

// eval-file runs in function scope; counters must live in globals.
global $smowp_pass, $smowp_fail;
$smowp_pass = 0;
$smowp_fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Test label.
 */
function smowp_check( $condition, $label ) {
	global $smowp_pass, $smowp_fail;
	if ( $condition ) {
		++$smowp_pass;
		echo "  ✓ {$label}\n";
	} else {
		++$smowp_fail;
		echo "  ✗ FAIL: {$label}\n";
	}
}

/**
 * Create a test image attachment through the real upload flow.
 *
 * @param string $name File name.
 * @return int Attachment ID.
 */
function smowp_make_attachment( $name ) {
	$image = imagecreatetruecolor( 1400, 900 );
	imagefilledrectangle( $image, 0, 0, 1399, 899, imagecolorallocate( $image, 40, 120, 160 ) );
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

	$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
	wp_update_attachment_metadata( $attachment_id, $metadata );

	return $attachment_id;
}

/**
 * All local file paths of an attachment (main + originals + sizes).
 *
 * @param int $attachment_id Attachment ID.
 * @return array<string, string> Object key => local path.
 */
function smowp_file_map( $attachment_id ) {
	$files = SmartMediaOffloader\Services\AttachmentRestorer::collect_attachment_files( $attachment_id );
	$map   = array();

	foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
		$map[ $entry['key'] ] = $entry['file'];
	}

	return $map;
}

// ---------------------------------------------------------------------------
// Setup: registered mock provider + engine, isolated settings.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'smowp_settings', array() );

$bucket_dir                      = get_temp_dir() . 'smowp-mock-bucket-' . time();
SmowpRegisteredMockProvider::$dir = $bucket_dir;

add_filter(
	'smowp_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = SmowpRegisteredMockProvider::class;

		return $providers;
	}
);

$provider  = new SmowpRegisteredMockProvider();
$offloader = new Offloader( $provider );
$offloader->register_observers();

echo "Mock bucket: {$bucket_dir}\n\n";

// ---------------------------------------------------------------------------
// Test 1 — Round-trip: full migration offload → restore → identical files.
// ---------------------------------------------------------------------------
echo "Test 1: round-trip — full cloud migration, then Bring Back\n";

update_option(
	'smowp_settings',
	array_merge( $original_settings, array( 'retention_policy' => 2, 'mirror_delete' => true, 'object_versioning' => false, 'path_prefix_active' => false ) ),
	true
);

$id1  = smowp_make_attachment( 'smowp-restore-full.jpg' );
$map1 = smowp_file_map( $id1 );

smowp_check( (bool) get_post_meta( $id1, 'smowp_offloaded', true ), 'attachment offloaded' );
smowp_check( ! file_exists( get_attached_file( $id1 ) ), 'local main file deleted (policy 2)' );

// Cloud-side checksums are the source of truth for the round-trip.
$cloud_hashes = array();
foreach ( $map1 as $key => $file ) {
	if ( $provider->object_exists( $key ) ) {
		$cloud_hashes[ $key ] = md5_file( trailingslashit( $bucket_dir ) . $key );
	}
}
smowp_check( count( $cloud_hashes ) >= 2, count( $cloud_hashes ) . ' objects in bucket before restore' );

$restorer = new AttachmentRestorer();
smowp_check( true === $restorer->restore( $id1, false ), 'restore() returned success' );

$all_back  = true;
$all_match = true;
foreach ( $cloud_hashes as $key => $hash ) {
	$file = $map1[ $key ];
	if ( ! file_exists( $file ) ) {
		$all_back = false;
	} elseif ( md5_file( $file ) !== $hash ) {
		$all_match = false;
	}
}
smowp_check( $all_back, 'every cloud object is back on disk' );
smowp_check( $all_match, 'restored files are byte-identical (md5) to cloud copies' );

smowp_check( ! get_post_meta( $id1, 'smowp_offloaded', true ), 'offload meta cleared' );
smowp_check( false === strpos( (string) wp_get_attachment_url( $id1 ), 'cdn.example.test' ), 'URL serves locally again' );

$still_in_cloud = true;
foreach ( array_keys( $cloud_hashes ) as $key ) {
	if ( ! $provider->object_exists( $key ) ) {
		$still_in_cloud = false;
	}
}
smowp_check( $still_in_cloud, 'cloud copies kept (delete_cloud off)' );

// ---------------------------------------------------------------------------
// Test 2 — Restore with delete_cloud: objects removed after verification.
// ---------------------------------------------------------------------------
echo "\nTest 2: restore with delete_cloud\n";

$id2  = smowp_make_attachment( 'smowp-restore-delete.jpg' );
$map2 = smowp_file_map( $id2 );

smowp_check( (bool) get_post_meta( $id2, 'smowp_offloaded', true ), 'attachment offloaded' );

smowp_check( true === $restorer->restore( $id2, true ), 'restore( delete_cloud ) returned success' );
smowp_check( file_exists( get_attached_file( $id2 ) ), 'main file restored locally' );

$cloud_gone = true;
foreach ( array_keys( $map2 ) as $key ) {
	if ( $provider->object_exists( $key ) ) {
		$cloud_gone = false;
	}
}
smowp_check( $cloud_gone, 'cloud objects deleted after verified restore' );

// ---------------------------------------------------------------------------
// Test 3 — Skip-if-present: retention 0 restore re-downloads nothing.
// ---------------------------------------------------------------------------
echo "\nTest 3: retention 0 — restore skips files already on disk\n";

update_option( 'smowp_settings', array_merge( get_option( 'smowp_settings' ), array( 'retention_policy' => 0 ) ), true );

$id3   = smowp_make_attachment( 'smowp-restore-skip.jpg' );
$file3 = get_attached_file( $id3 );
$mtime = filemtime( $file3 );

sleep( 1 ); // A re-download would bump mtime.
smowp_check( true === $restorer->restore( $id3, false ), 'restore() succeeded with all files present' );
clearstatcache();
smowp_check( filemtime( $file3 ) === $mtime, 'main file untouched (skip-if-identical)' );
smowp_check( ! get_post_meta( $id3, 'smowp_offloaded', true ), 'offload meta cleared' );

// ---------------------------------------------------------------------------
// Test 4 — Failure safety: missing cloud object leaves truthful meta.
// ---------------------------------------------------------------------------
echo "\nTest 4: failure safety — main object missing in cloud\n";

update_option( 'smowp_settings', array_merge( get_option( 'smowp_settings' ), array( 'retention_policy' => 2 ) ), true );

$id4  = smowp_make_attachment( 'smowp-restore-fail.jpg' );
$map4 = smowp_file_map( $id4 );

// Sabotage: remove the main object from the bucket.
$main_key = (string) array_key_first( $map4 );
unlink( trailingslashit( $bucket_dir ) . $main_key );

smowp_check( false === $restorer->restore( $id4, false ), 'restore() reported failure' );
smowp_check( (bool) get_post_meta( $id4, 'smowp_offloaded', true ), 'offload meta kept (attachment still serves from cloud)' );

$log4 = get_post_meta( $id4, 'smowp_error_log', true );
smowp_check( is_array( $log4 ) && ! empty( $log4 ), 'failure logged to smowp_error_log' );

// ---------------------------------------------------------------------------
// Test 5 — Preflight: counts offloaded items, checks disk space.
// ---------------------------------------------------------------------------
echo "\nTest 5: preflight\n";

Helpers::flush_media_stats();
$preflight = Helpers::get_restore_preflight();

smowp_check( $preflight['offloaded'] >= 1, "counts offloaded attachments ({$preflight['offloaded']})" );
smowp_check( $preflight['estimated_bytes'] > 0, 'estimates download size (' . $preflight['estimated_bytes'] . ' bytes)' );
smowp_check( is_bool( $preflight['disk_ok'] ), 'disk_ok computed' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id1, true );
wp_delete_attachment( $id2, true );
wp_delete_attachment( $id3, true );
wp_delete_attachment( $id4, true );
update_option( 'smowp_settings', $original_settings, true );
Helpers::flush_media_stats();

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$smowp_pass} passed, {$smowp_fail} failed\n";
exit( $smowp_fail > 0 ? 1 : 0 );
