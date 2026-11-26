<?php
/**
 * Integration test for uploads from WordPress's client-side media pipeline
 * (7.1 block editor, 7.2 Media Library): create → sideload → finalize.
 *
 * Replays the server side of core's REST flow (see
 * WP_REST_Attachments_Controller::create_item(), sideload_item() and
 * finalize_item()) against a filesystem mock provider:
 *
 * 1. create: rest_insert_attachment with generate_sub_sizes false, then
 *    wp_generate_attachment_metadata ('create') with no sub-sizes;
 * 2. sideload: the browser's files land in the folder, a "scaled" one
 *    replaces the main file;
 * 3. finalize: wp_generate_attachment_metadata ('update') with every size.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Offload\Observers\UploadObserver;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Tests\MockProvider;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-client-side-media.php\n";
	exit( 1 );
}

global $csm_pass, $csm_fail;
$csm_pass = 0;
$csm_fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $ok    Condition.
 * @param string $label Test label.
 */
function omnioffloader_csm_check( $ok, $label ) {
	global $csm_pass, $csm_fail;
	if ( $ok ) {
		++$csm_pass;
		echo "  ✓ {$label}\n";
	} else {
		++$csm_fail;
		echo "  ✗ FAIL: {$label}\n";
	}
}

/**
 * JPEG bytes of a solid image.
 *
 * @param int $w Width.
 * @param int $h Height.
 * @return string
 */
function omnioffloader_csm_jpeg( $w, $h ) {
	$image = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $image, 0, 0, $w - 1, $h - 1, imagecolorallocate( $image, 30, 90, 160 ) );
	ob_start();
	imagejpeg( $image, null, 80 );
	$bits = (string) ob_get_clean();
	imagedestroy( $image );

	return $bits;
}

/**
 * Step 1 of the pipeline: what POST /wp/v2/media does on the server.
 *
 * @param string $name File name.
 * @return int Attachment ID.
 */
function omnioffloader_csm_create( $name ) {
	$upload = wp_upload_bits( $name, null, omnioffloader_csm_jpeg( 3000, 2000 ) );
	if ( ! empty( $upload['error'] ) ) {
		echo "Cannot create upload: {$upload['error']}\n";
		exit( 1 );
	}

	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
	$request->set_param( 'generate_sub_sizes', false );
	do_action( 'rest_insert_attachment', get_post( $id ), $request, true );

	// The filters core adds for a client-side create.
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
	add_filter( 'fallback_intermediate_image_sizes', '__return_empty_array', 100 );
	add_filter( 'big_image_size_threshold', '__return_false', 100 );

	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

	remove_filter( 'intermediate_image_sizes_advanced', '__return_empty_array', 100 );
	remove_filter( 'fallback_intermediate_image_sizes', '__return_empty_array', 100 );
	remove_filter( 'big_image_size_threshold', '__return_false', 100 );

	return $id;
}

/**
 * Steps 2 and 3: sideload the browser's files, then finalize.
 *
 * @param int                  $id    Attachment ID.
 * @param array<string, int[]> $sizes Size name => [width, height].
 * @param array<string, mixed> $extra Extra metadata keys (companions).
 * @return array<string, mixed> Final metadata.
 */
function omnioffloader_csm_sideload_and_finalize( $id, array $sizes, array $extra = array() ) {
	$main = get_attached_file( $id );
	$dir  = trailingslashit( dirname( $main ) );
	$stem = pathinfo( $main, PATHINFO_FILENAME );

	// "scaled" sideload: replaces the main file, the upload becomes original_image.
	$scaled = $dir . $stem . '-scaled.jpg';
	file_put_contents( $scaled, omnioffloader_csm_jpeg( 2560, 1707 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	update_attached_file( $id, $scaled );

	$metadata                   = wp_get_attachment_metadata( $id );
	$metadata['original_image'] = wp_basename( $main );
	$metadata['file']           = _wp_relative_upload_path( $scaled );
	$metadata['width']          = 2560;
	$metadata['height']         = 1707;
	$metadata['sizes']          = array();

	foreach ( $sizes as $size => $dims ) {
		$file = $stem . '-' . $dims[0] . 'x' . $dims[1] . '.jpg';
		file_put_contents( $dir . $file, omnioffloader_csm_jpeg( $dims[0], $dims[1] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$metadata['sizes'][ $size ] = array(
			'file'      => $file,
			'width'     => $dims[0],
			'height'    => $dims[1],
			'mime-type' => 'image/jpeg',
		);
	}

	foreach ( $extra as $key => $file ) {
		file_put_contents( $dir . $file, 'companion' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$metadata[ $key ] = $file;
	}

	// Finalize: the 'update' pass with the complete sizes.
	$metadata = apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' );
	wp_update_attachment_metadata( $id, $metadata );

	return $metadata;
}

/**
 * Object keys of every file the attachment has.
 *
 * @param int $id Attachment ID.
 * @return string[]
 */
function omnioffloader_csm_keys( $id ) {
	$files = AttachmentHelper::collect_attachment_files( $id );

	return array_column( array_merge( $files['main'], $files['sizes'] ), 'key' );
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
$original_settings = get_option( 'omnioffloader_settings', array() );
$bucket_dir        = get_temp_dir() . 'omnioffloader-csm-mock-bucket-' . time();
$provider          = new MockProvider( $bucket_dir );
$async             = new AsyncUploadProcessor( $provider );
$offloader         = new Offloader( $provider, $async );
$offloader->register_observers();

$base_settings = array_merge(
	$original_settings,
	array(
		'retention_policy'   => 0,
		'async_upload'       => false,
		'mirror_delete'      => true,
		'object_versioning'  => false,
		'path_prefix_active' => false,
	)
);
update_option( 'omnioffloader_settings', $base_settings, true );

$created = array();

// ---------------------------------------------------------------------------
echo "Test 1: the create pass does not offload a client-side upload\n";

$id = omnioffloader_csm_create( 'omnioffloader-csm-a.jpg' );
$created[] = $id;

omnioffloader_csm_check( ! get_post_meta( $id, 'omnioffloader_offloaded', true ), 'not offloaded before its sub-sizes exist' );
omnioffloader_csm_check( false !== wp_next_scheduled( UploadObserver::FINALIZE_FALLBACK_HOOK, array( $id ) ), 'a fallback is scheduled in case finalize never comes' );

// ---------------------------------------------------------------------------
echo "\nTest 2: sideloaded sub-sizes keep their names\n";

$dir  = trailingslashit( dirname( get_attached_file( $id ) ) );
$stem = pathinfo( get_attached_file( $id ), PATHINFO_FILENAME );

// Core's sideload adds this filter (WP_REST_Attachments_Controller::
// filter_wp_unique_filename()) to drop the "-1" wp_unique_filename()
// always adds to sub-size-like names. Ours runs first and must leave the
// name alone, or core can't recognise it.
$core_sideload_filter = static function ( $filename, $ext, $fdir, $callback, $alt, $number ) use ( $stem ) {
	unset( $ext, $callback, $alt );
	if ( is_int( $number ) && preg_match( '/(.*)-(\d+x\d+|scaled)-' . $number . '$/', pathinfo( $filename, PATHINFO_FILENAME ), $m )
		&& $m[1] === $stem && ! file_exists( "$fdir/{$m[1]}-{$m[2]}." . pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
		return $m[1] . '-' . $m[2] . '.' . pathinfo( $filename, PATHINFO_EXTENSION );
	}
	return $filename;
};
add_filter( 'wp_unique_filename', $core_sideload_filter, 10, 6 );
omnioffloader_csm_check( $stem . '-150x150.jpg' === wp_unique_filename( $dir, $stem . '-150x150.jpg' ), 'a sideloaded sub-size keeps its name' );
omnioffloader_csm_check( $stem . '-scaled.jpg' === wp_unique_filename( $dir, $stem . '-scaled.jpg' ), 'and so does the scaled main file' );
remove_filter( 'wp_unique_filename', $core_sideload_filter, 10 );

// ---------------------------------------------------------------------------
echo "\nTest 3: finalize offloads every file, the scaled main file included\n";

omnioffloader_csm_sideload_and_finalize(
	$id,
	array(
		'thumbnail' => array( 150, 150 ),
		'medium'    => array( 300, 200 ),
		'large'     => array( 1024, 683 ),
	)
);

omnioffloader_csm_check( (bool) get_post_meta( $id, 'omnioffloader_offloaded', true ), 'offloaded after finalize' );
omnioffloader_csm_check( false === wp_next_scheduled( UploadObserver::FINALIZE_FALLBACK_HOOK, array( $id ) ), 'the fallback is cancelled' );

$keys    = omnioffloader_csm_keys( $id );
$missing = array_values( array_filter( $keys, static fn( $k ) => ! $provider->object_exists( $k ) ) );
omnioffloader_csm_check( 5 === count( $keys ), 'five files: scaled main, original and three sizes' );
omnioffloader_csm_check( array() === $missing, 'every file is in the bucket' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ) );
omnioffloader_csm_check( str_ends_with( $keys[0], '-scaled.jpg' ), 'the main object is the scaled file' );

// ---------------------------------------------------------------------------
echo "\nTest 4: a later 'update' pass uploads the files the bucket is missing\n";

$metadata                   = wp_get_attachment_metadata( $id );
$late                       = $stem . '-768x512.jpg';
$metadata['sizes']['medium_large'] = array(
	'file'      => $late,
	'width'     => 768,
	'height'    => 512,
	'mime-type' => 'image/jpeg',
);
file_put_contents( $dir . $late, omnioffloader_csm_jpeg( 768, 512 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
$metadata = apply_filters( 'wp_generate_attachment_metadata', $metadata, $id, 'update' );
wp_update_attachment_metadata( $id, $metadata );

$late_key = AttachmentHelper::get_offload_path( $id ) . $late;
omnioffloader_csm_check( $provider->object_exists( $late_key ), 'the late size was uploaded' );

// ---------------------------------------------------------------------------
echo "\nTest 5: a missing finalize is covered by the fallback\n";

$id_b      = omnioffloader_csm_create( 'omnioffloader-csm-b.jpg' );
$created[] = $id_b;
do_action( UploadObserver::FINALIZE_FALLBACK_HOOK, $id_b );
omnioffloader_csm_check( (bool) get_post_meta( $id_b, 'omnioffloader_offloaded', true ), 'offloaded by the fallback' );
omnioffloader_csm_check( $provider->object_exists( omnioffloader_csm_keys( $id_b )[0] ), 'its main file is in the bucket' );
wp_clear_scheduled_hook( UploadObserver::FINALIZE_FALLBACK_HOOK, array( $id_b ) );

// ---------------------------------------------------------------------------
echo "\nTest 6: GIF and HEIC companion files are part of the attachment\n";

$id_c      = omnioffloader_csm_create( 'omnioffloader-csm-c.jpg' );
$created[] = $id_c;
$stem_c    = pathinfo( get_attached_file( $id_c ), PATHINFO_FILENAME );
omnioffloader_csm_sideload_and_finalize(
	$id_c,
	array( 'thumbnail' => array( 150, 150 ) ),
	array(
		'animated_video'        => $stem_c . '.mp4',
		'animated_video_poster' => $stem_c . '-poster.jpg',
		'source_image'          => $stem_c . '.heic',
	)
);

$keys_c = omnioffloader_csm_keys( $id_c );
foreach ( array( '.mp4', '-poster.jpg', '.heic' ) as $suffix ) {
	$key = AttachmentHelper::get_offload_path( $id_c ) . $stem_c . $suffix;
	omnioffloader_csm_check( in_array( $key, $keys_c, true ) && $provider->object_exists( $key ), "companion {$stem_c}{$suffix} is collected and uploaded" );
}
omnioffloader_csm_check( in_array( $stem_c . '.mp4', AttachmentHelper::main_filenames( $id_c ), true ), 'companions stay with the main file under Smart Cleanup' );

// ---------------------------------------------------------------------------
echo "\nTest 7: Full migration keeps every file until finalize, then cleans up\n";

update_option( 'omnioffloader_settings', array_merge( $base_settings, array( 'retention_policy' => 2 ) ), true );
$id_d      = omnioffloader_csm_create( 'omnioffloader-csm-d.jpg' );
$created[] = $id_d;
$upload_d  = get_attached_file( $id_d );
omnioffloader_csm_check( file_exists( $upload_d ), 'the upload stays on the server after the create pass' );

omnioffloader_csm_sideload_and_finalize( $id_d, array( 'thumbnail' => array( 150, 150 ) ) );
$files_d = AttachmentHelper::collect_attachment_files( $id_d );
$left    = array_filter( array_merge( $files_d['main'], $files_d['sizes'] ), static fn( $e ) => file_exists( $e['file'] ) );
omnioffloader_csm_check( (bool) get_post_meta( $id_d, 'omnioffloader_offloaded', true ), 'offloaded after finalize' );
omnioffloader_csm_check( array() === $left, 'all local files removed only after every file was uploaded' );
update_option( 'omnioffloader_settings', $base_settings, true );

// ---------------------------------------------------------------------------
echo "\nTest 8: background upload waits for finalize too\n";

update_option( 'omnioffloader_settings', array_merge( $base_settings, array( 'async_upload' => true ) ), true );
add_filter( 'omnioffloader_use_async_upload', '__return_true' );
AsyncUploadProcessor::purge_stored_data();

$is_queue_empty = new ReflectionMethod( $async, 'is_queue_empty' );
$is_queue_empty->setAccessible( true );

$id_e      = omnioffloader_csm_create( 'omnioffloader-csm-e.jpg' );
$created[] = $id_e;
omnioffloader_csm_check( 'queued' === get_post_meta( $id_e, 'omnioffloader_status', true ), 'shown as queued while the browser sends sizes' );
omnioffloader_csm_check( $is_queue_empty->invoke( $async ), 'but not in the upload queue yet' );

omnioffloader_csm_sideload_and_finalize( $id_e, array( 'thumbnail' => array( 150, 150 ) ) );
omnioffloader_csm_check( ! $is_queue_empty->invoke( $async ), 'queued by finalize' );

remove_filter( 'omnioffloader_use_async_upload', '__return_true' );
remove_all_actions( 'shutdown' );
AsyncUploadProcessor::purge_stored_data();
update_option( 'omnioffloader_settings', $base_settings, true );

// ---------------------------------------------------------------------------
echo "\nTest 9: classic uploads still offload on the create pass\n";

$upload = wp_upload_bits( 'omnioffloader-csm-classic.jpg', null, omnioffloader_csm_jpeg( 1200, 800 ) );
$id_f   = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'classic',
		'post_status'    => 'inherit',
	),
	$upload['file']
);
$created[] = $id_f;
wp_update_attachment_metadata( $id_f, wp_generate_attachment_metadata( $id_f, $upload['file'] ) );
omnioffloader_csm_check( (bool) get_post_meta( $id_f, 'omnioffloader_offloaded', true ), 'offloaded right away' );
omnioffloader_csm_check( false === wp_next_scheduled( UploadObserver::FINALIZE_FALLBACK_HOOK, array( $id_f ) ), 'no fallback scheduled' );

// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
foreach ( $created as $cid ) {
	wp_clear_scheduled_hook( UploadObserver::FINALIZE_FALLBACK_HOOK, array( $cid ) );
	wp_delete_attachment( $cid, true );
}
update_option( 'omnioffloader_settings', $original_settings, true );

echo "\nRESULT: {$csm_pass} passed, {$csm_fail} failed\n";
if ( $csm_fail > 0 ) {
	exit( 1 );
}
