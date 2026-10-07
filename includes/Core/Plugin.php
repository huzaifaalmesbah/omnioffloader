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
use OmniOffloader\Api\MoveController;
use OmniOffloader\Api\RetentionController;
use OmniOffloader\Offload\Offloader;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Providers\ProviderRegistry;
use OmniOffloader\Services\AbstractBulkProcessor;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Services\BulkOffloadProcessor;
use OmniOffloader\Services\BulkRestoreProcessor;
use OmniOffloader\Services\BulkMoveProcessor;
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
	 * Bulk move processor when the engine is on, null otherwise.
	 *
	 * @var BulkMoveProcessor|null
	 */
	private $bulk_move = null;

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
	 * Get the bulk move processor (null when the engine is off).
	 *
	 * @since 0.1.4
	 *
	 * @return BulkMoveProcessor|null
	 */
	public function get_bulk_move() {
		return $this->bulk_move;
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
	 * @param string $job 'offload', 'restore', 'retention' or 'move'.
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
	 * Release everything a WP-CLI run that is gone left behind: its claim,
	 * its record and its queue's process lock.
	 *
	 * Without the queue lock, an admin job of the same type started next
	 * would sit idle until the 10 minute lock expired. The lock is left
	 * alone while that queue runs a job of its own (--force).
	 *
	 * @since 0.1.4
	 *
	 * @param string $job 'offload', 'restore', 'retention' or 'move'.
	 * @return void
	 */
	public function clear_dead_cli_run( $job ) {
		$queues = array(
			'offload'   => $this->bulk_offload,
			'restore'   => $this->bulk_restore,
			'retention' => $this->bulk_retention,
			'move'      => $this->bulk_move,
		);
		$queue  = $queues[ $job ] ?? null;

		if ( null !== $queue && 'running' !== ( $queue::get_state()['status'] ?? 'idle' ) ) {
			$queue->release_lock();
		}

		$this->release_cli_job();
		self::clear_cli_run();
	}

	/**
	 * Option holding the running WP-CLI job's record (see set_cli_run()).
	 */
	public const CLI_RUN_OPTION = 'omnioffloader_cli_run';

	/**
	 * Option set by `wp omnioffloader stop`. Kept apart from the run record,
	 * which the running job rewrites after every item and would clobber.
	 */
	public const CLI_STOP_OPTION = 'omnioffloader_cli_stop';

	/**
	 * Option keeping the last WP-CLI job's record after it ended, for
	 * `wp omnioffloader status`.
	 */
	public const CLI_LAST_RUN_OPTION = 'omnioffloader_cli_last_run';

	/**
	 * Record the running WP-CLI job: who runs it and how far it got.
	 *
	 * Lets `wp omnioffloader stop` reach it, `status` report it, and a new
	 * run tell a live lock from one a dead process left behind.
	 *
	 * @since 0.1.4
	 *
	 * @param array{job: string, pid: int, host: string, started: int, total: int, processed: int, failed: int, background: bool, log: string, files_at_once?: int, in_flight?: int[]} $run Run record.
	 * @return void
	 */
	public static function set_cli_run( array $run ) {
		// When the run last reported, so a run that died can say roughly when.
		$run['updated'] = time();

		update_option( self::CLI_RUN_OPTION, $run, false );
	}

	/**
	 * The running WP-CLI job's record.
	 *
	 * @since 0.1.4
	 *
	 * @param bool $fresh Read the database, not this request's option cache —
	 *                    for a long-running process watching another one.
	 * @return array<string, mixed>|null
	 */
	public static function get_cli_run( $fresh = false ) {
		$run = $fresh ? self::read_option_uncached( self::CLI_RUN_OPTION ) : get_option( self::CLI_RUN_OPTION );

		return is_array( $run ) && ! empty( $run['job'] ) ? $run : null;
	}

	/**
	 * Forget the running WP-CLI job's record and any stop request.
	 *
	 * Attachments the run had in flight lose their "processing" status:
	 * a run interrupted mid-batch (Ctrl-C, a kill, a fatal error) would
	 * otherwise leave them showing "Offloading..." / "Restoring..." forever.
	 *
	 * @since 0.1.4
	 *
	 * @return void
	 */
	public static function clear_cli_run() {
		$run = self::read_option_uncached( self::CLI_RUN_OPTION );

		if ( is_array( $run ) && ! empty( $run['in_flight'] ) && is_array( $run['in_flight'] ) ) {
			AbstractBulkProcessor::clear_pending_status( array_map( 'intval', $run['in_flight'] ) );
		}

		if ( is_array( $run ) && ! empty( $run['job'] ) ) {
			unset( $run['in_flight'] );
			$run['stopped'] = (bool) self::read_option_uncached( self::CLI_STOP_OPTION );
			update_option( self::CLI_LAST_RUN_OPTION, $run, false );
		}

		delete_option( self::CLI_RUN_OPTION );
		delete_option( self::CLI_STOP_OPTION );
	}

	/**
	 * The last WP-CLI job's record, kept after it ended.
	 *
	 * @since 0.1.4
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_cli_last_run() {
		$run = get_option( self::CLI_LAST_RUN_OPTION );

		return is_array( $run ) && ! empty( $run['job'] ) ? $run : null;
	}

	/**
	 * Ask the running WP-CLI job to stop after its current item.
	 *
	 * @since 0.1.4
	 *
	 * @return void
	 */
	public static function request_cli_stop() {
		update_option( self::CLI_STOP_OPTION, time(), false );
	}

	/**
	 * Whether `wp omnioffloader stop` asked the running job to stop.
	 *
	 * Read from the database on every call: the job runs for hours in one
	 * process, and its option cache would never see the request.
	 *
	 * @since 0.1.4
	 *
	 * @return bool
	 */
	public static function cli_stop_requested() {
		return (bool) self::read_option_uncached( self::CLI_STOP_OPTION );
	}

	/**
	 * Whether the process recorded for the running WP-CLI job still exists.
	 *
	 * Only answerable on the server that runs it (several web servers can
	 * share one database) and where PHP can look up processes.
	 *
	 * @since 0.1.4
	 *
	 * @param array<string, mixed> $run Run record.
	 * @return bool|null True alive, false gone, null unknown.
	 */
	public static function cli_run_alive( array $run ) {
		$pid = (int) ( $run['pid'] ?? 0 );

		if ( $pid < 1 || (string) ( $run['host'] ?? '' ) !== (string) gethostname() ) {
			return null;
		}

		if ( function_exists( 'posix_kill' ) ) {
			// EPERM (1): the process exists but belongs to another user.
			return posix_kill( $pid, 0 ) || ( function_exists( 'posix_get_last_error' ) && 1 === posix_get_last_error() );
		}

		return is_dir( '/proc/self' ) ? is_dir( '/proc/' . $pid ) : null;
	}

	/**
	 * Read an option straight from the database.
	 *
	 * The update_option() function always writes the database, so this sees
	 * a value another process saved even with a persistent object cache.
	 *
	 * @param string $name Option name.
	 * @return mixed False when missing.
	 */
	private static function read_option_uncached( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must bypass the object cache, see above.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		return null === $value ? false : maybe_unserialize( $value );
	}

	/**
	 * The bulk job currently running, if any — admin queue or WP-CLI.
	 *
	 * Offload, restore and retention runs all read or delete the same local
	 * files, so only one may run at a time (Safety Invariant #8).
	 *
	 * @since 0.1.3
	 *
	 * @return string 'offload', 'restore', 'retention', 'move', or '' when idle.
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
			'move'      => $this->bulk_move,
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
	 * @param string $job The job about to start ('offload', 'restore', 'retention' or 'move').
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
			'move'      => __( 'A move to the current folder is running — wait for it to finish first.', 'omnioffloader' ),
		);

		if ( $active === $job ) {
			$messages = array(
				'offload'   => __( 'A bulk offload is already running.', 'omnioffloader' ),
				'restore'   => __( 'A bulk restore is already running.', 'omnioffloader' ),
				'retention' => __( 'A retention policy update is already running.', 'omnioffloader' ),
				'move'      => __( 'A move to the current folder is already running.', 'omnioffloader' ),
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
		add_filter( 'plugin_row_meta', array( $this, 'add_plugin_docs_link' ), 10, 2 );

		// Offload state must stay truthful (Safety Invariant #4): never
		// editable as a custom field, via the UI, XML-RPC or REST meta.
		add_filter( 'is_protected_meta', array( $this, 'protect_offload_meta' ), 10, 2 );

		add_action( 'admin_notices', array( $this, 'safe_mode_notice' ) );
	}

	/**
	 * Tell administrators on the Dashboard and Media Library that this site
	 * is treated as a copy. The plugin's own screens show a banner instead.
	 *
	 * @since 0.1.3
	 *
	 * @return void
	 */
	public function safe_mode_notice() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_configured() || ! SafeMode::is_active() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || ! in_array( $screen->id, array( 'dashboard', 'upload' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'OmniOffloader Safe mode:', 'omnioffloader' ),
			esc_html__( 'this site looks like a staging, development or local copy, so nothing is uploaded to or deleted from your cloud storage. Media already in the cloud still loads from it.', 'omnioffloader' ),
			esc_url( admin_url( 'admin.php?page=omnioffloader&view=settings' ) ),
			esc_html__( 'Review in Settings', 'omnioffloader' )
		);
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
	 * Mark the plugin's attachment meta as protected.
	 *
	 * @since 0.1.3
	 *
	 * @param bool   $is_protected Whether the key is protected.
	 * @param string $meta_key     Meta key.
	 * @return bool
	 */
	public function protect_offload_meta( $is_protected, $meta_key ) {
		return 0 === strpos( (string) $meta_key, 'omnioffloader_' ) ? true : $is_protected;
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
	 * Add a Documentation link to the plugin's row on the Plugins screen.
	 *
	 * @param array<int|string, string> $meta Existing plugin row meta links.
	 * @param string                    $file Plugin basename of the row.
	 * @return array<int|string, string>
	 */
	public function add_plugin_docs_link( $meta, $file ) {
		if ( OMNIOFFLOADER_BASENAME !== $file ) {
			return $meta;
		}

		$meta[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( OMNIOFFLOADER_DOCS_URL ),
			esc_html__( 'Documentation', 'omnioffloader' )
		);

		return $meta;
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

		$move_api = new MoveController();
		$move_api->init();
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

		// The address the plugin runs on once configured is the live one a
		// later copy (staging, dev, local) is recognised against.
		SafeMode::maybe_record_live_site();

		// Must exist on every request: their constructors register the
		// async loopback + cron-healthcheck hooks that drive the queues.
		$this->async_upload   = new AsyncUploadProcessor( $provider );
		$this->bulk_offload   = new BulkOffloadProcessor( $provider );
		$this->bulk_restore   = new BulkRestoreProcessor();
		$this->bulk_retention = new BulkRetentionProcessor();
		$this->bulk_move      = new BulkMoveProcessor( $provider );

		$offloader = new Offloader( $provider, $this->async_upload );
		$offloader->register_observers();
	}
}
