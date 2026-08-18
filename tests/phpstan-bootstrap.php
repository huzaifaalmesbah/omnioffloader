<?php
/**
 * PHPStan bootstrap: declare constants defined at runtime by the main
 * plugin file so analysis can resolve them.
 *
 * @package SmartMediaOffloader
 */

define( 'SMOWP_VERSION', '0.1.0' );
define( 'SMOWP_DIR', dirname( __DIR__ ) . '/' );
define( 'SMOWP_URL', 'https://example.com/wp-content/plugins/smart-media-offloader/' );
define( 'SMOWP_ASSETS_URL', SMOWP_URL . 'assets/' );
define( 'SMOWP_BASENAME', 'smart-media-offloader/smart-media-offloader.php' );
define( 'SMOWP_FILE', SMOWP_DIR . 'smart-media-offloader.php' );
