<?php
/**
 * Integration test for UniqueFilenameObserver: a new upload never reuses a
 * name another attachment still has in the bucket, even when no local file
 * is left (Full Cloud Migration).
 *
 * Usage: wp eval-file tests/integration-unique-filename.php
 *
 * @package OmniOffloader
 */

use OmniOffloader\Offload\Observers\UniqueFilenameObserver;

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_unique_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) {
		echo "  ✓ {$label}\n";
		++$passed;
	} else {
		echo "  ✗ FAIL: {$label}\n";
		++$failed;
	}
}

/**
 * An attachment whose files exist only in the bucket: meta, no local file.
 */
function omnioffloader_cloud_only_attachment( $attached_file, array $metadata ) {
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'unique-filename-test',
			'post_status'    => 'inherit',
		)
	);
	update_post_meta( $id, '_wp_attached_file', $attached_file );
	update_post_meta( $id, '_wp_attachment_metadata', $metadata );

	return $id;
}

echo "Testing UniqueFilenameObserver\n\n";

$folder   = 'oo-unique-test-' . time();
$dir      = trailingslashit( wp_get_upload_dir()['basedir'] ) . $folder;
$observer = new UniqueFilenameObserver();
$ids      = array();

// A large photo: core stored the "-scaled" copy as the attached file and
// kept the original and the sizes in metadata only.
$ids[] = omnioffloader_cloud_only_attachment(
	"{$folder}/photo-scaled.jpg",
	array(
		'file'           => "{$folder}/photo-scaled.jpg",
		'original_image' => 'photo.jpg',
		'sizes'          => array(
			'thumbnail' => array( 'file' => 'photo-150x150.jpg' ),
		),
	)
);

// A PDF: its preview image is only in metadata.
$ids[] = omnioffloader_cloud_only_attachment(
	"{$folder}/doc.pdf",
	array(
		'sizes' => array(
			'full' => array( 'file' => 'doc-pdf.jpg' ),
		),
	)
);

// An edited image: the pre-edit original is only in the backup sizes.
$edited = omnioffloader_cloud_only_attachment(
	"{$folder}/edit-e1700000000.jpg",
	array( 'file' => "{$folder}/edit-e1700000000.jpg" )
);
update_post_meta( $edited, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'edit.jpg' ) ) );
$ids[] = $edited;

echo "Test 1: names only in metadata are taken\n";
omnioffloader_unique_check( 'photo-1.jpg' === $observer->ensure_unique( 'photo.jpg', '.jpg', $dir, null ), 'original of a scaled image (photo.jpg → photo-1.jpg)' );
omnioffloader_unique_check( 'photo-1.png' === $observer->ensure_unique( 'photo.png', '.png', $dir, null ), 'same name in another format (photo.png → photo-1.png)' );
omnioffloader_unique_check( 'doc-pdf-1.jpg' === $observer->ensure_unique( 'doc-pdf.jpg', '.jpg', $dir, null ), 'PDF preview (doc-pdf.jpg → doc-pdf-1.jpg)' );
omnioffloader_unique_check( 'edit-1.jpg' === $observer->ensure_unique( 'edit.jpg', '.jpg', $dir, null ), 'edit backup (edit.jpg → edit-1.jpg)' );

echo "\nTest 2: free names are kept\n";
omnioffloader_unique_check( 'other.jpg' === $observer->ensure_unique( 'other.jpg', '.jpg', $dir, null ), 'unrelated name unchanged' );
omnioffloader_unique_check( 'photo.jpg' === $observer->ensure_unique( 'photo.jpg', '.jpg', $dir . '/sub', null ), 'same name in a subfolder unchanged' );
omnioffloader_unique_check( 'photo.jpg' === $observer->ensure_unique( 'photo.jpg', '.jpg', get_temp_dir(), null ), 'folder outside uploads unchanged' );

echo "\nCleanup…\n";
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
