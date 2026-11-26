<?php
/**
 * Applies a retention policy to media that is already offloaded.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Services;

use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Traits\AttachmentHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RetentionApplier
 *
 * The retention policy is applied once, right after an upload is verified
 * (see LocalCleaner). Changing the setting later therefore only affects new
 * uploads; this class brings existing offloaded attachments in line with it.
 *
 * Each attachment records the policy it currently follows in
 * `omnioffloader_retention` (missing = 0). Moving to a stricter policy
 * deletes local files; moving to a looser one downloads them again (see
 * AttachmentRestorer::restore_local_copies()).
 *
 * Deleting is stricter than the offload path's single main-key check: every
 * file is verified against the bucket on its own, by size, before its local
 * copy goes. Thumbnails regenerated after the offload, or whose upload failed
 * quietly, are uploaded first. The stored policy only advances once every
 * file was handled, so a partial failure is retried on the next run.
 *
 * @since 0.1.3
 */
class RetentionApplier {

	use AttachmentHelper;

	/**
	 * Providers resolved so far, by key — one client per provider per run.
	 *
	 * @var array<string, ProviderInterface>
	 */
	private $providers = array();

	/**
	 * Bytes deleted from local disk by the last apply() call.
	 *
	 * @var int
	 */
	private $freed_bytes = 0;

	/**
	 * Whether the last apply() failed because the server ran out of disk
	 * space or storage quota while downloading.
	 *
	 * @var bool
	 */
	private $out_of_disk = false;

	/**
	 * Files uploaded by the last apply() call because the cloud lacked them.
	 *
	 * @var int
	 */
	private $repaired = 0;

	/**
	 * Bucket listings fetched ahead by prefetch(), by attachment ID.
	 *
	 * @var array<int, array<string, int>>
	 */
	private $prefetched = array();

	/**
	 * The policy an offloaded attachment currently follows.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int 0, 1 or 2. Attachments offloaded before the meta existed count as 0.
	 */
	public static function stored_policy( $attachment_id ) {
		$value = get_post_meta( $attachment_id, 'omnioffloader_retention', true );

		return '' === $value ? 0 : max( 0, min( 2, (int) $value ) );
	}

	/**
	 * Which file roles a policy keeps on the server.
	 *
	 * @param int $policy Retention policy.
	 * @return array{main: bool, sizes: bool}
	 */
	public static function local_roles( $policy ) {
		return array(
			'main'  => (int) $policy < 2,
			'sizes' => 0 === (int) $policy,
		);
	}

	/**
	 * Offloaded attachments whose stored policy differs from $policy.
	 *
	 * Covers both directions: attachments keeping more local files than
	 * $policy allows (cleaned up) and attachments missing files it keeps
	 * (downloaded again). apply() decides per attachment.
	 *
	 * @param int $policy Target retention policy.
	 * @param int $limit  Maximum IDs to return, 0 for all.
	 * @return int[]
	 */
	public static function eligible_ids( $policy, $limit = 0 ) {
		global $wpdb;

		$policy = max( 0, min( 2, (int) $policy ) );

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'omnioffloader_offloaded' AND m.meta_value = '1'
			 LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = 'omnioffloader_retention'
			 WHERE p.post_type = 'attachment'
			 AND CAST( COALESCE( NULLIF( r.meta_value, '' ), '0' ) AS UNSIGNED ) <> %d
			 ORDER BY p.ID ASC";

		if ( $limit > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- queue population query; the LIMIT placeholder is appended here.
			$ids = $wpdb->get_col( $wpdb->prepare( $sql . ' LIMIT %d', $policy, (int) $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- queue population query, prepared here.
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, $policy ) );
		}

		$ids = array_map( 'intval', $ids );

		// Attachments excluded by the filter would otherwise count as
		// "needs updating" forever. Only paid for when a filter is attached.
		if ( has_filter( 'omnioffloader_should_apply_retention' ) ) {
			$ids = array_values(
				array_filter(
					$ids,
					static function ( $id ) use ( $policy ) {
						/** This filter is documented in includes/Services/RetentionApplier.php */
						return (bool) apply_filters( 'omnioffloader_should_apply_retention', true, $id, $policy );
					}
				)
			);
		}

		return $ids;
	}

	/**
	 * How many attachments a run should prefetch and apply together.
	 *
	 * Follows the Transfer Speed setting (or WP-CLI --concurrency): that
	 * many bucket listings are in flight at once.
	 *
	 * @since 0.1.4
	 *
	 * @return int
	 */
	public static function group_size() {
		return AttachmentUploader::files_at_once();
	}

	/**
	 * Whether apply() would download files for an attachment (a looser
	 * policy) rather than check and delete them.
	 *
	 * @since 0.1.4
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $policy        Target retention policy.
	 * @return bool
	 */
	public static function downloads( $attachment_id, $policy ) {
		return (int) $policy < self::stored_policy( $attachment_id );
	}

	/**
	 * List the bucket for several attachments at once, ahead of apply().
	 *
	 * Moving to a stricter policy verifies each attachment's files with one
	 * listing; run one after another, a large library spends nearly all its
	 * time waiting on those round trips. This sends a group's listings
	 * together (see AbstractS3Provider::list_object_sizes_many()), and
	 * apply() uses the result. Purely an optimisation: an attachment not
	 * prefetched, or whose listing failed, is checked as before, and every
	 * file is still verified by size before its local copy is deleted.
	 *
	 * @since 0.1.4
	 *
	 * @param int[] $ids    Attachment IDs about to be applied.
	 * @param int   $policy Target retention policy.
	 * @return void
	 */
	public function prefetch( array $ids, $policy ) {
		$this->prefetched = array();
		$policy           = max( 0, min( 2, (int) $policy ) );
		$wanted           = array();

		if ( count( $ids ) < 2 ) {
			return;
		}

		// One query each for the group's posts and meta; apply() then reads
		// them from the cache. Callers drop each post's cache once applied.
		$ids = array_map( 'intval', $ids );
		_prime_post_caches( $ids, false, false );
		update_meta_cache( 'post', $ids );

		foreach ( $ids as $id ) {

			if ( ! self::is_offloaded( $id ) || self::stored_policy( $id ) >= $policy ) {
				continue;
			}

			$prefix = self::size_listing_prefix( wp_list_pluck( self::files_to_delete( $id, $policy ), 'key' ) );

			if ( '' === $prefix ) {
				continue;
			}

			$provider_key = (string) get_post_meta( $id, 'omnioffloader_provider', true );

			if ( '' !== $provider_key ) {
				$wanted[ $provider_key ][ $id ] = $prefix;
			}
		}

		foreach ( $wanted as $provider_key => $prefixes ) {
			$provider = $this->resolve_provider( (int) array_key_first( $prefixes ) );

			if ( null === $provider || ! method_exists( $provider, 'list_object_sizes_many' ) ) {
				continue;
			}

			$listed = $provider->list_object_sizes_many( array_values( $prefixes ), self::group_size() );

			foreach ( $prefixes as $listed_id => $prefix ) {
				if ( is_array( $listed[ $prefix ] ?? null ) ) {
					$this->prefetched[ $listed_id ] = $listed[ $prefix ];
				}
			}
		}
	}

	/**
	 * Bring one offloaded attachment in line with a retention policy.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $policy        Target retention policy.
	 * @return bool True when the attachment now follows $policy (or needs nothing).
	 */
	public function apply( $attachment_id, $policy ) {
		$attachment_id     = (int) $attachment_id;
		$policy            = max( 0, min( 2, (int) $policy ) );
		$this->freed_bytes = 0;
		$this->repaired    = 0;
		$this->out_of_disk = false;

		// Restored (Bring Back) since it was queued: nothing left to apply.
		if ( ! self::is_offloaded( $attachment_id ) ) {
			return true;
		}

		/**
		 * Filter whether a retention change applies to an attachment.
		 *
		 * Return false to leave an attachment's local files untouched, e.g.
		 * downloadable products or PDFs another plugin reads from disk.
		 *
		 * @since 0.1.3
		 *
		 * @param bool $apply         Default true.
		 * @param int  $attachment_id Attachment ID.
		 * @param int  $policy        Target retention policy.
		 */
		if ( ! apply_filters( 'omnioffloader_should_apply_retention', true, $attachment_id, $policy ) ) {
			return true;
		}

		$stored = self::stored_policy( $attachment_id );

		if ( $stored === $policy ) {
			return true;
		}

		if ( $policy < $stored ) {
			$restorer          = new AttachmentRestorer();
			$ok                = $restorer->restore_local_copies( $attachment_id, $policy );
			$this->out_of_disk = $restorer->ran_out_of_disk();

			return $ok;
		}

		return $this->clean( $attachment_id, $policy );
	}

	/**
	 * Local files that apply() would delete, without touching the network.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $policy        Target retention policy.
	 * @return array<int, array{file: string, key: string}>
	 */
	public static function files_to_delete( $attachment_id, $policy ) {
		$files   = self::collect_attachment_files( $attachment_id );
		$targets = 2 === (int) $policy ? array_merge( $files['main'], $files['sizes'] ) : $files['sizes'];

		return array_values(
			array_filter(
				$targets,
				static function ( $entry ) {
					return file_exists( $entry['file'] );
				}
			)
		);
	}

	/**
	 * Whether the last apply() failed because the server ran out of disk
	 * space or storage quota while downloading.
	 *
	 * @since 0.1.3
	 *
	 * @return bool
	 */
	public function ran_out_of_disk() {
		return $this->out_of_disk;
	}

	/**
	 * Bytes deleted by the last apply() call.
	 *
	 * @return int
	 */
	public function get_freed_bytes() {
		return $this->freed_bytes;
	}

	/**
	 * Files uploaded by the last apply() call to fill gaps in the bucket.
	 *
	 * @return int
	 */
	public function get_repaired() {
		return $this->repaired;
	}

	/**
	 * Delete the local files a stricter policy no longer keeps.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $policy        Target retention policy (1 or 2).
	 * @return bool
	 */
	private function clean( $attachment_id, $policy ) {
		$targets = self::files_to_delete( $attachment_id, $policy );

		if ( empty( $targets ) ) {
			update_post_meta( $attachment_id, 'omnioffloader_retention', $policy );

			return true;
		}

		$provider = $this->resolve_provider( $attachment_id );

		if ( null === $provider ) {
			return false;
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- a missing file may need uploading first.
			@set_time_limit( 0 );
		}

		// Listed ahead with the rest of its group (prefetch()), else now.
		$remote = $this->prefetched[ $attachment_id ] ?? self::remote_sizes( $provider, wp_list_pluck( $targets, 'key' ) );
		unset( $this->prefetched[ $attachment_id ] );
		$all_ok = true;

		foreach ( $targets as $entry ) {
			$file = $entry['file'];
			$key  = $entry['key'];

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- false is handled.
			$local_size = @filesize( $file );

			if ( false === $local_size ) {
				continue;
			}

			$remote_size = $remote[ $key ] ?? $provider->object_size( $key );

			// Missing from the bucket, or the cloud copy differs (typically an
			// image optimizer shrank the local file after the offload). The
			// local file is the current version: upload it, then verify again
			// before the local copy may go.
			if ( false === $remote_size || (int) $remote_size !== (int) $local_size ) {
				$remote_size = false;

				if ( false !== $provider->upload_file( $file, $key ) ) {
					$remote_size = $provider->object_size( $key );

					if ( false !== $remote_size ) {
						++$this->repaired;
					}
				}
			}

			if ( false === $remote_size || (int) $remote_size !== (int) $local_size ) {
				$all_ok = false;
				self::log_attachment_error(
					$attachment_id,
					sprintf(
						/* translators: %s: file name. */
						__( 'Kept "%s" on the server: it could not be uploaded to cloud storage and verified.', 'omnioffloader' ),
						wp_basename( $file )
					)
				);
				continue;
			}

			// Verified in the bucket, byte count for byte count (invariant #1).
			// Refuses anything that resolves outside the uploads directory.
			wp_delete_file_from_directory( $file, wp_get_upload_dir()['basedir'] );
			clearstatcache( true, $file );

			if ( file_exists( $file ) ) {
				$all_ok = false;
				self::log_attachment_error(
					$attachment_id,
					sprintf(
						/* translators: %s: file name. */
						__( 'Could not delete "%s" from the server.', 'omnioffloader' ),
						wp_basename( $file )
					)
				);
				continue;
			}

			$this->freed_bytes += (int) $local_size;
		}

		if ( ! $all_ok ) {
			// Stored policy stays put, so the next run picks this item up again.
			return false;
		}

		update_post_meta( $attachment_id, 'omnioffloader_retention', $policy );

		return true;
	}

	/**
	 * The provider an attachment was offloaded to.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return ProviderInterface|null
	 */
	private function resolve_provider( $attachment_id ) {
		$provider_key = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );

		if ( '' === $provider_key ) {
			self::log_attachment_error( $attachment_id, __( 'No provider recorded for this attachment.', 'omnioffloader' ) );

			return null;
		}

		if ( isset( $this->providers[ $provider_key ] ) ) {
			return $this->providers[ $provider_key ];
		}

		try {
			$this->providers[ $provider_key ] = ProviderRegistry::create( $provider_key );
		} catch ( \InvalidArgumentException $e ) {
			self::log_attachment_error(
				$attachment_id,
				sprintf(
					/* translators: %s: provider key. */
					__( 'Provider "%s" is not available.', 'omnioffloader' ),
					$provider_key
				)
			);

			return null;
		}

		return $this->providers[ $provider_key ];
	}
}
