<?php
/**
 * Main Plugin Class.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Core;

use OmniOffloader\Admin\Settings;
use OmniOffloader\Api\BringBackController;
use OmniOffloader\Api\CoreController;
use OmniOffloader\Api\OffloadController;
use OmniOffloader\Api\ProviderController;
use OmniOffloader\Api\RetentionController;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;
use OmniOffloader\Services\BulkRetentionProcessor;
use OmniOffloader\Traits\OptionsHelper;

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
	 * Async upload processor when the engine is on, null otherwise.
	 *
	 * @var AsyncUploadProcessor|null
	 */
	private $async_upload = null;

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
	 * Bulk retention processor when the engine is on, null otherwise.
	 *
	 * @var BulkRetentionProcessor|null
	 */
	private $bulk_retention = null;

	/**
	 * Get the active provider (null when the engine is off).
	 *
	 * @return ProviderInterface|null
	 */
	public function get_provider() {
		return $this->provider;
	}

	/**
	 * Get the async upload processor (null when the engine is off).
	 *
	 * @since 0.1.1
	 *
	 * @return AsyncUploadProcessor|null
	 */
	public function get_async_upload() {
		return $this->async_upload;
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
	 * Get the bulk retention processor (null when the engine is off).
	 *
	 * @since 0.1.3
	 *
	 * @return BulkRetentionProcessor|null
	 */
	public function get_bulk_retention() {
		return $this->bulk_retention;
	}

	/**
	 * Site transient naming the WP-CLI job currently running.
	 *
	 * Per site on multisite, like the queues (see Helpers::queue_prefix()).
	 *
	 * @since 0.1.3
	 *
	 * @return string
	 */
	public static function cli_job_lock_key() {
		return Helpers::queue_prefix() . '_cli_job';
	}

	/**
	 * Seconds a WP-CLI job claim lives without renewal.
	 */
	private const CLI_JOB_LOCK_TTL = 600;

	/**
	 * Announce a running WP-CLI job so admin-started jobs of every type wait.
	 *
	 * A CLI run never writes queue state, so without this the admin screens
	 * could start a different job over the same files. Call repeatedly to
	 * keep the claim alive; it expires on its own if the process dies.
	 *
	 * @since 0.1.3
	 *
	 * @param string $job 'offload', 'restore' or 'retention'.
	 * @return void
	 */
	public function claim_cli_job( $job ) {
		set_site_transient( self::cli_job_lock_key(), (string) $job, self::CLI_JOB_LOCK_TTL );
	}

	/**
	 * Release a claim taken with claim_cli_job().
	 *
	 * @since 0.1.3
	 *
	 * @return void
	 */
	public function release_cli_job() {
		delete_site_transient( self::cli_job_lock_key() );
	}

	/**
	 * The bulk job currently running, if any — admin queue or WP-CLI.
	 *
	 * Offload, restore and retention runs all read or delete the same local
	 * files, so only one may run at a time (Safety Invariant #8).
	 *
	 * @since 0.1.3
	 *
	 * @return string 'offload', 'restore', 'retention', or '' when idle.
	 */
	public function get_active_bulk_job() {
		$cli_job = get_site_transient( self::cli_job_lock_key() );

		if ( is_string( $cli_job ) && '' !== $cli_job ) {
			return $cli_job;
		}

		$jobs = array(
			'offload'   => $this->bulk_offload,
			'restore'   => $this->bulk_restore,
			'retention' => $this->bulk_retention,
		);

		foreach ( $jobs as $name => $processor ) {
			if ( null !== $processor && $processor->is_active() ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * Refusal for starting a bulk job while another one runs.
	 *
	 * @since 0.1.3
	 *
	 * @param string $job The job about to start ('offload', 'restore' or 'retention').
	 * @return \WP_Error|null
	 */
	public function bulk_job_conflict( $job ) {
		$active = $this->get_active_bulk_job();

		if ( '' === $active ) {
			return null;
		}

		$messages = array(
			'offload'   => __( 'A bulk offload is running — wait for it to finish first.', 'omnioffloader' ),
			'restore'   => __( 'A bulk restore is running — wait for it to finish first.', 'omnioffloader' ),
			'retention' => __( 'A retention policy update is running — wait for it to finish first.', 'omnioffloader' ),
		);

		if ( $active === $job ) {
			$messages = array(
				'offload'   => __( 'A bulk offload is already running.', 'omnioffloader' ),
				'restore'   => __( 'A bulk restore is already running.', 'omnioffloader' ),
				'retention' => __( 'A retention policy update is already running.', 'omnioffloader' ),
			);
		}

		return new \WP_Error( 'omnioffloader_bulk_running', $messages[ $active ], array( 'status' => 409 ) );
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

		add_filter( 'plugin_action_links_' . OMNIOFFLOADER_BASENAME, array( $this, 'add_plugin_settings_link' ) );
	}

	/**
	 * Run version-gated migrations once when the code version outruns the
	 * stored version. Idempotent and capability-independent.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		$stored_version = self::get_plugin_version();

		if ( ! version_compare( OMNIOFFLOADER_VERSION, $stored_version, '>' ) ) {
			return;
		}

		Migrator::run( $stored_version );

		self::update_plugin_version( OMNIOFFLOADER_VERSION );
	}

	/**
	 * Add settings link to the plugin action links.
	 *
	 * @param array<int|string, string> $links Existing plugin action links.
	 * @return array<int|string, string>
	 */
	public function add_plugin_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=omnioffloader' ) ) . '">' . esc_html__( 'Settings', 'omnioffloader' ) . '</a>';
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

		$retention_api = new RetentionController();
		$retention_api->init();
	}

	/**
	 * Boot the offload engine when a provider is ready.
	 *
	 * @return void
	 */
	private function initialize_offloader() {
		// Kill switch for tests and emergencies (define in wp-config.php).
		if ( defined( 'OMNIOFFLOADER_DISABLE_ENGINE' ) && OMNIOFFLOADER_DISABLE_ENGINE ) {
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

		// Must exist on every request: their constructors register the
		// async loopback + cron-healthcheck hooks that drive the queues.
		$this->async_upload   = new AsyncUploadProcessor( $provider );
		$this->bulk_offload   = new BulkOffloadProcessor( $provider );
		$this->bulk_restore   = new BulkRestoreProcessor();
		$this->bulk_retention = new BulkRetentionProcessor();

		$offloader = new Offloader( $provider, $this->async_upload );
		$offloader->register_observers();
	}
}
