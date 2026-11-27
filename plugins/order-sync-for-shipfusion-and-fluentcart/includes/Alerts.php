<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Tells a person when something needs them:
 *  - an admin notice on FluentCart's screens and the Dashboard while orders
 *    need attention or ShipFusion refuses the token;
 *  - an email (when "Email me" is on): at once when a cancel failed, at most
 *    every 6 hours when the token is refused, and one summary an hour for
 *    orders that need attention.
 */
class Alerts
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'notice']);
        add_action('upfluent_sf_cancel_failed', [$this, 'cancelFailed'], 10, 2);
        add_action('upfluent_sf_connection_failed', [$this, 'connectionFailed']);
        add_action('upfluent_sf_order_needs_attention', [$this, 'queueDigest']);
        add_action('upfluent_sf_send_digest', [$this, 'sendDigest']);
    }

    public function notice(): void
    {
        if (!Permissions::canManageSettings() || AdminMenu::isCurrentPage()) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->id, ['dashboard', 'toplevel_page_fluent-cart'], true)) {
            return;
        }
        $count = Store::counts()['attention'];
        $conn  = Settings::connection();
        if ($conn['status'] === 'error') {
            $text = __('ShipFusion refused the API token, so orders are waiting and are not being sent to the warehouse.', 'order-sync-for-shipfusion-and-fluentcart');
        } elseif ($count) {
            /* translators: %d: number of orders */
            $text = sprintf(_n('%d order needs your attention before the ShipFusion warehouse can handle it.', '%d orders need your attention before the ShipFusion warehouse can handle them.', $count, 'order-sync-for-shipfusion-and-fluentcart'), $count);
        } else {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html($text),
            esc_url(AdminMenu::url()),
            esc_html__('Open ShipFusion', 'order-sync-for-shipfusion-and-fluentcart')
        );
    }

    public function cancelFailed($order_id, $problem): void
    {
        /* translators: %d: order ID */
        $this->mail(sprintf(__('Order #%d may still ship: cancel it in Shipfusion 360', 'order-sync-for-shipfusion-and-fluentcart'), (int) $order_id), (string) $problem);
    }

    public function connectionFailed($message): void
    {
        if (get_transient('upfluent_sf_mailed_connection')) {
            return;
        }
        set_transient('upfluent_sf_mailed_connection', 1, 6 * HOUR_IN_SECONDS);
        $this->mail(
            __('ShipFusion refused your API token: orders are not being sent', 'order-sync-for-shipfusion-and-fluentcart'),
            (string) $message . "\n\n" . __('Orders wait and are sent automatically once a working token is saved.', 'order-sync-for-shipfusion-and-fluentcart')
        );
    }

    public function queueDigest(): void
    {
        if (!wp_next_scheduled('upfluent_sf_send_digest')) {
            wp_schedule_single_event(time() + HOUR_IN_SECONDS, 'upfluent_sf_send_digest');
        }
    }

    public function sendDigest(): void
    {
        $rows = Store::attention();
        if (!$rows) {
            return;
        }
        $lines = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            /* translators: 1: order ID, 2: the problem */
            $lines[] = sprintf(__('Order #%1$d: %2$s', 'order-sync-for-shipfusion-and-fluentcart'), $row['order_id'], (string) $row['problem']);
        }
        $this->mail(
            /* translators: %d: number of orders */
            sprintf(_n('%d order needs your attention in ShipFusion', '%d orders need your attention in ShipFusion', count($rows), 'order-sync-for-shipfusion-and-fluentcart'), count($rows)),
            implode("\n\n", $lines)
        );
    }

    private function mail(string $subject, string $body): void
    {
        if (!Settings::get('alerts')) {
            return;
        }
        $body .= "\n\n" . __('Open the ShipFusion screen to retry or mark orders as handled:', 'order-sync-for-shipfusion-and-fluentcart') . "\n" . AdminMenu::url();
        wp_mail(Settings::alertsEmail(), '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] ' . $subject, $body);
    }
}
