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

$queue = new BulkRestoreProcessor();

// A worker is busy with the run (its process lock is held).
$identifier_prop = new ReflectionProperty( $queue, 'identifier' );
$identifier = $identifier_prop->getValue( $queue );
set_site_transient( $identifier . '_process_lock', microtime(), MINUTE_IN_SECONDS );

$queued = $queue->start( false, array( $local_id ) );
delete_site_transient( $identifier . '_process_lock' );
$state  = BulkRestoreProcessor::get_state();

omnioffloader_queue_check( 0 === $queued, 'nothing queued' );
omnioffloader_queue_check( 'running' === $state['status'], 'run still running' );
omnioffloader_queue_check( 5 === (int) $state['total'] && 2 === (int) $state['processed'], 'counts untouched' );
omnioffloader_queue_check( true === (bool) $state['delete_cloud'], "run's delete-from-cloud choice kept" );

echo "\nTest 2: nothing to do with no run going reports idle\n";

update_option( BulkRestoreProcessor::STATE_OPTION, array( 'status' => 'done' ), false );
$queued = $queue->start( false, array( $local_id ) );
omnioffloader_queue_check( 0 === $queued && 'idle' === BulkRestoreProcessor::get_state()['status'], 'state set to idle' );

echo "\nTest 3: a worker whose run was cancelled and restarted\n";

// Private members of the shared base class.
$base           = OmniOffloader\Services\AbstractBulkProcessor::class;
$run_stopped    = new ReflectionMethod( $base, 'run_stopped' );
$record_results = new ReflectionMethod( $base, 'record_results' );
$pass_run       = new ReflectionProperty( $base, 'pass_run' );
$superseded     = new ReflectionProperty( $base, 'superseded' );
foreach ( array( $run_stopped, $record_results, $pass_run, $superseded ) as $reflected ) {
}

// The worker's pass began in run A.
update_option( BulkRestoreProcessor::STATE_OPTION, array( 'status' => 'running', 'run' => 'run-a', 'total' => 5, 'processed' => 1 ), false );
$pass_run->setValue( $queue, 'run-a' );
$superseded->setValue( $queue, false );
omnioffloader_queue_check( false === $run_stopped->invoke( $queue ), 'its own run: carries on' );

// Cancelled and started again: run B owns the queue now.
update_option( BulkRestoreProcessor::STATE_OPTION, array( 'status' => 'running', 'run' => 'run-b', 'total' => 3, 'processed' => 0 ), false );
omnioffloader_queue_check( true === $run_stopped->invoke( $queue ), 'replaced run: stops' );
omnioffloader_queue_check( true === $superseded->getValue( $queue ), 'knows it was superseded (so it does not purge the new queue)' );
$record_results->invoke( $queue, array( $local_id => true ) );
$state = BulkRestoreProcessor::get_state();
omnioffloader_queue_check( 0 === (int) $state['processed'] && 'run-b' === $state['run'], "its result is not counted in the new run" );

// A plain cancel still stops it.
update_option( BulkRestoreProcessor::STATE_OPTION, array( 'status' => 'cancelled', 'run' => 'run-a' ), false );
$superseded->setValue( $queue, false );
omnioffloader_queue_check( true === $run_stopped->invoke( $queue ) && false === $superseded->getValue( $queue ), 'a cancel stops it as before' );

echo "\nCleanup…\n";
wp_delete_post( $local_id, true );

if ( false === $saved_state ) {
	delete_option( BulkRestoreProcessor::STATE_OPTION );
} else {
	update_option( BulkRestoreProcessor::STATE_OPTION, $saved_state, false );
}

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
