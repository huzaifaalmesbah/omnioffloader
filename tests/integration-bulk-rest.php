<?php
/**
 * Integration test: async REST bulk offload (run via `wp eval-file`).
 *
 * Starts a bulk run through the REST controller and polls the REST
 * progress endpoint — which both reports state and self-heals stalled
 * queues by processing short batches inline when the async loopback
 * dispatch cannot reach the site.
 *
 * @package SmartMediaOffloader
 */

use SmartMediaOffloader\Services\BulkOffloadProcessor;

wp_set_current_user( 1 );

// Start (or resume a queue that is already active).
$smowp_request  = new WP_REST_Request( 'POST', '/smowp/v1/offload/bulk' );
$smowp_response = rest_do_request( $smowp_request );

if ( $smowp_response->is_error() ) {
	$smowp_data = $smowp_response->get_data();
	if ( isset( $smowp_data['code'] ) && 'smowp_bulk_running' === $smowp_data['code'] ) {
		echo "resuming already-active queue\n";
	} else {
		echo 'START ERROR: ' . wp_json_encode( $smowp_data ) . "\n";
		exit( 1 );
	}
} else {
	echo 'queued: ' . $smowp_response->get_data()['queued'] . "\n";
}

// Poll the REST endpoint until the queue finishes.
$smowp_deadline = time() + 120;

do {
	wp_cache_delete( BulkOffloadProcessor::STATE_OPTION, 'options' );

	$smowp_poll  = new WP_REST_Request( 'GET', '/smowp/v1/offload/bulk/progress' );
	$smowp_state = rest_do_request( $smowp_poll )->get_data();

	echo "poll: {$smowp_state['status']} {$smowp_state['processed']}/{$smowp_state['total']} errors={$smowp_state['errors']}\n";

	if ( 'running' !== $smowp_state['status'] ) {
		break;
	}

	sleep( 1 );
} while ( time() < $smowp_deadline );

if ( 'done' === $smowp_state['status'] && 0 === (int) $smowp_state['errors'] ) {
	echo "RESULT: PASS — {$smowp_state['processed']}/{$smowp_state['total']} processed\n";
	exit( 0 );
}

echo "RESULT: FAIL — status={$smowp_state['status']} errors={$smowp_state['errors']}\n";
exit( 1 );
