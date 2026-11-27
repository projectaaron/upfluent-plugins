<?php
/**
 * Plugin Name: Order Sync for ShipFusion and FluentCart
 * Plugin URI: https://upfluent.io/order-sync-for-shipfusion-and-fluentcart/
 * Description: Send paid FluentCart orders to your ShipFusion warehouse and get tracking and stock back automatically.
 * Version: 1.0.0
 * Author: upfluent.io
 * Author URI: https://upfluent.io/
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Requires Plugins: fluent-cart
 * Text Domain: order-sync-for-shipfusion-and-fluentcart
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

/**
 * The earlier private plugin ("FluentCart ShipFusion", folder fluentcart-shipfusion)
 * does the same job. Two copies would send every order twice, so this one
 * stands aside while the old one is active and asks to switch it off.
 */
if (defined('FCSF_VERSION')) {
    $upfluent_sf_notice = function () {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
            esc_html__('Order Sync for ShipFusion and FluentCart replaces the older "FluentCart ShipFusion" plugin and stays off while that one is active. Deactivate the old plugin, then paste your ShipFusion API token on the new ShipFusion screen and update the webhook address in Shipfusion 360.', 'order-sync-for-shipfusion-and-fluentcart'),
            esc_url(admin_url('plugins.php')),
            esc_html__('Go to Plugins', 'order-sync-for-shipfusion-and-fluentcart')
        );
    };
    add_action('admin_notices', $upfluent_sf_notice);
    unset($upfluent_sf_notice);
    return;
}

define('UPFLUENT_SF_VERSION', '1.0.0');
define('UPFLUENT_SF_FILE', __FILE__);
define('UPFLUENT_SF_DIR', plugin_dir_path(__FILE__));
define('UPFLUENT_SF_URL', plugin_dir_url(__FILE__));

require_once UPFLUENT_SF_DIR . 'includes/Permissions.php';
require_once UPFLUENT_SF_DIR . 'includes/Settings.php';
require_once UPFLUENT_SF_DIR . 'includes/Secrets.php';
require_once UPFLUENT_SF_DIR . 'includes/Log.php';
require_once UPFLUENT_SF_DIR . 'includes/Store.php';
require_once UPFLUENT_SF_DIR . 'includes/ApiException.php';
require_once UPFLUENT_SF_DIR . 'includes/Api.php';
require_once UPFLUENT_SF_DIR . 'includes/Lock.php';
require_once UPFLUENT_SF_DIR . 'includes/OrderPayload.php';
require_once UPFLUENT_SF_DIR . 'includes/Orders.php';
require_once UPFLUENT_SF_DIR . 'includes/OrderEvents.php';
require_once UPFLUENT_SF_DIR . 'includes/Tracking.php';
require_once UPFLUENT_SF_DIR . 'includes/Stock.php';
require_once UPFLUENT_SF_DIR . 'includes/Webhook.php';
require_once UPFLUENT_SF_DIR . 'includes/Cron.php';
require_once UPFLUENT_SF_DIR . 'includes/Alerts.php';
require_once UPFLUENT_SF_DIR . 'includes/RestController.php';
require_once UPFLUENT_SF_DIR . 'includes/OrderWidget.php';
require_once UPFLUENT_SF_DIR . 'includes/AdminMenu.php';
require_once UPFLUENT_SF_DIR . 'includes/FluentHub.php';
require_once UPFLUENT_SF_DIR . 'includes/Installer.php';
require_once UPFLUENT_SF_DIR . 'includes/Uninstaller.php';

register_activation_hook(__FILE__, ['\\UpfluentSf\\Installer', 'activate']);
register_deactivation_hook(__FILE__, ['\\UpfluentSf\\Cron', 'unschedule']);
register_uninstall_hook(__FILE__, ['\\UpfluentSf\\Uninstaller', 'run']);

/** Tables are created on activation; this also covers updates and multisite copies. */
add_action('plugins_loaded', ['\\UpfluentSf\\Installer', 'maybeUpgrade'], 5);

/**
 * FluentHub portal registration. Hooked straight from plugins_loaded, not from
 * fluentcart_loaded: FluentHub applies its apps filter at init (10), and this
 * must not depend on load order.
 */
add_action('plugins_loaded', function () {
    (new \UpfluentSf\FluentHub())->register();
});

/** Support link on the Plugins screen. */
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(UPFLUENT_SF_FILE)) {
        $links[] = '<a href="' . esc_url('https://upfluent.io/support/') . '" target="_blank" rel="noopener">' . esc_html__('Support', 'order-sync-for-shipfusion-and-fluentcart') . '</a>';
    }
    return $links;
}, 10, 2);

/** Custom cron intervals are needed before any schedule is checked. */
add_filter('cron_schedules', ['\\UpfluentSf\\Cron', 'intervals']);

/**
 * Everything below needs FluentCart. fluentcart_loaded fires on
 * plugins_loaded once FluentCart's app container is ready.
 */
add_action('fluentcart_loaded', function () {
    (new \UpfluentSf\AdminMenu())->register();
    (new \UpfluentSf\OrderEvents())->register();
    (new \UpfluentSf\Cron())->register();
    (new \UpfluentSf\Alerts())->register();
    (new \UpfluentSf\OrderWidget())->register();

    // Follow-ups a webhook schedules (they run in a later, separate request).
    add_action('upfluent_sf_recheck_order', ['\\UpfluentSf\\Tracking', 'check']);
    // Shipment changes put off while an order was busy, and address edits.
    add_action('upfluent_sf_order_job', ['\\UpfluentSf\\Orders', 'runJob'], 10, 2);
    add_action('upfluent_sf_recheck_stock', ['\\UpfluentSf\\Webhook', 'stockNudge']);

    if (defined('WP_CLI') && WP_CLI) {
        require_once UPFLUENT_SF_DIR . 'includes/Cli.php';
        \WP_CLI::add_command('upfluent-sf', '\\UpfluentSf\\Cli');
    }
});

add_action('rest_api_init', function () {
    if (!defined('FLUENT_CART_DIR_FILE')) {
        return;
    }
    (new \UpfluentSf\RestController())->register();
    (new \UpfluentSf\Webhook())->register();
});

add_action('admin_enqueue_scripts', function () {
    if (!defined('FLUENT_CART_DIR_FILE') || !\UpfluentSf\AdminMenu::isCurrentPage()) {
        return;
    }

    wp_enqueue_script('upfluent-sf-admin', UPFLUENT_SF_URL . 'assets/admin.js', ['wp-i18n'], UPFLUENT_SF_VERSION, true);
    wp_set_script_translations('upfluent-sf-admin', 'order-sync-for-shipfusion-and-fluentcart');
    wp_enqueue_style('upfluent-sf-admin', UPFLUENT_SF_URL . 'assets/admin.css', [], UPFLUENT_SF_VERSION);

    wp_localize_script('upfluent-sf-admin', 'upfluentSfAdmin', [
        'restUrl'   => rest_url('upfluent-sf/v1/'),
        'nonce'     => wp_create_nonce('wp_rest'),
        'pageUrl'   => \UpfluentSf\AdminMenu::url(),
        'version'   => UPFLUENT_SF_VERSION,
        'canManage' => \UpfluentSf\Permissions::canManageSettings(),
    ]);
});
