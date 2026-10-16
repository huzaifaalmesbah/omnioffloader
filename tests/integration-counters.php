<?php
/**
 * Integration test for the running media counters (run via `wp eval-file`).
 *
 * After every kind of change — offload, retention cleanup and download,
 * metadata edits, Bring Back, deleting an attachment — the counters must
 * give exactly what a full scan of the library gives, for both the Bring
 * Back and the retention preflight. Also: a drifted count is rebuilt.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\MediaCounters;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Services\RetentionApplier;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-counters.php\n";
	exit( 1 );
}

/**
 * No-arg-constructible mock with prefix listing (retention verifies sizes with it).
 */
class OmniOffloaderCountersMockProvider extends MockProvider {
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
	imagefilledrectangle( $image, 0, 0, 1399, 899, imagecolorallocate( $image, 90, 140, 60 ) );
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
 * The preflight numbers from a full scan of every offloaded attachment —
 * what the counters must equal.
 *
 * @param int $target Retention policy to estimate.
 * @return array<string, int>
 */
function omnioffloader_scan( $target ) {
	global $wpdb;

	$ids = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1' WHERE p.post_type = 'attachment'" ) );
	$out = array_fill_keys( array( 'offloaded', 'estimated_bytes', 'unsized', 'eligible', 'to_clean', 'to_download', 'freed_bytes', 'download_bytes' ), 0 );
	$new = RetentionApplier::local_roles( $target );

	foreach ( $ids as $id ) {
		wp_cache_delete( $id, 'post_meta' );
		$stored = RetentionApplier::stored_policy( $id );
		$old    = RetentionApplier::local_roles( $stored );
		$meta   = get_post_meta( $id, '_wp_attachment_metadata', true );
		$meta   = is_array( $meta ) ? $meta : array();
		$main   = isset( $meta['filesize'] ) && is_numeric( $meta['filesize'] ) ? (int) $meta['filesize'] : null;
		$sizes  = 0;
		$known  = true;

		foreach ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['filesize'] ) && is_numeric( $size['filesize'] ) ) {
				$sizes += (int) $size['filesize'];
			} elseif ( is_array( $size ) ) {
				$known = false;
			}
		}

		++$out['offloaded'];

		if ( ! $old['main'] ) {
			$out['estimated_bytes'] += (int) $main;
		}
		if ( ! $old['sizes'] ) {
			$out['estimated_bytes'] += $sizes;
		}
		if ( ( ! $old['main'] && null === $main ) || ( ! $old['sizes'] && ! $known ) ) {
			++$out['unsized'];
		}

		if ( $stored !== $target ) {
			++$out['eligible'];
			++$out[ $target > $stored ? 'to_clean' : 'to_download' ];

			foreach ( array(
				'main'  => (int) $main,
				'sizes' => $sizes,
			) as $role => $bytes ) {
				if ( $old[ $role ] && ! $new[ $role ] ) {
					$out['freed_bytes'] += $bytes;
				} elseif ( ! $old[ $role ] && $new[ $role ] ) {
					$out['download_bytes'] += $bytes;
				}
			}
		}
	}

	return $out;
}

/**
 * The same numbers from the counters.
 *
 * @param int $target Retention policy to estimate.
 * @return array<string, int>
 */
function omnioffloader_counted( $target ) {
	return array_merge( MediaCounters::restore_estimate(), MediaCounters::retention_estimate( $target ) );
}

/**
 * Total offloaded count straight from the counter rows, after applying the
 * pending changes but without letting get() rebuild anything.
 *
 * @return int
 */
function omnioffloader_raw_total() {
	global $wpdb;

	MediaCounters::flush();

	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM( CAST( option_value AS SIGNED ) ) FROM {$wpdb->options} WHERE option_name IN ( %s, %s, %s )", MediaCounters::ROW_PREFIX . '0_count', MediaCounters::ROW_PREFIX . '1_count', MediaCounters::ROW_PREFIX . '2_count' ) );
}

/**
 * Compare counters and scan for every target policy.
 *
 * @param string $label Step label.
 */
function omnioffloader_compare( $label ) {
	foreach ( array( 0, 1, 2 ) as $target ) {
		$scan    = omnioffloader_scan( $target );
		$counted = omnioffloader_counted( $target );
		ksort( $scan );
		ksort( $counted );

		if ( $scan !== $counted ) {
			echo '    scan:    ' . wp_json_encode( $scan ) . "\n";
			echo '    counted: ' . wp_json_encode( $counted ) . "\n";
		}

		omnioffloader_check( $scan === $counted, "{$label} — counters match a full scan (target policy {$target})" );
	}
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );

$bucket_dir                             = get_temp_dir() . 'omnioffloader-mock-bucket-' . time();
OmniOffloaderCountersMockProvider::$dir = $bucket_dir;

add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = OmniOffloaderCountersMockProvider::class;

		return $providers;
	}
);

$provider  = new OmniOffloaderCountersMockProvider();
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

echo "Test 1: building from scratch\n";
MediaCounters::delete_all();
omnioffloader_compare( 'first build' );
omnioffloader_check( MediaCounters::VERSION === (int) get_option( MediaCounters::VERSION_OPTION ), 'version recorded' );

echo "\nTest 2: offloading new uploads\n";
$before = omnioffloader_raw_total();
$id_a = omnioffloader_make_attachment( 'omnioffloader-counters-a.jpg' );
$id_b = omnioffloader_make_attachment( 'omnioffloader-counters-b.jpg' );
$id_c = omnioffloader_make_attachment( 'omnioffloader-counters-c.jpg' );
omnioffloader_check( (bool) get_post_meta( $id_a, 'omnioffloader_offloaded', true ), 'uploads offloaded' );
omnioffloader_check( $before + 3 === omnioffloader_raw_total(), 'the hooks added 3 to the stored count (no rebuild)' );
omnioffloader_compare( 'after offload' );

echo "\nTest 3: retention cleanup and download\n";
$applier = new RetentionApplier();
omnioffloader_check( $applier->apply( $id_a, 1 ), 'A → Smart Cleanup' );
omnioffloader_check( $applier->apply( $id_b, 2 ), 'B → Full Migration' );
omnioffloader_compare( 'after cleanup' );
omnioffloader_check( $applier->apply( $id_b, 0 ), 'B → back to Retain Local (download)' );
omnioffloader_compare( 'after download' );

echo "\nTest 4: metadata without sizes (older uploads)\n";
$meta = wp_get_attachment_metadata( $id_a );
unset( $meta['filesize'] );
foreach ( $meta['sizes'] as $name => $size ) {
	unset( $meta['sizes'][ $name ]['filesize'] );
	break;
}
wp_update_attachment_metadata( $id_a, $meta );
omnioffloader_compare( 'after metadata edit' );

echo "\nTest 5: Bring Back and delete\n";
$restorer = new AttachmentRestorer();
omnioffloader_check( $restorer->restore( $id_c, false ), 'C brought back' );
omnioffloader_compare( 'after Bring Back' );
$before = omnioffloader_raw_total();
wp_delete_attachment( $id_a, true );
omnioffloader_check( $before - 1 === omnioffloader_raw_total(), 'deleting A took 1 off the stored count (no rebuild)' );
omnioffloader_compare( 'after deleting A' );

echo "\nTest 6: the preflights use the counters\n";
$restore = Helpers::get_restore_preflight();
$scan    = omnioffloader_scan( 0 );
omnioffloader_check( $restore['offloaded'] === $scan['offloaded'] && $restore['estimated_bytes'] === $scan['estimated_bytes'], 'Bring Back preflight matches the scan' );
$retention = Helpers::get_retention_preflight();
$scan      = omnioffloader_scan( $retention['policy'] );
omnioffloader_check( $retention['eligible'] === $scan['eligible'] && $retention['freed_bytes'] === $scan['freed_bytes'], 'retention preflight matches the scan' );

echo "\nTest 7: drift is detected and rebuilt\n";
global $wpdb;
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = option_value + 5 WHERE option_name = %s", MediaCounters::ROW_PREFIX . '0_count' ) );
delete_transient( MediaCounters::CHECKED_TRANSIENT );
omnioffloader_compare( 'after a corrupted count' );

echo "\nCleanup…\n";
wp_delete_attachment( $id_b, true );
wp_delete_attachment( $id_c, true );
update_option( 'omnioffloader_settings', $original_settings, true );
Helpers::flush_media_stats();
omnioffloader_compare( 'after cleanup' );

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
