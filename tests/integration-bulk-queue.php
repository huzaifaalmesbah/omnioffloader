<?php
/**
 * Integration test for bulk queue start rules that need no provider.
 *
 * Usage: wp --exec="define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );" eval-file tests/integration-bulk-queue.php
 *
 * @package OmniOffloader
 */

use OmniOffloader\Services\BulkRestoreProcessor;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-bulk-queue.php\n";
	exit( 1 );
}

global $passed, $failed;
$passed = 0;
$failed = 0;

function omnioffloader_queue_check( $ok, $label ) {
	global $passed, $failed;
	if ( $ok ) {
		echo "  ✓ {$label}\n";
		++$passed;
	} else {
		echo "  ✗ FAIL: {$label}\n";
		++$failed;
	}
}

echo "Testing bulk queue start rules\n\n";

$saved_state = get_option( BulkRestoreProcessor::STATE_OPTION );

// A local attachment: nothing for Bring Back to do.
$local_id = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'bulk-queue-test',
		'post_status'    => 'inherit',
	)
);

echo "Test 1: adding nothing to a running Bring Back leaves it running\n";

$running = array(
	'status'       => 'running',
	'total'        => 5,
	'processed'    => 2,
	'delete_cloud' => true,
	'updated_at'   => time(),
);
update_option( BulkRestoreProcessor::STATE_OPTION, $running, false );

$queue  = new BulkRestoreProcessor();
$queued = $queue->start( false, array( $local_id ) );
$state  = BulkRestoreProcessor::get_state();

omnioffloader_queue_check( 0 === $queued, 'nothing queued' );
omnioffloader_queue_check( 'running' === $state['status'], 'run still running' );
omnioffloader_queue_check( 5 === (int) $state['total'] && 2 === (int) $state['processed'], 'counts untouched' );
omnioffloader_queue_check( true === (bool) $state['delete_cloud'], "run's delete-from-cloud choice kept" );

echo "\nTest 2: nothing to do with no run going reports idle\n";

update_option( BulkRestoreProcessor::STATE_OPTION, array( 'status' => 'done' ), false );
$queued = $queue->start( false, array( $local_id ) );
omnioffloader_queue_check( 0 === $queued && 'idle' === BulkRestoreProcessor::get_state()['status'], 'state set to idle' );

echo "\nCleanup…\n";
wp_delete_post( $local_id, true );

if ( false === $saved_state ) {
	delete_option( BulkRestoreProcessor::STATE_OPTION );
} else {
	update_option( BulkRestoreProcessor::STATE_OPTION, $saved_state, false );
}

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
