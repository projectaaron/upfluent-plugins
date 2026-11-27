<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * A lock that two requests cannot both take: one INSERT IGNORE on the
 * options table's unique key, so only one caller creates the row. (Not
 * add_option(): its INSERT … ON DUPLICATE KEY UPDATE lets a second caller
 * through.) A lock left by a request that died expires after its TTL.
 */
class Lock
{
    public static function acquire(string $name, int $ttl = 600): bool
    {
        global $wpdb;
        $option  = 'upfluent_sf_lock_' . $name;
        $expires = time() + $ttl;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic insert-if-absent; no WordPress API offers it.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $option,
            $expires
        ));
        wp_cache_delete($option, 'options');
        wp_cache_delete('notoptions', 'options');
        if ($inserted === 1) {
            return true;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the lock row itself, not a cached copy.
        $current = (int) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
        if ($current > time()) {
            return false;
        }
        // Expired: take it over only if nobody else did in the meantime.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compare-and-swap on the lock row; no WordPress API offers it.
        $taken = $wpdb->update($wpdb->options, ['option_value' => $expires], ['option_name' => $option, 'option_value' => $current]);
        wp_cache_delete($option, 'options');
        return (bool) $taken;
    }

    public static function release(string $name): void
    {
        delete_option('upfluent_sf_lock_' . $name);
    }

    /** Pushes the expiry forward during a long run. */
    public static function extend(string $name, int $ttl = 600): void
    {
        update_option('upfluent_sf_lock_' . $name, time() + $ttl, false);
    }
}
