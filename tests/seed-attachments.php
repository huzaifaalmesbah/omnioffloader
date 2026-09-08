<?php
/**
 * Seed local-only test attachments (run with the engine disabled).
 *
 * Usage:
 *   wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php <count> <prefix>
 *
 * @package OmniOffloader
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

$omnioffloader_count  = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 5;
$omnioffloader_prefix = isset( $args[1] ) ? sanitize_file_name( $args[1] ) : 'omnioffloader-seed';

$omnioffloader_ids = array();

for ( $i = 1; $i <= $omnioffloader_count; $i++ ) {
	$image = imagecreatetruecolor( 400, 300 );
	imagefilledrectangle( $image, 0, 0, 399, 299, imagecolorallocate( $image, ( 37 * $i ) % 255, 90, 150 ) );
	ob_start();
	imagejpeg( $image );
	$bits = ob_get_clean();
	imagedestroy( $image );

	$upload = wp_upload_bits( "{$omnioffloader_prefix}-{$i}.jpg", null, $bits );

	if ( ! empty( $upload['error'] ) ) {
		echo "ERROR: {$upload['error']}\n";
		exit( 1 );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => "{$omnioffloader_prefix} {$i}",
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	$omnioffloader_ids[] = $attachment_id;
}

echo 'seeded: ' . implode( ',', $omnioffloader_ids ) . "\n";
