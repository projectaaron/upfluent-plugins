<?php

namespace UpfluentSf;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;

defined('ABSPATH') || exit;

/**
 * Reads each open shipment from ShipFusion ("View Shipment") and mirrors it
 * in FluentCart:
 *
 *  - shipped: a FluentCart shipment (FluentCart 2.3 native shipments,
 *    ShipmentService::createShipment with the manual carrier) carrying the
 *    carrier and tracking number. FluentCart then sets the order's shipping
 *    status, sends its own shipment email with the tracking link and shows
 *    tracking in the customer's account.
 *  - delivered (from the package's tracking status): the FluentCart shipment
 *    moves to delivered (ShipmentService::applyTrackingUpdate), and the order
 *    can be completed.
 *  - on hold / back order / cancelled in the warehouse: recorded, and the
 *    ones a person must act on are listed under "Needs attention".
 *
 * Webhooks only trigger this read; nothing in a webhook body is trusted.
 */
class Tracking
{
    /** Carrier tracking pages, by a word in ShipFusion's carrier name. */
    const TRACKING_PAGES = [
        'dhlglobalmail' => 'https://www.dhl.com/us-en/home/tracking/tracking-ecommerce.html?tracking-id=%s',
        'dhl ecommerce' => 'https://www.dhl.com/us-en/home/tracking/tracking-ecommerce.html?tracking-id=%s',
        'dhl global'    => 'https://www.dhl.com/us-en/home/tracking/tracking-ecommerce.html?tracking-id=%s',
        'dhl'           => 'https://www.dhl.com/us-en/home/tracking.html?tracking-id=%s',
        'ups'           => 'https://www.ups.com/track?tracknum=%s',
        'fedex'         => 'https://www.fedex.com/fedextrack/?trknbr=%s',
        'usps'          => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=%s',
        'canada post'   => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s',
        'canadapost'    => 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor=%s',
        'purolator'     => 'https://www.purolator.com/en/shipping/tracker?pin=%s',
    ];

    /** Checks every open shipment (oldest check first). */
    public static function run(): array
    {
        $changed = 0;
        $failed  = 0;
        foreach (Store::toTrack() as $row) {
            Lock::extend('tracking');
            $result = self::check($row['order_id']);
            if ($result === null) {
                $failed++;
                if (Settings::connection()['status'] === 'error') {
                    break;
                }
            } elseif ($result) {
                $changed++;
            }
        }
        return ['changed' => $changed, 'failed' => $failed];
    }

    /**
     * Re-reads one order's shipment from ShipFusion.
     *
     * @return bool|null true when something changed, false when not, null when ShipFusion couldn't be read.
     */
    public static function check(int $order_id): ?bool
    {
        $row = Store::get($order_id);
        if (!$row || $row['shipment_id'] === '') {
            return false;
        }
        try {
            $shipment = (new Api())->get('/shipments/' . rawurlencode($row['shipment_id']));
        } catch (ApiException $e) {
            if ($e->getCode() === 404) {
                Store::needsAttention($order_id, __('ShipFusion has no record of this order\'s shipment any more. Check Shipfusion 360, then retry the order.', 'order-sync-for-shipfusion-and-fluentcart'));
            }
            return null;
        }
        Store::save($order_id, []);

        $status = (string) ($shipment['shipmentStatus'] ?? '');
        $before = $row['state'];
        $meta   = $row['meta'];

        switch ($status) {
            case 'inQueue':
            case 'inProcess':
                if (in_array($before, ['on_hold', 'back_order'], true)) {
                    unset($meta['hold_message'], $meta['held_by_store']);
                    Store::save($order_id, ['state' => 'sent', 'sf_status' => $status, 'meta' => $meta]);
                    return true;
                }
                Store::save($order_id, ['sf_status' => $status]);
                return false;

            case 'backOrder':
                if ($before !== 'back_order') {
                    Store::save($order_id, ['state' => 'back_order', 'sf_status' => $status]);
                    Log::order($order_id, __('Waiting for stock in ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), __('The warehouse is waiting for stock before it can ship this order.', 'order-sync-for-shipfusion-and-fluentcart'), 'warning');
                    return true;
                }
                return false;

            case 'onHold':
                if ($before === 'on_hold') {
                    return false;
                }
                Store::save($order_id, ['state' => 'on_hold', 'sf_status' => $status]);
                if (empty($meta['held_by_store'])) {
                    $reason = (string) ($meta['hold_message'] ?? '');
                    Store::needsAttention($order_id, trim(sprintf(
                        /* translators: %s: ShipFusion's hold reason, may be empty */
                        __('ShipFusion put the shipment on hold. %s Fix the order (for example its address) and retry, or release it in Shipfusion 360.', 'order-sync-for-shipfusion-and-fluentcart'),
                        $reason !== '' ? $reason . '.' : ''
                    )));
                }
                return true;

            case 'cancelled':
                if ($before !== 'cancelled') {
                    Store::save($order_id, ['state' => 'cancelled', 'sf_status' => $status]);
                    Log::order($order_id, __('Cancelled in ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), __('The shipment was cancelled in ShipFusion.', 'order-sync-for-shipfusion-and-fluentcart'), 'warning');
                    return true;
                }
                return false;

            case 'shipped':
                return self::shipped($order_id, $row, $shipment);
        }
        return false;
    }

    private static function shipped(int $order_id, array $row, array $shipment): bool
    {
        $packages = self::packages($shipment);
        if (!$packages) {
            // Shipped without a tracking number yet; checked again next run.
            Store::save($order_id, ['sf_status' => 'shipped']);
            return false;
        }

        $meta    = $row['meta'];
        $changed = false;
        $order   = OrderPayload::load($order_id);
        if (!$order) {
            return false;
        }

        // Recorded once, the first time ShipFusion reports it shipped; an order
        // FluentCart wouldn't take a shipment for (shipped by hand) is not retried.
        if (empty($meta['fc_shipment_id']) && $row['state'] !== 'shipped') {
            $first   = $packages[0];
            $others  = array_slice(array_column($packages, 'number'), 1);
            $notes   = sprintf(
                /* translators: %s: ShipFusion shipment number */
                __('Shipped by ShipFusion (shipment %s).', 'order-sync-for-shipfusion-and-fluentcart'),
                $row['shipment_id']
            );
            if ($others) {
                /* translators: %s: more tracking numbers */
                $notes .= ' ' . sprintf(__('More parcels: %s.', 'order-sync-for-shipfusion-and-fluentcart'), implode(', ', $others));
            }
            $items = [];
            foreach (OrderPayload::unitsToShip($order) as $item_id => $qty) {
                $items[] = ['order_item_id' => $item_id, 'qty' => $qty];
            }

            $created = null;
            if ($items) {
                $created = ShipmentService::createShipment($order, [
                    'items'           => $items,
                    'carrier_slug'    => Shipment::CARRIER_MANUAL,
                    'carrier_name'    => $first['carrier'],
                    'tracking_number' => $first['number'],
                    'tracking_url'    => $first['url'],
                    'notes'           => $notes,
                    'notify_customer' => true,
                    'shipped_at'      => $first['shipped_at'],
                ]);
            }
            if (is_wp_error($created)) {
                Log::warning(sprintf(
                    /* translators: %s: FluentCart's error */
                    __('Shipped in ShipFusion, but FluentCart did not record the shipment: %s', 'order-sync-for-shipfusion-and-fluentcart'),
                    $created->get_error_message()
                ), $order_id);
            } elseif ($created) {
                $meta['fc_shipment_id'] = (int) (is_object($created) ? $created->id : ($created['id'] ?? 0));
            }
            $meta['tracking'] = array_column($packages, 'number');
            Store::save($order_id, ['state' => 'shipped', 'sf_status' => 'shipped', 'shipped_at' => gmdate('Y-m-d H:i:s'), 'problem' => null, 'meta' => $meta]);
            $changed = true;
        }

        // Delivery comes from the carrier's tracking status on the package.
        $tracking_status = (string) ($packages[0]['tracking_status'] ?? '');
        $mapped          = self::mapTrackingStatus($tracking_status);
        if ($mapped && ($meta['last_tracking_status'] ?? '') !== $tracking_status) {
            $meta['last_tracking_status'] = $tracking_status;
            $native = !empty($meta['fc_shipment_id']) ? Shipment::query()->find((int) $meta['fc_shipment_id']) : null;
            if ($native) {
                ShipmentService::applyTrackingUpdate($native, $mapped, [
                    'tracking_status'      => $tracking_status,
                    'tracking_status_date' => gmdate('Y-m-d H:i:s'),
                ]);
            }
            $state = $mapped === 'delivered' ? 'delivered' : 'shipped';
            Store::save($order_id, ['state' => $state, 'meta' => $meta]);
            if ($mapped === 'delivered') {
                self::complete($order_id);
            }
            $changed = true;
        }
        return $changed;
    }

    /** Marks the order completed when the store wants that, through FluentCart's own status change. */
    private static function complete(int $order_id): void
    {
        if (!Settings::get('complete_on_delivery')) {
            return;
        }
        $order = OrderPayload::load($order_id);
        if (!$order || $order->status !== 'processing' || $order->shipping_status !== 'delivered') {
            return;
        }
        $result = OrderResource::updateStatuses([
            'order'    => $order,
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => 'completed'],
        ]);
        if (is_wp_error($result)) {
            Log::warning($result->get_error_message(), $order_id);
        }
    }

    /**
     * The shipment's parcels with a real tracking number.
     *
     * @return array<int, array{number: string, carrier: string, url: string, shipped_at: string, tracking_status: string}>
     */
    public static function packages(array $shipment): array
    {
        $out      = [];
        $packages = $shipment['tracking']['packages'] ?? [];
        if (!is_array($packages)) {
            return [];
        }
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $number = sanitize_text_field((string) ($pkg['trackingNumber'] ?? ''));
            if ($number === '' || in_array(strtolower($number), ['tbd', 'pending', 'n/a', 'na', 'none', '-'], true)) {
                continue;
            }
            $carrier = sanitize_text_field((string) ($pkg['shippingCarrier'] ?? $pkg['shippingCarrierCode'] ?? ''));
            $time    = (int) ($pkg['shipTimestamp'] ?? 0);
            $out[]   = [
                'number'          => $number,
                'carrier'         => $carrier,
                'url'             => self::trackingUrl($carrier . ' ' . (string) ($pkg['shippingCarrierCode'] ?? ''), $number, (string) ($pkg['trackingUrl'] ?? '')),
                'shipped_at'      => $time > 0 ? gmdate('Y-m-d H:i:s', $time) : gmdate('Y-m-d H:i:s'),
                'tracking_status' => sanitize_text_field((string) ($pkg['trackingStatus'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * The carrier's own tracking page. A link ShipFusion supplies is used only
     * when it points at one of those carriers; otherwise it is built here.
     */
    public static function trackingUrl(string $carrier, string $number, string $given = ''): string
    {
        $host = strtolower((string) wp_parse_url($given, PHP_URL_HOST));
        if ($host !== '' && preg_match('#^https://#i', $given)) {
            foreach (['dhl.com', 'ups.com', 'fedex.com', 'usps.com', 'canadapost-postescanada.ca', 'canadapost.ca', 'purolator.com'] as $allowed) {
                if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
                    return esc_url_raw($given, ['https']);
                }
            }
        }
        $carrier = strtolower($carrier);
        foreach (self::TRACKING_PAGES as $needle => $pattern) {
            if (strpos($carrier, $needle) !== false) {
                return sprintf($pattern, rawurlencode($number));
            }
        }
        return '';
    }

    /**
     * Carrier tracking text to a FluentCart shipment status. Failed and
     * returned deliveries are checked first, so "Complete: Returned to
     * sender" never reads as delivered.
     */
    public static function mapTrackingStatus(string $status): string
    {
        $s = strtolower(trim($status));
        if ($s === '') {
            return '';
        }
        if (strpos($s, 'return') !== false) {
            return 'returned';
        }
        if (strpos($s, 'exception') !== false || strpos($s, 'incomplete') !== false || strpos($s, 'fail') !== false) {
            return 'failed';
        }
        if (strpos($s, 'delivered') !== false || strpos($s, 'complete') !== false) {
            return 'delivered';
        }
        return 'in_transit';
    }
}
