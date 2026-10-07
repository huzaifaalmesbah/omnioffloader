<?php
/**
 * Integration test for AttachmentMover: media offloaded under an earlier
 * Path Prefix moves into the current folder, and is never left without a
 * working copy.
 *
 * Usage: wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-move.php
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

use OmniOffloader\Core\Helpers;
use OmniOffloader\Services\AttachmentMover;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-move.php\n";
	exit( 1 );
}

// Local test sites (*.test) are in Safe mode; this test exercises writes.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

/**
 * Mock provider resolvable through the registry, as Bring Back needs.
 */
class OmnioffloaderMoveMockProvider extends MockProvider {
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
}

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_move_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) {
		echo "  ✓ {$label}\n";
		++$passed;
	} else {
		echo "  ✗ FAIL: {$label}\n";
		++$failed;
	}
}

function omnioffloader_move_attachment( $name ) {
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

/**
 * Object keys of an attachment's own files.
 */
function omnioffloader_move_keys( $id ) {
	$files = AttachmentUploader::collect_attachment_files( $id );

	return wp_list_pluck( array_merge( $files['main'], $files['sizes'] ), 'key' );
}

echo "Testing AttachmentMover\n\n";

$bucket                              = get_temp_dir() . 'omnioffloader-move-bucket-' . time();
OmnioffloaderMoveMockProvider::$dir = $bucket;
add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = OmnioffloaderMoveMockProvider::class;

		return $providers;
	}
);

$provider = new OmnioffloaderMoveMockProvider();
$original = get_option( 'omnioffloader_settings', array() );
$prefix   = static function ( $folder ) use ( $original ) {
	update_option(
		'omnioffloader_settings',
		array_merge(
			$original,
			array(
				'path_prefix_active' => true,
				'path_prefix'        => $folder,
				'retention_policy'   => 2,
				'object_versioning'  => false,
			)
		),
		true
	);
};

// Offload three attachments under the old folder (Full Cloud Migration).
$prefix( 'move-old' );
$uploader = new AttachmentUploader( $provider );
$a        = omnioffloader_move_attachment( 'move-test-a.jpg' );
$b        = omnioffloader_move_attachment( 'move-test-b.jpg' );
$c        = omnioffloader_move_attachment( 'move-test-c.jpg' );
$uploader->upload( $a );
$uploader->upload( $b );
$uploader->upload( $c );
$old_a = omnioffloader_move_keys( $a );
$old_b = omnioffloader_move_keys( $b );
$old_c = omnioffloader_move_keys( $c );

$prefix( 'move-new' );
omnioffloader_move_check( 3 <= Helpers::count_offloaded_elsewhere( 'move-new/' ), 'three attachments outside the current folder' );

$mover = new AttachmentMover( $provider );

echo "\nTest 1: move, deleting the old copies\n";
omnioffloader_move_check( true === $mover->move( $a ), 'move() returned success' );
omnioffloader_move_check( 0 === strpos( (string) get_post_meta( $a, 'omnioffloader_path', true ), 'move-new/' ), 'stored path is in the new folder' );
omnioffloader_move_check( array() === array_filter( omnioffloader_move_keys( $a ), static fn( $key ) => ! $provider->object_exists( $key ) ), 'every file is in the new folder' );
omnioffloader_move_check( array() === array_filter( $old_a, array( $provider, 'object_exists' ) ), 'old copies deleted' );
omnioffloader_move_check( ! file_exists( get_attached_file( $a ) ), 'retention policy applied again (local file removed)' );

echo "\nTest 2: move, keeping the old copies\n";
omnioffloader_move_check( true === $mover->move( $b, false ), 'move() returned success' );
omnioffloader_move_check( 0 === strpos( (string) get_post_meta( $b, 'omnioffloader_path', true ), 'move-new/' ), 'stored path is in the new folder' );
omnioffloader_move_check( count( $old_b ) === count( array_filter( $old_b, array( $provider, 'object_exists' ) ) ), 'old copies kept' );

echo "\nTest 3: the new upload fails\n";
$skip = static fn( $should, $id ) => $id === $c ? false : $should;
add_filter( 'omnioffloader_should_offload_attachment', $skip, 10, 2 );
omnioffloader_move_check( false === $mover->move( $c ), 'move() reported failure' );
remove_filter( 'omnioffloader_should_offload_attachment', $skip, 10 );
omnioffloader_move_check( ! get_post_meta( $c, 'omnioffloader_offloaded', true ), 'attachment is local, not half-offloaded' );
omnioffloader_move_check( file_exists( get_attached_file( $c ) ), 'its file is on the server' );
omnioffloader_move_check( count( $old_c ) === count( array_filter( $old_c, array( $provider, 'object_exists' ) ) ), 'old copies not deleted' );

echo "\nTest 4: the admin queue (BulkMoveProcessor)\n";

// The queue shares its state with this site's real Move Media run: never
// touch it while any job runs, and put the previous state back afterwards.
$move_state = get_option( OmniOffloader\Services\BulkMoveProcessor::STATE_OPTION );
$move_busy  = is_array( $move_state ) && 'running' === ( $move_state['status'] ?? '' );

if ( $move_busy || '' !== (string) get_site_transient( OmniOffloader\Core\Plugin::cli_job_lock_key() ) ) {
	echo "  - skipped: a move or another job is running on this site\n";
} else {
	$prefix( 'move-old' );
	$d = omnioffloader_move_attachment( 'move-test-d.jpg' );
	$uploader->upload( $d );
	$prefix( 'move-new' );
	$queue  = new OmniOffloader\Services\BulkMoveProcessor( $provider );
	$queued = $queue->start( array( $d, $a ) );
	omnioffloader_move_check( 1 === $queued, 'queues only the attachment outside the folder' );
	omnioffloader_move_check( 'move-new/' === OmniOffloader\Services\BulkMoveProcessor::get_state()['folder'], 'target folder fixed in the state' );
	$item = new ReflectionMethod( $queue, 'process_item' );
	$item->setAccessible( true );
	omnioffloader_move_check( true === $item->invoke( $queue, $d ), 'process_item() moved it' );
	omnioffloader_move_check( 0 === strpos( (string) get_post_meta( $d, 'omnioffloader_path', true ), 'move-new/' ), 'stored path is in the new folder' );
	omnioffloader_move_check( true === $item->invoke( $queue, $d ), 'an attachment already moved counts as done' );
	$prefix( 'move-other' );
	omnioffloader_move_check( false === $item->invoke( $queue, $a ), 'a Path Prefix change mid-run stops the queue' );
	$queue->cancel_now();
	OmniOffloader\Services\AbstractBulkProcessor::purge_stored_data( 'bulk_move', OmniOffloader\Services\BulkMoveProcessor::STATE_OPTION );
	if ( false !== $move_state ) {
		update_option( OmniOffloader\Services\BulkMoveProcessor::STATE_OPTION, $move_state, false );
	}
	wp_delete_attachment( $d, true );
}

echo "\nCleanup…\n";
foreach ( array( $a, $b, $c ) as $id ) {
	wp_delete_attachment( $id, true );
}
update_option( 'omnioffloader_settings', $original, true );
Helpers::flush_media_stats();

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket, true );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
