<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Bulk-fill product identifiers (GTIN, MPN, brand) for FluentCart
 * variations, matched on SKU.
 *
 *   WooCommerce  Reads GTINs left behind by a WooCommerce store in the same
 *                database: Woo's own field (_global_unique_id, Woo 9.2+) and
 *                the meta keys of the common GTIN plugins. FluentCart's own
 *                WooCommerce migrator carries SKUs but not GTINs.
 *   CSV          sku,gtin,mpn,brand (header row optional, any order).
 *
 * Both run as a preview first (dry_run) and report matched, invalid,
 * duplicate and unmatched rows; applying never clears a code that is already
 * set unless the source provides a new one.
 */
class IdentifierImport
{
    /** Woo post meta holding a GTIN, in order of preference. Filter: upfluent_cmf_woo_gtin_keys */
    const WOO_GTIN_KEYS = [
        '_global_unique_id',   // WooCommerce core (9.2+)
        '_wpm_gtin_code',      // Product GTIN (EAN, UPC, ISBN) for WooCommerce
        'hwp_var_gtin',        // Product GTIN, variations
        'hwp_product_gtin',    // Product GTIN, products
        '_ts_gtin',            // Germanized
        '_alg_ean',            // EAN for WooCommerce
        '_ean',
        '_gtin',
        '_barcode',
    ];

    /** Woo post meta holding an MPN. Filter: upfluent_cmf_woo_mpn_keys */
    const WOO_MPN_KEYS = ['_wpm_mpn', '_ts_mpn', '_mpn'];

    const MAX_ROWS = 20000;

    public function registerRoutes(): void
    {
        foreach (['woocommerce' => 'importWoo', 'csv' => 'importCsv'] as $source => $method) {
            register_rest_route('fct-custom-meta/v1', '/identifiers/import/' . $source, [
                'methods'             => 'POST',
                'callback'            => [$this, $method],
                'permission_callback' => [Permissions::class, 'canEditProduct'],
            ]);
        }
    }

    // ─── Sources ───

    public function importWoo(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $gtin_keys = (array) apply_filters('upfluent_cmf_woo_gtin_keys', self::WOO_GTIN_KEYS);
        $mpn_keys  = (array) apply_filters('upfluent_cmf_woo_mpn_keys', self::WOO_MPN_KEYS);
        $keys      = array_values(array_unique(array_merge(['_sku'], $gtin_keys, $mpn_keys)));
        $in        = implode(',', array_fill(0, count($keys), '%s'));

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE p.post_type IN ('product', 'product_variation') AND pm.meta_key IN ($in) AND pm.meta_value <> ''",
            ...$keys
        ));
        // phpcs:enable
        if (!$meta) {
            return new WP_REST_Response(self::emptyReport(__('No WooCommerce products with SKUs were found in this database.', 'custom-meta-fields-for-fluentcart')));
        }

        $by_post = [];
        foreach ($meta as $m) {
            $by_post[(int) $m->post_id][$m->meta_key] = (string) $m->meta_value;
        }

        $rows = [];
        foreach ($by_post as $values) {
            $sku = trim((string) ($values['_sku'] ?? ''));
            if ($sku === '') {
                continue;
            }
            $gtin = '';
            foreach ($gtin_keys as $k) {
                if (!empty($values[$k])) {
                    $gtin = (string) $values[$k];
                    break;
                }
            }
            $mpn = '';
            foreach ($mpn_keys as $k) {
                if (!empty($values[$k])) {
                    $mpn = (string) $values[$k];
                    break;
                }
            }
            if ($gtin !== '' || $mpn !== '') {
                $rows[] = ['sku' => $sku, 'gtin' => $gtin, 'mpn' => $mpn, 'brand' => ''];
            }
        }

        return new WP_REST_Response(self::run($rows, (bool) $request->get_param('dry_run')));
    }

    public function importCsv(WP_REST_Request $request): WP_REST_Response
    {
        $csv = (string) $request->get_param('csv');
        if (trim($csv) === '') {
            return self::error(new WP_Error('empty_csv', __('The CSV is empty.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]));
        }

        $lines = preg_split('/\r\n|\r|\n/', $csv);
        $lines = array_values(array_filter((array) $lines, function ($l) {
            return trim((string) $l) !== '';
        }));
        if (count($lines) > self::MAX_ROWS) {
            return self::error(new WP_Error('too_many_rows', sprintf(
                /* translators: %d: row limit */
                __('Please import at most %d rows at a time.', 'custom-meta-fields-for-fluentcart'),
                self::MAX_ROWS
            ), ['status' => 400]));
        }

        $offset    = 0;
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $columns   = ['sku' => 0, 'gtin' => 1, 'mpn' => 2, 'brand' => 3];
        $first     = array_map(function ($c) {
            return strtolower(trim((string) $c));
        }, str_getcsv($lines[0], $delimiter));
        if (in_array('sku', $first, true)) {
            $aliases = ['gtin' => ['gtin', 'ean', 'upc', 'isbn', 'barcode'], 'mpn' => ['mpn'], 'brand' => ['brand'], 'sku' => ['sku']];
            $columns = [];
            foreach ($aliases as $key => $names) {
                foreach ($first as $i => $name) {
                    if (in_array($name, $names, true)) {
                        $columns[$key] = $i;
                        break;
                    }
                }
            }
            array_shift($lines);
            $offset = 1;
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells  = str_getcsv($line, $delimiter);
            $rows[] = [
                'sku'   => trim((string) ($cells[$columns['sku'] ?? -1] ?? '')),
                'gtin'  => trim((string) ($cells[$columns['gtin'] ?? -1] ?? '')),
                'mpn'   => trim((string) ($cells[$columns['mpn'] ?? -1] ?? '')),
                'brand' => trim((string) ($cells[$columns['brand'] ?? -1] ?? '')),
            ];
        }

        return new WP_REST_Response(self::run($rows, (bool) $request->get_param('dry_run'), $offset));
    }

    // ─── Matching and applying ───

    /**
     * @param array $rows [['sku','gtin','mpn','brand'], ...]
     */
    private static function run(array $rows, bool $dry_run, int $line_offset = 0): array
    {
        global $wpdb;

        $report = self::emptyReport('');
        $report['dry_run'] = $dry_run;
        $report['total']   = count($rows);

        $skus = array_values(array_unique(array_filter(array_column($rows, 'sku'), 'strlen')));
        $variations = [];
        foreach (array_chunk($skus, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '%s'));
            // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT v.id, v.post_id, v.sku FROM {$wpdb->prefix}fct_product_variations v
                   INNER JOIN {$wpdb->posts} p ON p.ID = v.post_id
                  WHERE v.sku IN ($in) AND p.post_status <> 'trash'",
                ...$chunk
            ));
            // phpcs:enable
            foreach ((array) $found as $v) {
                $variations[strtolower((string) $v->sku)][] = $v;
            }
        }

        $changes = [];   // product_id => ['brand' => , 'variations' => [vid => [gtin, mpn]]]
        $claimed = [];   // gtin => product_id, within this import
        $claimedVar = []; // gtin => variation_id, within this import
        foreach ($rows as $n => $row) {
            $line = $n + 1 + $line_offset;
            $key  = strtolower($row['sku']);
            if ($row['sku'] === '' || empty($variations[$key])) {
                $report['unmatched'][] = ['line' => $line, 'sku' => $row['sku']];
                continue;
            }
            if (count($variations[$key]) > 1) {
                $report['errors'][] = ['line' => $line, 'sku' => $row['sku'], 'message' => __('This SKU is used by more than one variation.', 'custom-meta-fields-for-fluentcart')];
                continue;
            }
            $v   = $variations[$key][0];
            $pid = (int) $v->post_id;

            $gtin = ProductIdentifiers::normalizeGtin($row['gtin']);
            if ($gtin instanceof WP_Error) {
                $report['errors'][] = ['line' => $line, 'sku' => $row['sku'], 'message' => $gtin->get_error_message()];
                continue;
            }
            if (!isset($changes[$pid])) {
                $changes[$pid] = ProductIdentifiers::stored($pid);
            }
            if ($gtin !== '') {
                // Already on another variation of the same product (saved or earlier in this file)?
                foreach ($changes[$pid]['variations'] as $other_vid => $ids) {
                    if ((int) $other_vid !== (int) $v->id && is_array($ids) && ($ids['gtin'] ?? '') === $gtin) {
                        $claimedVar[$gtin] = (int) $other_vid;
                        break;
                    }
                }
                $owner = $claimed[$gtin] ?? ProductIdentifiers::productUsingGtin($gtin, $pid);
                if (($owner && $owner !== $pid) || (isset($claimedVar[$gtin]) && $claimedVar[$gtin] !== (int) $v->id)) {
                    $owner = $owner ?: $pid;
                    $report['errors'][] = ['line' => $line, 'sku' => $row['sku'], 'message' => sprintf(
                        /* translators: %s: product title */
                        __('GTIN already used on “%s”.', 'custom-meta-fields-for-fluentcart'),
                        get_the_title($owner)
                    )];
                    continue;
                }
                $claimed[$gtin]    = $pid;
                $claimedVar[$gtin] = (int) $v->id;
            }

            $current = $changes[$pid]['variations'][(int) $v->id] ?? ['gtin' => '', 'mpn' => ''];
            $mpn     = substr(sanitize_text_field($row['mpn']), 0, 70);
            $changes[$pid]['variations'][(int) $v->id] = [
                'gtin' => $gtin !== '' ? $gtin : (string) ($current['gtin'] ?? ''),
                'mpn'  => $mpn !== '' ? $mpn : (string) ($current['mpn'] ?? ''),
            ];
            $brand = substr(sanitize_text_field($row['brand']), 0, 70);
            if ($brand !== '') {
                $changes[$pid]['brand'] = $brand;
            }
            $report['matched'][] = ['line' => $line, 'sku' => $row['sku'], 'product' => get_the_title($pid), 'gtin' => $gtin, 'mpn' => $mpn];
        }

        if (!$dry_run) {
            foreach ($changes as $pid => $data) {
                ProductIdentifiers::write((int) $pid, (string) $data['brand'], array_filter($data['variations'], function ($ids) {
                    return is_array($ids) && (($ids['gtin'] ?? '') !== '' || ($ids['mpn'] ?? '') !== '');
                }));
            }
        }
        $report['products'] = count($changes);

        // Keep responses small: counts are exact, lists are samples.
        foreach (['matched', 'unmatched', 'errors'] as $list) {
            $report[$list . '_count'] = count($report[$list]);
            $report[$list]            = array_slice($report[$list], 0, 50);
        }
        return $report;
    }

    private static function emptyReport(string $message): array
    {
        return [
            'message'         => $message,
            'dry_run'         => true,
            'total'           => 0,
            'products'        => 0,
            'matched'         => [],
            'unmatched'       => [],
            'errors'          => [],
            'matched_count'   => 0,
            'unmatched_count' => 0,
            'errors_count'    => 0,
        ];
    }

    private static function error(WP_Error $error): WP_REST_Response
    {
        $data   = (array) $error->get_error_data();
        $status = isset($data['status']) ? (int) $data['status'] : 400;
        unset($data['status']);
        return new WP_REST_Response(array_merge(['code' => $error->get_error_code(), 'message' => $error->get_error_message()], $data), $status);
    }
}
