<?php
/**
 * Observer contract: one WordPress hook concern per class.
 *
 * @package SmartMediaOffloader
 */

namespace SmartMediaOffloader\Offload;

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
