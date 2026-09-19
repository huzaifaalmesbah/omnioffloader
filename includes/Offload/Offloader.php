<?php
/**
 * Offload engine orchestrator.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload;

use OmniOffloader\Offload\Observers\ContentImageTagObserver;
use OmniOffloader\Offload\Observers\DeleteObserver;
use OmniOffloader\Offload\Observers\MediaLibraryFilterObserver;
use OmniOffloader\Offload\Observers\OffloadStatusObserver;
use OmniOffloader\Offload\Observers\RowActionsObserver;
use OmniOffloader\Offload\Observers\SrcsetObserver;
use OmniOffloader\Offload\Observers\UniqueFilenameObserver;
use OmniOffloader\Offload\Observers\UploadObserver;
use OmniOffloader\Offload\Observers\UrlRewriteObserver;
use OmniOffloader\Providers\ProviderInterface;
use OmniOffloader\Services\AsyncUploadProcessor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Offloader
 *
 * Attaches every observer and registers their hooks. Only instantiated
 * when a provider is configured and the SDK is available.
 */
class Offloader {

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
	 * Attached observers.
	 *
	 * @var ObserverInterface[]
	 */
	private $observers = array();

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
	 * Attach an observer.
	 *
	 * @param ObserverInterface $observer Observer instance.
	 * @return void
	 */
	public function attach( ObserverInterface $observer ) {
		$this->observers[] = $observer;
	}

	/**
	 * Attach the default observers and register all hooks.
	 *
	 * @return void
	 */
	public function register_observers() {
		$this->attach( new UploadObserver( $this->provider, $this->async_processor ) );
		$this->attach( new UrlRewriteObserver( $this->provider ) );
		$this->attach( new SrcsetObserver( $this->provider ) );
		$this->attach( new ContentImageTagObserver( $this->provider ) );
		$this->attach( new DeleteObserver( $this->provider ) );
		$this->attach( new UniqueFilenameObserver() );
		$this->attach( new MediaLibraryFilterObserver() );
		$this->attach( new OffloadStatusObserver( $this->provider ) );
		$this->attach( new RowActionsObserver( $this->provider ) );

		foreach ( $this->observers as $observer ) {
			$observer->register();
		}
	}
}
