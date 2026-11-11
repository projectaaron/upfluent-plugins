<?php

namespace S2FC;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Reads a Shopify "Export products" CSV into one array per product.
 *
 * Shopify writes one row per variant and extra rows for additional images.
 * Rows share a Handle; the first row of a handle carries the product-level
 * columns (Title, Body, Vendor, Type, Tags, Status ...). A row is a variant
 * when it has an Option1 Value or a Variant Price; a row with only an Image
 * Src is another gallery image.
 */
class Csv_Parser
{
    const MAX_ROWS = 20000;

    /** Columns we read, keyed by the exact Shopify header. */
    const COLUMNS = [
        'handle'          => 'Handle',
        'title'           => 'Title',
        'body'            => 'Body (HTML)',
        'vendor'          => 'Vendor',
        'category'        => 'Product Category',
        'type'            => 'Type',
        'tags'            => 'Tags',
        'published'       => 'Published',
        'opt1_name'       => 'Option1 Name',
        'opt1_value'      => 'Option1 Value',
        'opt2_name'       => 'Option2 Name',
        'opt2_value'      => 'Option2 Value',
        'opt3_name'       => 'Option3 Name',
        'opt3_value'      => 'Option3 Value',
        'sku'             => 'Variant SKU',
        'grams'           => 'Variant Grams',
        'tracker'         => 'Variant Inventory Tracker',
        'qty'             => 'Variant Inventory Qty',
        'policy'          => 'Variant Inventory Policy',
        'price'           => 'Variant Price',
        'compare'         => 'Variant Compare At Price',
        'shipping'        => 'Variant Requires Shipping',
        'taxable'         => 'Variant Taxable',
        'barcode'         => 'Variant Barcode',
        'image_src'       => 'Image Src',
        'image_position'  => 'Image Position',
        'image_alt'       => 'Image Alt Text',
        'gift_card'       => 'Gift Card',
        'seo_title'       => 'SEO Title',
        'seo_description' => 'SEO Description',
        'variant_image'   => 'Variant Image',
        'weight_unit'     => 'Variant Weight Unit',
        'cost'            => 'Cost per item',
        'status'          => 'Status',
        'mpn'             => 'Google Shopping / MPN',
        'length'          => 'Variant Packed Length',
        'width'           => 'Variant Packed Width',
        'height'          => 'Variant Packed Height',
        'dimension_unit'  => 'Variant Packed Dimension Unit',
    ];

    /** Older and newer exports name a few columns differently. */
    const ALIASES = [
        'barcode' => ['Variant Barcodes', 'Barcode', 'Variant GTIN', 'GTIN'],
        'qty'     => ['Variant Inventory Quantity', 'Inventory Qty', 'Inventory Quantity'],
    ];

    /**
     * @return array|WP_Error ['products' => [...], 'summary' => [...]]
     */
    public static function parse_file(string $path)
    {
        if (!is_readable($path)) {
            return new WP_Error('unreadable', __('The uploaded file could not be read.', 'shopify-to-fluentcart-migrator'));
        }

        $fh = fopen($path, 'r');
        if (!$fh) {
            return new WP_Error('unreadable', __('The uploaded file could not be opened.', 'shopify-to-fluentcart-migrator'));
        }

        // Strip a UTF-8 BOM so the first header matches "Handle".
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header || !is_array($header)) {
            fclose($fh);
            return new WP_Error('empty', __('The file is empty.', 'shopify-to-fluentcart-migrator'));
        }

        $map = self::map_columns($header);
        if (!isset($map['handle'], $map['title'])) {
            fclose($fh);
            return new WP_Error('not_shopify', __('This does not look like a Shopify product export: the "Handle" and "Title" columns are missing. In Shopify go to Products → Export and choose "CSV for Excel, Numbers, or other spreadsheet programs".', 'shopify-to-fluentcart-migrator'));
        }

        $products = [];
        $order    = [];
        $rows     = 0;
        $skipped  = 0;
        $has_qty  = isset($map['qty']);
        $columns  = count($header);

        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows++;
            if ($rows > self::MAX_ROWS) {
                fclose($fh);
                return new WP_Error('too_large', sprintf(
                    /* translators: %d: row limit */
                    __('This export has more than %d rows. Please split it into smaller exports (Shopify lets you export a filtered selection).', 'shopify-to-fluentcart-migrator'),
                    self::MAX_ROWS
                ));
            }
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            if (count($row) > $columns) {
                $skipped++; // A broken quote somewhere; the row cannot be trusted.
                continue;
            }
            $row = array_map([self::class, 'utf8'], $row);
            $r = self::read_row($row, $map);
            $handle = sanitize_title($r['handle']);
            if ($handle === '') {
                $skipped++;
                continue;
            }

            if (!isset($products[$handle])) {
                $products[$handle] = self::new_product($handle, $r);
                $order[] = $handle;
            }
            $p = &$products[$handle];
            foreach ($r['metafields'] as $mkey => $mval) {
                if (!isset($p['metafields'][$mkey])) {
                    $p['metafields'][$mkey] = $mval;
                }
            }

            // Later rows of the same handle sometimes carry product columns
            // Shopify left blank on the first; fill any blanks.
            foreach (['title', 'body', 'vendor', 'category', 'type', 'tags', 'status', 'seo_title', 'seo_description'] as $k) {
                if ($p['_raw'][$k] === '' && $r[$k] !== '') {
                    $p['_raw'][$k] = $r[$k];
                }
            }
            for ($i = 1; $i <= 3; $i++) {
                if ($r['opt' . $i . '_name'] !== '' && empty($p['options'][$i - 1])) {
                    $p['options'][$i - 1] = $r['opt' . $i . '_name'];
                }
            }

            $is_variant = $r['opt1_value'] !== '' || $r['price'] !== '' || $r['sku'] !== '';
            if ($is_variant) {
                $p['variants'][] = self::read_variant($r);
            }

            if ($r['image_src'] !== '') {
                $src = esc_url_raw(trim($r['image_src']));
                if ($src && !isset($p['_image_index'][$src])) {
                    $p['_image_index'][$src] = true;
                    $p['images'][] = [
                        'src'      => $src,
                        'position' => (int) $r['image_position'] ?: (count($p['images']) + 1),
                        'alt'      => sanitize_text_field($r['image_alt']),
                    ];
                }
            }
            unset($p);
        }
        fclose($fh);

        if (!$products) {
            return new WP_Error('no_products', __('No products were found in this file.', 'shopify-to-fluentcart-migrator'));
        }

        $list    = [];
        $summary = ['products' => 0, 'variants' => 0, 'images' => 0, 'gtin_products' => 0, 'gtin_variants' => 0, 'rows' => $rows, 'skipped_rows' => $skipped, 'has_qty' => $has_qty, 'variant_images' => 0, 'duplicate_skus' => []];
        $sku_seen = [];
        foreach ($order as $handle) {
            $product = self::finish_product($products[$handle]);
            $list[]  = $product;
            foreach ($product['variants'] as $v) {
                if ($v['sku'] !== '') {
                    $sku_seen[$v['sku']] = ($sku_seen[$v['sku']] ?? 0) + 1;
                }
            }
            $summary['products']++;
            $summary['variants'] += count($product['variants']);
            $summary['images']   += count($product['images']);
            foreach ($product['variants'] as $v) {
                if ($v['image'] !== '') {
                    $summary['variant_images']++;
                }
            }
            if ($product['gtin_count'] > 0) {
                $summary['gtin_products']++;
                $summary['gtin_variants'] += $product['gtin_count'];
            }
        }

        $summary['duplicate_skus'] = array_keys(array_filter($sku_seen, function ($n) {
            return $n > 1;
        }));

        return ['products' => $list, 'summary' => $summary];
    }

    /**
     * Shopify exports UTF-8, but a file that went through Excel may come
     * back as Windows-1252. Convert what is not valid UTF-8 rather than
     * letting json_encode fail on it later.
     */
    private static function utf8($value): string
    {
        $value = (string) $value;
        if ($value === '' || preg_match('//u', $value)) {
            return $value;
        }
        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && preg_match('//u', $converted)) {
                return $converted;
            }
        }
        return (string) wp_check_invalid_utf8($value, true);
    }

    /** Header name => column index for the columns we know. Case/space tolerant. */
    private static function map_columns(array $header): array
    {
        $norm = [];
        foreach ($header as $i => $name) {
            $norm[self::norm((string) $name)] = $i;
        }
        $map = [];
        foreach (self::COLUMNS as $key => $label) {
            $n = self::norm($label);
            if (isset($norm[$n])) {
                $map[$key] = $norm[$n];
                continue;
            }
            foreach (self::ALIASES[$key] ?? [] as $alias) {
                if (isset($norm[self::norm($alias)])) {
                    $map[$key] = $norm[self::norm($alias)];
                    break;
                }
            }
        }
        // Shopify metafield columns look like "Label (product.metafields.namespace.key)".
        foreach ($header as $i => $name) {
            if (preg_match('/\(product\.metafields\.([a-z0-9_\-]+\.[a-z0-9_\-]+)\)\s*$/i', (string) $name, $m)) {
                $map['metafields'][$m[1]] = $i;
            }
        }
        return $map;
    }

    private static function norm(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $s));
    }

    /**
     * Shopify prefixes values that look like numbers (SKUs, barcodes, option
     * values such as "'0123") with an apostrophe so spreadsheets keep the
     * leading zeros. It is not part of the value.
     */
    private static function unquote(string $value): string
    {
        return preg_replace('/^[\'’]+/u', '', $value);
    }

    private static function read_row(array $row, array $map): array
    {
        $r = [];
        foreach (self::COLUMNS as $key => $label) {
            $r[$key] = isset($map[$key], $row[$map[$key]]) ? trim((string) $row[$map[$key]]) : '';
        }
        foreach (['sku', 'barcode', 'mpn', 'opt1_value', 'opt2_value', 'opt3_value'] as $key) {
            $r[$key] = self::unquote($r[$key]);
        }
        $r['metafields'] = [];
        foreach ($map['metafields'] ?? [] as $mkey => $i) {
            if (isset($row[$i]) && trim((string) $row[$i]) !== '') {
                $r['metafields'][$mkey] = trim((string) $row[$i]);
            }
        }
        return $r;
    }

    private static function new_product(string $handle, array $r): array
    {
        return [
            'handle'       => $handle,
            '_raw'         => [
                'title'           => $r['title'],
                'body'            => $r['body'],
                'vendor'          => $r['vendor'],
                'category'        => $r['category'],
                'type'            => $r['type'],
                'tags'            => $r['tags'],
                'status'          => $r['status'],
                'published'       => $r['published'],
                'seo_title'       => $r['seo_title'],
                'seo_description' => $r['seo_description'],
                'gift_card'       => $r['gift_card'],
            ],
            'options'      => [],
            'variants'     => [],
            'images'       => [],
            'metafields'   => [],
            '_image_index' => [],
        ];
    }

    private static function read_variant(array $r): array
    {
        // Positional: a blank Option2 on a three-option product stays in place.
        $values = [$r['opt1_value'], $r['opt2_value'], $r['opt3_value']];
        while ($values && end($values) === '') {
            array_pop($values);
        }
        // Shopify prefixes numeric barcodes with an apostrophe so spreadsheets keep the digits.
        $barcode = preg_replace('/[\s\-\'"’]/u', '', $r['barcode']);

        return [
            'options'          => array_map('sanitize_text_field', $values),
            'sku'              => sanitize_text_field($r['sku']),
            'grams'            => (float) $r['grams'],
            'weight_unit'      => strtolower($r['weight_unit']),
            'tracked'          => strtolower($r['tracker']) === 'shopify' && $r['qty'] !== '',
            'qty'              => (int) $r['qty'],
            'continue_selling' => strtolower($r['policy']) === 'continue',
            'price'            => Helpers::to_cents($r['price']),
            'compare'          => Helpers::to_cents($r['compare']),
            'cost'             => Helpers::to_cents($r['cost']),
            'requires_shipping'=> $r['shipping'] === '' ? true : Helpers::truthy($r['shipping']),
            'taxable'          => $r['taxable'] === '' ? true : Helpers::truthy($r['taxable']),
            'barcode'          => sanitize_text_field($barcode),
            'mpn'              => sanitize_text_field($r['mpn']),
            'image'            => esc_url_raw($r['variant_image']),
            'length'           => $r['length'] !== '' ? (float) $r['length'] : null,
            'width'            => $r['width'] !== '' ? (float) $r['width'] : null,
            'height'           => $r['height'] !== '' ? (float) $r['height'] : null,
            'dimension_unit'   => strtolower($r['dimension_unit']),
        ];
    }

    /** Turn the accumulated rows into the shape the preview and importer use. */
    private static function finish_product(array $p): array
    {
        $raw = $p['_raw'];

        usort($p['images'], function ($a, $b) {
            return $a['position'] <=> $b['position'];
        });

        // A single "Default Title" variant is a simple product.
        $variants = $p['variants'];
        if (!$variants) {
            $variants[] = self::read_variant(array_fill_keys(array_keys(self::COLUMNS), ''));
        }
        $is_simple = count($variants) === 1 && (
            !$variants[0]['options'] || strtolower((string) $variants[0]['options'][0]) === 'default title'
        );

        $options = array_values(array_filter($p['options'], function ($o) {
            return $o !== '' && strtolower($o) !== 'title';
        }));

        $title = sanitize_text_field($raw['title']) ?: ucwords(str_replace('-', ' ', $p['handle']));

        $gtin_count = 0;
        $prices     = [];
        $stock      = 0;
        $all_digital = true;
        foreach ($variants as $i => &$v) {
            $v['title'] = $is_simple ? $title : implode(' / ', array_filter($v['options'], 'strlen'));
            if ($v['barcode'] !== '') {
                $gtin_count++;
            }
            $prices[] = $v['price'];
            $stock   += max(0, $v['qty']);
            if ($v['requires_shipping']) {
                $all_digital = false;
            }
        }
        unset($v);

        $status = strtolower($raw['status']);
        if (!in_array($status, ['active', 'draft', 'archived'], true)) {
            $status = ($raw['published'] !== '' && !Helpers::truthy($raw['published'])) ? 'draft' : 'active';
        }

        $tags = array_values(array_filter(array_map('trim', explode(',', $raw['tags']))));

        return [
            'handle'          => $p['handle'],
            'title'           => $title,
            'body'            => wp_kses_post($raw['body']),
            'excerpt'         => sanitize_text_field($raw['seo_description']),
            'seo_title'       => sanitize_text_field($raw['seo_title']),
            'vendor'          => sanitize_text_field($raw['vendor']),
            'type'            => sanitize_text_field($raw['type']),
            'category'        => sanitize_text_field($raw['category']),
            'tags'            => array_map('sanitize_text_field', $tags),
            'status'          => $status,
            'gift_card'       => Helpers::truthy($raw['gift_card']),
            'is_simple'       => $is_simple,
            'options'         => array_map('sanitize_text_field', $options),
            'variants'        => $variants,
            'images'          => $p['images'],
            'gtin_count'      => $gtin_count,
            'price_min'       => $prices ? min($prices) : 0,
            'price_max'       => $prices ? max($prices) : 0,
            'stock'           => $stock,
            'fulfillment'     => $all_digital ? 'digital' : 'physical',
            'metafields'      => $p['metafields'],
        ];
    }
}
