<?php
/**
 * Integration test for folder claims (run via `wp eval-file`).
 *
 * Simulates separate sites sharing one bucket by switching the site address:
 * the first site claims its folder, a second site on the same folder is put
 * in Safe mode (reason "folder"), cannot save that folder in Settings, can
 * pick its own, and can take a folder over. A confirmed domain move carries
 * the claim along.
 *
 * @package OmniOffloader
 */

require_once OMNIOFFLOADER_DIR . 'tests/MockProvider.php';

// Local test sites (*.test) are in Safe mode; this test exercises writes.
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Tests\MockProvider;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-folder-claim.php\n";
	exit( 1 );
}

/**
 * No-arg-constructible mock so ProviderRegistry::create() can build it.
 */
class SmowpClaimMockProvider extends MockProvider {
	/**
	 * Bucket dir shared with the test.
	 *
	 * @var string
	 */
	public static $dir = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( self::$dir );
	}
}

global $omnioffloader_pass, $omnioffloader_fail, $omnioffloader_home;
$omnioffloader_pass = 0;
$omnioffloader_fail = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Test label.
 */
function omnioffloader_check( $condition, $label ) {
	global $omnioffloader_pass, $omnioffloader_fail;
	if ( $condition ) {
		++$omnioffloader_pass;
		echo "  ✓ {$label}\n";
	} else {
		++$omnioffloader_fail;
		echo "  ✗ FAIL: {$label}\n";
	}
}

/**
 * Pretend to be another site: switch the address and drop cached state,
 * as a separate site with its own database would have.
 *
 * @param string $host Site host.
 */
function omnioffloader_be_site( $host ) {
	global $omnioffloader_home;
	$omnioffloader_home = 'https://' . $host;
	FolderClaim::forget();
}

/**
 * Owner named in a folder's marker.
 *
 * @param string $dir    Bucket dir.
 * @param string $prefix Folder with trailing slash, or ''.
 * @return string
 */
function omnioffloader_marker_owner( $dir, $prefix ) {
	$path = trailingslashit( $dir ) . FolderClaim::marker_key( $prefix );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test bucket.
	$data = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;

	return is_array( $data ) ? (string) ( $data['site'] ?? '' ) : '';
}

/**
 * Save settings through REST, as the Settings screen does.
 *
 * @param array<string, mixed> $changes Settings to change.
 * @return WP_REST_Response
 */
function omnioffloader_post_settings( array $changes ) {
	$request = new WP_REST_Request( 'POST', '/omnioffloader/v1/core/settings' );
	$request->set_body_params( array( 'settings' => $changes ) );

	return rest_do_request( $request );
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
$original_settings    = get_option( 'omnioffloader_settings', array() );
$original_credentials = get_option( 'omnioffloader_credentials', array() );
$original_claims      = get_option( FolderClaim::OPTION );

$bucket_dir                   = get_temp_dir() . 'omnioffloader-claim-bucket-' . time();
SmowpClaimMockProvider::$dir = $bucket_dir;

add_filter(
	'omnioffloader_cloud_providers',
	static function ( $providers ) {
		$providers['mock'] = SmowpClaimMockProvider::class;

		return $providers;
	}
);

// The stored Site Address is what identifies a site (not home_url()).
add_filter(
	'option_home',
	static function ( $url ) {
		global $omnioffloader_home;

		return $omnioffloader_home ? $omnioffloader_home : $url;
	}
);

$credentials         = is_array( $original_credentials ) ? $original_credentials : array();
$credentials['mock'] = array( 'bucket' => 'mock-bucket' );
update_option( 'omnioffloader_credentials', $credentials, false );

update_option(
	'omnioffloader_settings',
	array_merge(
		is_array( $original_settings ) ? $original_settings : array(),
		array(
			'provider'           => 'mock',
			'path_prefix_active' => true,
			'path_prefix'        => 'www',
		)
	),
	true
);

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

$provider = new SmowpClaimMockProvider();

echo "Mock bucket: {$bucket_dir}\n\n";

// ---------------------------------------------------------------------------
// Test 1 — the first site claims a free folder.
// ---------------------------------------------------------------------------
echo "Test 1: first site claims its folder\n";
omnioffloader_be_site( 'lexinter.example' );

$result = FolderClaim::ensure( $provider );
omnioffloader_check( FolderClaim::OURS === $result['status'], 'free folder becomes ours' );
omnioffloader_check( 'lexinter.example' === omnioffloader_marker_owner( $bucket_dir, 'www/' ), 'marker names the site' );
omnioffloader_check( ! FolderClaim::blocks( $provider ), 'writes allowed' );
omnioffloader_check( 'folder' !== SafeMode::reason(), 'no Safe mode' );

// ---------------------------------------------------------------------------
// Test 2 — a second site on the same folder is stopped.
// ---------------------------------------------------------------------------
echo "\nTest 2: second site on the same folder\n";
omnioffloader_be_site( 'attorneys.lexinter.example' );

omnioffloader_check( FolderClaim::blocks( $provider ), 'writes blocked' );
omnioffloader_check( 'folder' === SafeMode::reason() && SafeMode::is_active(), 'Safe mode reason is "folder"' );
omnioffloader_check( ! SafeMode::is_copy(), 'not counted as a copy' );
$status = SafeMode::status();
omnioffloader_check( 'lexinter.example' === $status['folder_owner'] && 'www' === $status['folder'], 'status names the owner and folder' );
omnioffloader_check( false !== strpos( SafeMode::message(), 'lexinter.example' ), 'refusal message names the owner' );
omnioffloader_check( 'lexinter.example' === omnioffloader_marker_owner( $bucket_dir, 'www/' ), 'marker left untouched' );

// ---------------------------------------------------------------------------
// Test 3 — Settings refuses another site's folder and claims a free one.
// ---------------------------------------------------------------------------
echo "\nTest 3: saving a Path Prefix\n";
update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'path_prefix' => 'attorneys' ) ), true );
omnioffloader_be_site( 'attorneys.lexinter.example' );
FolderClaim::ensure( $provider );

$response = omnioffloader_post_settings( array( 'path_prefix' => 'www' ) );
omnioffloader_check( 409 === $response->get_status(), 'taken folder refused with 409' );
omnioffloader_check( 'omnioffloader_folder_claimed' === ( $response->get_data()['code'] ?? '' ), 'error code omnioffloader_folder_claimed' );
omnioffloader_check( 'attorneys' === get_option( 'omnioffloader_settings' )['path_prefix'], 'setting unchanged' );

$response = omnioffloader_post_settings( array( 'path_prefix' => 'laws' ) );
omnioffloader_check( 200 === $response->get_status(), 'free folder saved' );
omnioffloader_check( 'attorneys.lexinter.example' === omnioffloader_marker_owner( $bucket_dir, 'laws/' ), 'new folder claimed on save' );

// ---------------------------------------------------------------------------
// Test 4 — the bucket root is a folder too.
// ---------------------------------------------------------------------------
echo "\nTest 4: bucket root\n";
update_option( 'omnioffloader_settings', array_merge( get_option( 'omnioffloader_settings' ), array( 'path_prefix_active' => false ) ), true );
omnioffloader_be_site( 'lexinter.example' );
omnioffloader_check( FolderClaim::OURS === FolderClaim::ensure( $provider )['status'], 'root claimed by the first site' );
omnioffloader_be_site( 'laws.lexinter.example' );
omnioffloader_check( FolderClaim::blocks( $provider ), 'second site on the root is blocked' );
omnioffloader_check( '' === SafeMode::status()['folder'], 'status reports the root as empty folder' );

// ---------------------------------------------------------------------------
// Test 5 — take over, and a confirmed move carries the claim along.
// ---------------------------------------------------------------------------
echo "\nTest 5: take over and domain move\n";
omnioffloader_check( FolderClaim::take_over( $provider ), 'take over succeeds' );
omnioffloader_check( 'laws.lexinter.example' === omnioffloader_marker_owner( $bucket_dir, '' ), 'marker now names this site' );
omnioffloader_check( ! FolderClaim::blocks( $provider ) && 'folder' !== SafeMode::reason(), 'conflict cleared' );

omnioffloader_be_site( 'laws-new.example' );
FolderClaim::follow_move( $provider, 'laws.lexinter.example' );
omnioffloader_check( 'laws-new.example' === omnioffloader_marker_owner( $bucket_dir, '' ), 'claim follows a confirmed move' );

omnioffloader_be_site( 'someone-else.example' );
FolderClaim::follow_move( $provider, 'laws.lexinter.example' );
omnioffloader_check( 'laws-new.example' === omnioffloader_marker_owner( $bucket_dir, '' ), 'move from an unrelated address changes nothing' );

// ---------------------------------------------------------------------------
// Test 5b — a per-request home_url filter (multilingual domains) is ignored.
// ---------------------------------------------------------------------------
echo "\nTest 5b: per-language domains do not change the site's identity\n";
$language_domain = static function ( $url, $path ) {
	return 'https://example.de' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
};
omnioffloader_be_site( 'laws-new.example' );
add_filter( 'home_url', $language_domain, 10, 2 );
omnioffloader_check( 'laws-new.example' === SafeMode::current_address(), 'identity comes from the stored Site Address' );
omnioffloader_check( ! FolderClaim::blocks( $provider ), 'site still owns its folder' );
remove_filter( 'home_url', $language_domain, 10 );

// ---------------------------------------------------------------------------
// Test 6 — filter and non-active providers.
// ---------------------------------------------------------------------------
echo "\nTest 6: opt-out filter\n";
add_filter( 'omnioffloader_check_folder_claim', '__return_false' );
omnioffloader_check( ! FolderClaim::blocks( $provider ), 'filter turns the check off' );
omnioffloader_check( null === FolderClaim::active_conflict(), 'no conflict reported' );
remove_filter( 'omnioffloader_check_folder_claim', '__return_false' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
update_option( 'omnioffloader_settings', $original_settings, true );
update_option( 'omnioffloader_credentials', $original_credentials, false );
if ( false === $original_claims ) {
	delete_option( FolderClaim::OPTION );
} else {
	update_option( FolderClaim::OPTION, $original_claims, true );
}

global $wp_filesystem;
WP_Filesystem();
$wp_filesystem->rmdir( $bucket_dir, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
