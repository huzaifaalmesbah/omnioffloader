<?php
/**
 * Mirror delete: removes cloud objects when an attachment is deleted.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload\Observers;

use OmniOffloader\Core\Helpers;
use OmniOffloader\Offload\ObserverInterface;
use OmniOffloader\Providers\ProviderInterface;
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
	 * Secondary backup provider.
	 *
	 * @var ProviderInterface|null
	 */
	private $secondary_provider;

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface      $provider           Cloud provider.
	 * @param ProviderInterface|null $secondary_provider Optional secondary backup provider.
	 */
	public function __construct( ProviderInterface $provider, ?ProviderInterface $secondary_provider = null ) {
		$this->provider           = $provider;
		$this->secondary_provider = $secondary_provider;
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

		if ( empty( $settings['mirror_delete'] ) ) {
			return;
		}

		$keys = self::collect_delete_keys( $attachment_id );

		// Delete from primary provider.
		$stored_provider = (string) get_post_meta( $attachment_id, 'omnioffloader_provider', true );

		if ( '' === $stored_provider || $stored_provider === $this->provider->get_key() ) {
			$this->provider->delete_objects( $keys );
		}

		// Also delete from secondary backup provider if offloaded.
		$secondary_offloaded = get_post_meta( $attachment_id, 'omnioffloader_secondary_offloaded', true );
		if ( $secondary_offloaded ) {
			$secondary = $this->secondary_provider;
			if ( null === $secondary ) {
				$sec_key = (string) get_post_meta( $attachment_id, 'omnioffloader_secondary_provider', true );
				if ( '' !== $sec_key ) {
					try {
						$secondary = \OmniOffloader\Providers\ProviderRegistry::create( $sec_key, 'secondary' );
					} catch ( \Exception $e ) {
						$secondary = null;
					}
				}
			}
			if ( null !== $secondary ) {
				$secondary->delete_objects( $keys );
			}
		}
	}
}
