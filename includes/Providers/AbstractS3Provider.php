<?php
/**
 * Shared implementation for S3-compatible providers.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Providers;

use OmniOffloader\Core\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AbstractS3Provider
 *
 * 95% of provider logic lives here, implemented against the AWS S3Client
 * (which speaks to every S3-compatible service). A concrete provider only
 * supplies client configuration and credential field definitions.
 */
abstract class AbstractS3Provider implements ProviderInterface {

	/**
	 * Max objects per DeleteObjects request (S3 API limit).
	 */
	protected const MAX_DELETE_OBJECTS = 1000;

	/**
	 * File size at or above which a multipart upload is used, in bytes.
	 *
	 * A single PutObject is capped at 5 GB by the S3 API, and a failure part
	 * way through one restarts the whole transfer — which is how a large video
	 * on a host with a short time limit becomes an unfinishable item.
	 */
	protected const MULTIPART_THRESHOLD = 67108864;

	/**
	 * Size of each multipart chunk, in bytes (S3 minimum is 5 MB).
	 */
	protected const MULTIPART_PART_SIZE = 8388608;

	/**
	 * Seconds to wait for a connection before giving up.
	 *
	 * The SDK sets no timeout by default, so an unreachable endpoint can hold
	 * a PHP worker — and the queue's process lock — indefinitely.
	 */
	protected const CONNECT_TIMEOUT = 10;

	/**
	 * Cached client instance.
	 *
	 * @var \Aws\S3\S3Client|null
	 */
	protected $client = null;

	/**
	 * Build a configured S3 client for this provider.
	 *
	 * @return \Aws\S3\S3Client
	 */
	abstract protected function create_client();

	/**
	 * Resolve a credential for this provider (constant wins over option).
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function credential( $field ) {
		return Helpers::get_credential( $this->get_key(), $field );
	}

	/**
	 * Lazily get the configured client.
	 *
	 * @return \Aws\S3\S3Client
	 */
	protected function get_client() {
		if ( null === $this->client ) {
			$this->client = $this->create_client();
		}

		return $this->client;
	}

	/**
	 * Bucket name from credentials.
	 *
	 * @return string|null
	 */
	public function get_bucket() {
		$bucket = $this->credential( 'bucket' );

		return '' !== $bucket ? $bucket : null;
	}

	/**
	 * Public/CDN base URL used for rewrites.
	 *
	 * @return string
	 */
	public function get_domain() {
		return Helpers::normalize_url( $this->credential( 'domain' ) );
	}

	/**
	 * Upload a local file to the bucket.
	 *
	 * @param string $file Absolute path of the local file.
	 * @param string $key  Object key to store it under.
	 * @return string|false Object URL on success, false on failure.
	 */
	public function upload_file( $file, $key ) {
		$client = $this->get_client();

		/**
		 * Filter the object ACL. Empty (default for R2) omits the ACL entirely.
		 *
		 * @param string $acl  ACL value, e.g. 'public-read'. Empty string to omit.
		 * @param string $file Local file path.
		 * @param string $key  Object key.
		 */
		$acl = apply_filters( 'omnioffloader_object_acl', '', $file, $key );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.filesystem_read_filesize -- local file size, not a filesystem abstraction concern.
		$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false/0 simply means "use a single PUT".

		try {
			if ( $size >= $this->multipart_threshold() ) {
				$this->upload_multipart( $file, $key, (string) $acl );
			} else {
				$params = array(
					'Bucket'     => $this->get_bucket(),
					'Key'        => $key,
					'SourceFile' => $file,
					'@http'      => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
					),
				);

				if ( ! empty( $acl ) ) {
					$params['ACL'] = $acl;
				}

				$client->putObject( $params );
			}

			return $client->getObjectUrl( (string) $this->get_bucket(), $key );
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Error uploading "%s": %s', $key, $e->getMessage() ) );

			return false;
		}
	}

	/**
	 * Upload a large file in parts.
	 *
	 * Each part is retried on its own, so a dropped connection costs one chunk
	 * instead of the whole transfer, and objects above the 5 GB single-PUT
	 * ceiling become possible.
	 *
	 * @since 0.1.1
	 *
	 * @param string $file Absolute path of the local file.
	 * @param string $key  Object key.
	 * @param string $acl  ACL value, empty to omit.
	 * @return void
	 * @throws \Exception When the upload fails.
	 */
	protected function upload_multipart( $file, $key, $acl ) {
		$config = array(
			'bucket'    => $this->get_bucket(),
			'key'       => $key,
			'part_size' => static::MULTIPART_PART_SIZE,
			'params'    => array(
				'@http' => array(
					'connect_timeout' => static::CONNECT_TIMEOUT,
				),
			),
		);

		// Only send an ACL when one was asked for — R2 rejects the header.
		if ( '' !== $acl ) {
			$config['acl'] = $acl;
		}

		$uploader = new \Aws\S3\MultipartUploader( $this->get_client(), $file, $config );

		try {
			$uploader->upload();
		} catch ( \Aws\Exception\MultipartUploadException $e ) {
			// Leave no half-assembled upload paying storage in the bucket.
			try {
				$this->get_client()->abortMultipartUpload( $e->getState()->getId() );
			} catch ( \Exception $abort_error ) {
				$this->log_error( sprintf( 'Could not abort multipart upload for "%s": %s', $key, $abort_error->getMessage() ) );
			}

			throw $e;
		}
	}

	/**
	 * File size at which uploads switch to multipart.
	 *
	 * @since 0.1.1
	 *
	 * @return int Bytes.
	 */
	protected function multipart_threshold() {
		/**
		 * Filter the multipart upload threshold.
		 *
		 * @param int    $threshold    Size in bytes.
		 * @param string $provider_key Provider key.
		 */
		$threshold = (int) apply_filters( 'omnioffloader_multipart_threshold', static::MULTIPART_THRESHOLD, $this->get_key() );

		// Below the S3 minimum part size a multipart upload is not valid.
		return max( 5242880, $threshold );
	}

	/**
	 * Download an object to a local path, atomically.
	 *
	 * Streams to a temp file in the destination directory first, then
	 * renames into place so a failed download never leaves a half-written
	 * file at the destination.
	 *
	 * @param string $key  Object key.
	 * @param string $dest Absolute destination path.
	 * @return bool
	 */
	public function download_file( $key, $dest ) {
		$dir = dirname( $dest );

		if ( ! wp_mkdir_p( $dir ) ) {
			$this->log_error( sprintf( 'Cannot create directory "%s".', $dir ) );

			return false;
		}

		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = function_exists( 'wp_tempnam' )
			? wp_tempnam( basename( $dest ), trailingslashit( $dir ) )
			: tempnam( $dir, 'omnioffloader_' );

		if ( ! $tmp ) {
			$this->log_error( 'Cannot create temp file for download.' );

			return false;
		}

		try {
			$this->get_client()->getObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'SaveAs' => $tmp,
					'@http'  => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
					),
				)
			);
		} catch ( \Exception $e ) {
			wp_delete_file( $tmp );
			$this->log_error( sprintf( 'Error downloading "%s": %s', $key, $e->getMessage() ) );

			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- atomic move; failure handled below.
		if ( ! @rename( $tmp, $dest ) ) {
			wp_delete_file( $tmp );
			$this->log_error( sprintf( 'Cannot move downloaded file into place: "%s".', $dest ) );

			return false;
		}

		return true;
	}

	/**
	 * Delete many objects in as few API calls as possible.
	 *
	 * @param string[] $keys Object keys.
	 * @return bool True when all deletes succeeded.
	 */
	public function delete_objects( array $keys ) {
		$keys = array_values(
			array_unique(
				array_filter(
					$keys,
					static function ( $key ) {
						return '' !== $key;
					}
				)
			)
		);

		if ( empty( $keys ) ) {
			return true;
		}

		$client = $this->get_client();
		$bucket = $this->get_bucket();
		$all_ok = true;

		foreach ( array_chunk( $keys, self::MAX_DELETE_OBJECTS ) as $chunk ) {
			$objects = array_map(
				static function ( $key ) {
					return array( 'Key' => $key );
				},
				$chunk
			);

			try {
				$result = $client->deleteObjects(
					array(
						'Bucket' => $bucket,
						'Delete' => array(
							'Objects' => $objects,
							'Quiet'   => true,
						),
						'@http'  => array(
							'connect_timeout' => static::CONNECT_TIMEOUT,
						),
					)
				);

				$errors = $result['Errors'] ?? array();

				if ( ! empty( $errors ) ) {
					$all_ok = false;
					$this->log_error( 'deleteObjects returned errors: ' . wp_json_encode( $errors ) );
				}
			} catch ( \Exception $e ) {
				// Fall back to per-object deletes (some providers limit batching).
				$this->log_error( 'deleteObjects failed, falling back to single deletes: ' . $e->getMessage() );

				foreach ( $chunk as $key ) {
					try {
						$client->deleteObject(
							array(
								'Bucket' => $bucket,
								'Key'    => $key,
								'@http'  => array(
									'connect_timeout' => static::CONNECT_TIMEOUT,
									'timeout'         => 5,
								),
							)
						);
					} catch ( \Exception $inner ) {
						$all_ok = false;
						$this->log_error( sprintf( 'deleteObject failed for "%s": %s', $key, $inner->getMessage() ) );
					}
				}
			}
		}

		return $all_ok;
	}

	/**
	 * Whether an object exists in the bucket.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	public function object_exists( $key ) {
		try {
			$this->get_client()->headObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'@http'  => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 30,
					),
				)
			);

			return true;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Size of an object in bytes.
	 *
	 * @param string $key Object key.
	 * @return int|false Size in bytes, or false when unknown/missing.
	 */
	public function object_size( $key ) {
		try {
			$result = $this->get_client()->headObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'@http'  => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 30,
					),
				)
			);

			$length = $result['ContentLength'] ?? null;

			return is_numeric( $length ) ? (int) $length : false;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	/**
	 * Check connectivity to the bucket with a short timeout.
	 *
	 * @return true|\WP_Error
	 */
	public function check_connection() {
		if ( null === $this->get_bucket() ) {
			return new \WP_Error(
				'omnioffloader_missing_bucket',
				__( 'No bucket name configured.', 'omnioffloader' )
			);
		}

		try {
			$this->get_client()->headBucket(
				array(
					'Bucket' => $this->get_bucket(),
					'@http'  => array(
						'connect_timeout' => 5,
						'timeout'         => 5,
					),
				)
			);

			return true;
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'omnioffloader_connection_failed',
				sprintf(
					/* translators: %s: error message from the storage provider. */
					__( 'Connection failed: %s', 'omnioffloader' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Log a provider error (never logs credentials).
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	protected function log_error( $message ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational logging.
		error_log( 'OmniOffloader [' . $this->get_key() . ']: ' . $message );
	}
}
