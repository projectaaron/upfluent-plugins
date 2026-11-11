<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Removes everything the plugin stored. Run by register_uninstall_hook().
 * FluentCart may not be loaded here, so $wpdb is used directly.
 */
class Uninstaller
{
    public static function run(): void
    {
        global $wpdb;

        // This plugin and the former Pro edition share every option, field
        // value and upload. Deleting one while the other is still installed
        // must not wipe them. Folders: custom-meta-fields-for-fluentcart, -pro
        // (former Pro edition), and fct-custom-meta-fields(-pro) from pre-release builds.
        $self   = basename(dirname(__DIR__));
        $others = array_diff(['custom-meta-fields-for-fluentcart', 'custom-meta-fields-for-fluentcart-pro', 'fct-custom-meta-fields', 'fct-custom-meta-fields-pro'], [$self]);
        foreach ($others as $other) {
            if (defined('WP_PLUGIN_DIR') && is_dir(WP_PLUGIN_DIR . '/' . $other)) {
                return;
            }
        }

        // Field values live in post meta under each field's id.
        $definitions = get_option('fct_custom_meta_field_definitions', []);
        if (is_array($definitions)) {
            foreach ($definitions as $def) {
                if (is_array($def) && !empty($def['field_id']) && is_string($def['field_id'])) {
                    ProductValuesService::deleteProductMeta(sanitize_key($def['field_id']));
                }
            }
        }

        // Per-variation identifiers (GTIN, MPN, brand) and their lookup rows.
        foreach (['_fct_cmf_identifiers', '_fct_cmf_gtin', '_cmf_identifiers', '_cmf_gtin'] as $key) {
            ProductValuesService::deleteProductMeta($key);
        }

        delete_option('fct_custom_meta_field_definitions');
        delete_option('fct_custom_meta_db_version');
        delete_option('fct_cmf_pending_uploads');
        delete_option('fct_cmf_core_watch');
        delete_option('upfluent_cmf_review');
        delete_option('__fct_cmf_license');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('fct_cmf_pu_') . '%'));
        delete_site_transient('fct_custom_meta_release');
        wp_clear_scheduled_hook('fct_cmf_cleanup_uploads');
        wp_clear_scheduled_hook('fct_cmf_core_watch_scan');
        wp_clear_scheduled_hook('fct_cmf_license_check');

        do_action('upfluent_cmf_uninstall');

        // Locally stored checkout uploads. Files on a remote driver (S3 …) are left in the bucket.
        $upload_dir = wp_get_upload_dir();
        $local_dir  = trailingslashit($upload_dir['basedir']) . 'fluent-cart/cmf-checkout';
        if (is_dir($local_dir)) {
            // scandir, not glob(GLOB_BRACE): the flag is missing on musl builds.
            foreach ((array) scandir($local_dir) as $entry) {
                if (is_string($entry) && is_file($local_dir . '/' . $entry)) {
                    wp_delete_file($local_dir . '/' . $entry);
                }
            }
            @rmdir($local_dir); // phpcs:ignore
        }

        // Checkout field values saved on orders (one row per order in FluentCart's order meta table).
        $order_meta = $wpdb->prefix . 'fct_order_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $has_order_meta = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $order_meta));
        if ($has_order_meta === $order_meta) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are $wpdb->prefix constants
            $wpdb->query($wpdb->prepare("DELETE FROM {$order_meta} WHERE meta_key = %s", 'cmf_checkout_fields'));
        }

        // Legacy (early pre-release) rows in FluentCart's product meta table and old post-meta fallback.
        $prefix_like = $wpdb->esc_like('_fct_cm_') . '%';

        $table = $wpdb->prefix . 'fct_product_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists === $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names are $wpdb->prefix constants
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE meta_key LIKE %s", $prefix_like));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $prefix_like));
    }
}
