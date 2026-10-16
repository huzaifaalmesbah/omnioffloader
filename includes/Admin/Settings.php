<?php
/**
 * Admin settings page: menu + React app enqueue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Admin;

use OmniOffloader\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 */
class Settings {

	/**
	 * Menu slug / page identifier.
	 */
	private const PAGE_SLUG = 'omnioffloader';

	/**
	 * Admin page hook suffix, set when the menu is registered.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		// Activation always lands on plugins.php, so only check there rather
		// than paying a transient read on every admin, AJAX and heartbeat hit.
		add_action( 'load-plugins.php', array( $this, 'maybe_activation_redirect' ) );
	}

	/**
	 * One-time redirect to our page after interactive activation.
	 *
	 * @return void
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'omnioffloader_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'omnioffloader_activation_redirect' );

		// Not on bulk activations, and only for admins.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		if ( isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Register the top-level admin menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		$this->hook_suffix = (string) add_menu_page(
			__( 'OmniOffloader', 'omnioffloader' ),
			__( 'OmniOffloader', 'omnioffloader' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-cloud-upload',
			80
		);
	}

	/**
	 * Enqueue the React app — only on our page.
	 *
	 * @param string $hook The current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$asset_path = OMNIOFFLOADER_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'OmniOffloader: build assets are missing. Run "pnpm install && pnpm build" in the plugin directory.', 'omnioffloader' )
					);
				}
			);

			return;
		}

		$asset_file = include $asset_path;

		wp_enqueue_script(
			'omnioffloader-admin-app',
			OMNIOFFLOADER_URL . 'build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'omnioffloader-admin-app',
			OMNIOFFLOADER_URL . 'build/index.css',
			array( 'wp-components' ),
			$asset_file['version']
		);

		// wp-scripts also builds index-rtl.css; swap it in for RTL locales.
		wp_style_add_data( 'omnioffloader-admin-app', 'rtl', 'replace' );

		wp_localize_script(
			'omnioffloader-admin-app',
			'omnioffloader',
			array(
				'version'    => OMNIOFFLOADER_VERSION,
				'sdk_loaded' => class_exists( \Aws\S3\S3Client::class ),
				'upload_url' => wp_get_upload_dir()['baseurl'],
				'media_url'  => admin_url( 'upload.php' ),
				'docs_url'   => OMNIOFFLOADER_DOCS_URL,
			)
		);

		// The first screen needs these before it can draw anything; embedded in
		// the page they save a round of REST requests (seconds on slow hosts).
		wp_add_inline_script(
			'omnioffloader-admin-app',
			'window.omnioffloaderPreload = ' . wp_json_encode( $this->preload_data() ) . ';',
			'before'
		);

		wp_set_script_translations(
			'omnioffloader-admin-app',
			'omnioffloader'
		);
	}

	/**
	 * REST responses the admin app reads on load, keyed by request path
	 * (see src/api/preload.ts). Run through the REST server like a real
	 * request, so permission checks and response shapes are the same.
	 *
	 * Credential fields come masked, as over REST, for every provider so
	 * switching provider in Settings needs no request either. Nothing here
	 * makes a remote call.
	 *
	 * @since 0.1.6
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function preload_data() {
		$paths = array(
			'/omnioffloader/v1/core/settings',
			'/omnioffloader/v1/core/providers',
			'/omnioffloader/v1/core/stats',
			'/omnioffloader/v1/core/cli-job',
		);

		foreach ( array_keys( ProviderRegistry::get_providers() ) as $key ) {
			$paths[] = '/omnioffloader/v1/provider/credentials?provider=' . rawurlencode( (string) $key );
		}

		$preloaded = array_reduce( $paths, 'rest_preload_api_request', array() );

		// Only the bodies are read; drop errors so the app fetches those itself.
		$data = array();

		foreach ( $preloaded as $path => $response ) {
			$body = $response['body'] ?? null;

			if ( is_array( $body ) && ! ( isset( $body['code'], $body['message'] ) && is_string( $body['code'] ) ) ) {
				$data[ $path ] = $body;
			}
		}

		return $data;
	}

	/**
	 * Render the React mount point.
	 *
	 * @return void
	 */
	public function render_page() {
		include OMNIOFFLOADER_DIR . 'includes/templates/admin-app.php';
	}
}
