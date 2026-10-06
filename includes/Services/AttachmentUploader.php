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
	 * Pages of 1,000 keys read when listing one upload folder in the bucket.
	 * A bigger folder falls back to one small listing per attachment.
	 */
	private const LINK_LIST_PAGES = 200;

	/**
	 * Folder listings kept in memory at once (see existing_sizes()).
	 */
	private const LINK_LISTINGS_KEPT = 2;

	/**
	 * Whether files already in the bucket with the same size are linked
	 * instead of uploaded again (see link_existing()).
	 *
	 * @var bool
	 */
	private $link_existing = false;

	/**
	 * Recent folder listings: key prefix => (object key => size), or false
	 * when the folder could not be listed whole.
	 *
	 * @var array<string, array<string, int>|false>
	 */
	private $listings = array();

	/**
	 * Files linked and uploaded since this uploader was created.
	 *
	 * @var array{linked: int, uploaded: int}
	 */
	private $file_counts = array(
		'linked'   => 0,
		'uploaded' => 0,
	);

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
	 * Link files that are already in the bucket instead of uploading them.
	 *
	 * For media copied to the bucket with another tool first (rclone, the
	 * AWS CLI…). A file counts as already there when an object with its
	 * key exists and has exactly its size; anything else is uploaded as
	 * usual. The attachment is still only marked offloaded once every file
	 * is in the bucket and its main object is confirmed.
	 *
	 * @since 0.1.4
	 *
	 * @param bool $enable Whether to link existing objects.
	 * @return void
	 */
	public function link_existing( $enable = true ) {
		$this->link_existing = (bool) $enable;
	}

	/**
	 * Files linked and uploaded so far.
	 *
	 * @since 0.1.4
	 *
	 * @return array{linked: int, uploaded: int}
	 */
	public function file_counts() {
		return $this->file_counts;
	}

	/**
	 * What linking would do for some attachments, without changing anything.
	 *
	 * For a dry run: reads the bucket, writes nothing.
	 *
	 * @since 0.1.4
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array<int, array{files: int, present: int, upload_bytes: int}> Local files, files already in the bucket, bytes still to upload.
	 */
	public function link_preview( array $ids ) {
		$report = array();

		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$files         = self::collect_attachment_files( $attachment_id );

			if ( empty( $files['main'] ) ) {
				$report[ $attachment_id ] = array(
					'files'        => 0,
					'present'      => 0,
					'upload_bytes' => 0,
				);
				continue;
			}

			$job = array(
				'main'   => $files['main'][0],
				'upload' => self::local_entries( $files ),
			);

			$present = $this->find_existing( array( $job ) );
			$bytes   = 0;

			foreach ( $job['upload'] as $entry ) {
				if ( empty( $present[ $entry['key'] ] ) ) {
					$bytes += self::local_size( $entry['file'] );
				}
			}

			$report[ $attachment_id ] = array(
				'files'        => count( $job['upload'] ),
				'present'      => count( $present ),
				'upload_bytes' => $bytes,
			);
		}

		return $report;
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
		}

		if ( empty( $jobs ) ) {
			return $results;
		}

		// Files already in the bucket with the same size: nothing to send.
		$present = $this->link_existing ? $this->find_existing( $jobs ) : array();

		foreach ( $jobs as $job ) {
			foreach ( $job['upload'] as $entry ) {
				if ( empty( $present[ $entry['key'] ] ) ) {
					$files[] = $entry;
				}
			}
		}

		$uploaded = empty( $files ) ? array() : $this->upload_all( $files );

		$this->file_counts['linked']   += count( $present );
		$this->file_counts['uploaded'] += count( array_filter( $uploaded ) );

		$uploaded = $present + $uploaded;

		// Confirm the main object of every attachment whose files all made
		// it. A main object just seen in the bucket listing needs no check.
		$verify = array();

		foreach ( $jobs as $job ) {
			if ( $this->all_uploaded( $job, $uploaded ) && file_exists( $job['main']['file'] ) && empty( $present[ $job['main']['key'] ] ) ) {
				$verify[] = $job['main']['key'];
			}
		}

		$confirmed = $present + $this->confirm_all( $verify );

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

		return array(
			'main'   => $files['main'][0],
			'upload' => self::local_entries( $files ),
		);
	}

	/**
	 * An attachment's files that exist on disk.
	 *
	 * Metadata can reference files that never existed (e.g. cleaned up by
	 * another plugin); those are skipped quietly.
	 *
	 * @since 0.1.4 Split out of prepare().
	 *
	 * @param array{main: array<int, array{file: string, key: string}>, sizes: array<int, array{file: string, key: string}>} $files Collected files.
	 * @return array<int, array{file: string, key: string}>
	 */
	private static function local_entries( array $files ) {
		return array_values(
			array_filter(
				array_merge( $files['main'], $files['sizes'] ),
				static function ( $entry ) {
					return file_exists( $entry['file'] );
				}
			)
		);
	}

	/**
	 * Size of a local file in bytes, 0 when it cannot be read.
	 *
	 * @param string $file Absolute path.
	 * @return int
	 */
	private static function local_size( $file ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished file simply has no size.
		$size = @filesize( $file );

		return false === $size ? 0 : (int) $size;
	}

	/**
	 * Which of some prepared attachments' files are already in the bucket
	 * with the same size as on disk.
	 *
	 * @since 0.1.4
	 *
	 * @param array<int, array{main: array{file: string, key: string}, upload: array<int, array{file: string, key: string}>}> $jobs Prepared attachments.
	 * @return array<string, bool> Object key => true, for every file present.
	 */
	private function find_existing( array $jobs ) {
		$present = array();

		foreach ( $jobs as $job ) {
			$sizes = $this->existing_sizes( $job );

			foreach ( $job['upload'] as $entry ) {
				$key = $entry['key'];

				if ( isset( $sizes[ $key ] ) && self::local_size( $entry['file'] ) === $sizes[ $key ] ) {
					$present[ $key ] = true;
				}
			}
		}

		return $present;
	}

	/**
	 * Sizes of the objects that may belong to one attachment.
	 *
	 * Lists the attachment's whole folder (e.g. "2024/05/") once and reuses
	 * it for the next attachments of that month, so a library costs about one
	 * request per 1,000 files. A folder too big to list falls back to one
	 * listing per attachment, then to one size lookup per file.
	 *
	 * @since 0.1.4
	 *
	 * @param array{main: array{file: string, key: string}, upload: array<int, array{file: string, key: string}>} $job Prepared attachment.
	 * @return array<string, int> Object key => size.
	 */
	private function existing_sizes( array $job ) {
		$main  = $job['main']['key'];
		$slash = strrpos( $main, '/' );
		$dir   = false === $slash ? '' : substr( $main, 0, $slash + 1 );

		// Optional provider method (see AbstractS3Provider::list_object_sizes()).
		if ( method_exists( $this->provider, 'list_object_sizes' ) ) {
			if ( ! array_key_exists( $dir, $this->listings ) ) {
				// Attachments arrive folder by folder; keep memory flat.
				$this->listings = array_slice( $this->listings, 1 - self::LINK_LISTINGS_KEPT, null, true );

				$this->listings[ $dir ] = $this->provider->list_object_sizes( $dir, self::LINK_LIST_PAGES );
			}

			if ( is_array( $this->listings[ $dir ] ) ) {
				return $this->listings[ $dir ];
			}
		}

		$keys  = wp_list_pluck( $job['upload'], 'key' );
		$sizes = self::remote_sizes( $this->provider, $keys );

		if ( ! empty( $sizes ) ) {
			return $sizes;
		}

		foreach ( $keys as $key ) {
			$size = $this->provider->object_size( $key );

			if ( false !== $size ) {
				$sizes[ $key ] = (int) $size;
			}
		}

		return $sizes;
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
