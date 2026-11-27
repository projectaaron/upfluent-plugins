<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Removes everything the plugin stored. Run by register_uninstall_hook();
 * FluentCart may not be loaded here. Orders and shipments in FluentCart and
 * in ShipFusion stay as they are.
 */
class Uninstaller
{
    public static function run(): void
    {
        if (!is_multisite()) {
            self::clean();
            return;
        }
        // Each site has its own tables, options and schedules.
        foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
            switch_to_blog((int) $site_id);
            self::clean();
            restore_current_blog();
        }
    }

    private static function clean(): void
    {
        global $wpdb;

        foreach (['upfluent_sf_orders', 'upfluent_sf_log'] as $table) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables on uninstall.
            $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
        }

        foreach (['upfluent_sf_settings', 'upfluent_sf_token', 'upfluent_sf_webhook_secret', 'upfluent_sf_state', 'upfluent_sf_since', 'upfluent_sf_db_version', 'upfluent_sf_sweep_after'] as $option) {
            delete_option($option);
        }

        // Locks and webhook de-duplication markers.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options and transients with the plugin's prefix; WordPress has no delete-by-prefix API.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('upfluent_sf_lock_') . '%',
            $wpdb->esc_like('_transient_upfluent_sf_') . '%',
            $wpdb->esc_like('_transient_timeout_upfluent_sf_') . '%'
        ));

        foreach (['upfluent_sf_send_orders', 'upfluent_sf_check_tracking', 'upfluent_sf_sync_stock', 'upfluent_sf_daily', 'upfluent_sf_send_digest', 'upfluent_sf_recheck_order', 'upfluent_sf_recheck_stock', 'upfluent_sf_order_job'] as $hook) {
            wp_unschedule_hook($hook);
        }
    }
}
