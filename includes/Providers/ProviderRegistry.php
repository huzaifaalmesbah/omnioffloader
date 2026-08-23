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
		'cloudflare_r2' => CloudflareR2::class,
	);

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
	 * Whether a provider key is registered.
	 *
	 * @param string $key Provider key.
	 * @return bool
	 */
	public static function has( $key ) {
		$providers = self::get_providers();

		return isset( $providers[ $key ] );
	}

	/**
	 * Create a provider instance.
	 *
	 * @param string $key Provider key.
	 * @return ProviderInterface
	 * @throws \InvalidArgumentException When the key is unknown or the class is invalid.
	 */
	public static function create( $key ) {
		$providers = self::get_providers();

		if ( ! isset( $providers[ $key ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Unsupported cloud provider: {$key}" ) );
		}

		$class = $providers[ $key ];

		if ( ! class_exists( $class ) || ! is_subclass_of( $class, ProviderInterface::class ) ) {
			throw new \InvalidArgumentException( esc_html( "Invalid cloud provider class for: {$key}" ) );
		}

		return new $class();
	}
}
