<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * POST /wp-json/upfluent-sf/v1/webhook: ShipFusion's webhooks
 * (docs.shipfusion.com/docs/webhooks): shipment_shipped, shipment_backOrder,
 * shipment_onHold, warehouseShipment_received, lowInventory.
 *
 * ShipFusion authenticates a webhook with the plain `authCode` in the JSON
 * body (no signature header is documented), so the secret is compared in
 * constant time against its stored hash, and a webhook is only a nudge:
 * shipment events re-read the shipment from the API, stock events re-read
 * the SKU's stock. Nothing in the body changes an order by itself. Events
 * for orders this plugin didn't send (another sales channel on the same
 * ShipFusion account) are answered and ignored.
 */
class Webhook
{
    public function register(): void
    {
        register_rest_route('upfluent-sf/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            // Authenticated by the authCode in the body, checked below.
            'permission_callback' => '__return_true',
        ]);
    }

    public static function url(): string
    {
        return rest_url('upfluent-sf/v1/webhook');
    }

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $body = $request->get_json_params();
        $code = is_array($body) && is_string($body['authCode'] ?? null) ? $body['authCode'] : '';
        if (!Settings::webhookSecretMatches($code)) {
            return new \WP_REST_Response(['success' => false], 403);
        }

        $type       = sanitize_key((string) ($body['type'] ?? ''));
        $message_id = sanitize_text_field((string) ($body['messageId'] ?? ''));
        if ($message_id !== '') {
            // ShipFusion may deliver the same event twice; act once.
            $seen = 'upfluent_sf_wh_' . md5($message_id);
            if (get_transient($seen)) {
                return new \WP_REST_Response(['success' => true], 200);
            }
            set_transient($seen, 1, DAY_IN_SECONDS);
        }

        $shipment_id = sanitize_text_field((string) ($body['shipmentId'] ?? ''));
        $text        = '';
        switch ($type) {
            case 'shipment_shipped':
            case 'shipment_backorder':
            case 'shipment_onhold':
                $row = $shipment_id !== '' ? Store::findByShipment($shipment_id) : null;
                if (!$row) {
                    // Not an order this plugin sent.
                    return new \WP_REST_Response(['success' => true, 'ignored' => true], 200);
                }
                if ($type === 'shipment_onhold') {
                    $meta                 = $row['meta'];
                    $meta['hold_message'] = substr(sanitize_text_field((string) ($body['holdMessage'] ?? '')), 0, 200);
                    Store::save($row['order_id'], ['meta' => $meta]);
                }
                wp_schedule_single_event(time(), 'upfluent_sf_recheck_order', [$row['order_id']]);
                $text = self::describe($type, $row['order_id']);
                break;

            case 'lowinventory':
            case 'warehouseshipment_received':
                $skus = [];
                if (!empty($body['SKU'])) {
                    $skus[] = sanitize_text_field((string) $body['SKU']);
                }
                foreach ((array) ($body['itemsInShipment'] ?? []) as $item) {
                    if (is_array($item) && !empty($item['SKU'])) {
                        $skus[] = sanitize_text_field((string) $item['SKU']);
                    }
                }
                if ($skus && Settings::get('sync_stock')) {
                    wp_schedule_single_event(time(), 'upfluent_sf_recheck_stock', [array_values(array_unique(array_slice($skus, 0, 100)))]);
                }
                $text = $type === 'lowinventory'
                    ? __('ShipFusion reported low stock; stock is being updated.', 'order-sync-for-shipfusion-and-fluentcart')
                    : __('ShipFusion received a delivery at the warehouse; stock is being updated.', 'order-sync-for-shipfusion-and-fluentcart');
                break;

            default:
                return new \WP_REST_Response(['success' => true, 'ignored' => true], 200);
        }

        $recent   = (array) (Settings::state()['webhook_recent'] ?? []);
        array_unshift($recent, ['time' => gmdate('c'), 'text' => $text]);
        Settings::setState('webhook_recent', array_slice($recent, 0, 10));
        Settings::setState('webhook_last_at', gmdate('c'));

        return new \WP_REST_Response(['success' => true], 200);
    }

    public static function stockNudge($skus): void
    {
        if (Lock::acquire('stock', 600)) {
            try {
                Stock::sync(array_map('strval', (array) $skus));
            } finally {
                Lock::release('stock');
            }
        }
    }

    private static function describe(string $type, int $order_id): string
    {
        $order = OrderPayload::load($order_id);
        $label = $order ? '#' . OrderPayload::orderNumber($order) : '#' . $order_id;
        switch ($type) {
            case 'shipment_shipped':
                /* translators: %s: order number */
                return sprintf(__('Order %s shipped.', 'order-sync-for-shipfusion-and-fluentcart'), $label);
            case 'shipment_backorder':
                /* translators: %s: order number */
                return sprintf(__('Order %s is waiting for stock at the warehouse.', 'order-sync-for-shipfusion-and-fluentcart'), $label);
            default:
                /* translators: %s: order number */
                return sprintf(__('Order %s was put on hold at the warehouse.', 'order-sync-for-shipfusion-and-fluentcart'), $label);
        }
    }
}
