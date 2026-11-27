<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * A "ShipFusion" card on FluentCart's order screen, through FluentCart's own
 * widget filter fluent_cart/widgets/single_order_page (WidgetsController,
 * FluentCart 2.3.3; $data['order'] is the Order). Tracking itself shows in
 * FluentCart's Shipments panel; this card says where the order is in the
 * warehouse and what, if anything, a person has to do.
 */
class OrderWidget
{
    public function register(): void
    {
        add_filter('fluent_cart/widgets/single_order_page', [$this, 'widget'], 10, 2);
    }

    public function widget($widgets, $data = [])
    {
        if (!is_array($widgets)) {
            $widgets = [];
        }
        $order = is_array($data) ? ($data['order'] ?? null) : null;
        if (!is_object($order) || !Permissions::canViewOrder()) {
            return $widgets;
        }
        $row = Store::get((int) $order->id);
        if (!$row || $row['state'] === 'handled') {
            return $widgets;
        }

        $lines = ['<p style="margin:0 0 6px;"><strong>' . esc_html(self::stateText($row)) . '</strong></p>'];
        if ($row['shipment_id'] !== '') {
            /* translators: %s: ShipFusion shipment number */
            $lines[] = '<p style="margin:0 0 6px;">' . esc_html(sprintf(__('ShipFusion shipment %s', 'order-sync-for-shipfusion-and-fluentcart'), $row['shipment_id'])) . '</p>';
        }
        if (!empty($row['meta']['warehouse']) && count((array) Settings::get('warehouses')) > 1) {
            /* translators: %s: ShipFusion warehouse code, e.g. IL */
            $lines[] = '<p style="margin:0 0 6px;">' . esc_html(sprintf(__('Ships from warehouse %s', 'order-sync-for-shipfusion-and-fluentcart'), $row['meta']['warehouse'])) . '</p>';
        }
        if (!empty($row['meta']['order_number'])) {
            /* translators: %s: order number used in ShipFusion */
            $lines[] = '<p style="margin:0 0 6px;">' . esc_html(sprintf(__('ShipFusion order number %s', 'order-sync-for-shipfusion-and-fluentcart'), $row['meta']['order_number'])) . '</p>';
        }
        if (in_array($row['state'], ['attention', 'waiting'], true) && !empty($row['problem'])) {
            $lines[] = '<p style="margin:0 0 6px;">' . esc_html((string) $row['problem']) . '</p>';
        }
        if ($row['state'] === 'attention' && Permissions::canManageSettings()) {
            $lines[] = '<p style="margin:0;"><a href="' . esc_url(AdminMenu::url()) . '">' . esc_html__('Retry or mark as handled', 'order-sync-for-shipfusion-and-fluentcart') . '</a></p>';
        }

        $widgets[] = [
            'title'    => __('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'),
            'subtitle' => '',
            'type'     => 'html',
            'content'  => implode('', $lines),
        ];
        return $widgets;
    }

    private static function stateText(array $row): string
    {
        switch ($row['state']) {
            case 'waiting':
                return __('Waiting to be sent to the warehouse', 'order-sync-for-shipfusion-and-fluentcart');
            case 'sent':
                return $row['sf_status'] === 'inProcess'
                    ? __('The warehouse is packing it', 'order-sync-for-shipfusion-and-fluentcart')
                    : __('In the warehouse queue', 'order-sync-for-shipfusion-and-fluentcart');
            case 'on_hold':
                return __('On hold at the warehouse', 'order-sync-for-shipfusion-and-fluentcart');
            case 'back_order':
                return __('Waiting for stock at the warehouse', 'order-sync-for-shipfusion-and-fluentcart');
            case 'shipped':
                return __('Shipped (tracking is under Shipments)', 'order-sync-for-shipfusion-and-fluentcart');
            case 'delivered':
                return __('Delivered', 'order-sync-for-shipfusion-and-fluentcart');
            case 'cancelled':
                return __('Cancelled at the warehouse', 'order-sync-for-shipfusion-and-fluentcart');
            default:
                return __('Needs your attention', 'order-sync-for-shipfusion-and-fluentcart');
        }
    }
}
