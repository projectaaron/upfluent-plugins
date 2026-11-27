<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Follows FluentCart order events (FluentCart 2.3.3; each fires with one
 * context array whose 'order' key holds the Order):
 *
 *   order_paid_done                     paid: send to the warehouse (runs in
 *                                       FluentCart's background queue, not the checkout)
 *   order_status_changed_to_canceled    an admin or API cancel
 *   order_canceled                      a cancel from FluentCart's renewal flow
 *   order_fully_refunded,
 *   payment_status_changed_to_refunded  fully refunded: cancel the shipment
 *   order_partially_refunded            item refunds: ship fewer units
 *   order_updated                       the order edit screen
 *   order_deleted                       deleted: cancel, forget
 *   order_status_changed_to_on-hold     hold the shipment
 *   order_status_changed_to_processing  release a shipment held from FluentCart
 */
class OrderEvents
{
    public function register(): void
    {
        add_action('fluent_cart/order_paid_done', [$this, 'paid']);
        add_action('fluent_cart/order_status_changed_to_canceled', [$this, 'cancelled']);
        add_action('fluent_cart/order_canceled', [$this, 'cancelled']);
        add_action('fluent_cart/order_fully_refunded', [$this, 'refunded']);
        add_action('fluent_cart/payment_status_changed_to_refunded', [$this, 'refunded']);
        add_action('fluent_cart/order_partially_refunded', [$this, 'changed']);
        add_action('fluent_cart/order_updated', [$this, 'changed']);
        add_action('fluent_cart/order_deleted', [$this, 'deleted']);
        add_action('fluent_cart/order_status_changed_to_on-hold', [$this, 'held']);
        add_action('fluent_cart/order_status_changed_to_processing', [$this, 'released']);
        add_filter('rest_request_after_callbacks', [$this, 'addressEdited'], 10, 3);
    }

    /** @param array|object $data */
    public function paid($data): void
    {
        $order_id = self::orderId($data);
        if (!$order_id || !Settings::get('send_orders')) {
            return;
        }
        $order = OrderPayload::load($order_id);
        if (!$order || !Orders::eligible($order)) {
            return;
        }
        Orders::queue($order_id);
        Orders::send($order_id);
    }

    public function cancelled($data): void
    {
        $order_id = self::orderId($data);
        if ($order_id) {
            Orders::cancel($order_id, __('the order was cancelled', 'order-sync-for-shipfusion-and-fluentcart'));
        }
    }

    public function refunded($data): void
    {
        $order_id = self::orderId($data);
        if ($order_id) {
            Orders::cancel($order_id, __('the order was refunded', 'order-sync-for-shipfusion-and-fluentcart'));
        }
    }

    public function changed($data): void
    {
        $order_id = self::orderId($data);
        if ($order_id) {
            Orders::refresh($order_id);
        }
    }

    public function deleted($data): void
    {
        $order_id = self::orderId($data);
        if (!$order_id) {
            return;
        }
        Orders::cancel($order_id, __('the order was deleted', 'order-sync-for-shipfusion-and-fluentcart'));
        $row = Store::get($order_id);
        // Kept while a cancel still has to reach ShipFusion.
        if ($row && $row['state'] !== 'attention' && empty($row['meta']['cancel_pending'])) {
            Store::delete($order_id);
        }
    }

    public function held($data): void
    {
        $order_id = self::orderId($data);
        if ($order_id) {
            Orders::hold($order_id);
        }
    }

    public function released($data): void
    {
        $order_id = self::orderId($data);
        if ($order_id) {
            Orders::release($order_id);
        }
    }

    /**
     * Workaround: FluentCart 2.3.3 saves an address edited on the order
     * screen (PUT /fluent-cart/v2/orders/{order}/address/{id} and
     * POST /fluent-cart/v2/orders/{id}/update-address-id, OrderResource::updateOrderAddress
     * and ::updateOrderAddressId) without firing any action, so the shipment
     * would keep the old address. This watches those two routes after they
     * succeed. Remove once FluentCart fires an address-updated action.
     *
     * @param \WP_REST_Response|\WP_Error|mixed $response
     * @param array                             $handler
     * @param \WP_REST_Request                  $request
     */
    public function addressEdited($response, $handler, $request)
    {
        if (!$request instanceof \WP_REST_Request || is_wp_error($response)) {
            return $response;
        }
        $route = (string) $request->get_route();
        if (preg_match('#^/fluent-cart/v2/orders/(\d+)/(address/\d+|update-address-id)$#', $route, $m)) {
            $status = $response instanceof \WP_REST_Response ? $response->get_status() : 200;
            if ($status < 300) {
                // In a later request: FluentCart's save shouldn't wait on ShipFusion.
                Orders::later((int) $m[1], 'refresh', 0);
            }
        }
        return $response;
    }

    /** @param array|object|int $data */
    public static function orderId($data): int
    {
        if (is_array($data) && isset($data['order'])) {
            $data = $data['order'];
        }
        if (is_object($data) && isset($data->id)) {
            return (int) $data->id;
        }
        if (is_array($data)) {
            return (int) ($data['id'] ?? 0);
        }
        return is_numeric($data) ? (int) $data : 0;
    }
}
