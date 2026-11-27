<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * The activity log shown under Troubleshooting: one row per event in the
 * plugin's own table, kept 30 days. Customer details (names, addresses,
 * emails, phone numbers) are only written while "Detailed logging" is on.
 * Order-specific events also go to the order's own activity in FluentCart.
 */
class Log
{
    const RETENTION_DAYS = 30;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'upfluent_sf_log';
    }

    public static function info(string $message, int $order_id = 0): void
    {
        self::write('info', $message, $order_id);
    }

    public static function warning(string $message, int $order_id = 0): void
    {
        self::write('warning', $message, $order_id);
    }

    public static function error(string $message, int $order_id = 0): void
    {
        self::write('error', $message, $order_id);
    }

    /** Only when detailed logging is on: messages that may contain customer details. */
    public static function detail(string $message, int $order_id = 0): void
    {
        if (Settings::get('debug')) {
            self::write('info', $message, $order_id);
        }
    }

    /**
     * Adds an entry to the order's activity in FluentCart, through its own
     * logger (which sanitizes title and content).
     */
    public static function order(int $order_id, string $title, string $content, string $status = 'info'): void
    {
        if ($order_id > 0 && function_exists('fluent_cart_add_log')) {
            fluent_cart_add_log($title, esc_html($content), $status, [
                'module_name' => 'order',
                'module_id'   => $order_id,
                'created_by'  => 'ShipFusion',
            ]);
        }
    }

    private static function write(string $level, string $message, int $order_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own log table.
        $wpdb->insert(self::table(), [
            'created_at' => gmdate('Y-m-d H:i:s'),
            'level'      => $level,
            'order_id'   => $order_id,
            'message'    => substr(sanitize_textarea_field($message), 0, 2000),
        ], ['%s', '%s', '%d', '%s']);
    }

    /** Newest first. */
    public static function recent(int $limit = 200): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table, read on demand.
        $rows = $wpdb->get_results($wpdb->prepare('SELECT created_at, level, order_id, message FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit), ARRAY_A);
        return array_map(function ($row) {
            return [
                'time'     => gmdate('c', strtotime($row['created_at'] . ' UTC')),
                'level'    => $row['level'],
                'order_id' => (int) $row['order_id'],
                'message'  => $row['message'],
            ];
        }, (array) $rows);
    }

    public static function prune(): void
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own log table.
        $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE created_at < %s', self::table(), $cutoff));
    }
}
