<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Product identifiers per FluentCart variation: GTIN (EAN, UPC, ISBN), MPN,
 * and a brand for the product. FluentCart has a SKU per variation but nothing
 * for these, and its WooCommerce migrator drops them.
 *
 * Storage: one post meta map on the product (META_KEY), keyed by variation ID,
 * so FluentCart's own variation rows are never written to. A second, flat
 * meta (LOOKUP_KEY, one row per GTIN) makes the duplicate check a single
 * indexed query. Both go with the product when it is deleted; a duplicated
 * product carries IDs of the original's variations, which match nothing and
 * are ignored.
 *
 * Output: ProductSchema adds gtin / mpn / brand to the structured data, and
 * [upfluent_cmf_gtin] prints the code of the variation the shopper has selected.
 */
class ProductIdentifiers
{
    const META_KEY   = '_fct_cmf_identifiers';
    const LOOKUP_KEY = '_fct_cmf_gtin';
    const SCRIPT     = 'fct-custom-meta-gtin';

    public function register(): void
    {
        add_shortcode('upfluent_cmf_gtin', [$this, 'shortcode']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('fct-custom-meta/v1', '/product/(?P<product_id>\d+)/identifiers', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'restGet'],
                'permission_callback' => [Permissions::class, 'canViewProduct'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'restSave'],
                'permission_callback' => [Permissions::class, 'canEditProduct'],
            ],
        ]);
        // One variation at a time, for the inputs shown next to FluentCart's SKU fields.
        register_rest_route('fct-custom-meta/v1', '/product/(?P<product_id>\d+)/identifiers/variation/(?P<variation_id>\d+)', [
            'methods'             => 'POST',
            'callback'            => [$this, 'restSaveVariation'],
            'permission_callback' => [Permissions::class, 'canEditProduct'],
        ]);
    }

    // ─── GTIN rules ───

    /**
     * Clean and check a GTIN. Spaces and hyphens are dropped; a 10-character
     * ISBN is converted to its GTIN-13 (978 prefix).
     *
     * @return string|WP_Error  the digits, '' for empty input
     */
    public static function normalizeGtin(string $raw)
    {
        $code = strtoupper((string) preg_replace('/[\s\-]/', '', $raw));
        if ($code === '') {
            return '';
        }

        if (strlen($code) === 10 && preg_match('/^\d{9}[\dX]$/', $code)) {
            if (!self::isbn10Valid($code)) {
                return new WP_Error('invalid_gtin', __('This ISBN-10 has an invalid check digit.', 'custom-meta-fields-for-fluentcart'));
            }
            $core = '978' . substr($code, 0, 9);
            return $core . self::checkDigit($core);
        }

        if (!preg_match('/^\d+$/', $code) || !in_array(strlen($code), [8, 12, 13, 14], true)) {
            return new WP_Error('invalid_gtin', __('A GTIN has 8, 12, 13 or 14 digits (EAN, UPC, ISBN-13 or GTIN-14).', 'custom-meta-fields-for-fluentcart'));
        }
        if (self::checkDigit(substr($code, 0, -1)) !== substr($code, -1)) {
            return new WP_Error('invalid_gtin', __('This GTIN has an invalid check digit. Please check for a typo.', 'custom-meta-fields-for-fluentcart'));
        }
        return $code;
    }

    /** GS1 mod-10 check digit for the digits before it. */
    private static function checkDigit(string $digits): string
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            // Weights alternate 3, 1 starting from the digit next to the check digit.
            $sum += (int) $digits[$len - 1 - $i] * ($i % 2 === 0 ? 3 : 1);
        }
        return (string) ((10 - $sum % 10) % 10);
    }

    private static function isbn10Valid(string $code): bool
    {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $v = $code[$i] === 'X' ? 10 : (int) $code[$i];
            $sum += $v * (10 - $i);
        }
        return $sum % 11 === 0;
    }

    /** schema.org property for a GTIN of this length: gtin8, gtin12, gtin13 or gtin14. */
    public static function schemaProperty(string $gtin): string
    {
        return 'gtin' . strlen($gtin);
    }

    // ─── Data ───

    /** FluentCart variations of a product, in display order. */
    public static function variations(int $product_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT id, variation_title, sku FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d ORDER BY serial_index ASC, id ASC",
            $product_id
        ));
        return is_array($rows) ? $rows : [];
    }

    /** @return array ['brand' => string, 'variations' => [variation_id => ['gtin' => , 'mpn' => ]]] */
    public static function stored(int $product_id): array
    {
        $data = get_post_meta($product_id, self::META_KEY, true);
        $data = is_array($data) ? $data : [];
        return [
            'brand'      => isset($data['brand']) && is_string($data['brand']) ? $data['brand'] : '',
            'variations' => isset($data['variations']) && is_array($data['variations']) ? $data['variations'] : [],
        ];
    }

    /** Identifiers of one variation: ['gtin' => '', 'mpn' => '']. */
    public static function forVariation(int $product_id, int $variation_id): array
    {
        $row = self::stored($product_id)['variations'][$variation_id] ?? [];
        return [
            'gtin' => is_array($row) ? (string) ($row['gtin'] ?? '') : '',
            'mpn'  => is_array($row) ? (string) ($row['mpn'] ?? '') : '',
        ];
    }

    /** @return array|WP_Error  ['brand' => , 'rows' => [...]] */
    public static function get($product_id)
    {
        $product_id = ProductValuesService::validateProduct($product_id);
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        $stored = self::stored($product_id);
        $rows   = [];
        foreach (self::variations($product_id) as $v) {
            $ids    = self::forVariation($product_id, (int) $v->id);
            $rows[] = [
                'variation_id' => (int) $v->id,
                'title'        => (string) $v->variation_title,
                'sku'          => (string) $v->sku,
                'gtin'         => $ids['gtin'],
                'mpn'          => $ids['mpn'],
            ];
        }
        return ['brand' => $stored['brand'], 'rows' => $rows];
    }

    /**
     * Validate and save. Rows for variations the product does not have are
     * ignored. Any invalid row stops the save and is reported by variation ID.
     *
     * @param array $input ['brand' => , 'rows' => [['variation_id' => , 'gtin' => , 'mpn' => ], ...]]
     * @return array|WP_Error
     */
    public static function set($product_id, $input)
    {
        $product_id = ProductValuesService::validateProduct($product_id);
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        $input = is_array($input) ? $input : [];

        $known = [];
        foreach (self::variations($product_id) as $v) {
            $known[(int) $v->id] = $v;
        }

        $brand = substr(sanitize_text_field(is_scalar($input['brand'] ?? null) ? (string) $input['brand'] : ''), 0, 70);

        // Brand only: the variation codes are edited next to FluentCart's SKU fields.
        if (!array_key_exists('rows', $input) || $input['rows'] === null) {
            self::write($product_id, $brand, self::stored($product_id)['variations']);
            return self::get($product_id);
        }

        $clean  = [];
        $errors = [];
        $seen   = [];
        foreach ((array) $input['rows'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $vid = (int) ($row['variation_id'] ?? 0);
            if (!isset($known[$vid])) {
                continue;
            }
            $gtin = self::normalizeGtin(is_scalar($row['gtin'] ?? null) ? (string) $row['gtin'] : '');
            if ($gtin instanceof WP_Error) {
                $errors[$vid] = $gtin->get_error_message();
                continue;
            }
            if ($gtin !== '' && isset($seen[$gtin])) {
                $errors[$vid] = __('The same GTIN is used on two variations of this product.', 'custom-meta-fields-for-fluentcart');
                continue;
            }
            if ($gtin !== '') {
                $other = self::productUsingGtin($gtin, $product_id);
                if ($other) {
                    $errors[$vid] = sprintf(
                        /* translators: %s: product title */
                        __('This GTIN is already used on “%s”.', 'custom-meta-fields-for-fluentcart'),
                        get_the_title($other)
                    );
                    continue;
                }
                $seen[$gtin] = true;
            }
            $mpn = sanitize_text_field(is_scalar($row['mpn'] ?? null) ? (string) $row['mpn'] : '');
            $mpn = substr($mpn, 0, 70);
            if ($gtin !== '' || $mpn !== '') {
                $clean[$vid] = ['gtin' => $gtin, 'mpn' => $mpn];
            }
        }

        if ($errors) {
            return new WP_Error('invalid_identifiers', __('Some identifiers need fixing.', 'custom-meta-fields-for-fluentcart'), ['status' => 400, 'errors' => $errors]);
        }

        self::write($product_id, $brand, $clean);

        return self::get($product_id);
    }

    /**
     * Validate and save the codes of one variation, leaving the others as they are.
     *
     * @return array|WP_Error  ['variation_id' => , 'gtin' => , 'mpn' => ]
     */
    public static function setVariation($product_id, $variation_id, $gtin_raw, $mpn_raw)
    {
        $product_id = ProductValuesService::validateProduct($product_id);
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        $variation_id = (int) $variation_id;
        $owned = false;
        foreach (self::variations($product_id) as $v) {
            if ((int) $v->id === $variation_id) {
                $owned = true;
                break;
            }
        }
        if (!$owned) {
            return new WP_Error('variation_not_found', __('Variation not found on this product.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }

        $gtin = self::normalizeGtin(is_scalar($gtin_raw) ? (string) $gtin_raw : '');
        if ($gtin instanceof WP_Error) {
            $gtin->add_data(['status' => 400]);
            return $gtin;
        }
        $stored = self::stored($product_id);
        if ($gtin !== '') {
            foreach ($stored['variations'] as $vid => $ids) {
                if ((int) $vid !== $variation_id && is_array($ids) && ($ids['gtin'] ?? '') === $gtin) {
                    return new WP_Error('duplicate_gtin', __('The same GTIN is used on another variation of this product.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
            }
            $other = self::productUsingGtin($gtin, $product_id);
            if ($other) {
                return new WP_Error('duplicate_gtin', sprintf(
                    /* translators: %s: product title */
                    __('This GTIN is already used on “%s”.', 'custom-meta-fields-for-fluentcart'),
                    get_the_title($other)
                ), ['status' => 400]);
            }
        }
        $mpn = substr(sanitize_text_field(is_scalar($mpn_raw) ? (string) $mpn_raw : ''), 0, 70);

        $variations = $stored['variations'];
        if ($gtin === '' && $mpn === '') {
            unset($variations[$variation_id]);
        } else {
            $variations[$variation_id] = ['gtin' => $gtin, 'mpn' => $mpn];
        }
        self::write($product_id, $stored['brand'], $variations);

        return ['variation_id' => $variation_id, 'gtin' => $gtin, 'mpn' => $mpn];
    }

    /** Store the map and rebuild the GTIN lookup rows. */
    public static function write(int $product_id, string $brand, array $variations): void
    {
        if ($brand === '' && !$variations) {
            delete_post_meta($product_id, self::META_KEY);
        } else {
            update_post_meta($product_id, self::META_KEY, ['brand' => $brand, 'variations' => $variations]);
        }
        delete_post_meta($product_id, self::LOOKUP_KEY);
        foreach ($variations as $row) {
            if (!empty($row['gtin'])) {
                add_post_meta($product_id, self::LOOKUP_KEY, $row['gtin']);
            }
        }
    }

    /**
     * Another FluentCart product already carrying this GTIN, or 0. A lookup row
     * only counts if that product still has a variation with the code, so a
     * duplicated product (which copies meta but not variation IDs) never blocks.
     */
    public static function productUsingGtin(string $gtin, int $except = 0): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id <> %d AND p.post_type = %s AND p.post_status <> 'trash'",
            self::LOOKUP_KEY,
            $gtin,
            $except,
            ProductValuesService::PRODUCT_CPT
        ));
        foreach ((array) $ids as $id) {
            foreach (self::variations((int) $id) as $v) {
                if (self::forVariation((int) $id, (int) $v->id)['gtin'] === $gtin) {
                    return (int) $id;
                }
            }
        }
        return 0;
    }

    // ─── REST ───

    public function restGet(WP_REST_Request $request): WP_REST_Response
    {
        $result = self::get($request->get_url_params()['product_id'] ?? 0);
        return $result instanceof WP_Error ? self::error($result) : new WP_REST_Response($result);
    }

    public function restSave(WP_REST_Request $request): WP_REST_Response
    {
        $input = ['brand' => $request->get_param('brand')];
        if ($request->has_param('rows')) {
            $input['rows'] = $request->get_param('rows');
        }
        $result = self::set($request->get_url_params()['product_id'] ?? 0, $input);
        if ($result instanceof WP_Error) {
            return self::error($result);
        }
        return new WP_REST_Response(array_merge(['message' => __('Identifiers saved.', 'custom-meta-fields-for-fluentcart')], $result));
    }

    public function restSaveVariation(WP_REST_Request $request): WP_REST_Response
    {
        $params = $request->get_url_params();
        $result = self::setVariation($params['product_id'] ?? 0, $params['variation_id'] ?? 0, $request->get_param('gtin'), $request->get_param('mpn'));
        if ($result instanceof WP_Error) {
            return self::error($result);
        }
        return new WP_REST_Response(array_merge(['message' => __('Saved.', 'custom-meta-fields-for-fluentcart')], $result));
    }

    private static function error(WP_Error $error): WP_REST_Response
    {
        $data   = (array) $error->get_error_data();
        $status = isset($data['status']) ? (int) $data['status'] : 400;
        unset($data['status']);
        return new WP_REST_Response(array_merge(['code' => $error->get_error_code(), 'message' => $error->get_error_message()], $data), $status);
    }

    // ─── Shortcode ───

    /**
     * [upfluent_cmf_gtin] prints the GTIN of the selected variation and follows the
     * shopper's choice. type="mpn" or type="brand" prints those instead;
     * post_id="" (or product_id="") targets another product.
     */
    public function shortcode($atts): string
    {
        // post_id matches [upfluent_cmf_field] and [upfluent_cmf_repeater]; product_id is accepted too.
        $atts = shortcode_atts(['type' => 'gtin', 'post_id' => '', 'product_id' => '', 'label' => ''], $atts, 'upfluent_cmf_gtin');
        $type = in_array($atts['type'], ['gtin', 'mpn', 'brand'], true) ? $atts['type'] : 'gtin';

        $pid = absint($atts['post_id']) ?: absint($atts['product_id']) ?: (int) get_the_ID();
        if (!$pid || get_post_type($pid) !== ProductValuesService::PRODUCT_CPT) {
            return '';
        }
        if ($pid !== (int) get_the_ID() && (get_post_status($pid) !== 'publish' || post_password_required($pid))
            && !current_user_can('read_post', $pid)) {
            return '';
        }

        $label = $atts['label'] !== '' ? '<span class="cmf-gtin-label">' . esc_html($atts['label']) . '</span> ' : '';

        if ($type === 'brand') {
            $brand = self::stored($pid)['brand'];
            return $brand !== '' ? '<span class="cmf-gtin cmf-gtin--brand">' . $label . esc_html($brand) . '</span>' : '';
        }

        $items = '';
        $first = true;
        $shown = '';
        $any   = false;
        foreach (self::variations($pid) as $v) {
            $value = self::forVariation($pid, (int) $v->id)[$type];
            $items .= sprintf(
                '<span class="cmf-gtin__value" data-variation-id="%d"%s>%s</span>',
                (int) $v->id,
                $first ? '' : ' hidden',
                esc_html($value)
            );
            if ($first) {
                $shown = $value;
            }
            $any   = $any || $value !== '';
            $first = false;
        }
        if (!$any) {
            return '';
        }

        // The wrapper hides while the selected variation has no code, so the label never shows alone.
        self::enqueueSwitcher();
        return '<span class="cmf-gtin cmf-gtin--' . esc_attr($type) . '" data-cmf-gtin="' . (int) $pid . '"' . ($shown === '' ? ' hidden' : '') . '>' . $label . $items . '</span>';
    }

    /** Swaps the shown value when FluentCart reports a new variation. */
    private static function enqueueSwitcher(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        wp_register_script(self::SCRIPT, '', [], UPFLUENT_CMF_VERSION, true);
        wp_enqueue_script(self::SCRIPT);
        wp_add_inline_script(self::SCRIPT, "window.addEventListener('fluentCartSingleProductVariationChanged',function(e){var d=e&&e.detail||{};document.querySelectorAll('[data-cmf-gtin=\"'+d.productId+'\"]').forEach(function(w){var hit=w.querySelector('[data-variation-id=\"'+d.variationId+'\"]');if(!hit)return;w.querySelectorAll('[data-variation-id]').forEach(function(s){s.hidden=s!==hit;});w.hidden=hit.textContent==='';});});");
    }
}
