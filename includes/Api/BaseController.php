<?php
/**
 * REST API base controller.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Api;

use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BaseController
 *
 * Shared REST plumbing: namespace, permission check, route helpers.
 */
abstract class BaseController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'omnioffloader/v1';

	/**
	 * Whether anti-caching hooks have been registered.
	 *
	 * @var bool
	 */
	private static $cache_hooks_registered = false;

	/**
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( ! self::$cache_hooks_registered ) {
			self::$cache_hooks_registered = true;
			add_filter( 'rest_pre_dispatch', array( $this, 'filter_rest_pre_dispatch' ), 10, 3 );
			add_filter( 'rest_post_dispatch', array( $this, 'filter_rest_post_dispatch' ), 10, 3 );
			add_filter( 'litespeed_can_cache', array( $this, 'filter_cache_exclusion' ) );
			add_filter( 'breeze_can_cache', array( $this, 'filter_cache_exclusion' ) );
		}
	}

	/**
	 * Pre-dispatch check: ensure caching is disabled as early as possible
	 * for any request under the plugin REST namespace.
	 *
	 * @param mixed            $result  Pre-dispatch result or null.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed
	 */
	public function filter_rest_pre_dispatch( $result, $server, $request ) {
		$route = $request->get_route();

		if ( 0 === strpos( $route, '/' . $this->namespace ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard WordPress caching constant.
				define( 'DONOTCACHEPAGE', true );
			}

			if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard object cache constant.
				define( 'DONOTCACHEOBJECT', true );
			}

			if ( ! defined( 'DONOTCACHEDB' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard DB cache constant.
				define( 'DONOTCACHEDB', true );
			}

			if ( ! defined( 'DONOTMINIFY' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard minify bypass constant.
				define( 'DONOTMINIFY', true );
			}

			if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP Rocket caching constant.
				define( 'DONOTROCKETOPTIMIZE', true );
			}

			if ( class_exists( '\LiteSpeed\Control' ) ) {
				\LiteSpeed\Control::set_nocache( 'OmniOffloader dynamic REST endpoint' );
			}

			nocache_headers();
		}

		return $result;
	}

	/**
	 * Attach anti-caching HTTP headers to all OmniOffloader REST responses.
	 *
	 * Prevents LiteSpeed Cache, Breeze, Varnish, reverse proxies, and browsers
	 * from caching dynamic REST endpoints.
	 *
	 * @param \WP_REST_Response|\WP_Error|\WP_HTTP_Response|mixed $response Current response.
	 * @param \WP_REST_Server                                     $server   Server instance.
	 * @param \WP_REST_Request                                    $request  Request used to generate response.
	 * @return \WP_REST_Response|\WP_Error|\WP_HTTP_Response|mixed
	 */
	public function filter_rest_post_dispatch( $response, $server, $request ) {
		$route = $request->get_route();

		if ( 0 === strpos( $route, '/' . $this->namespace ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard WordPress caching constant.
				define( 'DONOTCACHEPAGE', true );
			}

			if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard object cache constant.
				define( 'DONOTCACHEOBJECT', true );
			}

			if ( ! defined( 'DONOTCACHEDB' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard DB cache constant.
				define( 'DONOTCACHEDB', true );
			}

			if ( ! defined( 'DONOTMINIFY' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard minify bypass constant.
				define( 'DONOTMINIFY', true );
			}

			if ( ! defined( 'DONOTROCKETOPTIMIZE' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP Rocket caching constant.
				define( 'DONOTROCKETOPTIMIZE', true );
			}

			if ( class_exists( '\LiteSpeed\Control' ) ) {
				\LiteSpeed\Control::set_nocache( 'OmniOffloader dynamic REST endpoint' );
			}

			nocache_headers();

			if ( $response instanceof \WP_REST_Response ) {
				$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
				$response->header( 'Pragma', 'no-cache' );
				$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
				$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
				$response->header( 'X-Accel-Expires', '0' );
			}
		}

		return $response;
	}

	/**
	 * Filter for LiteSpeed Cache and Breeze to prevent caching plugin REST endpoints.
	 *
	 * @param bool $can_cache Whether the request can be cached.
	 * @return bool
	 */
	public function filter_cache_exclusion( $can_cache ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if ( '' !== $request_uri && false !== strpos( $request_uri, $this->namespace ) ) {
			return false;
		}

		return $can_cache;
	}

	/**
	 * Register this controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes();

	/**
	 * Authorization: all plugin endpoints are admin-only.
	 *
	 * @return bool|\WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'omnioffloader_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Authorization for media library operations: allowed for users who can upload files.
	 *
	 * @since 0.1.1
	 *
	 * @return bool|\WP_Error
	 */
	public function media_permissions_check() {
		if ( current_user_can( 'upload_files' ) ) {
			return true;
		}

		return new \WP_Error(
			'omnioffloader_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'omnioffloader' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Register a GET route.
	 *
	 * @param string               $route    Route path (no namespace).
	 * @param callable             $callback Handler.
	 * @param array<string, mixed> $args     Argument schema.
	 * @return void
	 */
	protected function register_get_route( $route, callable $callback, array $args = array() ) {
		register_rest_route(
			$this->namespace,
			$route,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => $callback,
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Register a POST route.
	 *
	 * @param string               $route    Route path (no namespace).
	 * @param callable             $callback Handler.
	 * @param array<string, mixed> $args     Argument schema.
	 * @return void
	 */
	protected function register_post_route( $route, callable $callback, array $args = array() ) {
		register_rest_route(
			$this->namespace,
			$route,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => $callback,
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'                => $args,
			)
		);
	}
}
