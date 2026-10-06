<?php
/**
 * Integration test for the rclone setup (Tools card, `wp omnioffloader
 * rclone`), run via `wp eval-file`.
 *
 * Checks the remote generated for every provider, that the secret key only
 * appears when explicitly asked for, the destination with and without a
 * Path Prefix, and the --files-from list.
 *
 * @package OmniOffloader
 */

// Safe mode would hide the setup on a local test site (*.test, localhost).
if ( ! defined( 'OMNIOFFLOADER_SAFE_MODE' ) ) {
	define( 'OMNIOFFLOADER_SAFE_MODE', false );
}

use OmniOffloader\Providers\AmazonS3;
use OmniOffloader\Providers\BackblazeB2;
use OmniOffloader\Providers\CloudflareR2;
use OmniOffloader\Providers\DigitalOceanSpaces;
use OmniOffloader\Providers\S3Compatible;
use OmniOffloader\Providers\Wasabi;
use OmniOffloader\Services\RcloneSetup;

if ( ! defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) || ! OMNIOFFLOADER_DISABLE_ENGINE ) {
	echo "ABORT: run with the engine disabled, e.g.:\n";
	echo "wp --exec=\"define( 'OMNIOFFLOADER_DISABLE_ENGINE', true );\" eval-file tests/integration-rclone.php\n";
	exit( 1 );
}

// eval-file runs in function scope; counters must live in globals.
global $omnioffloader_pass, $omnioffloader_fail;
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
 * A provider with test credentials.
 *
 * @param string               $class  Provider class.
 * @param array<string,string> $extra  Extra credential fields.
 * @return \OmniOffloader\Providers\AbstractS3Provider
 */
function omnioffloader_provider( $class, array $extra = array() ) {
	$provider = new $class();
	$provider->set_credential_overrides(
		array_merge(
			array(
				'key'    => 'TESTKEYID',
				'secret' => 'TOP-SECRET-VALUE',
				'bucket' => 'media-bucket',
			),
			$extra
		)
	);

	return $provider;
}

$original_settings = get_option( 'omnioffloader_settings', array() );

// ---------------------------------------------------------------------------
// Test 1 — Remote per provider.
// ---------------------------------------------------------------------------
echo "Test 1: remote per provider\n";

$cases = array(
	'Amazon S3'           => array( omnioffloader_provider( AmazonS3::class, array( 'region' => 'eu-west-1' ) ), array( 'provider = AWS', 'region = eu-west-1' ) ),
	'Cloudflare R2'       => array( omnioffloader_provider( CloudflareR2::class, array( 'endpoint' => 'abc.r2.cloudflarestorage.com' ) ), array( 'provider = Cloudflare', 'endpoint = https://abc.r2.cloudflarestorage.com' ) ),
	'Backblaze B2'        => array( omnioffloader_provider( BackblazeB2::class, array( 'endpoint' => 'https://s3.us-west-004.backblazeb2.com' ) ), array( 'provider = Other', 'region = us-west-004', 'endpoint = https://s3.us-west-004.backblazeb2.com' ) ),
	'DigitalOcean Spaces' => array( omnioffloader_provider( DigitalOceanSpaces::class, array( 'endpoint' => 'https://nyc3.digitaloceanspaces.com' ) ), array( 'provider = DigitalOcean', 'endpoint = https://nyc3.digitaloceanspaces.com' ) ),
	'Wasabi'              => array( omnioffloader_provider( Wasabi::class, array( 'region' => 'eu-central-1' ) ), array( 'provider = Wasabi', 'region = eu-central-1', 'endpoint = https://s3.eu-central-1.wasabisys.com' ) ),
	'S3-compatible'       => array( omnioffloader_provider( S3Compatible::class, array( 'endpoint' => 'https://minio.example.com', 'path_style' => '1' ) ), array( 'provider = Other', 'endpoint = https://minio.example.com', 'force_path_style = true' ) ),
);

foreach ( $cases as $label => $case ) {
	list( $provider, $expected ) = $case;
	$config                      = RcloneSetup::config( $provider );
	$missing                     = array_filter(
		$expected,
		static function ( $line ) use ( $config ) {
			return false === strpos( $config, $line . "\n" );
		}
	);

	omnioffloader_check( empty( $missing ) && 0 === strpos( $config, "[omnioffloader]\ntype = s3\n" ) && false !== strpos( $config, 'access_key_id = TESTKEYID' ), "{$label}: " . ( empty( $missing ) ? 'expected options' : 'missing ' . implode( ', ', $missing ) ) );
}

$s3c = omnioffloader_provider( S3Compatible::class, array( 'endpoint' => 'https://minio.example.com' ) );
omnioffloader_check( false !== strpos( RcloneSetup::config( $s3c ), 'force_path_style = false' ), 'S3-compatible without path style: force_path_style = false (rclone defaults to true)' );

// ---------------------------------------------------------------------------
// Test 2 — The secret key.
// ---------------------------------------------------------------------------
echo "\nTest 2: secret key\n";

$r2 = $cases['Cloudflare R2'][0];
omnioffloader_check( false === strpos( RcloneSetup::config( $r2 ), 'TOP-SECRET-VALUE' ) && false !== strpos( RcloneSetup::config( $r2 ), RcloneSetup::SECRET_PLACEHOLDER ), 'shown config has the placeholder, never the secret' );
omnioffloader_check( false !== strpos( RcloneSetup::config( $r2, true ), 'secret_access_key = TOP-SECRET-VALUE' ), 'config for --write-config carries the secret' );

// ---------------------------------------------------------------------------
// Test 3 — Destination and copy command.
// ---------------------------------------------------------------------------
echo "\nTest 3: destination\n";

update_option( 'omnioffloader_settings', array_merge( $original_settings, array( 'path_prefix_active' => false, 'path_prefix' => '' ) ), true );
omnioffloader_check( 'omnioffloader:media-bucket' === RcloneSetup::destination( $r2 ), 'no Path Prefix: bucket root' );

update_option( 'omnioffloader_settings', array_merge( $original_settings, array( 'path_prefix_active' => true, 'path_prefix' => 'shop/' ) ), true );
omnioffloader_check( 'omnioffloader:media-bucket/shop' === RcloneSetup::destination( $r2 ), 'Path Prefix "shop": this site\'s folder only' );

$command = RcloneSetup::copy_command( $r2 );
omnioffloader_check( 0 === strpos( $command, "rclone copy '" . RcloneSetup::source() . "' 'omnioffloader:media-bucket/shop'" ), 'copy command: uploads folder to the site folder, with rclone copy' );
omnioffloader_check( false !== strpos( $command, "--include '/[0-9][0-9][0-9][0-9]/**'" ) && false === strpos( $command, 'sync' ), 'copy command: year/month folders only, never sync' );
omnioffloader_check( false !== strpos( RcloneSetup::copy_command( $r2, '/tmp/list.txt' ), "--files-from '/tmp/list.txt'" ), 'with a file list: --files-from' );

// ---------------------------------------------------------------------------
// Test 4 — File list.
// ---------------------------------------------------------------------------
echo "\nTest 4: file list\n";

require_once ABSPATH . 'wp-admin/includes/image.php';

$image = imagecreatetruecolor( 1200, 800 );
ob_start();
imagejpeg( $image, null, 80 );
$bits = ob_get_clean();
imagedestroy( $image );

$upload = wp_upload_bits( 'omnioffloader-rclone-list.jpg', null, $bits );
$id     = wp_insert_attachment(
	array(
		'post_mime_type' => 'image/jpeg',
		'post_title'     => 'omnioffloader-rclone-list',
		'post_status'    => 'inherit',
	),
	$upload['file']
);
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );

$list  = get_temp_dir() . 'omnioffloader-rclone-list-' . time() . '.txt';
$count = RcloneSetup::write_file_list( $list );
$lines = file( $list, FILE_IGNORE_NEW_LINES );
$rel   = ltrim( (string) get_post_meta( $id, '_wp_attached_file', true ), '/' );

omnioffloader_check( is_int( $count ) && $count === count( $lines ), "{$count} file(s) listed" );
omnioffloader_check( in_array( $rel, $lines, true ), 'the new attachment\'s main file is listed, relative to uploads' );
omnioffloader_check( count( array_filter( $lines, static fn( $line ) => 0 === strpos( $line, '/' ) || false !== strpos( $line, '..' ) ) ) === 0, 'no absolute or ".." paths' );

// ---------------------------------------------------------------------------
// Cleanup.
// ---------------------------------------------------------------------------
echo "\nCleanup…\n";
wp_delete_attachment( $id, true );
wp_delete_file( $list );
update_option( 'omnioffloader_settings', $original_settings, true );

echo "\nRESULT: {$omnioffloader_pass} passed, {$omnioffloader_fail} failed\n";
exit( $omnioffloader_fail > 0 ? 1 : 0 );
