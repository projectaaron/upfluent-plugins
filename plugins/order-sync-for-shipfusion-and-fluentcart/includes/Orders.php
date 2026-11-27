<?php

namespace UpfluentSf;

use FluentCart\App\Models\Order;

defined('ABSPATH') || exit;

/**
 * Sending orders to ShipFusion and keeping the shipment in step with the
 * order: send, update, hold, release, cancel. Every change to a shipment goes
 * through here, under a per-order lock.
 */
class Orders
{
    /** Waits between automatic retries of a temporary failure, in minutes. */
    const RETRY_MINUTES = [5, 15, 30, 60, 120, 240];

    /**
     * Per-order lock lifetime, in seconds. A send can take several minutes
     * when ShipFusion is slow (retried searches, a stock read per warehouse),
     * so the lock is also extended between steps.
     */
    const LOCK_TTL = 600;

    /** ShipFusion statuses of a shipment the warehouse hasn't shipped or cancelled yet. */
    const OPEN_STATUSES = ['inQueue', 'inProcess', 'onHold', 'backOrder'];

    /** Whether an order should go to the warehouse at all. */
    public static function eligible(Order $order): bool
    {
        /**
         * Send test-mode orders too. ShipFusion has no sandbox: a test order
         * sent there is packed and shipped like any other.
         *
         * @param bool $send false
         */
        if ($order->mode === 'test' && !apply_filters('upfluent_sf_send_test_orders', false)) {
            return false;
        }
        if (!in_array($order->payment_status, ['paid', 'partially_refunded'], true)) {
            return false;
        }
        if (!in_array($order->status, ['processing', 'completed'], true)) {
            return false;
        }
        if (in_array($order->shipping_status, ['shipped', 'delivered', 'unshippable'], true)) {
            return false;
        }
        $since = (string) get_option('upfluent_sf_since', '');
        if ($since !== '' && OrderPayload::createdAt($order) < strtotime($since . ' UTC')) {
            return false;
        }
        return (bool) OrderPayload::unitsToShip($order);
    }

    /** Puts an order in line to be sent (no-op when it is already handled). */
    public static function queue(int $order_id): void
    {
        $row = Store::get($order_id);
        if ($row && $row['state'] !== 'waiting') {
            return;
        }
        Store::save($order_id, ['state' => 'waiting', 'next_try_at' => null]);
    }

    /**
     * Sends one order. Safe to call twice: an order already in ShipFusion,
     * or one ShipFusion already holds under the same order number, is linked
     * instead of sent again.
     *
     * @return array{ok: bool, message: string}
     */
    public static function send(int $order_id, bool $manual = false): array
    {
        if (!Lock::acquire('order_' . $order_id, self::LOCK_TTL)) {
            return ['ok' => false, 'message' => __('This order is being sent right now.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        try {
            $row = Store::get($order_id);
            if ($row && $row['shipment_id'] !== '') {
                return ['ok' => true, 'message' => __('Already in ShipFusion.', 'order-sync-for-shipfusion-and-fluentcart')];
            }
            $order = OrderPayload::load($order_id);
            if (!$order) {
                Store::delete($order_id);
                return ['ok' => false, 'message' => __('The order no longer exists.', 'order-sync-for-shipfusion-and-fluentcart')];
            }
            if (!$manual && !self::eligible($order)) {
                Store::delete($order_id);
                return ['ok' => false, 'message' => __('This order doesn\'t need to go to the warehouse.', 'order-sync-for-shipfusion-and-fluentcart')];
            }

            $payload = OrderPayload::build($order);
            Lock::extend('order_' . $order_id, self::LOCK_TTL);
            $api     = new Api();
            $id      = self::findExisting($api, $payload['orderNumber'], $payload['orderDate']);
            Lock::extend('order_' . $order_id, self::LOCK_TTL);
            if ($id === null) {
                // Another order already uses this number in ShipFusion (numbers repeat
                // after a deleted order or a restarted sequence): send it as
                // <number>-<order ID>; FluentCart never reuses an order ID.
                $taken                  = $payload['orderNumber'];
                $payload['orderNumber'] = OrderPayload::orderNumber($order) . '-' . $order_id;
                $id                     = self::findExisting($api, $payload['orderNumber'], $payload['orderDate']);
                if ($id === null) {
                    throw new ApiException(esc_html(sprintf(
                        /* translators: 1: order number, 2: the alternative number also taken */
                        __('ShipFusion already has other orders numbered %1$s and %2$s, so this order was not sent. Ship it from Shipfusion 360, then mark it as handled.', 'order-sync-for-shipfusion-and-fluentcart'),
                        $taken,
                        $payload['orderNumber']
                    )), 0, 'data');
                }
            }
            if ($id === '') {
                try {
                    $response = $api->post('/shipments', $payload);
                    $id       = (string) ($response['shipmentId'] ?? '');
                } catch (ApiException $e) {
                    // A create that timed out may have gone through: look before failing.
                    $id = $e->kind() === 'unsure' ? (string) self::findExisting($api, $payload['orderNumber'], $payload['orderDate']) : '';
                    if ($id === '') {
                        throw $e;
                    }
                }
                if ($id === '') {
                    throw new ApiException(esc_html__('ShipFusion accepted the order but sent back no shipment number. Check Shipfusion 360 before retrying.', 'order-sync-for-shipfusion-and-fluentcart'), 0, 'data');
                }
            }

            // Re-read: a cancel may have arrived while this send was talking to ShipFusion.
            $fresh             = Store::get($order_id);
            $meta              = $fresh['meta'] ?? [];
            $meta['warehouse'] = $payload['warehouse'];
            if ($payload['orderNumber'] !== OrderPayload::orderNumber($order)) {
                $meta['order_number'] = $payload['orderNumber'];
            }
            Store::save($order_id, [
                'shipment_id'  => $id,
                'meta'         => $meta,
                'state'        => 'sent',
                'sf_status'    => 'inQueue',
                'attempts'     => 0,
                'next_try_at'  => null,
                'problem'      => null,
                'payload_hash' => OrderPayload::fingerprint($payload),
                'sent_at'      => gmdate('Y-m-d H:i:s'),
            ]);
            /* translators: %s: ShipFusion shipment number */
            $note = sprintf(__('Sent to the ShipFusion warehouse as shipment %s.', 'order-sync-for-shipfusion-and-fluentcart'), $id);
            if (!empty($meta['order_number'])) {
                /* translators: %s: order number used in ShipFusion */
                $note .= ' ' . sprintf(__('ShipFusion order number: %s (the usual number was already used in ShipFusion).', 'order-sync-for-shipfusion-and-fluentcart'), $meta['order_number']);
            }
            Log::order($order_id, __('Sent to ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), $note, 'success');
            Log::info($note, $order_id);
            Log::detail('Payload: ' . wp_json_encode($payload), $order_id);
            if (!empty($meta['cancel_pending'])) {
                self::later($order_id, 'cancel', 0);
            }
            return ['ok' => true, 'message' => $note];
        } catch (ApiException $e) {
            return self::sendFailed($order_id, $e);
        } finally {
            Lock::release('order_' . $order_id);
        }
    }

    private static function sendFailed(int $order_id, ApiException $e): array
    {
        $message = $e->text();
        if ($e->isAuth()) {
            // Stays waiting: it goes out by itself once the token works again.
            Store::save($order_id, ['state' => 'waiting', 'next_try_at' => null]);
            return ['ok' => false, 'message' => $message];
        }
        $row      = Store::get($order_id);
        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        if ($e->isTemporary() && $attempts <= count(self::RETRY_MINUTES)) {
            Store::save($order_id, [
                'state'       => 'waiting',
                'attempts'    => $attempts,
                'next_try_at' => gmdate('Y-m-d H:i:s', time() + self::RETRY_MINUTES[$attempts - 1] * MINUTE_IN_SECONDS),
                'problem'     => $message,
            ]);
            Log::warning($message, $order_id);
            return ['ok' => false, 'message' => $message];
        }
        Store::save($order_id, ['attempts' => $attempts]);
        Store::needsAttention($order_id, $message);
        Log::error($message, $order_id);
        Log::order($order_id, __('Not sent to ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), $message, 'error');
        return ['ok' => false, 'message' => $message];
    }

    /**
     * The open ShipFusion shipment this store already created for this order
     * ("Search Shipments"), so a retry after a lost answer doesn't create a
     * second one: an open shipment with the same order number and order date.
     * Returns null when another order already uses the number (any other
     * shipment that isn't cancelled), '' when the number is free.
     */
    private static function findExisting(Api $api, string $order_number, string $order_date): ?string
    {
        try {
            $response = $api->get('/shipments/search', ['orderNumber' => $order_number]);
        } catch (ApiException $e) {
            // Only a refused search (4xx) means "nothing to find"; when ShipFusion
            // can't answer, sending now could create a second shipment.
            if ($e->kind() !== 'data') {
                throw $e;
            }
            return '';
        }
        $taken = false;
        foreach ((array) ($response['shipments'] ?? []) as $shipment) {
            if (!is_array($shipment) || empty($shipment['shipmentId'])) {
                continue;
            }
            $ref    = (string) ($shipment['orderReferenceNumber'] ?? $shipment['orderNumber'] ?? '');
            $status = (string) ($shipment['shipmentStatus'] ?? '');
            if ($ref !== $order_number || $status === 'cancelled') {
                continue;
            }
            if (in_array($status, self::OPEN_STATUSES, true) && (string) ($shipment['orderDate'] ?? '') === $order_date) {
                return (string) $shipment['shipmentId'];
            }
            $taken = true;
        }
        return $taken ? null : '';
    }

    /**
     * Cancels the shipment because the order was cancelled, refunded or
     * deleted. An order not sent yet is simply never sent. When another
     * request holds the order (a send in flight), the cancel is kept in
     * meta.cancel_pending and tried again shortly.
     */
    public static function cancel(int $order_id, string $why): void
    {
        $row = Store::get($order_id);
        if (!$row || in_array($row['state'], ['cancelled', 'shipped', 'delivered', 'handled'], true)) {
            return;
        }
        if (!Lock::acquire('order_' . $order_id, self::LOCK_TTL)) {
            Store::save($order_id, ['meta' => array_merge($row['meta'], ['cancel_pending' => $why])]);
            self::later($order_id, 'cancel');
            return;
        }
        try {
            self::cancelLocked($order_id, $why);
        } finally {
            Lock::release('order_' . $order_id);
        }
    }

    /** cancel() for a caller that already holds the order's lock. */
    private static function cancelLocked(int $order_id, string $why): void
    {
        $row = Store::get($order_id);
        if (!$row || in_array($row['state'], ['cancelled', 'shipped', 'delivered', 'handled'], true)) {
            return;
        }
        unset($row['meta']['cancel_pending']);
        if ($row['shipment_id'] === '') {
            Store::save($order_id, ['state' => 'cancelled', 'next_try_at' => null, 'meta' => $row['meta']]);
            return;
        }
        try {
            (new Api())->delete('/shipments/' . rawurlencode($row['shipment_id']));
            Store::save($order_id, ['state' => 'cancelled', 'sf_status' => 'cancelled', 'problem' => null, 'meta' => $row['meta']]);
            /* translators: %s: why, e.g. "the order was refunded" */
            $note = sprintf(__('Shipment cancelled in ShipFusion because %s.', 'order-sync-for-shipfusion-and-fluentcart'), $why);
            Log::order($order_id, __('Cancelled in ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), $note, 'success');
            Log::info($note, $order_id);
        } catch (ApiException $e) {
            $problem = sprintf(
                /* translators: 1: why the order was cancelled, 2: ShipFusion's error */
                __('The order was cancelled (%1$s) but the shipment could not be cancelled in ShipFusion, so the warehouse may still ship it. Cancel it in Shipfusion 360 now. %2$s', 'order-sync-for-shipfusion-and-fluentcart'),
                $why,
                $e->text()
            );
            Store::needsAttention($order_id, $problem);
            // Retry then cancels again instead of treating the shipment as live.
            Store::save($order_id, ['meta' => array_merge($row['meta'], ['cancel_pending' => $why])]);
            Log::error($problem, $order_id);
            Log::order($order_id, __('ShipFusion cancel failed', 'order-sync-for-shipfusion-and-fluentcart'), $problem, 'error');
            do_action('upfluent_sf_cancel_failed', $order_id, $problem);
        }
    }

    /**
     * Runs a shipment change in a later request (WP-Cron): when the order is
     * busy, and for address edits, which shouldn't wait on ShipFusion inside
     * FluentCart's own save. One pending event per order and job.
     */
    public static function later(int $order_id, string $job, int $delay = 60): void
    {
        $args = [$order_id, $job];
        if (!wp_next_scheduled('upfluent_sf_order_job', $args)) {
            wp_schedule_single_event(time() + $delay, 'upfluent_sf_order_job', $args);
        }
    }

    /** upfluent_sf_order_job */
    public static function runJob($order_id, $job): void
    {
        $order_id = (int) $order_id;
        switch ((string) $job) {
            case 'cancel':
                $row = Store::get($order_id);
                if ($row && !empty($row['meta']['cancel_pending'])) {
                    self::cancel($order_id, (string) $row['meta']['cancel_pending']);
                }
                break;
            case 'hold':
                self::hold($order_id);
                break;
            case 'release':
                self::release($order_id);
                break;
            case 'refresh':
                self::refresh($order_id);
                break;
        }
    }

    /**
     * Brings the shipment in line with the order after a refund, an address
     * fix or an edit: update it, cancel it when nothing is left to ship, or
     * retry sending an order that was stopped by a problem.
     */
    public static function refresh(int $order_id, bool $release_hold = false): void
    {
        $row = Store::get($order_id);
        if (!$row) {
            return;
        }
        if ($row['state'] === 'attention' && $row['shipment_id'] === '') {
            Store::save($order_id, ['state' => 'waiting', 'next_try_at' => null, 'attempts' => 0]);
            self::send($order_id);
            return;
        }
        if (!in_array($row['state'], Store::ACTIVE, true)) {
            return;
        }
        if (!Lock::acquire('order_' . $order_id, self::LOCK_TTL)) {
            self::later($order_id, 'refresh');
            return;
        }
        try {
            $row = Store::get($order_id);
            if ($row && in_array($row['state'], Store::ACTIVE, true)) {
                self::refreshLocked($order_id, $row, $release_hold);
            }
        } finally {
            Lock::release('order_' . $order_id);
        }
    }

    /**
     * @param bool $release_hold Also release a hold ShipFusion placed (Retry,
     *                           a person's decision); never on a plain order edit.
     */
    private static function refreshLocked(int $order_id, array $row, bool $release_hold): void
    {
        $order = OrderPayload::load($order_id);
        if (!$order) {
            return;
        }
        if (!OrderPayload::unitsToShip($order)) {
            self::cancelLocked($order_id, __('nothing in the order is left to ship', 'order-sync-for-shipfusion-and-fluentcart'));
            return;
        }
        try {
            $payload = OrderPayload::build($order);
        } catch (ApiException $e) {
            Store::needsAttention($order_id, $e->text());
            return;
        }
        if (OrderPayload::fingerprint($payload) === $row['payload_hash']) {
            return;
        }
        try {
            $api = new Api();
            $api->put('/shipments/' . rawurlencode($row['shipment_id']), $payload);
            if ($release_hold && $row['state'] === 'on_hold' && empty($row['meta']['held_by_store'])) {
                $api->put('/shipments/' . rawurlencode($row['shipment_id']) . '/resubmit');
                Store::save($order_id, ['state' => 'sent', 'sf_status' => 'inQueue']);
            }
            Store::save($order_id, ['payload_hash' => OrderPayload::fingerprint($payload), 'problem' => null]);
            $note = __('The shipment in ShipFusion was updated with the order\'s current items and address.', 'order-sync-for-shipfusion-and-fluentcart');
            Log::order($order_id, __('ShipFusion shipment updated', 'order-sync-for-shipfusion-and-fluentcart'), $note, 'success');
            Log::info($note, $order_id);
        } catch (ApiException $e) {
            $problem = sprintf(
                /* translators: %s: ShipFusion's error */
                __('The order changed but the shipment in ShipFusion could not be updated, so the warehouse may ship the old items or address. Change it in Shipfusion 360. %s', 'order-sync-for-shipfusion-and-fluentcart'),
                $e->text()
            );
            Store::needsAttention($order_id, $problem);
            Log::error($problem, $order_id);
        }
    }

    /** The store put the order on hold: hold the shipment too ("Hold Shipment"). */
    public static function hold(int $order_id): void
    {
        $row = Store::get($order_id);
        if (!$row || !in_array($row['state'], ['sent', 'back_order'], true)) {
            return;
        }
        if (!Lock::acquire('order_' . $order_id, self::LOCK_TTL)) {
            self::later($order_id, 'hold');
            return;
        }
        try {
            $row = Store::get($order_id);
            if (!$row || !in_array($row['state'], ['sent', 'back_order'], true)) {
                return;
            }
            (new Api())->put('/shipments/' . rawurlencode($row['shipment_id']) . '/hold', [
                'holdMessage' => __('The order is on hold in the store.', 'order-sync-for-shipfusion-and-fluentcart'),
            ]);
            Store::save($order_id, ['state' => 'on_hold', 'sf_status' => 'onHold', 'meta' => array_merge($row['meta'], ['held_by_store' => true])]);
            Log::order($order_id, __('Held in ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), __('The order is on hold, so its shipment is on hold in ShipFusion too.', 'order-sync-for-shipfusion-and-fluentcart'));
        } catch (ApiException $e) {
            $problem = sprintf(
                /* translators: %s: ShipFusion's error */
                __('The order is on hold but its shipment could not be held in ShipFusion; the warehouse may already be packing it. %s', 'order-sync-for-shipfusion-and-fluentcart'),
                $e->text()
            );
            Store::needsAttention($order_id, $problem);
        } finally {
            Lock::release('order_' . $order_id);
        }
    }

    /** The order is back to processing: release a shipment the store held ("Resubmit Shipment"). */
    public static function release(int $order_id): void
    {
        $row = Store::get($order_id);
        if (!$row || $row['state'] !== 'on_hold' || empty($row['meta']['held_by_store'])) {
            return;
        }
        if (!Lock::acquire('order_' . $order_id, self::LOCK_TTL)) {
            self::later($order_id, 'release');
            return;
        }
        try {
            $row = Store::get($order_id);
            if (!$row || $row['state'] !== 'on_hold' || empty($row['meta']['held_by_store'])) {
                return;
            }
            (new Api())->put('/shipments/' . rawurlencode($row['shipment_id']) . '/resubmit');
            $meta = $row['meta'];
            unset($meta['held_by_store']);
            Store::save($order_id, ['state' => 'sent', 'sf_status' => 'inQueue', 'meta' => $meta]);
            Log::order($order_id, __('Released in ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), __('The order is no longer on hold, so its shipment was released in ShipFusion.', 'order-sync-for-shipfusion-and-fluentcart'));
        } catch (ApiException $e) {
            Store::needsAttention($order_id, sprintf(
                /* translators: %s: ShipFusion's error */
                __('The order is back to processing but its shipment is still on hold in ShipFusion. Release it in Shipfusion 360. %s', 'order-sync-for-shipfusion-and-fluentcart'),
                $e->text()
            ));
        } finally {
            Lock::release('order_' . $order_id);
        }
    }

    /** Sends every order that is due. */
    public static function sendDue(): array
    {
        $sent = 0;
        $failed = 0;
        foreach (Store::due() as $order_id) {
            $result = self::send($order_id);
            $result['ok'] ? $sent++ : $failed++;
            if (!Settings::connected() && Settings::connection()['status'] === 'error') {
                break;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Finds paid orders that never reached the queue (a missed event, or
     * orders placed while sending was switched off and then switched on).
     * Works through the orders 50 at a time from a saved position, so orders
     * that match the query but aren't eligible can't hold the newer ones back.
     */
    public static function sweep(): void
    {
        global $wpdb;
        $since = (string) get_option('upfluent_sf_since', '');
        $after = (int) get_option('upfluent_sf_sweep_after', 0);
        // Workaround: a read-only SQL join of FluentCart's orders with the plugin's table;
        // FluentCart has no API for "orders not in another table". Replace with one when it has.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- joins FluentCart's orders with the plugin's table; no FluentCart API lists "orders not in another table".
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT o.id FROM %i o LEFT JOIN %i s ON s.order_id = o.id
             WHERE s.order_id IS NULL AND o.created_at >= %s AND o.mode = 'live'
               AND o.payment_status IN ('paid','partially_refunded') AND o.status IN ('processing','completed')
               AND o.shipping_status NOT IN ('shipped','delivered','unshippable') AND o.fulfillment_type <> 'digital'
               AND o.id > %d
             ORDER BY o.id ASC LIMIT 50",
            $wpdb->prefix . 'fct_orders',
            Store::table(),
            $since ?: '1970-01-01 00:00:00',
            $after
        ));
        $ids = array_map('intval', (array) $ids);
        // Start over from the oldest once the end is reached.
        update_option('upfluent_sf_sweep_after', count($ids) < 50 ? 0 : (int) end($ids), false);
        foreach ($ids as $id) {
            $order = OrderPayload::load($id);
            if ($order && self::eligible($order)) {
                self::queue($id);
            }
        }
    }

    /** "Retry" on the screen: try again now, whatever stopped it. */
    public static function retry(int $order_id): array
    {
        $row = Store::get($order_id);
        if (!$row) {
            return ['ok' => false, 'message' => __('This order isn\'t handled by ShipFusion.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        if ($row['shipment_id'] === '') {
            Store::save($order_id, ['state' => 'waiting', 'attempts' => 0, 'next_try_at' => null, 'problem' => null]);
            return self::send($order_id, true);
        }
        Store::save($order_id, ['state' => $row['sf_status'] === 'onHold' ? 'on_hold' : 'sent', 'problem' => null]);
        if (!empty($row['meta']['cancel_pending'])) {
            self::cancel($order_id, (string) $row['meta']['cancel_pending']);
        } else {
            self::refresh($order_id, true);
            Tracking::check($order_id);
        }
        $after = Store::get($order_id);
        return ($after && $after['state'] === 'attention')
            ? ['ok' => false, 'message' => (string) $after['problem']]
            : ['ok' => true, 'message' => __('Done. The order is in step with ShipFusion again.', 'order-sync-for-shipfusion-and-fluentcart')];
    }

    /** "Mark as handled": stop retrying and take it off the list. */
    public static function dismiss(int $order_id): array
    {
        Store::save($order_id, ['state' => 'handled', 'next_try_at' => null]);
        Log::info(__('Marked as handled by a person.', 'order-sync-for-shipfusion-and-fluentcart'), $order_id);
        return ['ok' => true, 'message' => __('Marked as handled.', 'order-sync-for-shipfusion-and-fluentcart')];
    }
}
