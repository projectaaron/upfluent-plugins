<?php

namespace UpfluentSf;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;

defined('ABSPATH') || exit;

/**
 * Copies ShipFusion's ready-to-ship quantity ("List Inventory", field
 * `ready`) into FluentCart's stock for every variation with the same SKU
 * that tracks stock. ShipFusion doesn't report bundle SKUs, so a bundle's
 * stock is worked out from its parts afterwards.
 *
 * Workaround: FluentCart 2.3.3 has no service method for setting a
 * variation's stock; its own endpoint (ProductController::updateInventory)
 * writes the variation and product detail through the models and dispatches
 * StockChanged. This does the same for the one variation, without that
 * endpoint's side effect of turning stock tracking on for every sibling.
 * Replace with a FluentCart stock API when one exists.
 */
class Stock
{
    /**
     * @param string[] $only_skus Limit to these SKUs (from a webhook); empty = all.
     * @return array{ok: bool, message: string}
     */
    public static function sync(array $only_skus = []): array
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return ['ok' => false, 'message' => __('Stock Management is off in FluentCart, so there is no stock to update. Turn it on in FluentCart, Settings, Features.', 'order-sync-for-shipfusion-and-fluentcart')];
        }

        $ready = self::readyBySku($only_skus);
        if ($ready === null) {
            return ['ok' => false, 'message' => Settings::connection()['message'] ?: __('Couldn\'t read stock from ShipFusion. It will be tried again.', 'order-sync-for-shipfusion-and-fluentcart')];
        }

        $updated  = 0;
        $unknown  = 0;
        $products = [];
        foreach ($ready as $sku => $qty) {
            $variations = ProductVariation::query()
                ->where('manage_stock', 1)
                ->where(function ($q) use ($sku) {
                    $q->where('sku', $sku)->orWhere('variation_identifier', $sku);
                })
                ->get();
            if (!count($variations)) {
                $unknown++;
                continue;
            }
            foreach ($variations as $variation) {
                if (self::setStock($variation, $qty)) {
                    $updated++;
                    $products[(int) $variation->post_id] = true;
                }
            }
        }

        $updated += self::syncBundles($products);

        if ($products) {
            (new StockChanged(array_keys($products)))->dispatch();
        }

        $message = sprintf(
            /* translators: 1: number of products updated, 2: number of ShipFusion SKUs read */
            _n('Updated stock for %1$d product option from %2$d ShipFusion SKUs.', 'Updated stock for %1$d product options from %2$d ShipFusion SKUs.', $updated, 'order-sync-for-shipfusion-and-fluentcart'),
            $updated,
            count($ready)
        );
        if ($unknown) {
            /* translators: %d: number of SKUs */
            $message .= ' ' . sprintf(_n('%d SKU in ShipFusion has no matching product in FluentCart.', '%d SKUs in ShipFusion have no matching product in FluentCart.', $unknown, 'order-sync-for-shipfusion-and-fluentcart'), $unknown);
        }
        Log::info($message);
        return ['ok' => true, 'message' => $message];
    }

    /**
     * ShipFusion's ready quantity per SKU, every page, added up over the
     * selected warehouses (with several, "List Inventory" for ALL gives each
     * item's per-warehouse quantities). Null when ShipFusion couldn't be read
     * (stock is then left as it is, never set to zero).
     *
     * @return array<string,int>|null
     */
    private static function readyBySku(array $only_skus): ?array
    {
        $api       = new Api();
        $ready     = [];
        $selected  = (array) Settings::get('warehouses');
        $several   = count($selected) > 1;
        $query     = ['warehouse' => $several ? 'ALL' : (string) $selected[0], 'perPage' => 250];
        if ($only_skus) {
            $query['search'] = implode(',', $only_skus);
        }
        for ($page = 1; $page <= 40; $page++) {
            try {
                $response = $api->get('/inventory', $query + ['page' => $page]);
            } catch (ApiException $e) {
                Log::warning($e->text());
                return null;
            }
            $items = $response['items'] ?? [];
            if (!is_array($items) || !$items) {
                break;
            }
            foreach ($items as $item) {
                $sku = is_array($item) ? trim((string) ($item['SKU'] ?? '')) : '';
                if ($sku === '') {
                    continue;
                }
                if ($several) {
                    $total = null;
                    foreach ((array) ($item['warehouses'] ?? []) as $place) {
                        if (is_array($place) && in_array($place['warehouse'] ?? '', $selected, true) && is_numeric($place['ready'] ?? null)) {
                            $total = (int) $total + max(0, (int) $place['ready']);
                        }
                    }
                    if ($total !== null) {
                        $ready[$sku] = $total;
                    }
                } elseif (isset($item['ready']) && is_numeric($item['ready'])) {
                    // A SKU without a numeric ready quantity is skipped, never written as 0.
                    $ready[$sku] = max(0, (int) $item['ready']);
                }
            }
            if ($page >= (int) ($response['totalPages'] ?? 1)) {
                break;
            }
            Lock::extend('stock');
        }
        return $ready;
    }

    /**
     * ShipFusion's ready quantity is what can still be sold, so it becomes
     * `available`; `total_stock` keeps FluentCart's own sum (available +
     * committed + on hold, as UpdateStock computes it), so FluentCart's next
     * stock change starts from the right number.
     */
    private static function setStock(ProductVariation $variation, int $qty): bool
    {
        $total = $qty + max(0, (int) $variation->committed) + max(0, (int) $variation->on_hold);
        if ((int) $variation->available === $qty && (int) $variation->total_stock === $total) {
            return false;
        }
        ProductVariation::query()->where('id', $variation->id)->update([
            'total_stock'  => $total,
            'available'    => $qty,
            'stock_status' => $qty > 0 ? 'in-stock' : 'out-of-stock',
        ]);
        $detail = ProductDetail::query()->where('post_id', $variation->post_id)->first();
        if ($detail) {
            $any = ProductVariation::query()->where('post_id', $variation->post_id)->where('available', '>', 0)->exists();
            $detail->stock_availability = $any ? 'in-stock' : 'out-of-stock';
            $detail->save();
        }
        return true;
    }

    /**
     * A bundle can be sold as many times as its scarcest part allows
     * (a part listed twice counts twice). Same bundle test as FluentCart's
     * Helper::isBundleItem().
     */
    private static function syncBundles(array &$products): int
    {
        $updated = 0;
        foreach (ProductVariation::query()->where('manage_stock', 1)->get() as $bundle) {
            if (!Helper::isBundleItem($bundle->other_info)) {
                continue;
            }
            $children = array_count_values(array_map('intval', (array) ($bundle->other_info['bundle_child_ids'] ?? [])));
            if (!$children) {
                continue;
            }
            $can = null;
            foreach ($children as $child_id => $per) {
                $child = ProductVariation::query()->find($child_id);
                if (!$child) {
                    $can = null;
                    break;
                }
                $makeable = intdiv(max(0, (int) $child->available), max(1, $per));
                $can      = $can === null ? $makeable : min($can, $makeable);
            }
            if ($can !== null && self::setStock($bundle, $can)) {
                $updated++;
                $products[(int) $bundle->post_id] = true;
            }
        }
        return $updated;
    }
}
