<?php
/**
 * Filesystem-backed mock provider for integration tests.
 *
 * Implements the full ProviderInterface against a local "bucket"
 * directory so the offload pipeline can be exercised end-to-end
 * without cloud credentials.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Tests;

use SmartMediaOffloader\Providers\ProviderInterface;

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
