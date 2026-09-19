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
	 * Hook route registration.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
