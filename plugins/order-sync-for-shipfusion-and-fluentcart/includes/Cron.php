<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Scheduled work (WP-Cron), each run behind its own lock so two never
 * overlap:
 *   upfluent_sf_send_orders     every 5 minutes: sweep, then send what is due
 *   upfluent_sf_check_tracking  the chosen interval: re-read open shipments
 *   upfluent_sf_sync_stock      the chosen interval: copy stock levels
 *   upfluent_sf_daily           prune the activity log
 */
class Cron
{
    const HOOKS = ['upfluent_sf_send_orders', 'upfluent_sf_check_tracking', 'upfluent_sf_sync_stock', 'upfluent_sf_daily'];

    public function register(): void
    {
        add_action('upfluent_sf_send_orders', [self::class, 'sendOrders']);
        add_action('upfluent_sf_check_tracking', [self::class, 'checkTracking']);
        add_action('upfluent_sf_sync_stock', [self::class, 'syncStock']);
        add_action('upfluent_sf_daily', [Log::class, 'prune']);
        add_action('update_option_' . Settings::OPTION, [self::class, 'reschedule']);
        add_action('add_option_' . Settings::OPTION_TOKEN, [self::class, 'reschedule']);
        add_action('update_option_' . Settings::OPTION_TOKEN, [self::class, 'reschedule']);
        add_action('admin_init', [self::class, 'ensureScheduled']);
    }

    public static function intervals($schedules)
    {
        if (!is_array($schedules)) {
            $schedules = [];
        }
        $schedules['upfluent_sf_5_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => __('Every 5 minutes', 'order-sync-for-shipfusion-and-fluentcart'),
        ];
        $schedules['upfluent_sf_30_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __('Every 30 minutes', 'order-sync-for-shipfusion-and-fluentcart'),
        ];
        return $schedules;
    }

    /** What should be scheduled, from the settings: hook => interval. Nothing talks to ShipFusion before a token is saved. */
    private static function wanted(): array
    {
        $s      = Settings::all();
        $wanted = ['upfluent_sf_daily' => 'daily'];
        if (!Settings::hasToken()) {
            return $wanted;
        }
        if ($s['send_orders']) {
            $wanted['upfluent_sf_send_orders'] = 'upfluent_sf_5_minutes';
        }
        if ($s['check_tracking']) {
            $wanted['upfluent_sf_check_tracking'] = $s['tracking_interval'];
        }
        if ($s['sync_stock']) {
            $wanted['upfluent_sf_sync_stock'] = $s['stock_interval'];
        }
        return $wanted;
    }

    public static function reschedule(): void
    {
        $wanted = self::wanted();
        foreach (self::HOOKS as $hook) {
            $event = wp_get_scheduled_event($hook);
            if (!isset($wanted[$hook])) {
                wp_clear_scheduled_hook($hook);
                continue;
            }
            if ($event && $event->schedule === $wanted[$hook]) {
                continue;
            }
            wp_clear_scheduled_hook($hook);
            wp_schedule_event(time() + 60, $wanted[$hook], $hook);
        }
    }

    /** Puts back a schedule that went missing (a cron table cleanup, a migration). */
    public static function ensureScheduled(): void
    {
        foreach (array_keys(self::wanted()) as $hook) {
            if (!wp_next_scheduled($hook)) {
                self::reschedule();
                return;
            }
        }
    }

    public static function unschedule(): void
    {
        foreach (self::HOOKS as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function sendOrders(): array
    {
        if (!Lock::acquire('orders', 600)) {
            return ['ok' => false, 'message' => __('Orders are being sent right now. Try again in a minute.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        try {
            if (Settings::get('send_orders')) {
                // Picks up missed paid orders; never when sending is off (orders shipped by hand).
                Orders::sweep();
            }
            $result  = Orders::sendDue();
            $message = sprintf(
                /* translators: 1: orders sent, 2: orders not sent */
                __('Sent %1$d orders. %2$d could not be sent yet.', 'order-sync-for-shipfusion-and-fluentcart'),
                $result['sent'],
                $result['failed']
            );
            $ok = $result['failed'] === 0;
            Settings::recordRun('orders', $ok, $message);
            return ['ok' => $ok, 'message' => $message];
        } finally {
            Lock::release('orders');
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function checkTracking(): array
    {
        if (!Lock::acquire('tracking', 600)) {
            return ['ok' => false, 'message' => __('Tracking is being checked right now. Try again in a minute.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        try {
            $result  = Tracking::run();
            $message = sprintf(
                /* translators: 1: orders with news, 2: orders that could not be checked */
                __('Checked tracking: %1$d orders had news, %2$d could not be checked.', 'order-sync-for-shipfusion-and-fluentcart'),
                $result['changed'],
                $result['failed']
            );
            $ok = $result['failed'] === 0;
            Settings::recordRun('tracking', $ok, $message);
            return ['ok' => $ok, 'message' => $message];
        } finally {
            Lock::release('tracking');
        }
    }

    /** @return array{ok: bool, message: string} */
    public static function syncStock(): array
    {
        if (!Lock::acquire('stock', 600)) {
            return ['ok' => false, 'message' => __('Stock is being updated right now. Try again in a minute.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        try {
            $result = Stock::sync();
            Settings::recordRun('stock', $result['ok'], $result['message']);
            return $result;
        } finally {
            Lock::release('stock');
        }
    }

    public static function nextRun(string $hook): ?string
    {
        $next = wp_next_scheduled($hook);
        return $next ? gmdate('c', $next) : null;
    }
}
