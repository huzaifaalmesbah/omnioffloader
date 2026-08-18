<?php
/**
 * Admin settings page: menu + React app enqueue.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Admin;

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
	private const PAGE_SLUG = 'smart-media-offloader';

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
		add_action( 'admin_init', array( $this, 'maybe_activation_redirect' ) );
	}

	/**
	 * One-time redirect to our page after interactive activation.
	 *
	 * @return void
	 */
	public function maybe_activation_redirect() {
		if ( ! get_transient( 'smowp_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'smowp_activation_redirect' );

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
			__( 'Smart Media Offloader', 'smart-media-offloader' ),
			__( 'Media Offloader', 'smart-media-offloader' ),
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

		$asset_path = SMOWP_DIR . 'build/index.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Smart Media Offloader: build assets are missing. Run "npm install && npm run build" in the plugin directory.', 'smart-media-offloader' )
					);
				}
			);

			return;
		}

		$asset_file = include $asset_path;

		wp_enqueue_script(
			'smowp-admin-app',
			SMOWP_URL . 'build/index.js',
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		wp_enqueue_style(
			'smowp-admin-app',
			SMOWP_URL . 'build/index.css',
			array( 'wp-components' ),
			$asset_file['version']
		);

		wp_localize_script(
			'smowp-admin-app',
			'smowp',
			array(
				'version'    => SMOWP_VERSION,
				'sdk_loaded' => class_exists( \Aws\S3\S3Client::class ),
				'upload_url' => wp_get_upload_dir()['baseurl'],
				'media_url'  => admin_url( 'upload.php' ),
			)
		);

		wp_set_script_translations(
			'smowp-admin-app',
			'smart-media-offloader',
			SMOWP_DIR . 'languages'
		);

		// Full-page app: keep other plugins' notices off our screen.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Render the React mount point.
	 *
	 * @return void
	 */
	public function render_page() {
		include SMOWP_DIR . 'includes/templates/admin-app.php';
	}
}
