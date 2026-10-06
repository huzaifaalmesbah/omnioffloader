<?php
/**
 * Filesystem-backed mock provider for integration tests.
 *
 * Implements the full ProviderInterface against a local "bucket"
 * directory so the offload pipeline can be exercised end-to-end
 * without cloud credentials.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Tests;

use OmniOffloader\Providers\ProviderInterface;

/**
 * Class MockProvider
 */
class MockProvider implements ProviderInterface {

	/**
	 * Bucket directory.
	 *
	 * @var string
	 */
	private $bucket_dir;

	/**
	 * Constructor.
	 *
	 * @param string $bucket_dir Directory acting as the bucket.
	 */
	public function __construct( $bucket_dir ) {
		$this->bucket_dir = rtrim( $bucket_dir, '/' ) . '/';
		wp_mkdir_p( $this->bucket_dir );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_key() {
		return 'mock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'Mock Provider';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_bucket() {
		return 'mock-bucket';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_domain() {
		return 'https://cdn.example.test';
	}

	/**
	 * {@inheritDoc}
	 */
	public function upload_file( $file, $key ) {
		$dest = $this->bucket_dir . $key;
		wp_mkdir_p( dirname( $dest ) );

		if ( ! copy( $file, $dest ) ) {
			return false;
		}

		return $this->get_domain() . '/' . $key;
	}

	/**
	 * {@inheritDoc}
	 */
	public function download_file( $key, $dest ) {
		$src = $this->bucket_dir . $key;

		if ( ! file_exists( $src ) ) {
			return false;
		}

		wp_mkdir_p( dirname( $dest ) );

		return copy( $src, $dest );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete_objects( array $keys ) {
		foreach ( $keys as $key ) {
			$path = $this->bucket_dir . $key;
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}

		return true;
	}

	/**
	 * Read a small object (see AbstractS3Provider::read_small_object()).
	 *
	 * @param string $key Object key.
	 * @return string|null Null when missing.
	 */
	public function read_small_object( $key ) {
		$path = $this->bucket_dir . $key;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test bucket.
		return is_file( $path ) ? (string) file_get_contents( $path ) : null;
	}

	/**
	 * Write a small object (see AbstractS3Provider::write_small_object()).
	 *
	 * @param string $key  Object key.
	 * @param string $body Contents.
	 * @return bool
	 */
	public function write_small_object( $key, $body ) {
		$path = $this->bucket_dir . $key;
		wp_mkdir_p( dirname( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local test bucket.
		return false !== file_put_contents( $path, $body );
	}

	/**
	 * {@inheritDoc}
	 */
	public function object_exists( $key ) {
		return file_exists( $this->bucket_dir . $key );
	}

	/**
	 * {@inheritDoc}
	 */
	public function object_size( $key ) {
		$path = $this->bucket_dir . $key;

		if ( ! file_exists( $path ) ) {
			return false;
		}

		$size = filesize( $path );

		return false === $size ? false : (int) $size;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, string> $overrides Ignored — the mock needs no credentials.
	 */
	public function set_credential_overrides( array $overrides ) {
		unset( $overrides );
	}

	/**
	 * {@inheritDoc}
	 */
	public function check_connection() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function credential_fields() {
		return array();
	}
}
