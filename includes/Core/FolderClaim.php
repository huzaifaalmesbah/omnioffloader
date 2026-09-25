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
	 * Verifies the folder (and claims it when free) at most once a day, so it
	 * costs one small request on the first transfer after a change.
	 *
	 * @param ProviderInterface $provider Provider about to write or delete.
	 * @return bool
	 */
	public static function blocks( ProviderInterface $provider ) {
		// The folder setting describes the active provider's bucket. A former
		// provider only serves Bring Back from keys this site recorded.
		if ( ! self::enabled() || $provider->get_key() !== self::active_provider_key() ) {
			return false;
		}

		return self::OTHER === self::ensure( $provider )['status'];
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
	 * @param ProviderInterface $provider Provider.
	 * @param bool              $force    Read the marker even if a fresh result is cached.
	 * @return array{status: string, owner: string}
	 */
	public static function ensure( ProviderInterface $provider, $force = false ) {
		$bucket = (string) $provider->get_bucket();
		$prefix = self::prefix();
		$ref    = self::ref( $provider->get_key(), $bucket, $prefix );
		$stored = self::stored();
		$entry  = $stored[ $ref ] ?? null;

		if ( ! $force && null !== $entry && ( isset( self::$checked[ $ref ] ) || time() - $entry['checked_at'] < self::TTL ) ) {
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
