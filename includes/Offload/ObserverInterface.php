<?php
/**
 * Observer contract: one WordPress hook concern per class.
 *
 * @package OmniOffloader
 */

namespace OmniOffloader\Offload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface ObserverInterface
 */
interface ObserverInterface {

	/**
	 * Register this observer's hooks.
	 *
	 * @return void
	 */
	public function register();
}
