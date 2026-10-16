<?php
/**
 * Integration test for `wp omnioffloader verify` (run via `wp eval-file`).
 *
 * Offloads with Retain Local Files to a mock bucket, then breaks it: a size
 * deleted from the bucket (cloud_missing), a cloud copy with another size
 * (cloud_differs), a local original deleted (local_missing), a file gone
 * from both places (lost) and a stuck "processing" status (stuck). Checks
 * that verify reports each, that --repair --dry-run changes nothing, and
 * that --repair fixes all but the lost file without deleting anything.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Core\Helpers;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\RetentionApplier;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-verify.php\n";
	exit( 1 );
}

/**
 * No-arg-constructible mock with the prefix listing verify relies on.
 */
class OmniOffloaderVerifyMockProvider extends MockProvider {
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

	/**
	 * Sizes of every object under a key prefix.
	 *
	 * @param string $prefix Key prefix.
	 * @return array<string, int>
	 */
	public function list_object_sizes( $prefix ) {
		clearstatcache();

		$base  = trailingslashit( self::$dir );
		$sizes = array();

		foreach ( (array) glob( $base . $prefix . '*' ) as $path ) {
			if ( is_file( (string) $path ) ) {
				$sizes[ substr( (string) $path, strlen( $base ) ) ] = (int) filesize( (string) $path );
			}
		}

		return $sizes;
	}
}

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

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	return $attachment_id;
}

/**
 * Run verify in-process and return its output and exit code.
 *
 * @param string $args Arguments after `omnioffloader verify`.
 * @return array{out: string, code: int}
 */
function omnioffloader_verify( $args ) {
	$result = WP_CLI::runcommand(
		'omnioffloader verify ' . $args,
		array(
			'launch'     => false,
			'return'     => 'all',
			'exit_error' => false,
		)
	);

	return array(
		'out'  => (string) $result->stdout . (string) $result->stderr,
		'code' => (int) $result->return_code,
	);
}

/**
 * Rows of a JSON verify run.
 *
 * @param string $args Arguments.
 * @return array<int, array<string, mixed>>
 */
function omnioffloader_verify_rows( $args ) {
	$run = omnioffloader_verify( $args . ' --format=json' );

	// A run without a TTY also logs "[time] Started verify…" lines.
	foreach ( explode( "\n", $run['out'] ) as $line ) {
		if ( 0 === strpos( $line, '[{' ) || '[]' === trim( $line ) ) {
			$rows = json_decode( $line, true );

			return is_array( $rows ) ? $rows : array();
		}
	}

	return array();
}

/**
 * Issue codes of a set of rows, sorted.
 *
 * @param array<int, array<string, mixed>> $rows Rows.
 * @return string[]
 */
function omnioffloader_issue_codes( array $rows ) {
	$codes = array_map( 'strval', wp_list_pluck( $rows, 'issue' ) );
	sort( $codes );

	return $codes;
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

$bucket_dir                           = get_temp_dir() . 'omnioffloader-mock-bucket-' . time();
OmniOffloaderVerifyMockProvider::$dir = $bucket_dir;

add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = OmniOffloaderVerifyMockProvider::class;

		return $providers;
	}
);

$provider  = new OmniOffloaderVerifyMockProvider();
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

$id_a = omnioffloader_make_attachment( 'omnioffloader-verify-a.jpg' );
$id_b = omnioffloader_make_attachment( 'omnioffloader-verify-b.jpg' );

foreach ( array( $id_a, $id_b ) as $id ) {
	if ( ! get_post_meta( $id, 'omnioffloader_offloaded', true ) ) {
		echo "ABORT: attachment {$id} was not offloaded during setup.\n";
		exit( 1 );
	}
}

$ids   = $id_a . ',' . $id_b;
$files = RetentionApplier::collect_attachment_files( $id_a );
$main  = $files['main'][0];
$size1 = $files['sizes'][0];
$size2 = $files['sizes'][1];
$lost  = RetentionApplier::collect_attachment_files( $id_b )['sizes'][0];

// ---------------------------------------------------------------------------
// Test 1 — Clean state.
// ---------------------------------------------------------------------------
echo "Test 1: a clean offload verifies\n";

$run = omnioffloader_verify( "--ids={$ids}" );
omnioffloader_check( 0 === $run['code'], 'exit code 0' );
omnioffloader_check( false !== strpos( $run['out'], 'Everything matches' ), 'reports everything matches' );

// ---------------------------------------------------------------------------
// Test 2 — Break it, then verify reports every issue.
// ---------------------------------------------------------------------------
echo "\nTest 2: issues are reported\n";

wp_delete_file( trailingslashit( $bucket_dir ) . $size1['key'] );                       // cloud_missing.
file_put_contents( trailingslashit( $bucket_dir ) . $size2['key'], 'different' );      // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture. cloud_differs.
wp_delete_file( $main['file'] );                                                       // local_missing.
wp_delete_file( $lost['file'] );                                                       // lost: both copies.
wp_delete_file( trailingslashit( $bucket_dir ) . $lost['key'] );
update_post_meta( $id_b, 'omnioffloader_status', 'processing' );                       // stuck.
delete_transient( 'omnioffloader_working_' . $id_b );                                  // This request's own upload marked it.

$rows = omnioffloader_verify_rows( "--ids={$ids}" );
omnioffloader_check(
	array( 'cloud_differs', 'cloud_missing', 'local_missing', 'lost', 'stuck' ) === omnioffloader_issue_codes( $rows ),
	'found cloud_missing, cloud_differs, local_missing, lost and stuck (' . implode( ', ', omnioffloader_issue_codes( $rows ) ) . ')'
);
omnioffloader_check( 1 === omnioffloader_verify( "--ids={$ids}" )['code'], 'exit code 1 while issues are left' );

// ---------------------------------------------------------------------------
// Test 3 — Dry run changes nothing.
// ---------------------------------------------------------------------------
echo "\nTest 3: --repair --dry-run\n";

$rows = omnioffloader_verify_rows( "--ids={$ids} --repair --dry-run" );
omnioffloader_check( in_array( 'would repair', wp_list_pluck( $rows, 'result' ), true ), 'lists repairs as "would repair"' );
clearstatcache();
omnioffloader_check( ! file_exists( trailingslashit( $bucket_dir ) . $size1['key'] ), 'nothing uploaded' );
omnioffloader_check( ! file_exists( $main['file'] ), 'nothing downloaded' );
omnioffloader_check( 'processing' === get_post_meta( $id_b, 'omnioffloader_status', true ), 'stuck status left alone' );

// ---------------------------------------------------------------------------
// Test 4 — Repair.
// ---------------------------------------------------------------------------
echo "\nTest 4: --repair\n";

$rows    = omnioffloader_verify_rows( "--ids={$ids} --repair" );
$results = array();

foreach ( $rows as $row ) {
	$results[ (string) $row['issue'] ] = (string) $row['result'];
}

clearstatcache();
omnioffloader_check( 'repaired' === ( $results['cloud_missing'] ?? '' ) && filesize( trailingslashit( $bucket_dir ) . $size1['key'] ) === filesize( $size1['file'] ), 'missing size uploaded again' );
omnioffloader_check( 'repaired' === ( $results['cloud_differs'] ?? '' ) && filesize( trailingslashit( $bucket_dir ) . $size2['key'] ) === filesize( $size2['file'] ), 'differing cloud copy replaced by the server copy' );
omnioffloader_check( 'repaired' === ( $results['local_missing'] ?? '' ) && file_exists( $main['file'] ), 'missing original downloaded again' );
omnioffloader_check( 'repaired' === ( $results['stuck'] ?? '' ) && '' === get_post_meta( $id_b, 'omnioffloader_status', true ), 'stuck status cleared' );
omnioffloader_check( 'not repairable' === ( $results['lost'] ?? '' ), 'lost file reported, not repaired' );
omnioffloader_check( (bool) get_post_meta( $id_a, 'omnioffloader_offloaded', true ) && (bool) get_post_meta( $id_b, 'omnioffloader_offloaded', true ), 'both attachments still offloaded' );
omnioffloader_check( empty( get_site_transient( OmniOffloader\Core\Plugin::cli_job_lock_key() ) ), 'job lock released' );

$rows = omnioffloader_verify_rows( "--ids={$ids}" );
omnioffloader_check( array( 'lost' ) === omnioffloader_issue_codes( $rows ), 'only the lost file is left' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id_a, true );
wp_delete_attachment( $id_b, true );
update_option( 'omnioffloader_settings', $original_settings, true );
Helpers::flush_media_stats();

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
