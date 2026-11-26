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
use OmniOffloader\Traits\AttachmentHelper;
use OmniOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UploadObserver
 */
class UploadObserver implements ObserverInterface {

	use AttachmentHelper;
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
	 * Cron hook that offloads a client-side upload whose finalize request
	 * never arrived.
	 *
	 * @since 0.1.8
	 */
	public const FINALIZE_FALLBACK_HOOK = 'omnioffloader_client_upload_fallback';

	/**
	 * Seconds to wait for a client-side upload's finalize request before
	 * offloading it with whatever sizes reached the server.
	 *
	 * @since 0.1.8
	 */
	public const FINALIZE_WAIT = 15 * MINUTE_IN_SECONDS;

	/**
	 * Attachments created in this request by the client-side media pipeline
	 * (their sub-sizes are still to come), as a lookup set.
	 *
	 * @var array<int, true>
	 */
	private $client_side_uploads = array();

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
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle' ), 999, 3 );

		// Client-side media (WP 7.1+): the browser makes the sub-sizes and
		// sends them after the attachment is created; see handle().
		add_action( 'rest_insert_attachment', array( $this, 'note_client_side_upload' ), 10, 3 );
		add_action( self::FINALIZE_FALLBACK_HOOK, array( $this, 'finalize_fallback' ) );
	}

	/**
	 * Remember an attachment created by the client-side media pipeline.
	 *
	 * With client-side processing, `POST /wp/v2/media` (generate_sub_sizes
	 * false) saves only the uploaded file. The browser then sideloads every
	 * sub-size — a "scaled" one replaces the main file — and the finalize
	 * request runs wp_generate_attachment_metadata a second time, with the
	 * complete sizes and the context 'update'. Offloading on the first pass
	 * would upload the wrong main file, no sizes, and mark the attachment
	 * offloaded so the second pass is skipped.
	 *
	 * @since 0.1.8
	 *
	 * @param \WP_Post         $attachment Attachment.
	 * @param \WP_REST_Request $request    Request.
	 * @param bool             $creating   Whether the attachment is being created.
	 * @return void
	 */
	public function note_client_side_upload( $attachment, $request, $creating ) {
		if ( ! $creating || ! $attachment instanceof \WP_Post || ! $request instanceof \WP_REST_Request ) {
			return;
		}

		$generate = $request->get_param( 'generate_sub_sizes' );

		// The schema turns it into a boolean and defaults it to true; only an
		// explicit false is a client-side upload.
		if ( false === $generate || ( is_string( $generate ) && ! rest_sanitize_boolean( $generate ) ) ) {
			$this->client_side_uploads[ (int) $attachment->ID ] = true;
		}
	}

	/**
	 * Offload a client-side upload whose finalize request never arrived.
	 *
	 * Finalize is best effort in core: a closed tab or a failed request
	 * leaves the attachment with the sizes that reached the server.
	 *
	 * @since 0.1.8
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function finalize_fallback( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( SafeMode::is_active() || 'attachment' !== get_post_type( $attachment_id ) || self::is_offloaded( $attachment_id ) ) {
			return;
		}

		$this->offload( $attachment_id, null );
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
	 * Runs once per upload on the classic path ('create'). A client-side
	 * upload runs it twice: at creation, before its sub-sizes exist, and
	 * from the finalize request ('update') with all of them — only that
	 * second pass offloads. An 'update' for an attachment that is already
	 * offloaded (a finalize retry, resumed sub-sizes) uploads the files the
	 * bucket is missing.
	 *
	 * @since 0.1.8 Takes $context; waits for a client-side upload's finalize.
	 *
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $context       'create' or 'update'.
	 * @return array<string, mixed> Unchanged metadata.
	 */
	public function handle( $metadata, $attachment_id, $context = 'create' ) {
		$attachment_id = (int) $attachment_id;

		if ( SafeMode::is_active() ) {
			return $metadata;
		}

		if ( 'create' === $context && isset( $this->client_side_uploads[ $attachment_id ] ) ) {
			unset( $this->client_side_uploads[ $attachment_id ] );

			if ( $this->should_use_async( $attachment_id ) ) {
				update_post_meta( $attachment_id, 'omnioffloader_status', 'queued' );
			}

			if ( ! wp_next_scheduled( self::FINALIZE_FALLBACK_HOOK, array( $attachment_id ) ) ) {
				wp_schedule_single_event( time() + self::FINALIZE_WAIT, self::FINALIZE_FALLBACK_HOOK, array( $attachment_id ) );
			}

			return $metadata;
		}

		if ( 'update' === $context ) {
			wp_clear_scheduled_hook( self::FINALIZE_FALLBACK_HOOK, array( $attachment_id ) );
		}

		$this->offload( $attachment_id, is_array( $metadata ) ? $metadata : null );

		return $metadata;
	}

	/**
	 * Offload now or in the background, or sync the files an offloaded
	 * attachment's bucket copy is missing.
	 *
	 * @since 0.1.8
	 *
	 * @param int                       $attachment_id Attachment ID.
	 * @param array<string, mixed>|null $metadata      In-flight metadata, null to read it.
	 * @return void
	 */
	private function offload( $attachment_id, $metadata ) {
		if ( $this->should_use_async( $attachment_id ) ) {
			$this->queue_for_async_upload( $attachment_id );

			return;
		}

		// Synchronous fallback (e.g. WP-CLI, tests, or async_upload disabled in settings).
		$uploader = new AttachmentUploader( $this->provider );

		if ( self::is_offloaded( $attachment_id ) ) {
			$uploader->upload_missing( $attachment_id, $metadata );

			return;
		}

		$uploader->upload( $attachment_id, $metadata );
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
