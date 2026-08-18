<?php
/**
 * Offload engine orchestrator.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload;

use SmartMediaOffloader\Offload\Observers\ContentImageTagObserver;
use SmartMediaOffloader\Offload\Observers\DeleteObserver;
use SmartMediaOffloader\Offload\Observers\MediaLibraryFilterObserver;
use SmartMediaOffloader\Offload\Observers\OffloadStatusObserver;
use SmartMediaOffloader\Offload\Observers\RowActionsObserver;
use SmartMediaOffloader\Offload\Observers\SrcsetObserver;
use SmartMediaOffloader\Offload\Observers\UniqueFilenameObserver;
use SmartMediaOffloader\Offload\Observers\UploadObserver;
use SmartMediaOffloader\Offload\Observers\UrlRewriteObserver;
use SmartMediaOffloader\Providers\ProviderInterface;

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
	 * Attached observers.
	 *
	 * @var ObserverInterface[]
	 */
	private $observers = array();

	/**
	 * Constructor.
	 *
	 * @param ProviderInterface $provider Cloud provider.
	 */
	public function __construct( ProviderInterface $provider ) {
		$this->provider = $provider;
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
		$this->attach( new UploadObserver( $this->provider ) );
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
