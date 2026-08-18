<?php
/**
 * Mirror delete: removes cloud objects when an attachment is deleted.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload\Observers;

use SmartMediaOffloader\Core\Helpers;
use SmartMediaOffloader\Offload\ObserverInterface;
use SmartMediaOffloader\Providers\ProviderInterface;
use SmartMediaOffloader\Traits\AttachmentHelper;
use SmartMediaOffloader\Traits\OptionsHelper;

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

		if ( empty( $settings['mirror_delete'] ) ) {
			return;
		}

		// Only delete from the provider the files actually live in.
		$stored_provider = (string) get_post_meta( $attachment_id, 'smowp_provider', true );

		if ( '' !== $stored_provider && $stored_provider !== $this->provider->get_key() ) {
			return;
		}

		$keys = self::collect_delete_keys( $attachment_id );

		$this->provider->delete_objects( $keys );
	}
}
