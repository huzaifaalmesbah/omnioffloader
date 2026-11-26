<?php
/**
 * Integration test for the background queue loopback: request
 * authentication, no forwarded cookies, the optional local URL, the cron
 * healthcheck never exiting, and the async queue's stall check.
 *
 * Makes no HTTP requests: dispatch() is short-circuited with pre_http_request.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-loopback.php\n";
	exit( 1 );
}

global $passed, $failed;
$passed = 0;
$failed = 0;

/**
 * Record one check.
 *
 * @param bool   $ok    Result.
 * @param string $label Description.
 */
function omnioffloader_loopback_check( $ok, $label ) {
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
 * Async queue whose items are only recorded, never uploaded.
 */
class OmniOffloader_Loopback_Test_Queue extends AsyncUploadProcessor {

	/**
	 * Items task() received.
	 *
	 * @var array<int, mixed>
	 */
	public $ran = array();

	/**
	 * Record the item instead of uploading it.
	 *
	 * @param mixed $item Queue item.
	 * @return false
	 */
	protected function task( $item ) {
		$this->ran[] = $item;

		return false;
	}

	/**
	 * Public wrapper for a protected method.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return mixed
	 */
	public function call( $method, array $args = array() ) {
		return $this->$method( ...$args );
	}
}

/**
 * Exception thrown in place of wp_die().
 */
class OmniOffloader_Loopback_Died extends Exception {
}

// wp_die() → exception carrying the response code.
add_filter(
	'wp_die_handler',
	static function () {
		return static function ( $message, $title = '', $args = array() ) {
			$code = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : ( is_int( $title ) ? $title : 0 );
			throw new OmniOffloader_Loopback_Died( (string) $code );
		};
	}
);

// No loopback request ever leaves this test.
$sent = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$sent ) {
		$sent[] = array(
			'url'  => $url,
			'args' => $args,
		);

		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

$bucket_dir = get_temp_dir() . 'omnioffloader-loopback-mock-bucket-' . time();
$queue      = new OmniOffloader_Loopback_Test_Queue( new MockProvider( $bucket_dir ) );
$identifier = omnioffloader_loopback_identifier( $queue );

/**
 * Queue identifier (protected property).
 *
 * @param object $queue Queue.
 * @return string
 */
function omnioffloader_loopback_identifier( $queue ) {
	$prop = new ReflectionProperty( $queue, 'identifier' );
	$prop->setAccessible( true );

	return (string) $prop->getValue( $queue );
}

AsyncUploadProcessor::purge_stored_data();

// ---------------------------------------------------------------------------
echo "Test 1: dispatch sends a signed token, no cookies and no URL nonce\n";

$_COOKIE['wordpress_logged_in_test'] = 'secret-session';
$queue->push_to_queue( 1001 )->save();
$queue->dispatch();

$last = end( $sent );
omnioffloader_loopback_check( false !== $last, 'a loopback request was sent' );
omnioffloader_loopback_check( empty( $last['args']['cookies'] ), 'no cookies are forwarded' );
omnioffloader_loopback_check( false === strpos( $last['url'], 'nonce=' ), 'no nonce in the URL' );
omnioffloader_loopback_check( false !== strpos( $last['url'], 'action=' . $identifier ), 'the URL carries the action' );
omnioffloader_loopback_check( ! empty( $last['args']['body']['omnioffloader_token'] ), 'the token is in the POST body' );
omnioffloader_loopback_check( 0 === strpos( $last['url'], admin_url( 'admin-ajax.php' ) ), 'default target is the public admin-ajax.php' );
unset( $_COOKIE['wordpress_logged_in_test'] );

$token = (string) $last['args']['body']['omnioffloader_token'];

// ---------------------------------------------------------------------------
echo "\nTest 2: token checks\n";

$window = (int) floor( time() / HOUR_IN_SECONDS );

$_POST = array( 'omnioffloader_token' => $token );
omnioffloader_loopback_check( true === $queue->call( 'is_valid_loopback_request' ), 'the dispatched token is valid' );

$_POST = array( 'omnioffloader_token' => $queue->call( 'loopback_token', array( $window - 1 ) ) );
omnioffloader_loopback_check( true === $queue->call( 'is_valid_loopback_request' ), "last hour's token is still valid" );

$_POST = array( 'omnioffloader_token' => $queue->call( 'loopback_token', array( $window - 2 ) ) );
omnioffloader_loopback_check( false === $queue->call( 'is_valid_loopback_request' ), 'a two-hour-old token is refused' );

$_POST = array( 'omnioffloader_token' => hash_hmac( 'sha256', 'omnioffloader_bulk_offload|' . $window, wp_salt( 'nonce' ) ) );
omnioffloader_loopback_check( false === $queue->call( 'is_valid_loopback_request' ), "another queue's token is refused" );

$_POST = array();
omnioffloader_loopback_check( false === $queue->call( 'is_valid_loopback_request' ), 'a missing token is refused' );

// ---------------------------------------------------------------------------
echo "\nTest 3: an unauthenticated hit is refused before any queue state is touched\n";

update_site_option( $identifier . '_status', 1 ); // The parent's "cancelled" flag.
$code = 0;
try {
	$queue->maybe_handle();
} catch ( OmniOffloader_Loopback_Died $e ) {
	$code = (int) $e->getMessage();
}
omnioffloader_loopback_check( 403 === $code, 'answered 403' );
omnioffloader_loopback_check( 1 === (int) get_site_option( $identifier . '_status' ), 'the cancelled flag was left alone (no cleanup ran)' );
omnioffloader_loopback_check( ! $queue->call( 'is_queue_empty' ), 'the queued item is still there' );
omnioffloader_loopback_check( array() === $queue->ran, 'nothing was processed' );
delete_site_option( $identifier . '_status' );

// ---------------------------------------------------------------------------
echo "\nTest 4: the cron healthcheck returns instead of exiting\n";

set_site_transient( $identifier . '_process_lock', microtime(), 60 );
$queue->handle_cron_healthcheck();
omnioffloader_loopback_check( true, 'returned while a worker holds the lock' );
delete_site_transient( $identifier . '_process_lock' );

AsyncUploadProcessor::purge_stored_data();
$queue->call( 'schedule_event' );
$queue->handle_cron_healthcheck();
omnioffloader_loopback_check( true, 'returned with an empty queue' );
omnioffloader_loopback_check( false === wp_next_scheduled( $identifier . '_cron' ), 'and cleared its cron event' );

// ---------------------------------------------------------------------------
echo "\nTest 5: an admin poll only takes over a queue that has gone quiet\n";

$queue->ran = array();
$queue->push_to_queue( 2002 )->save(); // save() touches the activity time.
$queue->process_queue_now();
omnioffloader_loopback_check( array() === $queue->ran, 'a just-queued item is left to its loopback' );

set_site_transient( $identifier . '_last_activity', time() - AsyncUploadProcessor::STALL_SECONDS - 1, HOUR_IN_SECONDS );
$queue->process_queue_now();
omnioffloader_loopback_check( array( 2002 ) === $queue->ran, 'a queue quiet for STALL_SECONDS is processed inline' );
omnioffloader_loopback_check( false === get_site_transient( $identifier . '_last_activity' ), 'the activity time is cleared when the queue completes' );

// ---------------------------------------------------------------------------
echo "\nTest 6: OMNIOFFLOADER_LOOPBACK_URL sends the loopback to the local server\n";

define( 'OMNIOFFLOADER_LOOPBACK_URL', 'https://127.0.0.1/wp-admin/admin-ajax.php' );
$queue->push_to_queue( 3003 )->save();
$queue->dispatch();
$last = end( $sent );
omnioffloader_loopback_check( 0 === strpos( $last['url'], 'https://127.0.0.1/wp-admin/admin-ajax.php?' ), 'the request goes to the local URL' );
omnioffloader_loopback_check( wp_parse_url( home_url(), PHP_URL_HOST ) === ( $last['args']['headers']['Host'] ?? '' ), 'the Host header names this site' );

// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
AsyncUploadProcessor::purge_stored_data();
$_POST = array();

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
if ( $failed > 0 ) {
	exit( 1 );
}
