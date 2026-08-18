<?php
/**
 * Seed local-only test attachments (run with the engine disabled).
 *
 * Usage:
 *   wp --exec="define( 'SMOWP_DISABLE_ENGINE', true );" eval-file tests/seed-attachments.php <count> <prefix>
 *
 * @package SmartMediaOffloader
 */

require_once ABSPATH . 'wp-admin/includes/image.php';

$smowp_count  = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 5;
$smowp_prefix = isset( $args[1] ) ? sanitize_file_name( $args[1] ) : 'smowp-seed';

$smowp_ids = array();

for ( $i = 1; $i <= $smowp_count; $i++ ) {
	$image = imagecreatetruecolor( 400, 300 );
	imagefilledrectangle( $image, 0, 0, 399, 299, imagecolorallocate( $image, ( 37 * $i ) % 255, 90, 150 ) );
	ob_start();
	imagejpeg( $image );
	$bits = ob_get_clean();
	imagedestroy( $image );

	$upload = wp_upload_bits( "{$smowp_prefix}-{$i}.jpg", null, $bits );

	if ( ! empty( $upload['error'] ) ) {
		echo "ERROR: {$upload['error']}\n";
		exit( 1 );
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => "{$smowp_prefix} {$i}",
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

	$smowp_ids[] = $attachment_id;
}

echo 'seeded: ' . implode( ',', $smowp_ids ) . "\n";
