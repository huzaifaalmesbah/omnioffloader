<?php
/**
 * Mirror delete: removes cloud objects when an attachment is deleted.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Core\SafeMode;
use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Services\AttachmentRestorer;
use OmniOffloader\Traits\AttachmentHelper;
use OmniOffloader\Traits\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DeleteObserver
 */
class DeleteObserver implements ObserverInterface {

	use AttachmentHelper;
	use OptionsHelper;

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
		// Before core deletes files/meta, while metadata is still readable.
		add_action( 'delete_attachment', array( $this, 'handle' ), 10, 1 );
	}

	/**
	 * Delete the attachment's cloud objects when Mirror Delete is enabled.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function handle( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( ! self::is_offloaded( $attachment_id ) ) {
			return;
		}

		Helpers::flush_media_stats();

		$settings = self::get_settings();

		// A copy of the site must never delete files the live site serves.
		if ( empty( $settings['mirror_delete'] ) || SafeMode::is_active() ) {
			return;
		}

		$keys = self::collect_delete_keys( $attachment_id );

		// Delete from the provider it was offloaded to: after a provider
		// switch, that provider's saved settings still reach its bucket.
		$stored_provider = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );
		$provider        = '' === $stored_provider || $stored_provider === $this->provider->get_key()
			? $this->provider
			: AttachmentRestorer::provider_for( $attachment_id );

		if ( null !== $provider ) {
			$provider->delete_objects( $keys );
		}
	}
}
