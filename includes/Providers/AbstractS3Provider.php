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
	 * Settings every S3 client built by this plugin shares.
	 *
	 * Providers pass their own config through this so the defaults below
	 * apply to all of them.
	 *
	 * @since 0.1.3
	 *
	 * @param array<string, mixed> $config Provider-specific client config.
	 * @return array<string, mixed>
	 */
	protected function client_config( array $config ) {
		return array_merge(
			array(
				'version'                      => 'latest',
				'use_aws_shared_config_files'  => false,
				// Since SDK 3.337 every PutObject is CRC32-checksummed by
				// default, which reads each file one extra time. The SigV4
				// payload hash already covers integrity, and several
				// S3-compatible services reject the newer checksum headers.
				'request_checksum_calculation' => 'when_required',
			),
			$config
		);
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
	 * @since 0.1.3 Added $concurrency.
	 *
	 * @param string $file        Absolute path of the local file.
	 * @param string $key         Object key to store it under.
	 * @param int    $concurrency Multipart parts in flight at once; 0 for the SDK default.
	 * @return string|false Object URL on success, false on failure.
	 */
	public function upload_file( $file, $key, $concurrency = 0 ) {
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
				$this->upload_multipart( $file, $key, (string) $acl, (int) $concurrency );
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
	 * Upload several files, up to $concurrency of them at once.
	 *
	 * An attachment is a handful of small files (original + generated sizes),
	 * and uploading them one after another spends most of the time waiting on
	 * round trips. Running them side by side over curl_multi overlaps that
	 * waiting inside this one PHP process — no extra workers, and commands
	 * are created lazily, so only $concurrency files are open at a time.
	 * Files at or above the multipart threshold still go one at a time.
	 *
	 * Not part of ProviderInterface, so third-party providers keep working —
	 * callers check method_exists() and fall back to upload_file().
	 *
	 * @since 0.1.3
	 *
	 * @param array<int, array{file: string, key: string}> $files       Files to upload.
	 * @param int                                          $concurrency Files in flight at once.
	 * @return array<string, bool> Object key => whether it uploaded.
	 */
	public function upload_files( array $files, $concurrency = 1 ) {
		$results     = array();
		$concurrency = max( 1, (int) $concurrency );
		$threshold   = $this->multipart_threshold();
		$pooled      = array();

		foreach ( $files as $entry ) {
			$results[ $entry['key'] ] = false;

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false/0 simply means "use a single PUT".
			$size = (int) @filesize( $entry['file'] );

			if ( 1 === $concurrency || $size >= $threshold ) {
				$results[ $entry['key'] ] = false !== $this->upload_file( $entry['file'], $entry['key'], $concurrency );
			} else {
				$pooled[] = $entry;
			}
		}

		if ( empty( $pooled ) ) {
			return $results;
		}

		$client = $this->get_client();
		$bucket = $this->get_bucket();

		$commands = static function () use ( $client, $bucket, $pooled ) {
			foreach ( $pooled as $index => $entry ) {
				$params = array(
					'Bucket'     => $bucket,
					'Key'        => $entry['key'],
					'SourceFile' => $entry['file'],
					'@http'      => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
					),
				);

				/** This filter is documented in includes/Providers/AbstractS3Provider.php */
				$acl = apply_filters( 'omnioffloader_object_acl', '', $entry['file'], $entry['key'] );

				if ( ! empty( $acl ) ) {
					$params['ACL'] = $acl;
				}

				yield $index => $client->getCommand( 'PutObject', $params );
			}
		};

		try {
			$pool = new \Aws\CommandPool(
				$client,
				$commands(),
				array(
					'concurrency'            => $concurrency,
					// Report results under the keys yielded above, not a counter.
					'preserve_iterator_keys' => true,
					'fulfilled'              => static function ( $result, $index ) use ( &$results, $pooled ) {
						$results[ $pooled[ $index ]['key'] ] = true;
					},
					'rejected'               => function ( $reason, $index ) use ( $pooled ) {
						$message = $reason instanceof \Throwable ? $reason->getMessage() : 'unknown error';
						$this->log_error( sprintf( 'Error uploading "%s": %s', $pooled[ $index ]['key'], $message ) );
					},
				)
			);

			$pool->promise()->wait();
		} catch ( \Exception $e ) {
			// Anything not reported as fulfilled stays false.
			$this->log_error( sprintf( 'Upload batch failed: %s', $e->getMessage() ) );
		}

		return $results;
	}

	/**
	 * Upload a large file in parts.
	 *
	 * Each part is retried on its own, so a dropped connection costs one chunk
	 * instead of the whole transfer, and objects above the 5 GB single-PUT
	 * ceiling become possible.
	 *
	 * @since 0.1.1
	 * @since 0.1.3 Added $concurrency.
	 *
	 * @param string $file        Absolute path of the local file.
	 * @param string $key         Object key.
	 * @param string $acl         ACL value, empty to omit.
	 * @param int    $concurrency Parts in flight at once; 0 for the SDK default.
	 * @return void
	 * @throws \Exception When the upload fails.
	 */
	protected function upload_multipart( $file, $key, $acl, $concurrency = 0 ) {
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

		// The SDK uploads 5 parts at once by default; follow the site's
		// transfer speed setting instead so a low-resource host stays low.
		if ( $concurrency > 0 ) {
			$config['concurrency'] = (int) $concurrency;
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
	 * Whether a local file is big enough to be uploaded in parts.
	 *
	 * @since 0.1.3
	 *
	 * @param string $file Absolute path.
	 * @return bool
	 */
	public function is_large_file( $file ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false/0 means small.
		return (int) @filesize( $file ) >= $this->multipart_threshold();
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
		$tmp = $this->temp_file_for( $dest );

		if ( false === $tmp ) {
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

		return $this->move_into_place( $tmp, $dest );
	}

	/**
	 * Download several objects, up to $concurrency of them at once.
	 *
	 * The download counterpart of upload_files(): one curl_multi pool inside
	 * this PHP process, each object streamed to its own temp file and only
	 * renamed into place once complete, exactly like download_file().
	 *
	 * Not part of ProviderInterface — callers check method_exists().
	 *
	 * @since 0.1.3
	 *
	 * @param array<int, array{key: string, dest: string}> $files       Objects to download.
	 * @param int                                          $concurrency Downloads in flight at once.
	 * @return array<string, bool> Object key => whether it is now at its destination.
	 */
	public function download_files( array $files, $concurrency = 1 ) {
		$results     = array();
		$concurrency = max( 1, (int) $concurrency );
		$files       = array_values( $files );

		foreach ( $files as $entry ) {
			$results[ $entry['key'] ] = false;
		}

		if ( 1 === $concurrency ) {
			foreach ( $files as $entry ) {
				$results[ $entry['key'] ] = $this->download_file( $entry['key'], $entry['dest'] );
			}

			return $results;
		}

		$client = $this->get_client();
		$bucket = $this->get_bucket();
		$tmps   = array();

		// Temp files are created as each download starts, so a worker killed
		// mid-restore leaves at most $concurrency of them behind.
		$commands = function () use ( $client, $bucket, $files, &$tmps ) {
			foreach ( $files as $index => $entry ) {
				$tmp = $this->temp_file_for( $entry['dest'] );

				if ( false === $tmp ) {
					continue;
				}

				$tmps[ $index ] = $tmp;

				yield $index => $client->getCommand(
					'GetObject',
					array(
						'Bucket' => $bucket,
						'Key'    => $entry['key'],
						'SaveAs' => $tmp,
						'@http'  => array(
							'connect_timeout' => static::CONNECT_TIMEOUT,
						),
					)
				);
			}
		};

		$done = array();

		try {
			$pool = new \Aws\CommandPool(
				$client,
				$commands(),
				array(
					'concurrency'            => $concurrency,
					// Skipped entries leave gaps, so keys must be the yielded ones.
					'preserve_iterator_keys' => true,
					'fulfilled'              => static function ( $result, $index ) use ( &$done ) {
						$done[ $index ] = true;
					},
					'rejected'               => function ( $reason, $index ) use ( $files ) {
						$message = $reason instanceof \Throwable ? $reason->getMessage() : 'unknown error';
						$this->log_error( sprintf( 'Error downloading "%s": %s', $files[ $index ]['key'], $message ) );
					},
				)
			);

			$pool->promise()->wait();
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Download batch failed: %s', $e->getMessage() ) );
		}

		// Rename only complete downloads; a failed one never reaches $dest.
		foreach ( $tmps as $index => $tmp ) {
			if ( isset( $done[ $index ] ) ) {
				$results[ $files[ $index ]['key'] ] = $this->move_into_place( $tmp, $files[ $index ]['dest'] );
			} else {
				wp_delete_file( $tmp );
			}
		}

		return $results;
	}

	/**
	 * Create the destination directory and a temp file inside it.
	 *
	 * The temp file lives next to $dest so the final rename() stays on one
	 * filesystem and is atomic.
	 *
	 * @since 0.1.3
	 *
	 * @param string $dest Absolute destination path.
	 * @return string|false Temp file path.
	 */
	protected function temp_file_for( $dest ) {
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

		return $tmp;
	}

	/**
	 * Atomically move a finished download to its destination.
	 *
	 * @since 0.1.3
	 *
	 * @param string $tmp  Temp file path.
	 * @param string $dest Absolute destination path.
	 * @return bool
	 */
	protected function move_into_place( $tmp, $dest ) {
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
	 * Whether several objects exist, checked up to $concurrency at a time.
	 *
	 * Not part of ProviderInterface — callers check method_exists() and fall
	 * back to object_exists().
	 *
	 * @since 0.1.3
	 *
	 * @param string[] $keys        Object keys.
	 * @param int      $concurrency Checks in flight at once.
	 * @return array<string, bool> Object key => exists.
	 */
	public function objects_exist( array $keys, $concurrency = 1 ) {
		$exists = array_fill_keys( $keys, false );
		$keys   = array_values( $keys );
		$client = $this->get_client();
		$bucket = $this->get_bucket();

		$commands = static function () use ( $client, $bucket, $keys ) {
			foreach ( $keys as $index => $key ) {
				yield $index => $client->getCommand(
					'HeadObject',
					array(
						'Bucket' => $bucket,
						'Key'    => $key,
						'@http'  => array(
							'connect_timeout' => static::CONNECT_TIMEOUT,
							'timeout'         => 30,
						),
					)
				);
			}
		};

		try {
			$pool = new \Aws\CommandPool(
				$client,
				$commands(),
				array(
					'concurrency'            => max( 1, (int) $concurrency ),
					'preserve_iterator_keys' => true,
					'fulfilled'              => static function ( $result, $index ) use ( &$exists, $keys ) {
						$exists[ $keys[ $index ] ] = true;
					},
				)
			);

			$pool->promise()->wait();
		} catch ( \Exception $e ) {
			// Anything not confirmed stays false: the attachment is not marked
			// offloaded and keeps its local files.
			$this->log_error( sprintf( 'Existence check failed: %s', $e->getMessage() ) );
		}

		return $exists;
	}

	/**
	 * Sizes of every object under a key prefix, in as few requests as possible.
	 *
	 * Lets a caller verify all of an attachment's files (main + every size)
	 * with one ListObjectsV2 call instead of one HEAD request per file. Not
	 * part of ProviderInterface, so third-party providers keep working —
	 * callers check method_exists() and fall back to object_size().
	 *
	 * @since 0.1.3
	 *
	 * @param string $prefix    Key prefix, e.g. "2024/05/photo".
	 * @param int    $max_pages Pages of 1,000 keys to read before giving up.
	 * @return array<string, int>|false Key => size map, or false on error or
	 *                                  when the prefix matches too many keys.
	 */
	public function list_object_sizes( $prefix, $max_pages = 2 ) {
		$sizes = array();
		$token = null;
		$page  = 0;

		try {
			do {
				$params = array(
					'Bucket' => $this->get_bucket(),
					'Prefix' => $prefix,
					'@http'  => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 30,
					),
				);

				if ( null !== $token ) {
					$params['ContinuationToken'] = $token;
				}

				$result = $this->get_client()->listObjectsV2( $params );

				foreach ( (array) ( $result['Contents'] ?? array() ) as $object ) {
					if ( is_array( $object ) && isset( $object['Key'], $object['Size'] ) ) {
						$sizes[ (string) $object['Key'] ] = (int) $object['Size'];
					}
				}

				$token = ! empty( $result['IsTruncated'] ) ? (string) ( $result['NextContinuationToken'] ?? '' ) : null;
				++$page;
			} while ( null !== $token && '' !== $token && $page < $max_pages );
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Error listing "%s": %s', $prefix, $e->getMessage() ) );

			return false;
		}

		// Still truncated: the prefix is too broad to be worth paging through.
		if ( null !== $token && '' !== $token ) {
			return false;
		}

		return $sizes;
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
		/**
		 * Filter whether provider errors are written to the PHP error log.
		 *
		 * Failures are always recorded on the attachment itself
		 * (omnioffloader_error_log); this only controls the server log,
		 * which gets one line per failed file — off unless WP_DEBUG.
		 *
		 * @since 0.1.3
		 *
		 * @param bool   $log     Default WP_DEBUG.
		 * @param string $message Error message (object keys, SDK text; never secrets).
		 */
		if ( ! apply_filters( 'omnioffloader_log_provider_errors', defined( 'WP_DEBUG' ) && WP_DEBUG, $message ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operational logging, opt-in via WP_DEBUG / filter.
		error_log( 'OmniOffloader [' . $this->get_key() . ']: ' . $message );
	}
}
