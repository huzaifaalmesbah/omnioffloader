<?php
/**
 * Applies the retention policy after a verified offload.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LocalCleaner
 *
 * Policies:
 *  0 — Retain Local Files: delete nothing.
 *  1 — Smart Local Cleanup: delete generated size files, keep originals.
 *  2 — Full Cloud Migration: delete every local copy.
 *
 * Never called before the offload is verified (see AttachmentUploader).
 */
class LocalCleaner {

	use AttachmentHelper;

	/**
	 * Apply a retention policy to an offloaded attachment.
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param int                       $policy        Retention policy (0|1|2).
	 * @param array<string, mixed>|null $metadata      In-flight metadata (see AttachmentHelper).
	 * @return void
	 */
	public static function clean( $attachment_id, $policy, $metadata = null ) {
		$policy = (int) $policy;

		if ( 0 === $policy ) {
			return;
		}

		// Safety: only clean local files of verified-offloaded attachments.
		if ( ! self::is_offloaded( $attachment_id ) ) {
			return;
		}

		$files = self::collect_attachment_files( $attachment_id, $metadata );

		$to_delete = $files['sizes'];

		if ( 2 === $policy ) {
			$to_delete = array_merge( $files['main'], $files['sizes'] );
		}

		foreach ( $to_delete as $entry ) {
			if ( file_exists( $entry['file'] ) ) {
				wp_delete_file( $entry['file'] );
			}
		}
	}
}
