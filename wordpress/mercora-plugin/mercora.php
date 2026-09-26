<?php
/**
 * Plugin Name:      Mercora
 * Description:      Mercora / Alice integration layer for WooCommerce.
 * Version:          0.1.1
 * Requires PHP:     8.1
 * Requires Plugins: woocommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'MERCORA_VERSION', '0.1.1' );
define( 'MERCORA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MERCORA_URL', plugin_dir_url( __FILE__ ) );

require_once MERCORA_PATH . 'includes/class-assistant-route.php';
require_once MERCORA_PATH . 'includes/class-rest.php';
require_once MERCORA_PATH . 'includes/class-launcher.php';
require_once MERCORA_PATH . 'includes/class-footer.php';
require_once MERCORA_PATH . 'includes/class-home.php';
require_once MERCORA_PATH . 'includes/class-catalog-controls.php';

Mercora\Assistant_Route::init();
Mercora\Rest::init();
Mercora\Launcher::init();
Mercora\Footer::init();
Mercora\Home::init();
Mercora\Catalog_Controls::init();

register_activation_hook( __FILE__, static function () {
	Mercora\Assistant_Route::add_rewrite();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
