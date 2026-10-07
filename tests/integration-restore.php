<?php
/**
 * Integration test for Bring Back (run via `wp eval-file`).
 *
 * Proves the full round-trip: offload with Full Cloud Migration → restore →
 * files byte-identical to the cloud copies, URLs local again. Also covers
 * opt-in cloud deletion, skip-if-present, failure safety (meta stays
 * truthful when a download fails), and the disk-space preflight.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

// Safe mode would treat a local test site (*.test, localhost) as a copy and
// refuse every upload; these tests exercise the real pipeline.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}


use OmniOffloader\Core\Helpers;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Tests\MockProvider;

// The real offload engine must be off for a hermetic run.
if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-restore.php\n";
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
function omnioffloader_file_map( $attachment_id ) {
	$files = OmniOffloader\Services\AttachmentRestorer::collect_attachment_files( $attachment_id );
	$map   = array();

	foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
		$map[ $entry['key'] ] = $entry['file'];
	}

	return $map;
}

// ---------------------------------------------------------------------------
// Setup: registered mock provider + engine, isolated settings.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

$bucket_dir                      = get_temp_dir() . 'omnioffloader-mock-bucket-' . time();
SmowpRegisteredMockProvider::$dir = $bucket_dir;

add_filter(
	'omnioffloader_cloud_providers',
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
	'omnioffloader_settings',
	array_merge( $original_settings, array( 'retention_policy' => 2, 'mirror_delete' => true, 'object_versioning' => false, 'path_prefix_active' => false ) ),
	true
);

$id1  = omnioffloader_make_attachment( 'omnioffloader-restore-full.jpg' );
$map1 = omnioffloader_file_map( $id1 );

omnioffloader_check( (bool) get_post_meta( $id1, 'omnioffloader_offloaded', true ), 'attachment offloaded' );
omnioffloader_check( ! file_exists( get_attached_file( $id1 ) ), 'local main file deleted (policy 2)' );

// Cloud-side checksums are the source of truth for the round-trip.
$cloud_hashes = array();
foreach ( $map1 as $key => $file ) {
	if ( $provider->object_exists( $key ) ) {
		$cloud_hashes[ $key ] = md5_file( trailingslashit( $bucket_dir ) . $key );
	}
}
omnioffloader_check( count( $cloud_hashes ) >= 2, count( $cloud_hashes ) . ' objects in bucket before restore' );

$restorer = new AttachmentRestorer();
omnioffloader_check( true === $restorer->restore( $id1, false ), 'restore() returned success' );

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
omnioffloader_check( $all_back, 'every cloud object is back on disk' );
omnioffloader_check( $all_match, 'restored files are byte-identical (md5) to cloud copies' );

omnioffloader_check( ! get_post_meta( $id1, 'omnioffloader_offloaded', true ), 'offload meta cleared' );
omnioffloader_check( false === strpos( (string) wp_get_attachment_url( $id1 ), 'cdn.example.test' ), 'URL serves locally again' );

$still_in_cloud = true;
foreach ( array_keys( $cloud_hashes ) as $key ) {
	if ( ! $provider->object_exists( $key ) ) {
		$still_in_cloud = false;
	}
}
omnioffloader_check( $still_in_cloud, 'cloud copies kept (delete_cloud off)' );

// ---------------------------------------------------------------------------
// Test 2 — Restore with delete_cloud: objects removed after verification.
// ---------------------------------------------------------------------------
echo "\nTest 2: restore with delete_cloud\n";

$id2  = omnioffloader_make_attachment( 'omnioffloader-restore-delete.jpg' );
$map2 = omnioffloader_file_map( $id2 );

omnioffloader_check( (bool) get_post_meta( $id2, 'omnioffloader_offloaded', true ), 'attachment offloaded' );

omnioffloader_check( true === $restorer->restore( $id2, true ), 'restore( delete_cloud ) returned success' );
omnioffloader_check( file_exists( get_attached_file( $id2 ) ), 'main file restored locally' );

$cloud_gone = true;
foreach ( array_keys( $map2 ) as $key ) {
	if ( $provider->object_exists( $key ) ) {
		$cloud_gone = false;
	}
}
omnioffloader_check( $cloud_gone, 'cloud objects deleted after verified restore' );

// ---------------------------------------------------------------------------
// Test 2b — delete_cloud keeps objects that were never downloaded.
// ---------------------------------------------------------------------------
echo "\nTest 2b: delete_cloud keeps an edit backup that is only in the cloud\n";

$id2b   = omnioffloader_make_attachment( 'omnioffloader-restore-backup.jpg' );
$map2b  = omnioffloader_file_map( $id2b );
$key2b  = (string) array_key_first( $map2b );
$bkey2b = trailingslashit( dirname( $key2b ) ) . 'omnioffloader-restore-backup-orig.jpg';

// An image edited after offload: core recorded the pre-edit original as a
// backup, and only the bucket has it.
update_post_meta( $id2b, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'omnioffloader-restore-backup-orig.jpg' ) ) );
copy( trailingslashit( $bucket_dir ) . $key2b, trailingslashit( $bucket_dir ) . $bkey2b );

omnioffloader_check( true === $restorer->restore( $id2b, true ), 'restore( delete_cloud ) returned success' );
omnioffloader_check( ! $provider->object_exists( $key2b ), 'restored main object deleted from cloud' );
omnioffloader_check( $provider->object_exists( $bkey2b ), 'backup with no local copy kept in cloud' );

// ---------------------------------------------------------------------------
// Test 3 — Skip-if-present: retention 0 restore re-downloads nothing.
// ---------------------------------------------------------------------------
echo "\nTest 3: retention 0 — restore skips files already on disk\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 0 ) ), true );

$id3   = omnioffloader_make_attachment( 'omnioffloader-restore-skip.jpg' );
$file3 = get_attached_file( $id3 );
$mtime = filemtime( $file3 );

sleep( 1 ); // A re-download would bump mtime.
omnioffloader_check( true === $restorer->restore( $id3, false ), 'restore() succeeded with all files present' );
clearstatcache();
omnioffloader_check( filemtime( $file3 ) === $mtime, 'main file untouched (skip-if-identical)' );
omnioffloader_check( ! get_post_meta( $id3, 'omnioffloader_offloaded', true ), 'offload meta cleared' );

// ---------------------------------------------------------------------------
// Test 4 — Failure safety: missing cloud object leaves truthful meta.
// ---------------------------------------------------------------------------
echo "\nTest 4: failure safety — main object missing in cloud\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 2 ) ), true );

$id4  = omnioffloader_make_attachment( 'omnioffloader-restore-fail.jpg' );
$map4 = omnioffloader_file_map( $id4 );

// Sabotage: remove the main object from the bucket.
$main_key = (string) array_key_first( $map4 );
unlink( trailingslashit( $bucket_dir ) . $main_key );

omnioffloader_check( false === $restorer->restore( $id4, false ), 'restore() reported failure' );
omnioffloader_check( (bool) get_post_meta( $id4, 'omnioffloader_offloaded', true ), 'offload meta kept (attachment still serves from cloud)' );

$log4 = get_post_meta( $id4, 'omnioffloader_error_log', true );
omnioffloader_check( is_array( $log4 ) && ! empty( $log4 ), 'failure logged to omnioffloader_error_log' );

// ---------------------------------------------------------------------------
// Test 5 — Preflight: counts offloaded items, checks disk space.
// ---------------------------------------------------------------------------
echo "\nTest 5: preflight\n";

Helpers::flush_media_stats();
$preflight = Helpers::get_restore_preflight();

omnioffloader_check( $preflight['offloaded'] >= 1, "counts offloaded attachments ({$preflight['offloaded']})" );
omnioffloader_check( $preflight['estimated_bytes'] > 0, 'estimates download size (' . $preflight['estimated_bytes'] . ' bytes)' );
omnioffloader_check( is_bool( $preflight['disk_ok'] ), 'disk_ok computed' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id1, true );
wp_delete_attachment( $id2, true );
wp_delete_attachment( $id2b, true );
wp_delete_attachment( $id3, true );
wp_delete_attachment( $id4, true );
update_option( 'omnioffloader_settings', $original_settings, true );
Helpers::flush_media_stats();

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
