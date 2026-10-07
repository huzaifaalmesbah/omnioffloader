<?php
/**
 * Integration test: the Media Library status route clears a "queued" /
 * "processing" status that no job will ever resolve.
 *
 * Needs a configured provider (the route only exists with the engine on);
 * touches no bucket.
 *
 * Usage: wp --user=<admin> eval-file tests/integration-stale-status.php
 *
 * @package OmniOffloader
 */

use OmniOffloader\Core\Plugin;

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_stale_check( $ok, $label ) {
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
 * A local attachment with a status, created $age seconds ago.
 */
function omnioffloader_stale_attachment( $status, $age ) {
	$date = gmdate( 'Y-m-d H:i:s', time() - $age );
	$id   = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => 'stale-status-test',
			'post_status'    => 'inherit',
			'post_date'      => get_date_from_gmt( $date ),
			'post_date_gmt'  => $date,
		)
	);
	update_post_meta( $id, 'omnioffloader_status', $status );

	return $id;
}

function omnioffloader_poll( array $ids ) {
	$request = new WP_REST_Request( 'GET', '/omnioffloader/v1/offload/attachment-status' );
	$request->set_param( 'ids', implode( ',', $ids ) );

	return rest_do_request( $request )->get_data();
}

if ( ! current_user_can( 'manage_options' ) || null === Plugin::get_instance()->get_async_upload() ) {
	echo "ABORT: run as an administrator on a site with a configured provider, e.g. wp --user=admin eval-file tests/integration-stale-status.php\n";
	exit( 1 );
}

echo "Testing stale status clean-up\n\n";

$old_queued     = omnioffloader_stale_attachment( 'queued', HOUR_IN_SECONDS );
$old_processing = omnioffloader_stale_attachment( 'processing', HOUR_IN_SECONDS );
$fresh_queued   = omnioffloader_stale_attachment( 'queued', 30 );

echo "Test 1: nothing running\n";
$data = omnioffloader_poll( array( $old_queued, $old_processing, $fresh_queued ) );
omnioffloader_stale_check( 'local' === $data['statuses']->{$old_queued}['status'], 'old "queued" item shows Local' );
omnioffloader_stale_check( '' === get_post_meta( $old_queued, 'omnioffloader_status', true ), 'its status meta is gone' );
omnioffloader_stale_check( 'local' === $data['statuses']->{$old_processing}['status'], 'old "processing" item shows Local' );
omnioffloader_stale_check( 'queued' === $data['statuses']->{$fresh_queued}['status'], 'upload from 30 s ago keeps "queued"' );

echo "\nTest 2: a WP-CLI job is running\n";
update_post_meta( $old_queued, 'omnioffloader_status', 'queued' );
set_site_transient( Plugin::cli_job_lock_key(), 'offload', MINUTE_IN_SECONDS );
$data = omnioffloader_poll( array( $old_queued ) );
delete_site_transient( Plugin::cli_job_lock_key() );
omnioffloader_stale_check( 'queued' === $data['statuses']->{$old_queued}['status'], 'status kept while a job runs' );

echo "\nCleanup…\n";
foreach ( array( $old_queued, $old_processing, $fresh_queued ) as $id ) {
	wp_delete_post( $id, true );
}

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
