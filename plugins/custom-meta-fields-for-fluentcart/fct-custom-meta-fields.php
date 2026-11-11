<?php
/**
 * Plugin Name: Custom Meta Fields for FluentCart
 * Description: Unlimited custom product and checkout fields for FluentCart, shown with shortcodes and Elementor widgets and emitted as Product JSON-LD, plus GTIN and MPN per variation and a brand per product.
 * Version: 1.1.1
 * Author: upfluent.io
 * Author URI: https://upfluent.io/fluentcart-custom-meta-fields/
 * Requires at least: 5.9
 * Requires PHP: 7.4
 * Text Domain: custom-meta-fields-for-fluentcart
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined('ABSPATH') || exit;

/**
 * One copy at a time. Sites that bought the former Pro edition can still have
 * its folder (custom-meta-fields-for-fluentcart-pro) active next to this one.
 * It loads first and defines the same constants, so this copy stands aside and
 * offers to switch it off; everything it did is in this plugin now and both
 * use the same data. The Pro edition's last update (1.1.0) does the switch
 * itself.
 */
if (defined('UPFLUENT_CMF_FILE')) {
    $upfluent_cmf_running = plugin_basename(UPFLUENT_CMF_FILE);
    if ($upfluent_cmf_running !== plugin_basename(__FILE__)) {
        $upfluent_cmf_notice = function () use ($upfluent_cmf_running) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $network = is_multisite() && is_plugin_active_for_network($upfluent_cmf_running);
            if (!current_user_can($network ? 'manage_network_plugins' : 'activate_plugins') || $network !== is_network_admin()) {
                return;
            }
            $base = $network ? network_admin_url('plugins.php') : admin_url('plugins.php');
            $url  = wp_nonce_url(add_query_arg(['action' => 'deactivate', 'plugin' => rawurlencode($upfluent_cmf_running)], $base), 'deactivate-plugin_' . $upfluent_cmf_running);
            printf(
                '<div class="notice notice-info"><p>%s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
                esc_html__('Custom Meta Fields for FluentCart now includes everything from the Pro edition, free. Deactivate and then delete the Pro edition to switch over; your fields, values and checkout answers stay as they are.', 'custom-meta-fields-for-fluentcart'),
                esc_url($url),
                esc_html__('Deactivate the Pro edition', 'custom-meta-fields-for-fluentcart')
            );
        };
        add_action('admin_notices', $upfluent_cmf_notice);
        add_action('network_admin_notices', $upfluent_cmf_notice);
        unset($upfluent_cmf_notice);
    }
    unset($upfluent_cmf_running);
    return;
}

define('UPFLUENT_CMF_VERSION', '1.1.1');
define('UPFLUENT_CMF_FILE', __FILE__);
define('UPFLUENT_CMF_DIR', plugin_dir_path(__FILE__));
define('UPFLUENT_CMF_URL', plugin_dir_url(__FILE__));

require_once UPFLUENT_CMF_DIR . 'includes/Permissions.php';
require_once UPFLUENT_CMF_DIR . 'includes/AdminMenu.php';
require_once UPFLUENT_CMF_DIR . 'includes/Uninstaller.php';
require_once UPFLUENT_CMF_DIR . 'includes/FieldsService.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProductValuesService.php';
require_once UPFLUENT_CMF_DIR . 'includes/PostMetaRegistrar.php';
require_once UPFLUENT_CMF_DIR . 'includes/EditorWidget.php';
require_once UPFLUENT_CMF_DIR . 'includes/FieldDefinitionsController.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProductFieldsController.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProductIdentifiers.php';
require_once UPFLUENT_CMF_DIR . 'includes/CoreFeatureWatch.php';
require_once UPFLUENT_CMF_DIR . 'includes/ReviewNotice.php';
require_once UPFLUENT_CMF_DIR . 'includes/Shortcodes.php';
require_once UPFLUENT_CMF_DIR . 'includes/FluentHub.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProductSchema.php';
require_once UPFLUENT_CMF_DIR . 'includes/Abilities.php';
require_once UPFLUENT_CMF_DIR . 'includes/MediaHelper.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProDefinitions.php';
require_once UPFLUENT_CMF_DIR . 'includes/CheckoutFields.php';
require_once UPFLUENT_CMF_DIR . 'includes/CheckoutUploads.php';
require_once UPFLUENT_CMF_DIR . 'includes/CheckoutSettingsBridge.php';
require_once UPFLUENT_CMF_DIR . 'includes/IdentifierImport.php';
require_once UPFLUENT_CMF_DIR . 'includes/ProRendering.php';
require_once UPFLUENT_CMF_DIR . 'includes/ElementorWidgets/Integration.php';

// Media and repeater types, the checkout context and their storage rules.
\UpfluentCmf\ProDefinitions::register();

// Registered early so the handle exists before Elementor enqueues it for a widget.
add_action('wp_enqueue_scripts', ['\UpfluentCmf\Shortcodes', 'registerStyle'], 5);

/** After activation, open the settings screen with the welcome panel once. */
register_activation_hook(__FILE__, function () {
    if (!(defined('WP_CLI') && WP_CLI) && !isset($_GET['activate-multi'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        set_transient('fct_cmf_activation_redirect', 1, 60);
    }
});
add_action('admin_init', function () {
    if (!get_transient('fct_cmf_activation_redirect')) {
        return;
    }
    delete_transient('fct_cmf_activation_redirect');
    if (wp_doing_ajax() || !current_user_can('manage_options') || !defined('FLUENT_CART_DIR_FILE')) {
        return;
    }
    wp_safe_redirect(admin_url('admin.php?page=fct-custom-meta&welcome=1'));
    exit;
});

/** Support link on the Plugins screen. */
add_filter('plugin_row_meta', function ($links, $file) {
    if ($file === plugin_basename(UPFLUENT_CMF_FILE)) {
        $links[] = '<a href="' . esc_url('https://upfluent.io/support/') . '" target="_blank" rel="noopener">' . esc_html__('Support', 'custom-meta-fields-for-fluentcart') . '</a>';
    }
    return $links;
}, 10, 2);

/**
 * FluentHub portal registration. Hooked straight from plugins_loaded, not
 * from fluentcart_loaded: FluentHub applies its apps filter at init (10) and
 * silently skips anything registered later.
 */
add_action('plugins_loaded', function () {
    (new \UpfluentCmf\FluentHub())->register();
}, 5);

/**
 * Everything below needs FluentCart. fluentcart_loaded fires on plugins_loaded
 * with the app container; FluentCart registers the fluent-products post type
 * on init.
 */
add_action('fluentcart_loaded', function () {
    // Settings screen, in FluentCart's own sidebar and the WordPress one.
    (new \UpfluentCmf\AdminMenu())->register();

    // register_post_meta() for every field on fluent-products + one-time migration.
    (new \UpfluentCmf\PostMetaRegistrar())->register();

    // The product editor card as a FluentCart sidebar widget, saved with FluentCart's Save.
    (new \UpfluentCmf\EditorWidget())->register();

    // [upfluent_cmf_field] and [upfluent_cmf_gtin].
    (new \UpfluentCmf\Shortcodes())->register();
    (new \UpfluentCmf\ProductIdentifiers())->register();

    // JSON-LD Product / Breadcrumb / Organization on single product pages.
    (new \UpfluentCmf\ProductSchema())->register();

    // AI agent access: WordPress Abilities → FluentCart MCP server / MCP adapter.
    (new \UpfluentCmf\Abilities())->register();

    // Flags FluentCart updates that add GTIN, schema or custom fields to core.
    (new \UpfluentCmf\CoreFeatureWatch())->register();
    (new \UpfluentCmf\ReviewNotice())->register();

    // Media and repeater output and [upfluent_cmf_repeater].
    (new \UpfluentCmf\ProRendering())->register();

    // Checkout fields (render, validate, save, order screen, receipt, emails),
    // uploads through FluentCart storage, and the switches on FluentCart's own
    // Checkout Fields screen.
    (new \UpfluentCmf\CheckoutFields())->register();
    (new \UpfluentCmf\CheckoutUploads())->register();
    (new \UpfluentCmf\CheckoutSettingsBridge())->register();

    // Elementor widgets for Media and Repeater fields. No-op without Elementor.
    (new \UpfluentCmf\ElementorWidgets\Integration())->register();
});

register_uninstall_hook(__FILE__, ['\\UpfluentCmf\\Uninstaller', 'run']);

register_deactivation_hook(__FILE__, function () {
    \UpfluentCmf\CoreFeatureWatch::unschedule();
    \UpfluentCmf\CheckoutUploads::unschedule();
});

add_action('rest_api_init', function () {
    if (!defined('FLUENT_CART_DIR_FILE')) {
        return;
    }

    (new \UpfluentCmf\FieldDefinitionsController())->register();
    (new \UpfluentCmf\ProductFieldsController())->register();
    (new \UpfluentCmf\ProductIdentifiers())->registerRoutes();
    (new \UpfluentCmf\IdentifierImport())->registerRoutes();
});

add_action('admin_enqueue_scripts', function () {
    if (!defined('FLUENT_CART_DIR_FILE')) {
        return;
    }

    $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

    $is_plugin_page     = ($current_page === 'fct-custom-meta');
    $is_fluentcart_page = ($current_page === 'fluent-cart');

    if (!$is_plugin_page && !$is_fluentcart_page) {
        return;
    }

    // admin-pro.js (Media, Repeater, Checkout tab) must run before admin.js.
    $admin_deps = wp_script_is('fct-custom-meta-admin-pro', 'registered') ? ['fct-custom-meta-admin-pro'] : [];

    wp_enqueue_script(
        'fct-custom-meta-admin',
        UPFLUENT_CMF_URL . 'assets/admin.js',
        $admin_deps,
        UPFLUENT_CMF_VERSION,
        true
    );

    wp_enqueue_style(
        'fct-custom-meta-admin',
        UPFLUENT_CMF_URL . 'assets/admin.css',
        [],
        UPFLUENT_CMF_VERSION
    );

    $config = [
        'restUrl'     => rest_url('fct-custom-meta/v1/'),
        'nonce'       => wp_create_nonce('wp_rest'),
        'adminUrl'    => admin_url(),
        'context'     => $is_plugin_page ? 'settings' : 'fluentcart',
        'inHub'       => $is_plugin_page && \UpfluentCmf\FluentHub::unifiedUiEnabled(),
        'isAdmin'     => current_user_can('manage_options'),
        'canEdit'     => \UpfluentCmf\Permissions::canEditProduct(),
        'editorWidget' => \UpfluentCmf\EditorWidget::available(),
        'canManage'   => \UpfluentCmf\Permissions::canManageDefinitions(),
        'i18n'        => [
            'loading'          => __('Loading custom fields…', 'custom-meta-fields-for-fluentcart'),
            'noFields'         => __('No custom fields defined.', 'custom-meta-fields-for-fluentcart'),
            'addFieldsHere'    => __('Add fields here', 'custom-meta-fields-for-fluentcart'),
            'loadError'        => __('Could not load custom fields.', 'custom-meta-fields-for-fluentcart'),
            'save'             => __('Save Custom Fields', 'custom-meta-fields-for-fluentcart'),
            'saving'           => __('Saving…', 'custom-meta-fields-for-fluentcart'),
            'saved'            => __('Saved.', 'custom-meta-fields-for-fluentcart'),
            'saveError'        => __('Could not save.', 'custom-meta-fields-for-fluentcart'),
            'readOnly'         => __('You can view but not edit these fields.', 'custom-meta-fields-for-fluentcart'),
            'unsaved'          => __('You have unsaved custom field changes.', 'custom-meta-fields-for-fluentcart'),
            'select'           => __('— Select —', 'custom-meta-fields-for-fluentcart'),
            'notInList'        => __('(not in list)', 'custom-meta-fields-for-fluentcart'),
            'enabled'          => __('Enabled', 'custom-meta-fields-for-fluentcart'),
            'noColor'          => __('No color', 'custom-meta-fields-for-fluentcart'),
            'required'         => __('Field ID and Title are required.', 'custom-meta-fields-for-fluentcart'),
            'invalidId'        => __('Field ID may only contain lowercase letters, numbers, underscores and hyphens.', 'custom-meta-fields-for-fluentcart'),
            'confirmDelete'    => __('Delete this field? Its stored values will be removed from all products.', 'custom-meta-fields-for-fluentcart'),
            'addField'         => __('Add Field', 'custom-meta-fields-for-fluentcart'),
            'updateField'      => __('Update Field', 'custom-meta-fields-for-fluentcart'),
            'addNewField'      => __('Add New Field', 'custom-meta-fields-for-fluentcart'),
            'editField'        => __('Edit Field', 'custom-meta-fields-for-fluentcart'),
            'errorSaving'      => __('Error saving field.', 'custom-meta-fields-for-fluentcart'),
            'noneDefined'      => __('No custom fields defined yet. Add one above.', 'custom-meta-fields-for-fluentcart'),
            'backToFluentCart' => __('Back to FluentCart', 'custom-meta-fields-for-fluentcart'),
            'sectionTitle'     => __('Custom Meta Fields', 'custom-meta-fields-for-fluentcart'),
            'copyShortcode'    => __('Copy Shortcode', 'custom-meta-fields-for-fluentcart'),
            'copySmartcode'    => __('Copy Email Code', 'custom-meta-fields-for-fluentcart'),
            'copied'           => __('Copied', 'custom-meta-fields-for-fluentcart'),
            'productTab'       => __('Product Fields', 'custom-meta-fields-for-fluentcart'),
        ],
    ];

    // admin-pro.js settings and strings (ProDefinitions::adminConfig).
    $config = apply_filters('upfluent_cmf_admin_config', $config, $is_plugin_page);

    wp_localize_script('fct-custom-meta-admin', 'fctCustomMeta', is_array($config) ? $config : []);
});
