<?php

namespace UpfluentSf;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;

defined('ABSPATH') || exit;

/**
 * Builds the ShipFusion "Create Outbound Shipment" body from a FluentCart
 * order, read through FluentCart's own models (FluentCart 2.3).
 *
 * Quantities are the units the customer still has and that are not shipped
 * yet: OrderItem::unitsLeft() takes refunded units off (2.3 item refunds) and
 * shippedUnitsKept() takes off units already shipped through a FluentCart
 * shipment. Digital and service lines never go to the warehouse.
 */
class OrderPayload
{
    /** Orders whose items or address can't be sent; the message says what to fix. */
    public static function problem(string $message): ApiException
    {
        return new ApiException($message, 0, 'data');
    }

    public static function load(int $order_id): ?Order
    {
        return Order::query()->with(['order_items', 'shipping_address', 'billing_address', 'customer'])->find($order_id);
    }

    /** Units per order item still to ship: [order_item_id => qty]. */
    public static function unitsToShip(Order $order): array
    {
        $units = [];
        foreach ($order->order_items as $item) {
            if ($item->fulfillment_type !== 'physical') {
                continue;
            }
            $qty = $item->unitsLeft() - $item->shippedUnitsKept();
            if ($qty > 0) {
                $units[(int) $item->id] = $qty;
            }
        }
        return $units;
    }

    /**
     * @throws ApiException kind "data" when the order can't be sent as it is.
     */
    public static function build(Order $order): array
    {
        $units = self::unitsToShip($order);
        if (!$units) {
            throw self::problem(esc_html__('Nothing in this order needs shipping: no physical items are left after refunds and earlier shipments.', 'order-sync-for-shipfusion-and-fluentcart'));
        }

        $lines = self::lines($order, $units);

        $address = self::address($order);
        $absent  = self::missingAddressParts($address);
        if ($absent) {
            throw self::problem(esc_html(sprintf(
                /* translators: %s: address parts, e.g. "city, postcode" */
                __('The shipping address is incomplete (missing: %s). Fix the address on the order; it is sent again automatically.', 'order-sync-for-shipfusion-and-fluentcart'),
                implode(', ', $absent)
            )));
        }

        $items = [];
        foreach ($lines as $sku => $qty) {
            $items[] = ['SKU' => (string) $sku, 'qty' => (int) $qty];
        }

        $payload = [
            'orderNumber' => self::shipfusionNumber($order),
            'orderDate'   => gmdate('Ymd', self::createdAt($order)),
            'warehouse'   => self::warehouse($order, $address['country'], $items),
            'toAddress'   => $address,
            'items'       => $items,
        ];

        $note = trim((string) $order->note);
        if ($note !== '') {
            $payload['specialInstructions'] = substr(wp_strip_all_tags($note), 0, 500);
        }

        $payload = array_merge($payload, self::carrierService($order));

        /**
         * The ShipFusion "Create Outbound Shipment" body, before it is sent.
         *
         * @param array $payload
         * @param Order $order
         */
        return (array) apply_filters('upfluent_sf_shipment_payload', $payload, $order);
    }

    /** The order number ShipFusion shows and searches by. */
    public static function orderNumber(Order $order): string
    {
        return $order->invoice_no ? (string) $order->invoice_no : 'FC-' . (int) $order->id;
    }

    /**
     * The number this order has in ShipFusion: FluentCart's own number, or the
     * <number>-<order ID> it was sent under because ShipFusion already had
     * another order with that number (Orders::send).
     */
    public static function shipfusionNumber(Order $order): string
    {
        $row = Store::get((int) $order->id);
        return (string) ($row['meta']['order_number'] ?? '') ?: self::orderNumber($order);
    }

    /**
     * The warehouse this order ships from. Once sent, the one it was sent to
     * (kept on the row). Otherwise, with one warehouse selected, that one;
     * with several, the first selected warehouse that has every item ready
     * ("View Inventory" per SKU and warehouse), trying the warehouse in the
     * customer's country first (Toronto for Canada, the US ones elsewhere).
     * If none has everything, or ShipFusion can't be read, the first selected
     * warehouse in the customer's country, else the first selected one.
     *
     * @param array<int,array{SKU:string,qty:int}> $items
     */
    public static function warehouse(Order $order, string $country, array $items): string
    {
        $row = Store::get((int) $order->id);
        if (!empty($row['meta']['warehouse'])) {
            return (string) $row['meta']['warehouse'];
        }
        $selected = (array) Settings::get('warehouses');
        if (count($selected) < 2) {
            return (string) ($selected[0] ?? 'IL');
        }
        $local = array_values(array_filter($selected, function ($code) use ($country) {
            return ($code === Settings::CANADA_WAREHOUSE) === ($country === 'CA');
        }));
        $ordered = array_values(array_unique(array_merge($local, $selected)));
        $api     = new Api();
        foreach ($ordered as $code) {
            try {
                foreach ($items as $item) {
                    $stock = $api->get('/inventory/' . rawurlencode($item['SKU']), ['warehouse' => $code]);
                    if ((int) ($stock['ready'] ?? 0) < (int) $item['qty']) {
                        continue 2;
                    }
                }
            } catch (ApiException $e) {
                if ($e->isAuth()) {
                    throw $e;
                }
                if ($e->kind() === 'data') {
                    continue;   // The SKU isn't stocked in this warehouse.
                }
                break;          // ShipFusion can't be read right now.
            }
            return $code;
        }
        return $ordered[0];
    }

    /** When the order was placed, as a timestamp. FluentCart stores UTC. */
    public static function createdAt(Order $order): int
    {
        $raw = (string) ($order->getAttributes()['created_at'] ?? '');
        return $raw !== '' ? (int) strtotime($raw . ' UTC') : time();
    }

    /** A short fingerprint of what was sent, to notice later changes. */
    public static function fingerprint(array $payload): string
    {
        return md5(wp_json_encode([$payload['toAddress'] ?? [], $payload['items'] ?? []]));
    }

    /**
     * SKU => quantity for the warehouse, from the units still to ship.
     *
     * @param array<int,int> $units unitsToShip()
     * @return array<string,int>
     * @throws ApiException kind "data" when a product has no SKU.
     */
    public static function lines(Order $order, array $units): array
    {
        // FluentCart stores a bundle's parts as rows of their own (payment_type
        // "bundle", line_meta bundle_parent_item_id) and a subscription's signup
        // fee as a row with the product's SKU (CheckoutProcessor, FluentCart
        // 2.3.3). Fees are never picked; a bundle goes out either as the bundle
        // SKU or, with upfluent_sf_send_bundle_parts, as its part rows.
        $parts_of = [];
        foreach ($order->order_items as $item) {
            if ($item->payment_type === 'bundle') {
                $parts_of[(int) ($item->line_meta['bundle_parent_item_id'] ?? 0)] = true;
            }
        }

        $lines   = [];
        $missing = [];
        $by_part = [];
        $send_parts = [];
        foreach ($order->order_items as $item) {
            $qty = $units[(int) $item->id] ?? 0;
            if (!$qty || in_array($item->payment_type, ['signup_fee', 'fee'], true)) {
                continue;
            }
            if ($item->payment_type === 'bundle') {
                $by_part[] = $item;
                continue;
            }
            $variation = ProductVariation::query()->find((int) $item->object_id);
            $sku       = $variation ? self::sku($variation) : '';
            if ($sku === '') {
                $missing[] = (string) ($item->title ?: $item->post_title);
                continue;
            }
            $children = $variation && Helper::isBundleItem($variation->other_info) ? array_map('intval', (array) ($variation->other_info['bundle_child_ids'] ?? [])) : [];
            /**
             * Send a bundle's parts instead of the bundle's own SKU, for
             * warehouses that have no kit set up for the bundle.
             *
             * @param bool             $components false: send the bundle SKU.
             * @param ProductVariation $variation  The bundle variation.
             */
            if ($children && apply_filters('upfluent_sf_send_bundle_parts', false, $variation)) {
                if (isset($parts_of[(int) $item->id])) {
                    $send_parts[(int) $item->id] = true;
                    continue;
                }
                // Orders without part rows: work the parts out from the bundle.
                foreach (array_count_values($children) as $child_id => $per_bundle) {
                    $child     = ProductVariation::query()->find($child_id);
                    $child_sku = $child ? self::sku($child) : '';
                    if ($child_sku === '') {
                        $missing[] = (string) ($item->title ?: $item->post_title);
                        continue 2;
                    }
                    $lines[$child_sku] = ($lines[$child_sku] ?? 0) + $qty * $per_bundle;
                }
                continue;
            }
            $lines[$sku] = ($lines[$sku] ?? 0) + $qty;
        }
        foreach ($by_part as $item) {
            if (!isset($send_parts[(int) ($item->line_meta['bundle_parent_item_id'] ?? 0)])) {
                continue;
            }
            $part = ProductVariation::query()->find((int) $item->object_id);
            $sku  = $part ? self::sku($part) : '';
            if ($sku === '') {
                $missing[] = (string) ($item->title ?: $item->post_title);
                continue;
            }
            $lines[$sku] = ($lines[$sku] ?? 0) + $units[(int) $item->id];
        }

        if ($missing) {
            throw self::problem(esc_html(sprintf(
                /* translators: %s: product names */
                __('These products have no SKU, so the warehouse can\'t pick them: %s. Add the SKU on the product, then retry the order.', 'order-sync-for-shipfusion-and-fluentcart'),
                implode(', ', array_unique($missing))
            )));
        }

        return $lines;
    }

    private static function sku(ProductVariation $variation): string
    {
        $sku = trim((string) $variation->sku);
        return $sku !== '' ? $sku : trim((string) $variation->variation_identifier);
    }

    private static function address(Order $order): array
    {
        $address = $order->shipping_address ?: $order->billing_address;
        $billing = $order->billing_address;

        $name  = trim((string) ($address->name ?? ''));
        $parts = $name === '' ? [] : preg_split('/\s+/', $name);
        $last  = count($parts) > 1 ? (string) array_pop($parts) : '';
        $first = implode(' ', $parts);
        if ($first === '' && $order->customer) {
            $first = (string) $order->customer->first_name;
            $last  = (string) $order->customer->last_name;
        }

        $phone = (string) ($address->phone ?? '');
        if ($phone === '' && $billing) {
            $phone = (string) $billing->phone;
        }
        if ($phone === '') {
            $phone = (string) Settings::get('fallback_phone');
        }

        $email = $order->customer ? (string) $order->customer->email : '';

        return [
            'firstName'     => $first,
            'lastName'      => $last,
            'companyName'   => (string) ($address->company_name ?? ''),
            'address1'      => (string) ($address->address_1 ?? ''),
            'address2'      => (string) ($address->address_2 ?? ''),
            'city'          => (string) ($address->city ?? ''),
            'state'         => (string) ($address->state ?? ''),
            'zipPostalCode' => (string) ($address->postcode ?? ''),
            'country'       => strtoupper((string) ($address->country ?? '')),
            'phoneNumber'   => $phone,
            'emailAddress'  => $email,
        ];
    }

    /** Parts ShipFusion needs to ship; state only where countries use it. */
    private static function missingAddressParts(array $address): array
    {
        $labels = [
            'firstName'     => __('name', 'order-sync-for-shipfusion-and-fluentcart'),
            'address1'      => __('street address', 'order-sync-for-shipfusion-and-fluentcart'),
            'city'          => __('city', 'order-sync-for-shipfusion-and-fluentcart'),
            'zipPostalCode' => __('postcode', 'order-sync-for-shipfusion-and-fluentcart'),
            'country'       => __('country', 'order-sync-for-shipfusion-and-fluentcart'),
            'emailAddress'  => __('customer email', 'order-sync-for-shipfusion-and-fluentcart'),
        ];
        if (in_array($address['country'], ['US', 'CA', 'AU', 'MX', 'BR', 'IN'], true)) {
            $labels['state'] = __('state or province', 'order-sync-for-shipfusion-and-fluentcart');
        }
        $missing = [];
        foreach ($labels as $field => $label) {
            if (trim((string) $address[$field]) === '') {
                $missing[] = $label;
            }
        }
        return $missing;
    }

    /**
     * Carrier and service for the shipping method the customer chose. Without
     * a mapping ShipFusion uses the warehouse default.
     */
    private static function carrierService(Order $order): array
    {
        $config = is_array($order->config) ? $order->config : [];
        $title  = strtolower(trim((string) ($config['shipping_method_title'] ?? '')));

        /**
         * Map FluentCart shipping method titles (lower case) to ShipFusion
         * carrier and service codes (docs.shipfusion.com, Carriers / Services), e.g.
         *   ['express' => ['shippingCarrier' => 'USPS', 'shippingService' => 'Priority']]
         * An exact title match wins; otherwise the first key the title contains.
         *
         * @param array $map
         * @param Order $order
         */
        $map = (array) apply_filters('upfluent_sf_shipping_method_map', [], $order);
        if ($title === '' || !$map) {
            return [];
        }
        $target = $map[$title] ?? null;
        if (!is_array($target)) {
            foreach ($map as $needle => $candidate) {
                if (is_array($candidate) && strpos($title, strtolower((string) $needle)) !== false) {
                    $target = $candidate;
                    break;
                }
            }
        }
        return is_array($target) ? array_intersect_key(array_filter($target, 'is_string'), ['shippingCarrier' => 1, 'shippingService' => 1]) : [];
    }
}
