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
	 * Offload one attachment (main file, sizes, sources).
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata      In-flight metadata (see AttachmentHelper).
	 * @return bool True when the attachment ended up fully offloaded.
	 */
	public function upload( $attachment_id, $metadata = null ) {
		$attachment_id = (int) $attachment_id;

		if ( self::is_offloaded( $attachment_id ) ) {
			return true;
		}

		/**
		 * Filter whether an attachment should be offloaded.
		 *
		 * @param bool $should        Default true.
		 * @param int  $attachment_id Attachment ID.
		 */
		if ( ! apply_filters( 'omnioffloader_should_offload_attachment', true, $attachment_id ) ) {
			return false;
		}

		/**
		 * Fires before an attachment is offloaded.
		 *
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'omnioffloader_before_offload', $attachment_id );

		$files = self::collect_attachment_files( $attachment_id, $metadata );

		if ( empty( $files['main'] ) ) {
			self::log_attachment_error( $attachment_id, __( 'No attached file found.', 'omnioffloader' ) );

			return false;
		}

		$all_ok = true;

		foreach ( array_merge( $files['main'], $files['sizes'] ) as $entry ) {
			// Metadata can reference files that never existed on disk
			// (e.g. cleaned up by another plugin); skip those quietly.
			if ( ! file_exists( $entry['file'] ) ) {
				continue;
			}

			if ( false === $this->provider->upload_file( $entry['file'], $entry['key'] ) ) {
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
		$main = $files['main'][0];

		if ( ! $all_ok || ! file_exists( $main['file'] ) || ! $this->provider->object_exists( $main['key'] ) ) {
			if ( ! file_exists( $main['file'] ) ) {
				self::log_attachment_error( $attachment_id, __( 'Main file is missing locally.', 'omnioffloader' ) );
			}

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

		/**
		 * Fires after an attachment was fully offloaded.
		 *
		 * @param int    $attachment_id Attachment ID.
		 * @param string $subdir        Key prefix used in the bucket.
		 */
		do_action( 'omnioffloader_after_offload', $attachment_id, $subdir );

		// Apply the retention policy now that the upload is verified.
		LocalCleaner::clean( $attachment_id, $retention, $metadata );

		Helpers::flush_media_stats();

		return true;
	}
}
