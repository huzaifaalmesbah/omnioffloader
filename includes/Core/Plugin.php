<?php
/**
 * Main Plugin Class.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Core;

use SmartMediaOffloader\Admin\Settings;
use SmartMediaOffloader\Api\BringBackController;
use SmartMediaOffloader\Api\CoreController;
use SmartMediaOffloader\Api\OffloadController;
use SmartMediaOffloader\Api\ProviderController;
use SmartMediaOffloader\Offload\Offloader;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Providers\ProviderRegistry;
use SmartMediaOffloader\Services\BulkOffloadProcessor;
use SmartMediaOffloader\Services\BulkRestoreProcessor;
use SmartMediaOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 *
 * Boots everything on plugins_loaded. No side effects at file load time.
 */
class Plugin {

	use OptionsHelper;

	/**
	 * Instance of this class.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Active provider when the engine is on, null otherwise.
	 *
	 * @var ProviderInterface|null
	 */
	private $provider = null;

	/**
	 * Bulk offload processor when the engine is on, null otherwise.
	 *
	 * @var BulkOffloadProcessor|null
	 */
	private $bulk_offload = null;

	/**
	 * Bulk restore processor when the engine is on, null otherwise.
	 *
	 * @var BulkRestoreProcessor|null
	 */
	private $bulk_restore = null;

	/**
	 * Get the active provider (null when the engine is off).
	 *
	 * @return ProviderInterface|null
	 */
	public function get_provider() {
		return $this->provider;
	}

	/**
	 * Get the bulk offload processor (null when the engine is off).
	 *
	 * @return BulkOffloadProcessor|null
	 */
	public function get_bulk_offload() {
		return $this->bulk_offload;
	}

	/**
	 * Get the bulk restore processor (null when the engine is off).
	 *
	 * @return BulkRestoreProcessor|null
	 */
	public function get_bulk_restore() {
		return $this->bulk_restore;
	}

	/**
	 * Return the single instance of this class.
	 *
	 * @return Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the plugin.
	 *
	 * @return void
	 */
	public function run() {
		$this->initialize_classes();

		// Run migrations in every context (front-end, cron, WP-CLI, admin),
		// so updates applied outside wp-admin still migrate data.
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		add_filter( 'plugin_action_links_' . SMOWP_BASENAME, array( $this, 'add_plugin_settings_link' ) );
	}

	/**
	 * Run version-gated migrations once when the code version outruns the
	 * stored version. Idempotent and capability-independent.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored_version = self::get_plugin_version();

		if ( ! version_compare( SMOWP_VERSION, $stored_version, '>' ) ) {
			return;
		}

		Migrator::run( $stored_version );

		self::update_plugin_version( SMOWP_VERSION );
	}

	/**
	 * Add settings link to the plugin action links.
	 *
	 * @param array<int|string, string> $links Existing plugin action links.
	 * @return array<int|string, string>
	 */
	public function add_plugin_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=smart-media-offloader' ) ) . '">' . esc_html__( 'Settings', 'smart-media-offloader' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Initialize the loaded classes.
	 *
	 * Always: REST controllers for settings/provider + admin page.
	 * The offload engine only loads when a provider is configured and
	 * the AWS SDK is available.
	 *
	 * @return void
	 */
	private function initialize_classes() {
		$core_api = new CoreController();
		$core_api->init();

		$provider_api = new ProviderController();
		$provider_api->init();

		$settings = new Settings();
		$settings->init();

		$this->initialize_offloader();

		$offload_api = new OffloadController();
		$offload_api->init();

		$bring_back_api = new BringBackController();
		$bring_back_api->init();
	}

	/**
	 * Boot the offload engine when a provider is ready.
	 *
	 * @return void
	 */
	private function initialize_offloader() {
		// Kill switch for tests and emergencies (define in wp-config.php).
		if ( defined( 'SMOWP_DISABLE_ENGINE' ) && SMOWP_DISABLE_ENGINE ) {
			return;
		}

		if ( ! self::is_configured() || ! class_exists( \Aws\S3\S3Client::class ) ) {
			return;
		}

		try {
			$provider = ProviderRegistry::create( self::get_provider_key() );
		} catch ( \InvalidArgumentException $e ) {
			// Saved provider no longer registered — leave the engine off.
			return;
		}

		$this->provider = $provider;

		$offloader = new Offloader( $provider );
		$offloader->register_observers();

		// Must exist on every request: their constructors register the
		// async loopback + cron-healthcheck hooks that drive the queues.
		$this->bulk_offload = new BulkOffloadProcessor( $provider );
		$this->bulk_restore = new BulkRestoreProcessor();
	}
}
