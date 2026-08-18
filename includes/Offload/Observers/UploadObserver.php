<?php
/**
 * Offloads new uploads once their metadata is generated.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload\Observers;

use SmartMediaOffloader\Offload\ObserverInterface;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Services\AttachmentUploader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class UploadObserver
 */
class UploadObserver implements ObserverInterface {

	/**
	 * Cloud provider.
	 *
	 * @var ProviderInterface
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Late priority: run after every size (and modern-format source)
		// has been added to the metadata by core and other plugins.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle' ), 999, 2 );
	}

	/**
	 * Offload the attachment after metadata generation.
	 *
	 * @param array<string, mixed> $metadata      Attachment metadata.
	 * @param int                  $attachment_id Attachment ID.
	 * @return array<string, mixed> Unchanged metadata.
	 */
	public function handle( $metadata, $attachment_id ) {
		// The filter fires again for regenerations and edits; the uploader
		// is a no-op for already-offloaded attachments. Pass the in-flight
		// metadata through — the DB copy may not be saved yet on first upload.
		$uploader = new AttachmentUploader( $this->provider );
		$uploader->upload( (int) $attachment_id, is_array( $metadata ) ? $metadata : null );

		return $metadata;
	}
}
