<?php
// wordpress/mercora-plugin/mercora.php
/**
 * Plugin Name:      Mercora
 * Description:      Mercora / Alice integration layer for WooCommerce.
 * Version:          0.2.0
 * Requires PHP:     8.1
 * Requires Plugins: woocommerce
 */

defined("ABSPATH") || exit();

define("MERCORA_VERSION", "0.2.0");
define("MERCORA_PATH", plugin_dir_path(__FILE__));
define("MERCORA_URL", plugin_dir_url(__FILE__));

// Local, uncommitted settings (Alice sync URL + secret).
if (file_exists(MERCORA_PATH . "config.local.php")) {
    require_once MERCORA_PATH . "config.local.php";
}

/** Asset version = file modified time, so a normal refresh always loads the latest CSS/JS. */
function mercora_asset_ver(string $rel): string
{
    $file = MERCORA_PATH . $rel;
    return file_exists($file) ? (string) filemtime($file) : MERCORA_VERSION;
}

require_once MERCORA_PATH . "includes/class-assistant-route.php";
require_once MERCORA_PATH . "includes/class-rest.php";
require_once MERCORA_PATH . "includes/class-launcher.php";
require_once MERCORA_PATH . "includes/class-catalog-controls.php";
require_once MERCORA_PATH . "includes/class-home.php";
require_once MERCORA_PATH . "includes/class-footer.php";
require_once MERCORA_PATH . "includes/class-sync.php";
require_once MERCORA_PATH . "includes/class-brain.php";

Mercora\Assistant_Route::init();
Mercora\Rest::init();
Mercora\Launcher::init();
Mercora\Catalog_Controls::init();
Mercora\Home::init();
Mercora\Footer::init();
Mercora\Sync::init();

register_activation_hook(__FILE__, static function () {
    Mercora\Assistant_Route::add_rewrite();
    flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, "flush_rewrite_rules");
