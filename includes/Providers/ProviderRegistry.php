<?php
/**
 * Provider registry.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ProviderRegistry
 *
 * Adding a provider = one class + one line here. Nothing else changes:
 * the settings UI renders credential fields from the provider's own
 * definitions served over REST.
 */
class ProviderRegistry {

	/**
	 * Built-in providers: key => class.
	 *
	 * @var array<string, class-string<ProviderInterface>>
	 */
	private static $providers = array(
		'amazon_s3'           => AmazonS3::class,
		'cloudflare_r2'       => CloudflareR2::class,
		'backblaze_b2'        => BackblazeB2::class,
		'digitalocean_spaces' => DigitalOceanSpaces::class,
		'wasabi'              => Wasabi::class,
		's3_compatible'       => S3Compatible::class,
	);

	/**
	 * Key aliases for backward and cross compatibility.
	 *
	 * @var array<string, string>
	 */
	private static $aliases = array(
		'aws'          => 'amazon_s3',
		'aws_s3'       => 'amazon_s3',
		'dos'          => 'digitalocean_spaces',
		'digitalocean' => 'digitalocean_spaces',
		'minio'        => 's3_compatible',
	);

	/**
	 * Resolve a key to its canonical form if it is an alias.
	 *
	 * @param string $key Provider key or alias.
	 * @return string
	 */
	public static function canonical_key( $key ) {
		return self::$aliases[ $key ] ?? $key;
	}

	/**
	 * Get the registered provider map.
	 *
	 * @return array<string, class-string<ProviderInterface>>
	 */
	public static function get_providers() {
		/**
		 * Filter the registered cloud providers.
		 *
		 * @param array<string, class-string<ProviderInterface>> $providers Provider key => class map.
		 */
		$providers = apply_filters( 'omnioffloader_cloud_providers', self::$providers );

		return is_array( $providers ) ? $providers : self::$providers;
	}

	/**
	 * Whether a provider key is registered (including aliases).
	 *
	 * @param string $key Provider key.
	 * @return bool
	 */
	public static function has( $key ) {
		$canonical = self::canonical_key( $key );
		$providers = self::get_providers();

		return isset( $providers[ $canonical ] );
	}

	/**
	 * Create a provider instance.
	 *
	 * @param string $key  Provider key.
	 * @param string $role Storage role ('primary' or 'secondary').
	 * @return ProviderInterface
	 * @throws \InvalidArgumentException When the key is unknown or the class is invalid.
	 */
	public static function create( $key, $role = 'primary' ) {
		$canonical = self::canonical_key( $key );
		$providers = self::get_providers();

		if ( ! isset( $providers[ $canonical ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Unsupported cloud provider: {$key}" ) );
		}

		$class = $providers[ $canonical ];

		if ( ! class_exists( $class ) || ! is_subclass_of( $class, ProviderInterface::class ) ) {
			throw new \InvalidArgumentException( esc_html( "Invalid cloud provider class for: {$key}" ) );
		}

		return new $class( $role );
	}
}
