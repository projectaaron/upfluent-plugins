<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * One row per FluentCart order this plugin handles, in its own table:
 * where the order is (state), its ShipFusion shipment id and status, retry
 * bookkeeping, and the problem a person must look at.
 *
 * States:
 *   waiting     to be sent (next_try_at says when)
 *   sent        in ShipFusion's queue or being packed
 *   on_hold     on hold in ShipFusion (address check, or held from FluentCart)
 *   back_order  waiting for stock in ShipFusion
 *   shipped     shipped; a FluentCart shipment with tracking exists
 *   delivered   the carrier reports it delivered
 *   cancelled   cancelled in ShipFusion, or never sent because it was cancelled
 *   attention   a person has to look at it (problem says why)
 *   handled     a person marked the problem as handled; nothing more happens
 */
class Store
{
    const ACTIVE = ['sent', 'on_hold', 'back_order'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'upfluent_sf_orders';
    }

    public static function get(int $order_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; rows change on every sync.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE order_id = %d', self::table(), $order_id), ARRAY_A);
        return $row ? self::decode($row) : null;
    }

    public static function findByShipment(string $shipment_id): ?array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM %i WHERE shipment_id = %s', self::table(), $shipment_id), ARRAY_A);
        return $row ? self::decode($row) : null;
    }

    /** Inserts or updates the given columns. */
    public static function save(int $order_id, array $data): void
    {
        global $wpdb;
        if (isset($data['meta']) && is_array($data['meta'])) {
            $data['meta'] = wp_json_encode($data['meta']);
        }
        $data['updated_at'] = gmdate('Y-m-d H:i:s');
        if (self::get($order_id) === null) {
            $data['order_id']   = $order_id;
            $data['created_at'] = $data['updated_at'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
            $wpdb->insert(self::table(), $data);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $wpdb->update(self::table(), $data, ['order_id' => $order_id]);
    }

    public static function delete(int $order_id): void
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $wpdb->delete(self::table(), ['order_id' => $order_id], ['%d']);
    }

    /** Marks an order as needing a person, with a plain reason. */
    public static function needsAttention(int $order_id, string $problem): void
    {
        self::save($order_id, ['state' => 'attention', 'problem' => $problem, 'problem_at' => gmdate('Y-m-d H:i:s'), 'next_try_at' => null]);
        do_action('upfluent_sf_order_needs_attention', $order_id, $problem);
    }

    /** Orders due to be sent now. */
    public static function due(int $limit = 25): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT order_id FROM %i WHERE state = 'waiting' AND (next_try_at IS NULL OR next_try_at <= %s) ORDER BY order_id ASC LIMIT %d",
            self::table(),
            gmdate('Y-m-d H:i:s'),
            $limit
        ));
        return array_map('intval', (array) $ids);
    }

    /** Orders in ShipFusion whose status still has to be followed (shipped ones for 45 days), oldest check first. */
    public static function toTrack(int $limit = 50): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE shipment_id <> '' AND (state IN ('sent','on_hold','back_order') OR (state = 'shipped' AND shipped_at >= %s)) ORDER BY updated_at ASC LIMIT %d",
            self::table(),
            $since,
            $limit
        ), ARRAY_A);
        return array_map([self::class, 'decode'], (array) $rows);
    }

    public static function attention(): array
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i WHERE state = 'attention' ORDER BY problem_at DESC LIMIT 100", self::table()), ARRAY_A);
        return array_map([self::class, 'decode'], (array) $rows);
    }

    public static function counts(): array
    {
        global $wpdb;
        $week = gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table, read for the status card.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(state = 'waiting') waiting, SUM(state = 'attention') attention,
                SUM(sent_at >= %s) sent_7d, SUM(shipped_at >= %s) shipped_7d FROM %i",
            $week,
            $week,
            self::table()
        ), ARRAY_A);
        return [
            'waiting'    => (int) ($row['waiting'] ?? 0),
            'sent_7d'    => (int) ($row['sent_7d'] ?? 0),
            'shipped_7d' => (int) ($row['shipped_7d'] ?? 0),
            'attention'  => (int) ($row['attention'] ?? 0),
        ];
    }

    private static function decode(array $row): array
    {
        $row['order_id'] = (int) $row['order_id'];
        $row['attempts'] = (int) $row['attempts'];
        $meta            = json_decode((string) ($row['meta'] ?? ''), true);
        $row['meta']     = is_array($meta) ? $meta : [];
        return $row;
    }
}
