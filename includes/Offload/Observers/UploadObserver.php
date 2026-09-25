<?php
/**
 * Offloads new uploads once their metadata is generated.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Services\AsyncUploadProcessor;
use OmniOffloader\Services\AttachmentUploader;
use OmniOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UploadObserver
 */
class UploadObserver implements ObserverInterface {

	use OptionsHelper;

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Background async upload processor.
	 *
	 * @var AsyncUploadProcessor|null
	 */
	private $async_processor;

	/**
	 * Whether background dispatch has been triggered for this request.
	 *
	 * @var bool
	 */
	private $dispatched = false;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface         $provider        Cloud provider.
	 * @param AsyncUploadProcessor|null $async_processor Optional background async upload processor.
	 */
	public function __construct( ProviderInterface $provider, ?AsyncUploadProcessor $async_processor = null ) {
		$this->provider        = $provider;
		$this->async_processor = $async_processor;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Immediately set queued status on attachment creation so Media Library UI
		// shows background processing state while metadata generates.
		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );

		// Late priority: run after every size (and modern-format source)
		// has been added to the metadata by core and other plugins.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle' ), 999, 2 );
	}

	/**
	 * Mark newly created attachment as queued immediately.
	 *
	 * @since 0.1.1
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function on_add_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		// A copy of the site keeps new uploads on its own server.
		if ( SafeMode::is_active() ) {
			return;
		}

		if ( $this->should_use_async( $attachment_id ) ) {
			update_post_meta( $attachment_id, 'omnioffloader_status', 'queued' );
		}
	}

	/**
	 * Offload the attachment after metadata generation.
	 *
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @return array<string, mixed> Unchanged metadata.
	 */
	public function handle( $metadata, $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( SafeMode::is_active() ) {
			return $metadata;
		}

		if ( $this->should_use_async( $attachment_id ) ) {
			$this->queue_for_async_upload( $attachment_id );

			return $metadata;
		}

		// Synchronous fallback (e.g. WP-CLI, tests, or async_upload disabled in settings).
		$uploader = new AttachmentUploader( $this->provider );
		$uploader->upload( $attachment_id, is_array( $metadata ) ? $metadata : null );

		return $metadata;
	}

	/**
	 * Whether this attachment upload should be processed in the background.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	private function should_use_async( $attachment_id ) {
		if ( null === $this->async_processor ) {
			return false;
		}

		$settings = self::get_settings();
		if ( empty( $settings['async_upload'] ) ) {
			return false;
		}

		// WP-CLI commands (like wp media import) run synchronously in terminal by default.
		$use_async = ! ( defined( 'WP_CLI' ) && WP_CLI && ! wp_doing_cron() );

		/**
		 * Filter whether a new attachment should be offloaded asynchronously in the background.
		 *
		 * @param bool $use_async     Default true (false in WP-CLI terminal).
		 * @param int  $attachment_id Attachment ID.
		 */
		return (bool) apply_filters( 'omnioffloader_use_async_upload', $use_async, $attachment_id );
	}

	/**
	 * Queue the attachment for background upload and register shutdown dispatch.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function queue_for_async_upload( $attachment_id ) {
		if ( ! $this->async_processor ) {
			return;
		}

		update_post_meta( $attachment_id, 'omnioffloader_status', 'queued' );

		$this->async_processor->push_to_queue( $attachment_id );
		$this->async_processor->save();

		// Schedule dispatch on shutdown so WordPress finishes saving
		// _wp_attachment_metadata to the DB before the worker runs.
		add_action( 'shutdown', array( $this, 'dispatch_async_upload' ) );
	}

	/**
	 * Dispatch background processing on request shutdown.
	 *
	 * In PHP-FPM, fastcgi_finish_request() releases the client response
	 * immediately so the user experiences zero waiting time.
	 *
	 * @since 0.1.1
	 *
	 * @return void
	 */
	public function dispatch_async_upload() {
		if ( $this->dispatched ) {
			return;
		}

		$this->dispatched = true;

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		if ( $this->async_processor ) {
			$this->async_processor->dispatch();
		}
	}
}
