<?php
/**
 * Admin settings page: menu + React app enqueue.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Admin;

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
			)
		);

		wp_set_script_translations(
			'omnioffloader-admin-app',
			'omnioffloader'
		);
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
