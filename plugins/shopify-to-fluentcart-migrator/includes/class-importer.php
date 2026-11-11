<?php

namespace S2FC;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Writes one parsed Shopify product into FluentCart: the fluent-products
 * post, its fct_product_details row, one fct_product_variations row per
 * variant, the gallery, categories/brand/tags, and the GTINs.
 *
 * FluentCart has no public "create product" PHP API, so the rows are written
 * the way FluentCart's own REST controller writes them (verified against
 * FluentCart 1.6.x).
 */
class Importer
{
    /** @var array url => attachment id, shared across one import run */
    private static $image_cache = [];

    public static function default_options(): array
    {
        return [
            'status'      => 'draft',   // draft | shopify | publish
            'images'      => 1,
            'category_source' => 'type', // type | category | tags | type_category | none
            'tags'        => 1,
            'vendor'      => 1,
            'weight_unit' => self::store_weight_unit(),
            'skip_done'   => 1,
        ];
    }

    /** Where FluentCart product categories can come from, with labels for the screen. */
    public static function category_sources(): array
    {
        return [
            'type'          => __('Shopify Type (e.g. "Book", "Prints")', 'shopify-to-fluentcart-migrator'),
            'category'      => __('Shopify Product Category, last part (e.g. "Media > Books" → "Books")', 'shopify-to-fluentcart-migrator'),
            'tags'          => __('Shopify Tags (one category per tag)', 'shopify-to-fluentcart-migrator'),
            'type_category' => __('Both Type and Product Category', 'shopify-to-fluentcart-migrator'),
            'none'          => __('None, I will set categories in FluentCart', 'shopify-to-fluentcart-migrator'),
        ];
    }

    /** The category names one product would get under a source. */
    public static function categories_for(array $product, string $source): array
    {
        $type = trim((string) ($product['type'] ?? ''));
        $leaf = '';
        if (!empty($product['category'])) {
            $parts = array_map('trim', explode('>', (string) $product['category']));
            $leaf  = (string) end($parts);
        }
        switch ($source) {
            case 'type':
                $names = [$type];
                break;
            case 'category':
                $names = [$leaf];
                break;
            case 'tags':
                $names = (array) ($product['tags'] ?? []);
                break;
            case 'type_category':
                $names = [$type];
                if ($leaf !== '' && strcasecmp($leaf, $type) !== 0) {
                    $names[] = $leaf;
                }
                break;
            default:
                $names = [];
        }
        return array_values(array_unique(array_filter(array_map('trim', $names), 'strlen')));
    }

    /** The unit FluentCart's own store settings use, falling back to oz. */
    public static function store_weight_unit(): string
    {
        $helper = '\\FluentCart\\App\\Helpers\\Helper';
        if (class_exists($helper) && method_exists($helper, 'shopConfig')) {
            try {
                $unit = (string) call_user_func([$helper, 'shopConfig'], 'weight_unit');
                if (in_array($unit, ['g', 'kg', 'oz', 'lb'], true)) {
                    return $unit;
                }
            } catch (\Throwable $e) {
                unset($e); // FluentCart not ready to answer; use the default below.
            }
        }
        return 'oz';
    }

    public static function sanitize_options(array $in): array
    {
        $o = self::default_options();
        if (isset($in['status']) && in_array($in['status'], ['draft', 'shopify', 'publish'], true)) {
            $o['status'] = $in['status'];
        }
        foreach (['images', 'tags', 'vendor', 'skip_done'] as $flag) {
            $o[$flag] = !empty($in[$flag]) ? 1 : 0;
        }
        if (isset($in['category_source']) && isset(self::category_sources()[$in['category_source']])) {
            $o['category_source'] = $in['category_source'];
        } elseif (array_key_exists('categories', $in)) {
            // Option shape from 1.0.0.
            $o['category_source'] = !empty($in['categories']) ? 'type' : 'none';
        }
        if (isset($in['weight_unit']) && in_array($in['weight_unit'], ['g', 'kg', 'oz', 'lb'], true)) {
            $o['weight_unit'] = $in['weight_unit'];
        }
        return $o;
    }

    /** Product (post) ID already imported from this handle, or 0. */
    public static function existing_by_handle(string $handle): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_status <> 'trash' LIMIT 1",
            Helpers::HANDLE_META,
            $handle,
            Helpers::POST_TYPE
        ));
        return (int) $id;
    }

    /** handle => post ID for every handle in the list that was imported before. */
    public static function existing_handles(array $handles): array
    {
        global $wpdb;
        $handles = array_values(array_unique(array_filter(array_map('strval', $handles))));
        $map = [];
        foreach (array_chunk($handles, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %s placeholders.
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.meta_value AS handle, pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                  WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status <> 'trash' AND pm.meta_value IN ($in)",
                Helpers::HANDLE_META,
                Helpers::POST_TYPE,
                ...$chunk
            ));
            // phpcs:enable
            foreach ((array) $rows as $r) {
                if (!isset($map[$r->handle])) {
                    $map[$r->handle] = (int) $r->post_id;
                }
            }
        }
        return $map;
    }

    /** SKUs from the list that already exist on any FluentCart variation. */
    public static function existing_skus(array $skus): array
    {
        global $wpdb;
        $skus = array_values(array_unique(array_filter(array_map('strval', $skus))));
        if (!$skus) {
            return [];
        }
        $found = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of %s placeholders.
            $rows = $wpdb->get_col($wpdb->prepare("SELECT sku FROM {$wpdb->prefix}fct_product_variations WHERE sku IN ($in)", ...$chunk));
            foreach ((array) $rows as $sku) {
                $found[$sku] = true;
            }
        }
        return $found;
    }

    /**
     * Import one product.
     *
     * @return array|WP_Error ['post_id' => int, 'status' => 'imported'|'skipped', 'warnings' => string[], 'gtin' => array]
     */
    public static function import(array $product, array $options)
    {
        global $wpdb;

        if (!Helpers::fluentcart_ready()) {
            return new WP_Error('no_fluentcart', __('FluentCart is not active.', 'shopify-to-fluentcart-migrator'));
        }

        $options  = self::sanitize_options($options);
        $warnings = [];

        $existing = self::existing_by_handle($product['handle']);
        if ($existing && $options['skip_done']) {
            return [
                'post_id'  => $existing,
                'status'   => 'skipped',
                'warnings' => [__('Already imported earlier; skipped.', 'shopify-to-fluentcart-migrator')],
                'gtin'     => ['saved' => 0, 'skipped' => 0, 'errors' => []],
            ];
        }

        // One import per handle at a time: a retried request or a second tab
        // must not create the same product twice.
        $lock = 's2fc_lock_' . md5($product['handle']);
        if (get_transient($lock)) {
            return new WP_Error('locked', __('This product is already being imported in another request. Wait a moment and try again.', 'shopify-to-fluentcart-migrator'));
        }
        set_transient($lock, time(), 5 * MINUTE_IN_SECONDS);

        try {
            $result = self::write($product, $options);
        } finally {
            delete_transient($lock);
        }
        return $result;
    }

    /** The actual write; import() wraps it in the lock. */
    private static function write(array $product, array $options)
    {
        global $wpdb;
        $warnings = [];

        @set_time_limit(180);

        // ── Post ──
        $post_status = self::post_status($product['status'], $options['status']);
        $postarr = [
            'post_type'      => Helpers::POST_TYPE,
            'post_title'     => $product['title'],
            'post_name'      => $product['handle'],
            'post_content'   => $product['body'],
            'post_excerpt'   => $product['excerpt'],
            'post_status'    => $post_status,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ];
        $post_id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // ── Variations ──
        $now      = current_time('mysql', true);
        $existing_skus = self::existing_skus(array_column($product['variants'], 'sku'));
        $seen_skus     = [];
        $variation_ids = [];
        $variant_images = [];
        $gtin_rows     = [];
        $any_managed   = false;
        $any_in_stock  = false;
        $fulfillment   = $product['fulfillment'];
        $table         = $wpdb->prefix . 'fct_product_variations';

        foreach ($product['variants'] as $i => $v) {
            $sku = $v['sku'];
            if ($sku !== '' && (isset($existing_skus[$sku]) || isset($seen_skus[$sku]))) {
                $warnings[] = sprintf(
                    /* translators: 1: SKU, 2: variant title */
                    __('SKU "%1$s" already exists in FluentCart, so "%2$s" was imported without a SKU.', 'shopify-to-fluentcart-migrator'),
                    $sku,
                    $v['title']
                );
                $sku = '';
            }
            if ($sku !== '') {
                $seen_skus[$sku] = true;
            }

            $managed  = (bool) $v['tracked'];
            $qty      = max(0, (int) $v['qty']);
            $in_stock = !$managed || $qty > 0 || $v['continue_selling'];
            $any_managed  = $any_managed || $managed;
            $any_in_stock = $any_in_stock || $in_stock;

            $weight = $v['grams'] > 0 ? Helpers::convert_grams((float) $v['grams'], $options['weight_unit']) : null;

            $dim_unit = in_array($v['dimension_unit'] ?? '', ['in', 'cm', 'mm', 'm'], true) ? $v['dimension_unit'] : 'in';
            $other_info = [
                'description'       => '',
                'payment_type'      => 'onetime',
                'package_slug'      => null,
                'weight'            => $weight,
                'weight_unit'       => $options['weight_unit'],
                'length'            => $v['length'] ?? null,
                'width'             => $v['width'] ?? null,
                'height'            => $v['height'] ?? null,
                'dimension_unit'    => $dim_unit,
                'tax_class'         => null,
                'tax_exempt'        => $v['taxable'] ? 'no' : 'yes',
                'is_bundle_product' => 'no',
                'bundle_child_ids'  => [],
                'shopify'           => [
                    'barcode' => $v['barcode'],
                    'mpn'     => $v['mpn'] ?? '',
                    'options' => $v['options'],
                ],
            ];
            $variant_fulfillment = $v['requires_shipping'] ? 'physical' : 'digital';

            $row = [
                'post_id'              => $post_id,
                'media_id'             => null,
                'serial_index'         => $i + 1,
                'sku'                  => $sku !== '' ? $sku : null,
                'variation_title'      => $v['title'],
                'variation_identifier' => null,
                'item_price'           => (int) $v['price'],
                'compare_price'        => (int) $v['compare'],
                'item_cost'            => (int) $v['cost'],
                'manage_cost'          => $v['cost'] > 0 ? 'true' : 'false',
                'stock_status'         => $in_stock ? 'in-stock' : 'out-of-stock',
                'manage_stock'         => $managed ? '1' : '0',
                'backorders'           => $v['continue_selling'] ? 1 : 0,
                'total_stock'          => $qty,
                'available'            => $qty,
                'on_hold'              => 0,
                'committed'            => 0,
                'sold_individually'    => 0,
                'payment_type'         => 'onetime',
                'fulfillment_type'     => $variant_fulfillment,
                'item_status'          => 'active',
                'other_info'           => wp_json_encode($other_info),
                'shipping_class'       => null,
                'downloadable'         => $variant_fulfillment === 'digital' ? 'true' : 'false',
                'created_at'           => $now,
                'updated_at'           => $now,
            ];
            $row = apply_filters('s2fc_variation_row', $row, $v, $product, $options);

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ok = $wpdb->insert($table, $row);
            if ($ok === false) {
                $warnings[] = sprintf(
                    /* translators: 1: variant title, 2: database error */
                    __('Variant "%1$s" could not be saved: %2$s', 'shopify-to-fluentcart-migrator'),
                    $v['title'],
                    $wpdb->last_error
                );
                continue;
            }
            $vid = (int) $wpdb->insert_id;
            $variation_ids[] = $vid;
            if ($v['barcode'] !== '' || ($v['mpn'] ?? '') !== '') {
                $gtin_rows[] = ['variation_id' => $vid, 'gtin' => $v['barcode'], 'mpn' => $v['mpn'] ?? ''];
            }
            if ($options['images'] && $v['image'] !== '' && !$product['is_simple']) {
                $variant_images[$vid] = ['url' => $v['image'], 'title' => $v['title']];
            }
        }

        if (!$variation_ids) {
            wp_delete_post($post_id, true);
            return new WP_Error('no_variants', __('No variant could be saved, so the product was not created.', 'shopify-to-fluentcart-migrator') . ' ' . implode(' ', $warnings));
        }

        // ── Product detail ──
        $prices = array_column($product['variants'], 'price');
        $detail = [
            'post_id'             => $post_id,
            'fulfillment_type'    => $fulfillment,
            'variation_type'      => $product['is_simple'] ? 'simple' : 'simple_variations',
            'stock_availability'  => $any_in_stock ? 'in-stock' : 'out-of-stock',
            'min_price'           => $prices ? (int) min($prices) : 0,
            'max_price'           => $prices ? (int) max($prices) : 0,
            'default_media'       => null,
            'other_info'          => wp_json_encode(['group_pricing_by' => 'payment_type', 'use_pricing_table' => 'no']),
            'default_variation_id'=> $variation_ids[0],
            'manage_stock'        => $any_managed ? '1' : '0',
            'manage_downloadable' => $fulfillment === 'digital' ? '1' : '0',
            'created_at'          => $now,
            'updated_at'          => $now,
        ];
        $detail = apply_filters('s2fc_detail_row', $detail, $product, $options);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ($wpdb->insert($wpdb->prefix . 'fct_product_details', $detail) === false) {
            wp_delete_post($post_id, true);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete($table, ['post_id' => $post_id]);
            return new WP_Error('detail_failed', __('FluentCart product details could not be saved: ', 'shopify-to-fluentcart-migrator') . $wpdb->last_error);
        }

        // ── Taxonomies ──
        $cats = self::categories_for($product, $options['category_source']);
        if ($cats) {
            self::assign_terms($post_id, Helpers::CATEGORY_TAX, $cats, $warnings);
        }
        if ($options['vendor'] && $product['vendor'] !== '') {
            self::assign_terms($post_id, Helpers::BRAND_TAX, [$product['vendor']], $warnings);
        }
        if ($options['tags'] && $product['tags']) {
            if (taxonomy_exists(Helpers::TAG_TAX)) {
                self::assign_terms($post_id, Helpers::TAG_TAX, $product['tags'], $warnings);
            } else {
                update_post_meta($post_id, '_s2fc_shopify_tags', $product['tags']);
            }
        }

        // ── Images ──
        $image_count = 0;
        if ($options['images'] && $product['images']) {
            $gallery = [];
            foreach ($product['images'] as $img) {
                $att_id = self::sideload($img['src'], $post_id, $img['alt'] ?: $product['title']);
                if (is_wp_error($att_id)) {
                    $warnings[] = sprintf(
                        /* translators: 1: image URL, 2: error */
                        __('Image %1$s could not be downloaded: %2$s', 'shopify-to-fluentcart-migrator'),
                        basename(strtok($img['src'], '?')),
                        $att_id->get_error_message()
                    );
                    continue;
                }
                $gallery[] = [
                    'id'    => $att_id,
                    'url'   => wp_get_attachment_url($att_id),
                    'title' => get_the_title($att_id) ?: $product['title'],
                ];
                $image_count++;
            }
            if ($gallery) {
                set_post_thumbnail($post_id, $gallery[0]['id']);
                update_post_meta($post_id, Helpers::GALLERY_META, $gallery);
            }
        }

        // Variant images: the variation's media_id plus the product_thumbnail
        // meta row FluentCart's admin and cart read (same as its Woo migrator).
        $variant_image_count = 0;
        foreach ($variant_images as $vid => $img) {
            $att_id = self::sideload($img['url'], $post_id, $img['title']);
            if (is_wp_error($att_id)) {
                $warnings[] = sprintf(
                    /* translators: 1: variant title, 2: error */
                    __('Variant image for "%1$s" could not be downloaded: %2$s', 'shopify-to-fluentcart-migrator'),
                    $img['title'],
                    $att_id->get_error_message()
                );
                continue;
            }
            self::set_variation_image($vid, $att_id, $img['title']);
            $variant_image_count++;
        }

        // ── SEO (only when a known SEO plugin is present) ──
        if ($product['seo_title'] !== '' || $product['excerpt'] !== '') {
            if (defined('RANK_MATH_VERSION')) {
                if ($product['seo_title'] !== '') {
                    update_post_meta($post_id, 'rank_math_title', $product['seo_title']);
                }
                if ($product['excerpt'] !== '') {
                    update_post_meta($post_id, 'rank_math_description', $product['excerpt']);
                }
            } elseif (defined('WPSEO_VERSION')) {
                if ($product['seo_title'] !== '') {
                    update_post_meta($post_id, '_yoast_wpseo_title', $product['seo_title']);
                }
                if ($product['excerpt'] !== '') {
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', $product['excerpt']);
                }
            }
        }

        // ── GTIN ──
        $gtin = Gtin::save($post_id, $gtin_rows, $product['vendor']);
        foreach ($gtin['errors'] as $e) {
            $warnings[] = __('GTIN', 'shopify-to-fluentcart-migrator') . ' ' . $e;
        }

        if ($product['gift_card']) {
            $warnings[] = __('This is a Shopify gift card. It was imported as a normal product; FluentCart needs a gift card add-on to sell it as one.', 'shopify-to-fluentcart-migrator');
        }

        // ── Source record, for later tools ──
        update_post_meta($post_id, Helpers::SOURCE_META, [
            'handle'      => $product['handle'],
            'vendor'      => $product['vendor'],
            'type'        => $product['type'],
            'category'    => $product['category'],
            'tags'        => $product['tags'],
            'status'      => $product['status'],
            'options'     => $product['options'],
            'variants'    => array_map(function ($v, $vid) {
                return ['variation_id' => $vid, 'sku' => $v['sku'], 'barcode' => $v['barcode'], 'options' => $v['options']];
            }, array_slice($product['variants'], 0, count($variation_ids)), $variation_ids),
            'images'      => array_column($product['images'], 'src'),
            'metafields'  => $product['metafields'] ?? [],
            'imported_at' => time(),
            'migrator'    => S2FC_VERSION,
        ]);
        // Written last: a product carries the handle only once everything
        // else is in place, so a request that died halfway is redone, not skipped.
        update_post_meta($post_id, Helpers::HANDLE_META, $product['handle']);

        clean_post_cache($post_id);
        do_action('s2fc_product_imported', $post_id, $product, $variation_ids, $options);

        return [
            'post_id'  => $post_id,
            'status'   => 'imported',
            'warnings' => $warnings,
            'gtin'     => $gtin,
            'images'   => $image_count,
            'variant_images' => $variant_image_count,
            'variants' => count($variation_ids),
        ];
    }

    /** Attach an image to one variation the way FluentCart stores it. */
    public static function set_variation_image(int $variation_id, int $attachment_id, string $title): void
    {
        global $wpdb;
        $now   = current_time('mysql', true);
        $media = [[
            'id'    => $attachment_id,
            'title' => get_the_title($attachment_id) ?: $title,
            'url'   => wp_get_attachment_url($attachment_id),
        ]];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update($wpdb->prefix . 'fct_product_variations', ['media_id' => $attachment_id, 'updated_at' => $now], ['id' => $variation_id]);

        $table = $wpdb->prefix . 'fct_product_meta';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is $wpdb->prefix plus a constant.
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE object_id = %d AND object_type = 'product_variant_info' AND meta_key = 'product_thumbnail' LIMIT 1",
            $variation_id
        ));
        // phpcs:enable
        $row = ['meta_value' => wp_json_encode($media), 'updated_at' => $now];
        if ($existing) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, $row, ['id' => (int) $existing]);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, $row + [
                'object_id'   => $variation_id,
                'object_type' => 'product_variant_info',
                'meta_key'    => 'product_thumbnail',
                'created_at'  => $now,
            ]);
        }
    }

    private static function post_status(string $shopify_status, string $mode): string
    {
        if ($mode === 'publish') {
            return 'publish';
        }
        if ($mode === 'draft') {
            return 'draft';
        }
        switch ($shopify_status) {
            case 'active':
                return 'publish';
            case 'archived':
                return 'private';
            default:
                return 'draft';
        }
    }

    private static function assign_terms(int $post_id, string $taxonomy, array $names, array &$warnings): void
    {
        if (!taxonomy_exists($taxonomy)) {
            return;
        }
        $ids = [];
        foreach (array_unique(array_filter($names)) as $name) {
            $term = term_exists($name, $taxonomy);
            if (!$term) {
                $term = wp_insert_term($name, $taxonomy);
            }
            if (is_wp_error($term)) {
                $warnings[] = sprintf('%s: %s', $name, $term->get_error_message());
                continue;
            }
            $ids[] = (int) (is_array($term) ? $term['term_id'] : $term);
        }
        if ($ids) {
            wp_set_object_terms($post_id, $ids, $taxonomy, false);
        }
    }

    /**
     * Download a Shopify CDN image into the Media Library.
     *
     * Shopify's CDN can hold HEIC, TIFF or extension-less files that
     * WordPress will not accept; those are requested as JPEG through the
     * CDN's own format parameter, so every image comes across.
     *
     * @return int|WP_Error attachment ID
     */
    private static function sideload(string $url, int $post_id, string $alt)
    {
        if (isset(self::$image_cache[$url])) {
            return self::$image_cache[$url];
        }
        // Reuse an attachment imported earlier in this session from the same URL.
        $cached = get_transient('s2fc_img_' . md5($url));
        if ($cached && get_post($cached)) {
            return self::$image_cache[$url] = (int) $cached;
        }

        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $name = sanitize_file_name(wp_basename($path)) ?: 'image';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $fetch = $url;
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
            $fetch = add_query_arg('format', 'jpg', $url);
            $name  = ($ext ? substr($name, 0, -strlen($ext) - 1) : $name) . '.jpg';
        }

        $tmp = download_url($fetch, 30);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $tmp], $post_id, $alt);
        if (is_wp_error($id)) {
            @unlink($tmp);
            return $id;
        }
        $id = (int) $id;
        // Belt and braces: make sure the file really landed in the uploads folder.
        $stored = get_attached_file($id);
        if (!$stored || !file_exists($stored)) {
            wp_delete_attachment($id, true);
            return new WP_Error('not_saved', __('WordPress accepted the image but the file is missing from the uploads folder. Check that wp-content/uploads is writable.', 'shopify-to-fluentcart-migrator'));
        }
        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
        }
        set_transient('s2fc_img_' . md5($url), $id, DAY_IN_SECONDS);
        return self::$image_cache[$url] = $id;
    }
}
