<?php
/**
 * Plugin Name: Shopify to FluentCart Migrator
 * Plugin URI:  https://upfluent.io/shopify-migrator/
 * Description: Move your Shopify products into FluentCart in three steps: export the CSV from Shopify, upload it here, review the list and publish. Free.
 * Version:     1.0.0
 * Author:      upfluent.io
 * Author URI:  https://upfluent.io/
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Text Domain: shopify-to-fluentcart-migrator
 * Domain Path: /languages
 * Requires Plugins: fluent-cart
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

if (defined('S2FC_VERSION')) {
    return; // Another copy is already loaded.
}

define('S2FC_VERSION', '1.0.0');
define('S2FC_FILE', __FILE__);
define('S2FC_DIR', plugin_dir_path(__FILE__));
define('S2FC_URL', plugin_dir_url(__FILE__));
define('S2FC_PAGE', 's2fc-migrator');

foreach (['Helpers', 'Session', 'Csv_Parser', 'Gtin', 'Importer', 'Promos', 'Ajax', 'Admin'] as $s2fc_class) {
    require_once S2FC_DIR . 'includes/class-' . strtolower(str_replace('_', '-', $s2fc_class)) . '.php';
}
unset($s2fc_class);

add_action('plugins_loaded', function () {
    load_plugin_textdomain('shopify-to-fluentcart-migrator', false, dirname(plugin_basename(S2FC_FILE)) . '/languages');

    if (is_admin()) {
        (new \S2FC\Admin())->register();
        (new \S2FC\Ajax())->register();
    }
});

register_activation_hook(__FILE__, function () {
    \S2FC\Session::ensure_dir();
});

register_deactivation_hook(__FILE__, function () {
    \S2FC\Session::clear();
});
