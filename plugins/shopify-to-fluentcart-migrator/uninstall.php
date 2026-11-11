<?php
/**
 * Removes the migrator's own data. Imported FluentCart products are left alone.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

function s2fc_uninstall_site() {
    global $wpdb;

    // Per-user sessions and image transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $names = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 's2fc\\_session\\_%' OR option_name LIKE 's2fc\\_flash\\_%'");
    foreach ((array) $names as $name) {
        delete_option($name);
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $transients = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_s2fc\\_%'");
    foreach ((array) $transients as $name) {
        delete_transient(substr($name, strlen('_transient_')));
    }

    $upload = wp_upload_dir();
    $dir    = trailingslashit($upload['basedir']) . 's2fc-migrator';
    if (is_dir($dir)) {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($dir . '/' . $entry)) {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $s2fc_site_id) {
        switch_to_blog($s2fc_site_id);
        s2fc_uninstall_site();
        restore_current_blog();
    }
} else {
    s2fc_uninstall_site();
}
