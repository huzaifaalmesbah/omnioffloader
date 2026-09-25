<?php
/**
 * Integration test: async REST bulk offload (run via `wp eval-file`).
 *
 * Starts a bulk run through the REST controller and polls the REST
 * progress endpoint — which both reports state and self-heals stalled
 * queues by processing short batches inline when the async loopback
 * dispatch cannot reach the site.
 *
 * @package OmniOffloader
 */

// Safe mode would treat a local test site (*.test, localhost) as a copy and
// refuse every upload; these tests exercise the real pipeline.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}


use OmniOffloader\Services\BulkOffloadProcessor;

wp_set_current_user( 1 );

// Start (or resume a queue that is already active).
$omnioffloader_request  = new WP_REST_Request( 'POST', '/omnioffloader/v1/offload/bulk' );
$omnioffloader_response = rest_do_request( $omnioffloader_request );

if ( $omnioffloader_response->is_error() ) {
	$omnioffloader_data = $omnioffloader_response->get_data();
	if ( isset( $omnioffloader_data['code'] ) && 'omnioffloader_bulk_running' === $omnioffloader_data['code'] ) {
		echo "resuming already-active queue\n";
	} else {
		echo 'START ERROR: ' . wp_json_encode( $omnioffloader_data ) . "\n";
		exit( 1 );
	}
} else {
	echo 'queued: ' . $omnioffloader_response->get_data()['queued'] . "\n";
}

// Poll the REST endpoint until the queue finishes.
$omnioffloader_deadline = time() + 120;

do {
	wp_cache_delete( BulkOffloadProcessor::STATE_OPTION, 'options' );

	$omnioffloader_poll  = new WP_REST_Request( 'GET', '/omnioffloader/v1/offload/bulk/progress' );
	$omnioffloader_state = rest_do_request( $omnioffloader_poll )->get_data();

	echo "poll: {$omnioffloader_state['status']} {$omnioffloader_state['processed']}/{$omnioffloader_state['total']} errors={$omnioffloader_state['errors']}\n";

	if ( 'running' !== $omnioffloader_state['status'] ) {
		break;
	}

	sleep( 1 );
} while ( time() < $omnioffloader_deadline );

if ( 'done' === $omnioffloader_state['status'] && 0 === (int) $omnioffloader_state['errors'] ) {
	echo "RESULT: PASS — {$omnioffloader_state['processed']}/{$omnioffloader_state['total']} processed\n";
	exit( 0 );
}

echo "RESULT: FAIL — status={$omnioffloader_state['status']} errors={$omnioffloader_state['errors']}\n";
exit( 1 );
