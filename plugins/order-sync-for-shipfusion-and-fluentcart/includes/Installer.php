<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Tables, the "orders after" floor and the schedules. Runs on activation and
 * again whenever the stored schema version is older than this plugin's.
 */
class Installer
{
    const DB_VERSION = 1;

    public static function activate(): void
    {
        self::install();
        Cron::reschedule();
    }

    public static function maybeUpgrade(): void
    {
        if ((int) get_option('upfluent_sf_db_version', 0) < self::DB_VERSION) {
            self::install();
        }
    }

    private static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $orders  = Store::table();
        $log     = Log::table();

        // dbDelta format: two spaces after PRIMARY KEY, one column per line, no IF NOT EXISTS.
        dbDelta("CREATE TABLE {$orders} (
order_id bigint(20) unsigned NOT NULL,
shipment_id varchar(64) NOT NULL DEFAULT '',
state varchar(20) NOT NULL DEFAULT 'waiting',
sf_status varchar(32) NOT NULL DEFAULT '',
attempts smallint(5) unsigned NOT NULL DEFAULT 0,
next_try_at datetime NULL DEFAULT NULL,
problem text NULL,
problem_at datetime NULL DEFAULT NULL,
payload_hash char(32) NOT NULL DEFAULT '',
sent_at datetime NULL DEFAULT NULL,
shipped_at datetime NULL DEFAULT NULL,
meta longtext NULL,
created_at datetime NOT NULL,
updated_at datetime NOT NULL,
PRIMARY KEY  (order_id),
KEY state (state),
KEY shipment_id (shipment_id)
) {$charset};");

        dbDelta("CREATE TABLE {$log} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
created_at datetime NOT NULL,
level varchar(10) NOT NULL DEFAULT 'info',
order_id bigint(20) unsigned NOT NULL DEFAULT 0,
message text NOT NULL,
PRIMARY KEY  (id),
KEY created_at (created_at)
) {$charset};");

        // Only orders placed from now on are sent automatically, so turning the
        // plugin on in an established store never ships old orders again.
        if (!get_option('upfluent_sf_since')) {
            update_option('upfluent_sf_since', gmdate('Y-m-d H:i:s'), false);
        }
        update_option('upfluent_sf_db_version', self::DB_VERSION, true);
    }
}
