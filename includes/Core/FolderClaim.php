<?php
/**
 * Folder claim: keeps two sites that share one bucket out of each other's files.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FolderClaim
 *
 * Separate WordPress sites (a main domain and its subdomains, say) can share
 * one bucket and one CDN address, each under its own path prefix. The sites
 * share no database, so none of them can see which keys another one uses:
 * two sites on the same prefix write the same "2026/09/logo.png" key, the
 * second upload overwrites the first site's file, and Mirror Delete on one
 * site deletes a file the other still serves.
 *
 * So each site claims its folder with a small marker object,
 * `{prefix}/.omnioffloader-site.json`, naming the site address. A site that
 * finds another site's marker in its folder records a conflict, and Safe
 * mode (reason `folder`) then pauses every cloud write and delete, through
 * the same guards that protect the live bucket from a staging copy.
 *
 * Remote calls happen only around transfers and explicit admin actions:
 * the first upload or delete after an upgrade or a settings change, saving
 * settings, Test Connection, and once a day while transfers run. The result
 * is kept in an option, so the Safe mode check itself never leaves the
 * server.
 *
 * @since 0.1.3
 */
class FolderClaim {

	/**
	 * Marker object name, stored at the root of the site's folder.
	 */
	public const MARKER = '.omnioffloader-site.json';

	/**
	 * Option caching the verified state per provider + bucket + folder.
	 */
	public const OPTION = 'omnioffloader_folder_claims';

	/**
	 * Option listing every folder this site claimed, per provider + bucket.
	 *
	 * Offloaded media keeps the folder it was offloaded to, so after a Path
	 * Prefix change the site still writes and deletes in its old folders.
	 * Knowing them lets blocks() check the folder a key is really in.
	 */
	public const FOLDERS_OPTION = 'omnioffloader_folders_used';

	/**
	 * Most folders remembered per provider + bucket.
	 */
	private const MAX_FOLDERS = 20;

	/**
	 * Seconds a verified claim is trusted before it is read again.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Most folders remembered (older entries are dropped).
	 */
	private const MAX_ENTRIES = 10;

	/**
	 * Folder states: the folder is this site's, another site's, or could not
	 * be checked (network error, provider without marker support).
	 */
	public const OURS    = 'ours';
	public const OTHER   = 'other';
	public const FREE    = 'free';
	public const UNKNOWN = 'unknown';

	/**
	 * References already verified in this request.
	 *
	 * @var array<string, bool>
	 */
	private static $checked = array();

	/**
	 * Whether folder claims are checked at all.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether this site checks that its bucket folder is not used
		 * by another site. Only turn this off when two sites deliberately
		 * write into the same folder and never share file names.
		 *
		 * @since 0.1.3
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'omnioffloader_check_folder_claim', true );
	}

	/**
	 * The folder new uploads go to, from settings.
	 *
	 * Mirrors the prefix part of AttachmentHelper::build_subdir().
	 *
	 * @param array<string, mixed>|null $settings Settings; null for the saved ones.
	 * @return string E.g. "attorneys/" — empty for the bucket root.
	 */
	public static function prefix( $settings = null ) {
		$settings = null === $settings ? Plugin::get_settings() : $settings;

		if ( empty( $settings['path_prefix_active'] ) ) {
			return '';
		}

		$prefix = Helpers::sanitize_path( (string) ( $settings['path_prefix'] ?? '' ) );

		return '' !== $prefix ? trailingslashit( $prefix ) : '';
	}

	/**
	 * Key of the marker object for a folder.
	 *
	 * @param string $prefix Folder, as returned by prefix().
	 * @return string
	 */
	public static function marker_key( $prefix ) {
		return $prefix . self::MARKER;
	}

	/**
	 * Whether cloud writes and deletes through this provider must stop because
	 * another site owns the folder.
	 *
	 * The current folder always counts. With $keys, so does each folder they
	 * are in: media offloaded before a Path Prefix change stays in the old
	 * folder, and if another site took that folder over, writing or deleting
	 * there would touch its files.
	 *
	 * Verifies each folder (and claims it when free) at most once a day, so
	 * it costs one small request on the first transfer after a change.
	 *
	 * @since 0.1.4 The $keys parameter.
	 *
	 * @param ProviderInterface $provider Provider about to write or delete.
	 * @param string[]          $keys     Object keys about to be written or deleted.
	 * @return bool
	 */
	public static function blocks( ProviderInterface $provider, array $keys = array() ) {
		// The folder setting describes the active provider's bucket. A former
		// provider only serves Bring Back from keys this site recorded.
		if ( ! self::enabled() || $provider->get_key() !== self::active_provider_key() ) {
			return false;
		}

		if ( self::OTHER === self::ensure( $provider )['status'] ) {
			return true;
		}

		$current = self::prefix();
		$folders = self::folders_used( $provider->get_key(), (string) $provider->get_bucket() );

		foreach ( array_unique( array_map( static fn( $key ) => self::folder_of( (string) $key, $folders, $current ), $keys ) ) as $folder ) {
			if ( $folder !== $current && self::OTHER === self::ensure( $provider, false, $folder )['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The folder an object key is in: the longest folder this site used that
	 * the key starts with, or the current one when none matches.
	 *
	 * @since 0.1.4
	 *
	 * @param string   $key     Object key.
	 * @param string[] $folders Folders this site used (see folders_used()).
	 * @param string   $current The current folder.
	 * @return string
	 */
	public static function folder_of( $key, array $folders, $current ) {
		$best = null;

		foreach ( array_merge( $folders, array( $current ) ) as $folder ) {
			if ( ( '' === $folder || 0 === strpos( $key, $folder ) ) && ( null === $best || strlen( $folder ) > strlen( $best ) ) ) {
				$best = $folder;
			}
		}

		return null === $best ? $current : $best;
	}

	/**
	 * Folders this site claimed in a provider's bucket.
	 *
	 * @since 0.1.4
	 *
	 * @param string $provider_key Provider key.
	 * @param string $bucket       Bucket.
	 * @return string[]
	 */
	public static function folders_used( $provider_key, $bucket ) {
		$all = get_option( self::FOLDERS_OPTION );

		// First read since the list exists (0.1.4): folders used before it
		// are only known from the media itself.
		if ( ! is_array( $all ) ) {
			$all = self::seed_folders();
		}

		$list = $all[ md5( $provider_key . '|' . $bucket ) ] ?? array();

		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : array();
	}

	/**
	 * Build the folder list once, if it doesn't exist yet. Run by the 0.1.4
	 * migration so the one scan of offloaded media happens on update, not
	 * on whichever upload or delete first needs the list.
	 *
	 * @since 0.1.4
	 *
	 * @return void
	 */
	public static function seed() {
		if ( ! is_array( get_option( self::FOLDERS_OPTION ) ) ) {
			self::seed_folders();
		}
	}

	/**
	 * Build the folder list from the paths offloaded media is stored under,
	 * per provider and bucket, and save it.
	 *
	 * A stored path is the folder, the upload folder and an optional version
	 * segment ("shop/2026/09/[20260925143012/]"), so the folder is what comes
	 * before the attachment's upload folder. Media at the uploads root has no
	 * upload folder to anchor on and is skipped.
	 *
	 * @since 0.1.4
	 *
	 * @return array<string, string[]>
	 */
	private static function seed_folders() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time seed of a stored list.
		$rows = $wpdb->get_results(
			"SELECT pv.meta_value AS provider, bk.meta_value AS bucket, p.meta_value AS path, MIN( f.meta_value ) AS attached
			 FROM {$wpdb->postmeta} p
			 INNER JOIN {$wpdb->postmeta} o ON o.post_id = p.post_id AND o.meta_key = 'omnioffloader_offloaded' AND o.meta_value = '1'
			 INNER JOIN {$wpdb->postmeta} f ON f.post_id = p.post_id AND f.meta_key = '_wp_attached_file'
			 INNER JOIN {$wpdb->postmeta} pv ON pv.post_id = p.post_id AND pv.meta_key = 'omnioffloader_provider'
			 INNER JOIN {$wpdb->postmeta} bk ON bk.post_id = p.post_id AND bk.meta_key = 'omnioffloader_bucket'
			 WHERE p.meta_key = 'omnioffloader_path'
			 GROUP BY pv.meta_value, bk.meta_value, p.meta_value"
		);

		$all = array();

		foreach ( (array) $rows as $row ) {
			$dir = dirname( (string) $row->attached );

			if ( '.' === $dir || '' === $dir ) {
				continue;
			}

			$at = strpos( (string) $row->path, trailingslashit( $dir ) );

			if ( false === $at ) {
				continue;
			}

			$ref    = md5( (string) $row->provider . '|' . (string) $row->bucket );
			$folder = substr( (string) $row->path, 0, $at );

			if ( ! in_array( $folder, $all[ $ref ] ?? array(), true ) ) {
				$all[ $ref ][] = $folder;
			}
		}

		foreach ( $all as $ref => $list ) {
			$all[ $ref ] = array_slice( $list, -self::MAX_FOLDERS );
		}

		update_option( self::FOLDERS_OPTION, $all, true );

		return $all;
	}

	/**
	 * Old folders this site still has media in that another site now owns,
	 * from the cached checks only (never a remote call).
	 *
	 * @since 0.1.4
	 *
	 * @return array<int, array{folder: string, owner: string}>
	 */
	public static function lost_folders() {
		$provider = self::active_provider_key();
		$bucket   = Helpers::get_credential( $provider, 'bucket' );

		if ( ! self::enabled() || '' === $provider || '' === $bucket ) {
			return array();
		}

		$current = self::prefix();
		$stored  = self::stored();
		$lost    = array();

		foreach ( self::folders_used( $provider, $bucket ) as $folder ) {
			$entry = $stored[ self::ref( $provider, $bucket, $folder ) ] ?? null;

			if ( $folder !== $current && null !== $entry && self::OTHER === $entry['status'] ) {
				$lost[] = array(
					'folder' => $folder,
					'owner'  => $entry['owner'],
				);
			}
		}

		return $lost;
	}

	/**
	 * Add a folder to the list of folders this site claimed.
	 *
	 * @param string $provider_key Provider key.
	 * @param string $bucket       Bucket.
	 * @param string $prefix       Folder.
	 * @return void
	 */
	private static function remember_folder( $provider_key, $bucket, $prefix ) {
		$all = get_option( self::FOLDERS_OPTION );
		$all = is_array( $all ) ? $all : self::seed_folders();
		$ref = md5( $provider_key . '|' . $bucket );

		$list = isset( $all[ $ref ] ) && is_array( $all[ $ref ] ) ? $all[ $ref ] : array();

		if ( in_array( $prefix, $list, true ) ) {
			return;
		}

		$list[]      = $prefix;
		$all[ $ref ] = array_slice( $list, -self::MAX_FOLDERS );

		// Autoloaded: blocks() reads it before every cloud write.
		update_option( self::FOLDERS_OPTION, $all, true );
	}

	/**
	 * Verify the active provider's folder when the cached result is missing
	 * or stale, so Safe mode is current before a job starts.
	 *
	 * For explicit actions only (starting an offload): it may make one
	 * request to the bucket.
	 *
	 * @return void
	 */
	public static function refresh() {
		if ( ! self::enabled() || SafeMode::is_copy() ) {
			return;
		}

		$provider = self::provider();

		if ( null !== $provider ) {
			self::ensure( $provider );
		}
	}

	/**
	 * Whether the active provider's folder is recorded as another site's.
	 *
	 * Reads the cached state only — never a remote call — because Safe mode
	 * asks on every request that might write.
	 *
	 * @return array{owner: string, folder: string}|null Null when there is no conflict.
	 */
	public static function active_conflict() {
		if ( ! self::enabled() ) {
			return null;
		}

		$provider = self::active_provider_key();
		$bucket   = Helpers::get_credential( $provider, 'bucket' );

		if ( '' === $provider || '' === $bucket ) {
			return null;
		}

		$prefix = self::prefix();
		$entry  = self::stored()[ self::ref( $provider, $bucket, $prefix ) ] ?? null;

		if ( null === $entry || self::OTHER !== $entry['status'] ) {
			return null;
		}

		return array(
			'owner'  => $entry['owner'],
			'folder' => '' !== $prefix ? untrailingslashit( $prefix ) : '',
		);
	}

	/**
	 * Make sure the provider's folder is this site's: read the marker when
	 * the cached result is missing or stale, and claim the folder when free.
	 *
	 * @since 0.1.4 The $prefix parameter.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param bool              $force    Read the marker even if a fresh result is cached.
	 * @param string|null       $prefix   Folder to check; null for the current one.
	 * @return array{status: string, owner: string}
	 */
	public static function ensure( ProviderInterface $provider, $force = false, $prefix = null ) {
		$bucket = (string) $provider->get_bucket();
		$prefix = null === $prefix ? self::prefix() : (string) $prefix;
		$ref    = self::ref( $provider->get_key(), $bucket, $prefix );
		$stored = self::stored();
		$entry  = $stored[ $ref ] ?? null;

		if ( ! $force && null !== $entry && ( isset( self::$checked[ $ref ] ) || time() - $entry['checked_at'] < self::TTL ) ) {
			// Installs from before the folder list record theirs here.
			if ( self::OURS === $entry['status'] ) {
				self::remember_folder( $provider->get_key(), $bucket, $prefix );
			}

			return array(
				'status' => $entry['status'],
				'owner'  => $entry['owner'],
			);
		}

		// A copy of the site never writes, so it cannot claim; its live
		// original keeps the folder verified. And one failed read per request
		// is enough: the transfers that follow would fail the same way.
		if ( SafeMode::is_copy() || ( ! $force && isset( self::$checked[ $ref ] ) ) ) {
			return array(
				'status' => null !== $entry ? $entry['status'] : self::UNKNOWN,
				'owner'  => null !== $entry ? $entry['owner'] : '',
			);
		}

		self::$checked[ $ref ] = true;

		$result = self::inspect( $provider, $prefix );

		if ( self::FREE === $result['status'] ) {
			$result = self::write_marker( $provider, $prefix )
				? array(
					'status' => self::OURS,
					'owner'  => SafeMode::current_address(),
				)
				: array(
					'status' => self::UNKNOWN,
					'owner'  => '',
				);
		}

		// Unknown: keep what was known before and try again next request,
		// rather than blocking — or clearing a conflict — over a network blip.
		if ( self::UNKNOWN !== $result['status'] ) {
			self::remember( $ref, $result['status'], $result['owner'] );
		}

		if ( self::OURS === $result['status'] ) {
			self::remember_folder( $provider->get_key(), $bucket, $prefix );
		}

		return $result;
	}

	/**
	 * Read a folder's marker without writing anything.
	 *
	 * @param ProviderInterface $provider Provider (may carry draft credentials).
	 * @param string            $prefix   Folder, as returned by prefix().
	 * @return array{status: string, owner: string}
	 */
	public static function inspect( ProviderInterface $provider, $prefix ) {
		if ( ! method_exists( $provider, 'read_small_object' ) ) {
			return array(
				'status' => self::UNKNOWN,
				'owner'  => '',
			);
		}

		$body = $provider->read_small_object( self::marker_key( $prefix ) );

		if ( $body instanceof \WP_Error ) {
			return array(
				'status' => self::UNKNOWN,
				'owner'  => '',
			);
		}

		if ( null === $body ) {
			return array(
				'status' => self::FREE,
				'owner'  => '',
			);
		}

		$data  = json_decode( $body, true );
		$owner = is_array( $data ) && isset( $data['site'] ) && is_string( $data['site'] ) ? $data['site'] : '';

		// An unreadable marker was not written by this plugin's current
		// format; treat the folder as claimed by someone unknown.
		if ( '' === $owner ) {
			return array(
				'status' => self::OTHER,
				'owner'  => __( 'an unknown site', 'omnioffloader' ),
			);
		}

		return array(
			'status' => SafeMode::current_address() === $owner ? self::OURS : self::OTHER,
			'owner'  => $owner,
		);
	}

	/**
	 * Claim the folder for this site, overwriting another site's marker.
	 *
	 * For "This folder belongs to this site": a site that moved to a new
	 * address, or one whose other site is gone for good.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @return bool
	 */
	public static function take_over( ProviderInterface $provider ) {
		$prefix = self::prefix();

		if ( ! self::write_marker( $provider, $prefix ) ) {
			return false;
		}

		self::remember( self::ref( $provider->get_key(), (string) $provider->get_bucket(), $prefix ), self::OURS, SafeMode::current_address() );
		self::remember_folder( $provider->get_key(), (string) $provider->get_bucket(), $prefix );

		return true;
	}

	/**
	 * After a confirmed domain move, move the folder's claim along with the
	 * site when the marker still names the old address.
	 *
	 * @param ProviderInterface $provider    Provider.
	 * @param string            $old_address Address recorded as live before the move.
	 * @return void
	 */
	public static function follow_move( ProviderInterface $provider, $old_address ) {
		if ( '' === $old_address || ! self::enabled() ) {
			return;
		}

		$found = self::inspect( $provider, self::prefix() );

		if ( self::OTHER === $found['status'] && $found['owner'] === $old_address ) {
			self::take_over( $provider );
		}
	}

	/**
	 * Forget every cached result, so the next transfer reads the markers again.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
		self::$checked = array();
		SafeMode::reset();
	}

	/**
	 * Refusal for a folder another site uses.
	 *
	 * @param string $owner  Address of the site that owns the folder.
	 * @param string $folder Folder; empty for the bucket root.
	 * @return \WP_Error
	 */
	public static function refusal( $owner, $folder ) {
		return new \WP_Error(
			'omnioffloader_folder_claimed',
			self::message( $owner, $folder ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Why a folder cannot be used.
	 *
	 * @param string $owner  Address of the site that owns the folder.
	 * @param string $folder Folder; empty for the bucket root.
	 * @return string
	 */
	public static function message( $owner, $folder ) {
		$where = '' !== $folder
			/* translators: %s: folder name in the bucket, e.g. "attorneys". */
			? sprintf( __( 'The folder "%s" in this bucket', 'omnioffloader' ), $folder )
			: __( 'The root of this bucket', 'omnioffloader' );

		return sprintf(
			/* translators: 1: "The folder "x" in this bucket" or "The root of this bucket", 2: other site's address. */
			__( '%1$s is already used by another site (%2$s). Two sites writing to the same folder overwrite and delete each other\'s files, so nothing is uploaded to or deleted from the cloud. Give this site its own Path Prefix in OmniOffloader → Settings.', 'omnioffloader' ),
			$where,
			$owner
		);
	}

	/**
	 * Write this site's marker into a folder.
	 *
	 * @param ProviderInterface $provider Provider.
	 * @param string            $prefix   Folder.
	 * @return bool
	 */
	private static function write_marker( ProviderInterface $provider, $prefix ) {
		if ( ! method_exists( $provider, 'write_small_object' ) ) {
			return false;
		}

		$body = wp_json_encode(
			array(
				'plugin'     => 'omnioffloader',
				'site'       => SafeMode::current_address(),
				'url'        => trailingslashit( (string) get_option( 'home' ) ),
				'claimed_at' => gmdate( 'c' ),
				'note'       => 'This folder belongs to the WordPress site above. Other sites sharing the bucket must use their own folder.',
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		return false !== $body && $provider->write_small_object( self::marker_key( $prefix ), $body );
	}

	/**
	 * Cache a verified result.
	 *
	 * @param string $ref    Reference from ref().
	 * @param string $status OURS or OTHER.
	 * @param string $owner  Address named in the marker.
	 * @return void
	 */
	private static function remember( $ref, $status, $owner ) {
		$stored = self::stored();
		$before = $stored[ $ref ]['status'] ?? '';

		unset( $stored[ $ref ] );
		$stored[ $ref ] = array(
			'status'     => $status,
			'owner'      => $owner,
			'checked_at' => time(),
		);

		// Oldest first; keep the most recent entries.
		$stored = array_slice( $stored, -self::MAX_ENTRIES, null, true );

		// Autoloaded: Safe mode reads it on requests that may write.
		update_option( self::OPTION, $stored, true );

		if ( $before !== $status ) {
			SafeMode::reset();
		}
	}

	/**
	 * Cached results.
	 *
	 * @return array<string, array{status: string, owner: string, checked_at: int}>
	 */
	private static function stored() {
		$stored = get_option( self::OPTION, array() );
		$clean  = array();

		if ( ! is_array( $stored ) ) {
			return $clean;
		}

		foreach ( $stored as $ref => $entry ) {
			if ( is_array( $entry ) && isset( $entry['status'] ) && in_array( $entry['status'], array( self::OURS, self::OTHER ), true ) ) {
				$clean[ (string) $ref ] = array(
					'status'     => (string) $entry['status'],
					'owner'      => isset( $entry['owner'] ) ? (string) $entry['owner'] : '',
					'checked_at' => isset( $entry['checked_at'] ) ? (int) $entry['checked_at'] : 0,
				);
			}
		}

		return $clean;
	}

	/**
	 * Cache key for a provider + bucket + folder.
	 *
	 * @param string $provider Provider key.
	 * @param string $bucket   Bucket.
	 * @param string $prefix   Folder.
	 * @return string
	 */
	private static function ref( $provider, $bucket, $prefix ) {
		return md5( $provider . '|' . $bucket . '|' . $prefix );
	}

	/**
	 * The active provider, ready for remote calls; null when no provider is
	 * configured or the AWS SDK is missing.
	 *
	 * @param string|null $key Provider key; null for the saved one.
	 * @return ProviderInterface|null
	 */
	public static function provider( $key = null ) {
		$key = null === $key ? self::active_provider_key() : $key;

		if ( '' === $key || ! class_exists( \Aws\S3\S3Client::class ) || ! ProviderRegistry::has( $key ) ) {
			return null;
		}

		try {
			$provider = ProviderRegistry::create( $key );
		} catch ( \InvalidArgumentException $e ) {
			return null;
		}

		return null !== $provider->get_bucket() ? $provider : null;
	}

	/**
	 * Active provider key without loading the provider.
	 *
	 * @return string
	 */
	private static function active_provider_key() {
		return (string) Plugin::get_provider_key();
	}
}
