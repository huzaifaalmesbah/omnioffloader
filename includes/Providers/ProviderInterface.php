<?php
/**
 * Cloud provider contract.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ProviderInterface
 *
 * Every cloud storage provider must fulfill this contract. Adding a new
 * provider means one class implementing this (usually by extending
 * AbstractS3Provider) plus one line in ProviderRegistry.
 */
interface ProviderInterface {

	/**
	 * Machine key, e.g. 'cloudflare_r2'.
	 *
	 * @return string
	 */
	public function get_key();

	/**
	 * Human name, e.g. 'Cloudflare R2'.
	 *
	 * @return string
	 */
	public function get_name();

	/**
	 * Bucket name from credentials.
	 *
	 * @return string|null
	 */
	public function get_bucket();

	/**
	 * Public/CDN base URL used for rewrites (trailing-slash-free).
	 *
	 * @return string
	 */
	public function get_domain();

	/**
	 * Upload a local file to the bucket.
	 *
	 * @param string $file Absolute path of the local file.
	 * @param string $key  Object key to store it under.
	 * @return string|false Object URL on success, false on failure.
	 */
	public function upload_file( $file, $key );

	/**
	 * Download an object to a local path (Bring Back).
	 *
	 * @param string $key  Object key.
	 * @param string $dest Absolute destination path.
	 * @return bool
	 */
	public function download_file( $key, $dest );

	/**
	 * Delete many objects, batched.
	 *
	 * @param string[] $keys Object keys.
	 * @return bool True when all deletes succeeded.
	 */
	public function delete_objects( array $keys );

	/**
	 * Whether an object exists in the bucket.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	public function object_exists( $key );

	/**
	 * Size of an object in bytes (Bring Back skip-if-identical check).
	 *
	 * @param string $key Object key.
	 * @return int|false Size in bytes, or false when unknown/missing.
	 */
	public function object_size( $key );

	/**
	 * Check connectivity to the bucket.
	 *
	 * @return true|\WP_Error True when reachable, WP_Error with details otherwise.
	 */
	public function check_connection();

	/**
	 * Set temporary credential overrides for testing unsaved credentials.
	 *
	 * @param array<string, string> $overrides Map of field name => value.
	 * @return void
	 */
	public function set_credential_overrides( array $overrides );

	/**
	 * Credential field definitions, rendered generically by the React admin UI.
	 *
	 * Each item: name, label, type (text|password), placeholder, description.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function credential_fields();
}
