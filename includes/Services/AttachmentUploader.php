<?php
/**
 * Offloads a single attachment to the cloud.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Traits\AttachmentHelper;
use OmniOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AttachmentUploader
 *
 * Safety invariant: the attachment is only marked offloaded — and local
 * files are only cleaned up — after every file uploaded successfully and
 * the main object is verified to exist in the bucket.
 */
class AttachmentUploader {

	use AttachmentHelper;
	use OptionsHelper;

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	/**
	 * How many attachments a bulk run offloads together (see upload_many()).
	 *
	 * One at a time on the Low transfer speed, so the lightest setting
	 * stays exactly that.
	 *
	 * @since 0.1.3
	 *
	 * @return int<1, 3>
	 */
	public static function batch_size() {
		return self::files_at_once() > 1 ? 3 : 1;
	}

	/**
	 * Whether any of an attachment's local files needs a multipart upload.
	 *
	 * Such an attachment can take long enough for the host to kill the
	 * worker, so bulk runs give it a batch of its own.
	 *
	 * @since 0.1.3
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function has_large_file( $attachment_id ) {
		// Optional provider method (see AbstractS3Provider::is_large_file()).
		if ( ! method_exists( $this->provider, 'is_large_file' ) ) {
			return false;
		}

		$files = self::collect_attachment_files( (int) $attachment_id );

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			if ( $this->provider->is_large_file( $entry['file'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Offload one attachment (main file, sizes, sources).
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata      In-flight metadata (see AttachmentHelper).
	 * @return bool True when the attachment ended up fully offloaded.
	 */
	public function upload( $attachment_id, $metadata = null ) {
		$attachment_id = (int) $attachment_id;
		$results       = $this->upload_many( array( $attachment_id ), array( $attachment_id => $metadata ) );

		return $results[ $attachment_id ] ?? false;
	}

	/**
	 * Offload several attachments through one shared transfer pool.
	 *
	 * An attachment's files rarely fill every upload slot, and each one ends
	 * with a round trip to confirm its main file. Pooling a few attachments
	 * keeps the slots busy and confirms all their main files at once. Each
	 * attachment is still judged on its own: it is only marked offloaded —
	 * and its local files only cleaned up — once every one of its files
	 * uploaded and its main object is confirmed in the bucket.
	 *
	 * @since 0.1.3
	 *
	 * @since 0.1.3 A filtered-out attachment maps to null (skipped), not false.
	 *
	 * @param int[]                                 $ids      Attachment IDs.
	 * @param array<int, array<string, mixed>|null> $metadata In-flight metadata by ID (see AttachmentHelper).
	 * @return array<int, bool|null> Attachment ID => fully offloaded; null when
	 *                               the should-offload filter skipped it.
	 */
	public function upload_many( array $ids, array $metadata = array() ) {
		$results = array();
		$jobs    = array();
		$files   = array();
		$raised  = false;

		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;

			if ( self::is_offloaded( $attachment_id ) ) {
				$results[ $attachment_id ] = true;
				continue;
			}

			if ( ! $raised ) {
				wp_raise_memory_limit( 'admin' );
				if ( function_exists( 'set_time_limit' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- safe timeout extension for large file transfers.
					@set_time_limit( 0 );
				}
				$raised = true;
			}

			$job = $this->prepare( $attachment_id, $metadata[ $attachment_id ] ?? null );

			if ( false === $job ) {
				$results[ $attachment_id ] = null;
				continue;
			}

			if ( null === $job ) {
				$results[ $attachment_id ] = false;
				continue;
			}

			$jobs[ $attachment_id ] = $job;
			$files                  = array_merge( $files, $job['upload'] );
		}

		if ( empty( $jobs ) ) {
			return $results;
		}

		$uploaded = empty( $files ) ? array() : $this->upload_all( $files );

		// Confirm the main object of every attachment whose files all made it.
		$verify = array();

		foreach ( $jobs as $job ) {
			if ( $this->all_uploaded( $job, $uploaded ) && file_exists( $job['main']['file'] ) ) {
				$verify[] = $job['main']['key'];
			}
		}

		$confirmed = $this->confirm_all( $verify );

		foreach ( $jobs as $attachment_id => $job ) {
			$results[ $attachment_id ] = $this->finish( $attachment_id, $job, $uploaded, $confirmed, $metadata[ $attachment_id ] ?? null );
		}

		return $results;
	}

	/**
	 * Everything that happens before an attachment's files are uploaded.
	 *
	 * @since 0.1.3 Split out of upload().
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata      In-flight metadata.
	 * @return array{main: array{file: string, key: string}, upload: array<int, array{file: string, key: string}>}|false|null
	 *         False when a filter skipped the attachment, null when it cannot be offloaded.
	 */
	private function prepare( $attachment_id, $metadata ) {
		/**
		 * Filter whether an attachment should be offloaded.
		 *
		 * @param bool $should        Default true.
		 * @param int  $attachment_id Attachment ID.
		 */
		if ( ! apply_filters( 'omnioffloader_should_offload_attachment', true, $attachment_id ) ) {
			// Queued by a bulk run or the Media Library, but never processed:
			// without this its badge would show "Queued" forever.
			if ( 'queued' === get_post_meta( $attachment_id, 'omnioffloader_status', true ) ) {
				delete_post_meta( $attachment_id, 'omnioffloader_status' );
			}

			return false;
		}

		/**
		 * Fires before an attachment is offloaded.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'omnioffloader_before_offload', $attachment_id );

		update_post_meta( $attachment_id, 'omnioffloader_status', 'processing' );

		$files = self::collect_attachment_files( $attachment_id, $metadata );

		if ( empty( $files['main'] ) ) {
			self::log_attachment_error( $attachment_id, __( 'No attached file found.', 'omnioffloader' ) );
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );

			return null;
		}

		// Metadata can reference files that never existed on disk (e.g.
		// cleaned up by another plugin); skip those quietly.
		$upload = array_values(
			array_filter(
				array_merge( $files['main'], $files['sizes'] ),
				static function ( $entry ) {
					return file_exists( $entry['file'] );
				}
			)
		);

		return array(
			'main'   => $files['main'][0],
			'upload' => $upload,
		);
	}

	/**
	 * Whether every file of a prepared attachment uploaded.
	 *
	 * @param array{main: array{file: string, key: string}, upload: array<int, array{file: string, key: string}>} $job      Prepared attachment.
	 * @param array<string, bool>                                                                                 $uploaded Object key => uploaded.
	 * @return bool
	 */
	private function all_uploaded( array $job, array $uploaded ) {
		foreach ( $job['upload'] as $entry ) {
			if ( empty( $uploaded[ $entry['key'] ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Record the outcome of one attachment once its transfers are done.
	 *
	 * @since 0.1.3 Split out of upload().
	 *
	 * @param int                                                                                                 $attachment_id Attachment ID.
	 * @param array{main: array{file: string, key: string}, upload: array<int, array{file: string, key: string}>} $job           Prepared attachment.
	 * @param array<string, bool>                                                                                 $uploaded      Object key => uploaded.
	 * @param array<string, bool>                                                                                 $confirmed     Main object key => found in the bucket.
	 * @param array<string, mixed>|null                                                                           $metadata      In-flight metadata.
	 * @return bool
	 */
	private function finish( $attachment_id, array $job, array $uploaded, array $confirmed, $metadata ) {
		$all_ok = true;

		foreach ( $job['upload'] as $entry ) {
			if ( empty( $uploaded[ $entry['key'] ] ) ) {
				$all_ok = false;
				self::log_attachment_error(
					$attachment_id,
					sprintf(
						/* translators: %s: object key. */
						__( 'Upload failed for "%s".', 'omnioffloader' ),
						$entry['key']
					)
				);
			}
		}

		// The main file must exist locally and in the bucket.
		$main = $job['main'];

		if ( ! $all_ok || ! file_exists( $main['file'] ) || empty( $confirmed[ $main['key'] ] ) ) {
			if ( ! file_exists( $main['file'] ) ) {
				self::log_attachment_error( $attachment_id, __( 'Main file is missing locally.', 'omnioffloader' ) );
			}
			update_post_meta( $attachment_id, 'omnioffloader_status', 'failed' );

			return false;
		}

		$settings  = self::get_settings();
		$retention = (int) $settings['retention_policy'];
		$subdir    = self::build_subdir( $attachment_id );

		self::write_offload_meta(
			$attachment_id,
			$this->provider->get_key(),
			(string) $this->provider->get_bucket(),
			$subdir,
			$retention
		);

		// Apply the retention policy now that the upload is verified.
		LocalCleaner::clean( $attachment_id, $retention, $metadata );

		Helpers::flush_media_stats();

		/**
		 * Fires after an attachment was offloaded and verified in the bucket.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'omnioffloader_after_offload', $attachment_id );

		return true;
	}

	/**
	 * Confirm objects exist in the bucket, several at once when possible.
	 *
	 * @since 0.1.3
	 *
	 * @param string[] $keys Object keys.
	 * @return array<string, bool> Object key => exists.
	 */
	private function confirm_all( array $keys ) {
		if ( empty( $keys ) ) {
			return array();
		}

		// Optional provider method (see AbstractS3Provider::objects_exist()).
		if ( count( $keys ) > 1 && method_exists( $this->provider, 'objects_exist' ) ) {
			return $this->provider->objects_exist( $keys, self::files_at_once() );
		}

		$confirmed = array();

		foreach ( $keys as $key ) {
			$confirmed[ $key ] = $this->provider->object_exists( $key );
		}

		return $confirmed;
	}

	/**
	 * Upload an attachment's files, several at once when the provider can.
	 *
	 * @since 0.1.3
	 *
	 * @param array<int, array{file: string, key: string}> $files Files to upload.
	 * @return array<string, bool> Object key => whether it uploaded.
	 */
	private function upload_all( array $files ) {
		$concurrency = self::files_at_once();

		// Optional provider method (see AbstractS3Provider::upload_files()).
		// Used at every speed so "low" also caps multipart parts at one.
		if ( method_exists( $this->provider, 'upload_files' ) ) {
			return $this->provider->upload_files( $files, $concurrency );
		}

		$results = array();

		foreach ( $files as $entry ) {
			$results[ $entry['key'] ] = false !== $this->provider->upload_file( $entry['file'], $entry['key'] );
		}

		return $results;
	}
}
