<?php
/**
 * Shared implementation for S3-compatible providers.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Providers;

use OmniOffloader\Core\FolderClaim;
use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\SafeMode;

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
	 * Most parts of one large file uploaded at once, whatever the transfer
	 * concurrency: WP-CLI --concurrency can reach 64, and each part in
	 * flight holds a buffer and a connection.
	 */
	protected const MAX_PART_CONCURRENCY = 10;

	/**
	 * Seconds to wait for a connection before giving up.
	 *
	 * The SDK sets no timeout by default, so an unreachable endpoint can hold
	 * a PHP worker — and the queue's process lock — indefinitely.
	 */
	protected const CONNECT_TIMEOUT = 10;

	/**
	 * Seconds between transfer heartbeats (see heartbeat()).
	 */
	protected const HEARTBEAT_SECONDS = 30;

	/**
	 * When the last transfer heartbeat fired.
	 *
	 * Static: every provider instance in the request shares one clock.
	 *
	 * @var int
	 */
	private static $last_heartbeat = 0;

	/**
	 * Whether the last download_file() / download_files() call failed because
	 * the server refused to write to its disk (full, or storage quota used up).
	 *
	 * @var bool
	 */
	private $disk_write_failed = false;

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
	 * In-memory credential overrides (e.g. for testing unsaved drafts).
	 *
	 * @var array<string, string>
	 */
	protected $credential_overrides = array();

	/**
	 * Set temporary credential overrides for testing unsaved credentials.
	 *
	 * @param array<string, string> $overrides Map of field name => value.
	 * @return void
	 */
	public function set_credential_overrides( array $overrides ) {
		$this->credential_overrides = $overrides;
		$this->client               = null;
	}

	/**
	 * Resolve a credential for this provider (overrides win, constant wins over option).
	 *
	 * @param string $field Field name.
	 * @return string
	 */
	protected function credential( $field ) {
		if ( isset( $this->credential_overrides[ $field ] ) && '' !== $this->credential_overrides[ $field ] ) {
			return (string) $this->credential_overrides[ $field ];
		}

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
		// Last line of defence: a copy of the site never writes to the live
		// bucket, whichever code path asked (see Core\SafeMode).
		if ( SafeMode::is_active() ) {
			$this->log_error( sprintf( 'Safe mode: not uploading "%s".', $key ) );

			return false;
		}

		// Another site sharing the bucket owns this folder (see Core\FolderClaim).
		if ( FolderClaim::blocks( $this, array( $key ) ) ) {
			$this->log_error( sprintf( 'Folder used by another site: not uploading "%s".', $key ) );

			return false;
		}

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
					'@http'      => $this->transfer_http_options(),
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
		if ( SafeMode::is_active() ) {
			$this->log_error( 'Safe mode: not uploading.' );

			return array_fill_keys( wp_list_pluck( $files, 'key' ), false );
		}

		if ( FolderClaim::blocks( $this, wp_list_pluck( $files, 'key' ) ) ) {
			$this->log_error( 'Folder used by another site: not uploading.' );

			return array_fill_keys( wp_list_pluck( $files, 'key' ), false );
		}

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
		$http   = $this->transfer_http_options();

		$commands = static function () use ( $client, $bucket, $pooled, $http ) {
			foreach ( $pooled as $index => $entry ) {
				$params = array(
					'Bucket'     => $bucket,
					'Key'        => $entry['key'],
					'SourceFile' => $entry['file'],
					'@http'      => $http,
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
				'@http' => $this->transfer_http_options(),
			),
		);

		// Only send an ACL when one was asked for — R2 rejects the header.
		if ( '' !== $acl ) {
			$config['acl'] = $acl;
		}

		// The SDK uploads 5 parts at once by default; follow the site's
		// transfer speed setting instead so a low-resource host stays low.
		if ( $concurrency > 0 ) {
			$config['concurrency'] = min( static::MAX_PART_CONCURRENCY, (int) $concurrency );
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
	 * HTTP options for requests that move file bytes.
	 *
	 * The progress callback fires many times a second while bytes flow;
	 * heartbeat() turns that into a throttled action, so whoever holds a job
	 * lock can keep it alive through a single transfer longer than the lock.
	 *
	 * @since 0.1.3
	 *
	 * @return array<string, mixed>
	 */
	protected function transfer_http_options() {
		return array(
			'connect_timeout' => static::CONNECT_TIMEOUT,
			'progress'        => static function () {
				self::heartbeat();
			},
		);
	}

	/**
	 * Fire `omnioffloader_transfer_heartbeat` at most every HEARTBEAT_SECONDS.
	 *
	 * @since 0.1.3
	 *
	 * @return void
	 */
	public static function heartbeat() {
		$now = time();

		if ( $now - self::$last_heartbeat < static::HEARTBEAT_SECONDS ) {
			return;
		}

		self::$last_heartbeat = $now;

		/**
		 * Fires every HEARTBEAT_SECONDS while a file is being uploaded or
		 * downloaded, so a long transfer can renew the locks of the job
		 * running it.
		 *
		 * @since 0.1.3
		 */
		do_action( 'omnioffloader_transfer_heartbeat' );
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
		$this->start_download_call();

		return $this->download_one( $key, $dest );
	}

	/**
	 * Download one object, without resetting the disk-write flag.
	 *
	 * @since 0.1.3 Split out of download_file().
	 *
	 * @param string $key  Object key.
	 * @param string $dest Absolute destination path.
	 * @return bool
	 */
	private function download_one( $key, $dest ) {
		$tmp = $this->temp_file_for( $dest );

		if ( false === $tmp ) {
			$this->note_download_error( '' );

			return false;
		}

		try {
			$this->get_client()->getObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'SaveAs' => $tmp,
					'@http'  => $this->transfer_http_options(),
				)
			);
		} catch ( \Exception $e ) {
			$this->note_download_error( $e->getMessage() );
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
		$this->start_download_call();

		$results     = array();
		$concurrency = max( 1, (int) $concurrency );
		$files       = array_values( $files );

		foreach ( $files as $entry ) {
			$results[ $entry['key'] ] = false;
		}

		if ( 1 === $concurrency ) {
			foreach ( $files as $entry ) {
				$results[ $entry['key'] ] = $this->download_one( $entry['key'], $entry['dest'] );
			}

			return $results;
		}

		$client = $this->get_client();
		$bucket = $this->get_bucket();
		$tmps   = array();
		$http   = $this->transfer_http_options();

		// Temp files are created as each download starts, so a worker killed
		// mid-restore leaves at most $concurrency of them behind.
		$commands = function () use ( $client, $bucket, $files, $http, &$tmps ) {
			foreach ( $files as $index => $entry ) {
				$tmp = $this->temp_file_for( $entry['dest'] );

				if ( false === $tmp ) {
					$this->note_download_error( '' );
					continue;
				}

				$tmps[ $index ] = $tmp;

				yield $index => $client->getCommand(
					'GetObject',
					array(
						'Bucket' => $bucket,
						'Key'    => $entry['key'],
						'SaveAs' => $tmp,
						'@http'  => $http,
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
						$this->note_download_error( $message );
						$this->log_error( sprintf( 'Error downloading "%s": %s', $files[ $index ]['key'], $message ) );
					},
				)
			);

			$pool->promise()->wait();
		} catch ( \Exception $e ) {
			$this->note_download_error( $e->getMessage() );
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
	 * Whether the last download call failed because the server's disk
	 * refused the write — full, or the hosting plan's storage quota used up.
	 *
	 * PHP cannot see a per-account quota (cPanel, CloudLinux), so free space
	 * reported beforehand can be far too high; this is how a restore learns
	 * it has actually run out. Not part of ProviderInterface — callers check
	 * method_exists().
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	public function download_hit_disk_limit() {
		return $this->disk_write_failed;
	}

	/**
	 * Reset the disk-write flag for a new download call.
	 *
	 * @return void
	 */
	private function start_download_call() {
		$this->disk_write_failed = false;
		error_clear_last();
	}

	/**
	 * Record a download failure caused by the local disk refusing a write.
	 *
	 * The SDK's message rarely says why a write failed (curl reports only
	 * "error 23: Failed writing body"), but PHP's own warning does carry the
	 * system error ("Disk quota exceeded", "No space left on device").
	 *
	 * @param string $message Error message from the SDK, or '' when none.
	 * @return void
	 */
	private function note_download_error( $message ) {
		$last = error_get_last();

		if ( self::is_disk_write_error( (string) $message ) || ( is_array( $last ) && self::is_disk_write_error( (string) $last['message'] ) ) ) {
			$this->disk_write_failed = true;
		}
	}

	/**
	 * Whether an error message means a local write was refused for lack of space.
	 *
	 * @since 0.1.3
	 *
	 * @param string $message Error message.
	 * @return bool
	 */
	public static function is_disk_write_error( $message ) {
		$signs = array(
			'Disk quota exceeded',
			'No space left on device',
			'cURL error 23',
			'Failed writing body',
			'Failure writing output',
		);

		foreach ( $signs as $sign ) {
			if ( false !== stripos( $message, $sign ) ) {
				return true;
			}
		}

		return false;
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
		// A copy of the site never deletes the live site's files.
		if ( SafeMode::is_active() ) {
			$this->log_error( 'Safe mode: not deleting cloud objects.' );

			return false;
		}

		// The keys may now hold the other site's files.
		if ( FolderClaim::blocks( $this, $keys ) ) {
			$this->log_error( 'Folder used by another site: not deleting cloud objects.' );

			return false;
		}

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
	 * Copy objects inside the bucket, up to $concurrency at a time.
	 *
	 * The provider copies the bytes itself; nothing passes through this
	 * server. Used to move media into another folder of the same bucket.
	 * A single CopyObject is limited to 5 GB, so larger objects fail here
	 * and the caller falls back to downloading and uploading them.
	 *
	 * Not part of ProviderInterface — callers check method_exists().
	 *
	 * @since 0.1.4
	 *
	 * @param array<int, array{from: string, to: string}> $pairs       Source and destination keys.
	 * @param int                                         $concurrency Copies in flight at once.
	 * @return array<string, bool> Destination key => copied.
	 */
	public function copy_objects( array $pairs, $concurrency = 1 ) {
		$targets = wp_list_pluck( $pairs, 'to' );
		$copied  = array_fill_keys( $targets, false );

		// Same guards as an upload: a copy of the site, or a folder another
		// site owns, never gets written to.
		if ( SafeMode::is_active() ) {
			$this->log_error( 'Safe mode: not copying.' );

			return $copied;
		}

		if ( FolderClaim::blocks( $this, $targets ) ) {
			$this->log_error( 'Folder used by another site: not copying.' );

			return $copied;
		}

		$pairs  = array_values( $pairs );
		$client = $this->get_client();
		$bucket = (string) $this->get_bucket();

		$commands = static function () use ( $client, $bucket, $pairs ) {
			foreach ( $pairs as $index => $pair ) {
				$params = array(
					'Bucket'            => $bucket,
					'Key'               => $pair['to'],
					// Bucket/key, with the key URL-encoded but its slashes kept.
					'CopySource'        => $bucket . '/' . str_replace( '%2F', '/', rawurlencode( $pair['from'] ) ),
					'MetadataDirective' => 'COPY',
					'@http'             => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 300,
					),
				);

				/** This filter is documented in includes/Providers/AbstractS3Provider.php (upload_file). */
				$acl = apply_filters( 'omnioffloader_object_acl', '', '', $pair['to'] );

				if ( '' !== $acl ) {
					$params['ACL'] = $acl;
				}

				yield $index => $client->getCommand( 'CopyObject', $params );
			}
		};

		try {
			$pool = new \Aws\CommandPool(
				$client,
				$commands(),
				array(
					'concurrency'            => max( 1, (int) $concurrency ),
					'preserve_iterator_keys' => true,
					'fulfilled'              => static function ( $result, $index ) use ( &$copied, $pairs ) {
						$copied[ $pairs[ $index ]['to'] ] = true;
					},
					'rejected'               => function ( $reason, $index ) use ( $pairs ) {
						$this->log_error( sprintf( 'Copy failed for "%s": %s', $pairs[ $index ]['from'], $reason instanceof \Exception ? $reason->getMessage() : 'unknown error' ) );
					},
				)
			);

			$pool->promise()->wait();
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Copy failed: %s', $e->getMessage() ) );
		}

		return $copied;
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
	 * Read a small object's contents (the folder marker).
	 *
	 * Not part of ProviderInterface: FolderClaim checks method_exists().
	 *
	 * @since 0.1.3
	 *
	 * @param string $key Object key.
	 * @return string|null|\WP_Error Contents; null when the object does not exist.
	 */
	public function read_small_object( $key ) {
		try {
			$result = $this->get_client()->getObject(
				array(
					'Bucket' => $this->get_bucket(),
					'Key'    => $key,
					'@http'  => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 10,
					),
				)
			);

			return substr( (string) $result['Body'], 0, 4096 );
		} catch ( \Aws\S3\Exception\S3Exception $e ) {
			if ( 404 === $e->getStatusCode() || 'NoSuchKey' === $e->getAwsErrorCode() ) {
				return null;
			}

			$this->log_error( sprintf( 'Error reading "%s": %s', $key, $e->getMessage() ) );

			return new \WP_Error( 'omnioffloader_read_failed', $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Error reading "%s": %s', $key, $e->getMessage() ) );

			return new \WP_Error( 'omnioffloader_read_failed', $e->getMessage() );
		}
	}

	/**
	 * Write a small object (the folder marker). Never public, never cached.
	 *
	 * Not part of ProviderInterface: FolderClaim checks method_exists().
	 *
	 * @since 0.1.3
	 *
	 * @param string $key  Object key.
	 * @param string $body Contents.
	 * @return bool
	 */
	public function write_small_object( $key, $body ) {
		// A copy of the site never writes to the live bucket. A folder
		// conflict does not stop this: taking a folder over writes here.
		if ( SafeMode::is_copy() ) {
			return false;
		}

		try {
			$this->get_client()->putObject(
				array(
					'Bucket'       => $this->get_bucket(),
					'Key'          => $key,
					'Body'         => $body,
					'ContentType'  => 'application/json',
					'CacheControl' => 'no-store',
					'@http'        => array(
						'connect_timeout' => static::CONNECT_TIMEOUT,
						'timeout'         => 10,
					),
				)
			);

			return true;
		} catch ( \Exception $e ) {
			$this->log_error( sprintf( 'Error writing "%s": %s', $key, $e->getMessage() ) );

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
