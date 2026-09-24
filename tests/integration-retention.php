<?php
/**
 * Integration test for applying a retention policy to existing media
 * (run via `wp eval-file`).
 *
 * Offloads with Retain Local Files, then switches policy after the fact:
 * Smart Cleanup deletes only verified sizes, a size missing from the bucket
 * is uploaded before its local copy goes, a size-mismatched cloud copy keeps
 * its local file, Full Cloud Migration removes originals, and moving back to
 * Retain Local downloads everything again while the attachment stays
 * offloaded. Also covers eligibility and the preflight.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Core\Helpers;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\RetentionApplier;
use OmniOffloader\Tests\MockProvider;

// The real offload engine must be off for a hermetic run.
if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-retention.php\n";
	exit( 1 );
}

/**
 * No-arg-constructible mock so ProviderRegistry::create() can build it, plus
 * the optional prefix listing AbstractS3Provider offers.
 */
class SmowpRetentionMockProvider extends MockProvider {
	/**
	 * Bucket dir shared with the test.
	 *
	 * @var string
	 */
	public static $dir = '';

	/**
	 * Number of list_object_sizes() calls.
	 *
	 * @var int
	 */
	public static $list_calls = 0;

	/**
	 * Object keys whose upload should fail.
	 *
	 * @var string[]
	 */
	public static $fail_uploads = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( self::$dir );
	}

	/**
	 * Upload, unless the key is marked to fail.
	 *
	 * @param string $file Local file.
	 * @param string $key  Object key.
	 * @return string|false
	 */
	public function upload_file( $file, $key ) {
		if ( in_array( $key, self::$fail_uploads, true ) ) {
			return false;
		}

		return parent::upload_file( $file, $key );
	}

	/**
	 * Sizes of every object under a key prefix.
	 *
	 * @param string $prefix Key prefix.
	 * @return array<string, int>
	 */
	public function list_object_sizes( $prefix ) {
		++self::$list_calls;
		clearstatcache();

		$base  = trailingslashit( self::$dir );
		$sizes = array();

		foreach ( glob( $base . $prefix . '*' ) as $path ) {
			if ( is_file( $path ) ) {
				$sizes[ substr( $path, strlen( $base ) ) ] = (int) filesize( $path );
			}
		}

		return $sizes;
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
	imagefilledrectangle( $image, 0, 0, 1399, 899, imagecolorallocate( $image, 160, 80, 40 ) );
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
 * Whether every file of a role exists locally.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $role          'main' or 'sizes'.
 * @return array{all: bool, none: bool}
 */
function omnioffloader_local_state( $attachment_id, $role ) {
	clearstatcache();
	$files = RetentionApplier::collect_attachment_files( $attachment_id );
	$all   = true;
	$none  = true;

	foreach ( $files[ $role ] as $entry ) {
		if ( file_exists( $entry['file'] ) ) {
			$none = false;
		} else {
			$all = false;
		}
	}

	return array(
		'all'  => $all,
		'none' => $none,
	);
}

// ---------------------------------------------------------------------------
// Setup: registered mock provider + engine, Retain Local Files.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

$bucket_dir                      = get_temp_dir() . 'omnioffloader-mock-bucket-' . time();
SmowpRetentionMockProvider::$dir = $bucket_dir;

add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = SmowpRetentionMockProvider::class;

		return $providers;
	}
);

$provider  = new SmowpRetentionMockProvider();
$offloader = new Offloader( $provider );
$offloader->register_observers();

update_option(
	'omnioffloader_settings',
	array_merge(
		$original_settings,
		array(
			'retention_policy'   => 0,
			'mirror_delete'      => true,
			'object_versioning'  => false,
			'path_prefix_active' => false,
			'async_upload'       => false,
		)
	),
	true
);

echo "Mock bucket: {$bucket_dir}\n\n";

$id_a = omnioffloader_make_attachment( 'omnioffloader-retention-a.jpg' );
$id_b = omnioffloader_make_attachment( 'omnioffloader-retention-b.jpg' );
$id_c = omnioffloader_make_attachment( 'omnioffloader-retention-c.jpg' );
$id_d = omnioffloader_make_attachment( 'omnioffloader-retention-d.jpg' );

foreach ( array( $id_a, $id_b, $id_c, $id_d ) as $id ) {
	if ( ! get_post_meta( $id, 'omnioffloader_offloaded', true ) ) {
		echo "ABORT: attachment {$id} was not offloaded during setup.\n";
		exit( 1 );
	}
}

$applier = new RetentionApplier();

// ---------------------------------------------------------------------------
// Test 1 — Eligibility: everything offloaded under policy 0.
// ---------------------------------------------------------------------------
echo "Test 1: eligibility\n";

$eligible = RetentionApplier::eligible_ids( 1 );
omnioffloader_check( in_array( $id_a, $eligible, true ) && in_array( $id_c, $eligible, true ), 'policy-0 items are eligible for Smart Cleanup' );
omnioffloader_check( ! in_array( $id_a, RetentionApplier::eligible_ids( 0 ), true ), 'policy-0 items are not eligible for Retain Local' );

delete_post_meta( $id_c, 'omnioffloader_retention' );
omnioffloader_check( 0 === RetentionApplier::stored_policy( $id_c ), 'missing retention meta counts as policy 0' );
omnioffloader_check( in_array( $id_c, RetentionApplier::eligible_ids( 1 ), true ), 'item without retention meta is still eligible' );

// ---------------------------------------------------------------------------
// Test 2 — Smart Cleanup: sizes deleted after verification, originals kept.
// ---------------------------------------------------------------------------
echo "\nTest 2: 0 → 1 (Smart Cleanup)\n";

SmowpRetentionMockProvider::$list_calls = 0;

omnioffloader_check( true === $applier->apply( $id_a, 1 ), 'apply() succeeded' );
omnioffloader_check( omnioffloader_local_state( $id_a, 'sizes' )['none'], 'local sizes deleted' );
omnioffloader_check( omnioffloader_local_state( $id_a, 'main' )['all'], 'local original kept' );
omnioffloader_check( 1 === RetentionApplier::stored_policy( $id_a ), 'stored policy advanced to 1' );
omnioffloader_check( $applier->get_freed_bytes() > 0, 'freed bytes reported (' . $applier->get_freed_bytes() . ')' );
omnioffloader_check( SmowpRetentionMockProvider::$list_calls > 0, 'verified sizes with one prefix listing' );
omnioffloader_check( (bool) get_post_meta( $id_a, 'omnioffloader_offloaded', true ), 'attachment still offloaded' );

// ---------------------------------------------------------------------------
// Test 3 — Missing in bucket: uploaded first, then deleted locally.
// ---------------------------------------------------------------------------
echo "\nTest 3: size missing from the bucket is repaired before cleanup\n";

$files_b    = RetentionApplier::collect_attachment_files( $id_b );
$missing    = $files_b['sizes'][0];
$missing_at = trailingslashit( $bucket_dir ) . $missing['key'];
unlink( $missing_at );
clearstatcache();

omnioffloader_check( true === $applier->apply( $id_b, 1 ), 'apply() succeeded' );
omnioffloader_check( 1 === $applier->get_repaired(), 'one file re-uploaded' );
omnioffloader_check( file_exists( $missing_at ), 'missing object now in the bucket' );
omnioffloader_check( ! file_exists( $missing['file'] ), 'its local copy deleted after upload' );

// ---------------------------------------------------------------------------
// Test 4 — Size mismatch (e.g. an optimizer shrank the local file after the
// offload): the local file is re-uploaded, verified, then deleted.
// ---------------------------------------------------------------------------
echo "\nTest 4: cloud copy of a different size is replaced from the server\n";

$files_c    = RetentionApplier::collect_attachment_files( $id_c );
$stale      = $files_c['sizes'][0];
$stale_at   = trailingslashit( $bucket_dir ) . $stale['key'];
$local_size = filesize( $stale['file'] );
file_put_contents( $stale_at, 'stale' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test sabotage.
clearstatcache();

omnioffloader_check( true === $applier->apply( $id_c, 1 ), 'apply() succeeded' );
omnioffloader_check( $applier->get_repaired() >= 1, 'mismatched file re-uploaded' );
clearstatcache();
omnioffloader_check( filesize( $stale_at ) === $local_size, 'cloud copy now matches the server file' );
omnioffloader_check( ! file_exists( $stale['file'] ), 'local copy deleted after verification' );
omnioffloader_check( 1 === RetentionApplier::stored_policy( $id_c ), 'stored policy advanced to 1' );

// ---------------------------------------------------------------------------
// Test 4b — Mismatch and the re-upload fails: local file kept.
// ---------------------------------------------------------------------------
echo "\nTest 4b: re-upload failure keeps the local file\n";

$files_d = RetentionApplier::collect_attachment_files( $id_d );
$bad     = $files_d['sizes'][0];
file_put_contents( trailingslashit( $bucket_dir ) . $bad['key'], 'truncated' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test sabotage.
SmowpRetentionMockProvider::$fail_uploads = array( $bad['key'] );
clearstatcache();

omnioffloader_check( false === $applier->apply( $id_d, 1 ), 'apply() reported failure' );
omnioffloader_check( file_exists( $bad['file'] ), 'mismatched size kept locally' );
omnioffloader_check( 0 === RetentionApplier::stored_policy( $id_d ), 'stored policy unchanged (retried next run)' );

$log_d = get_post_meta( $id_d, 'omnioffloader_error_log', true );
omnioffloader_check( is_array( $log_d ) && ! empty( $log_d ), 'failure logged to omnioffloader_error_log' );

SmowpRetentionMockProvider::$fail_uploads = array();

// ---------------------------------------------------------------------------
// Test 4c — Filtered-out attachments are not reported as needing updates.
// ---------------------------------------------------------------------------
echo "\nTest 4c: omnioffloader_should_apply_retention excludes from eligibility\n";

$exclude_d = static function ( $apply, $id ) use ( $id_d ) {
	return $id === $id_d ? false : $apply;
};
add_filter( 'omnioffloader_should_apply_retention', $exclude_d, 10, 2 );
omnioffloader_check( ! in_array( $id_d, RetentionApplier::eligible_ids( 1 ), true ), 'filtered item not eligible' );
remove_filter( 'omnioffloader_should_apply_retention', $exclude_d, 10 );
omnioffloader_check( in_array( $id_d, RetentionApplier::eligible_ids( 1 ), true ), 'eligible again without the filter' );

// ---------------------------------------------------------------------------
// Test 5 — Full Cloud Migration: originals removed.
// ---------------------------------------------------------------------------
echo "\nTest 5: 1 → 2 (Full Cloud Migration)\n";

omnioffloader_check( true === $applier->apply( $id_a, 2 ), 'apply() succeeded' );
omnioffloader_check( omnioffloader_local_state( $id_a, 'main' )['none'], 'local original deleted' );
omnioffloader_check( 2 === RetentionApplier::stored_policy( $id_a ), 'stored policy advanced to 2' );

// ---------------------------------------------------------------------------
// Test 6 — Back to Retain Local: files downloaded, still offloaded.
// ---------------------------------------------------------------------------
echo "\nTest 6: 2 → 0 (Retain Local) downloads local copies again\n";

omnioffloader_check( true === $applier->apply( $id_a, 0 ), 'apply() succeeded' );
omnioffloader_check( omnioffloader_local_state( $id_a, 'main' )['all'], 'original back on disk' );
omnioffloader_check( omnioffloader_local_state( $id_a, 'sizes' )['all'], 'sizes back on disk' );
omnioffloader_check( 0 === RetentionApplier::stored_policy( $id_a ), 'stored policy back to 0' );
omnioffloader_check( (bool) get_post_meta( $id_a, 'omnioffloader_offloaded', true ), 'attachment still offloaded (served from cloud)' );

// ---------------------------------------------------------------------------
// Test 7 — Preflight follows the saved policy.
// ---------------------------------------------------------------------------
echo "\nTest 7: preflight\n";

update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'retention_policy' => 1 ) ), true );
$preflight = Helpers::get_retention_preflight( true );

omnioffloader_check( 1 === $preflight['policy'], 'reports the saved policy' );
omnioffloader_check( $preflight['to_clean'] >= 2, "counts items to clean ({$preflight['to_clean']})" );
omnioffloader_check( $preflight['freed_bytes'] > 0, 'estimates bytes to free (' . $preflight['freed_bytes'] . ')' );
omnioffloader_check( ! in_array( $id_b, RetentionApplier::eligible_ids( 1 ), true ), 'items already at the policy are skipped' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id_a, true );
wp_delete_attachment( $id_b, true );
wp_delete_attachment( $id_c, true );
wp_delete_attachment( $id_d, true );
update_option( 'omnioffloader_settings', $original_settings, true );
Helpers::flush_media_stats();

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
