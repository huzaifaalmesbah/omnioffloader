<?php
/**
 * PHPStan bootstrap: declare constants defined at runtime by the main
 * plugin file so analysis can resolve them.
 *
 * @package OmniOffloader
 */

define( 'OMNIOFFLOADER_VERSION', '0.1.3' );
define( 'OMNIOFFLOADER_DIR', dirname( __DIR__ ) . '/' );
define( 'OMNIOFFLOADER_URL', 'https://example.com/wp-content/plugins/omnioffloader/' );
define( 'OMNIOFFLOADER_ASSETS_URL', OMNIOFFLOADER_URL . 'assets/' );
define( 'OMNIOFFLOADER_BASENAME', 'omnioffloader/omnioffloader.php' );
define( 'OMNIOFFLOADER_FILE', OMNIOFFLOADER_DIR . 'omnioffloader.php' );
