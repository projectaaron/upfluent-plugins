<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Per-product field values.
 *
 * Storage: WordPress post meta on the product post, meta key = field_id,
 * single value. Every field is registered with register_post_meta() (see
 * PostMetaRegistrar) so the values are typed, REST-exposed, and readable by
 * Elementor dynamic tags and other post-meta consumers.
 *
 * Value shapes:
 *   text/textarea/number/select/url/email/date/color  string
 *   checkbox                                           "1" | "0"
 *
 * Media and repeater sanitise through the upfluent_cmf_sanitize_value filter and add to
 * the editor payload through upfluent_cmf_product_values.
 *
 * Early pre-release builds stored values in FluentCart's fct_product_meta table under
 * _fct_cm_{field_id}; PostMetaRegistrar migrates those once.
 */
class ProductValuesService
{
    const PRODUCT_CPT = 'fluent-products';

    /** Legacy (early pre-release) storage. */
    const LEGACY_PREFIX = '_fct_cm_';
    const LEGACY_MODEL  = '\FluentCart\App\Models\ProductMeta';

    /**
     * @return int|WP_Error  validated product ID
     */
    public static function validateProduct($product_id)
    {
        $product_id = absint($product_id);
        if (!$product_id || get_post_type($product_id) !== self::PRODUCT_CPT) {
            return new WP_Error('product_not_found', __('Product not found.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }
        return $product_id;
    }

    /**
     * @return array|WP_Error  ['fields' => definitions, 'values' => [field_id => value]] plus any extras from ProDefinitions
     */
    public static function get($product_id)
    {
        $product_id = self::validateProduct($product_id);
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }

        $definitions = FieldsService::productFields();
        $values      = [];

        foreach ($definitions as $def) {
            $values[$def['field_id']] = self::read($product_id, $def);
        }

        $result = apply_filters('upfluent_cmf_product_values', ['fields' => $definitions, 'values' => $values], $product_id);
        return is_array($result) ? $result : ['fields' => $definitions, 'values' => $values];
    }

    /** Read one field's value, normalised to its type. */
    public static function read(int $product_id, array $def)
    {
        return self::sanitizeValue(get_post_meta($product_id, $def['field_id'], true), $def);
    }

    /**
     * Saves the given values. Unknown field ids are ignored; each value is
     * sanitised per its field type.
     *
     * @return array|WP_Error  [field_id => stored value]
     */
    public static function set($product_id, $values)
    {
        $product_id = self::validateProduct($product_id);
        if ($product_id instanceof WP_Error) {
            return $product_id;
        }
        if (is_object($values)) {
            $values = (array) $values;
        }
        if (!is_array($values)) {
            return new WP_Error('invalid_values', __('Invalid values.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        $defs = [];
        foreach (FieldsService::productFields() as $def) {
            $defs[$def['field_id']] = $def;
        }

        $saved   = [];
        $ignored = [];
        foreach ($values as $field_id => $value) {
            if (!is_string($field_id)) {
                continue;
            }
            $field_id = sanitize_key($field_id);
            if (!isset($defs[$field_id])) {
                $ignored[] = $field_id;
                continue;
            }
            $clean = self::sanitizeValue($value, $defs[$field_id]);
            self::write($product_id, $defs[$field_id], $clean);
            $saved[$field_id] = $clean;
        }

        if (!$saved && $ignored) {
            return new WP_Error('unknown_fields', __('None of the given field ids are defined.', 'custom-meta-fields-for-fluentcart'), ['status' => 400, 'unknown' => $ignored]);
        }

        return $saved;
    }

    /** Persist one value; empty values delete the row to keep post meta clean. */
    public static function write(int $product_id, array $def, $value): void
    {
        $empty = ($value === '' || $value === 0 || $value === [] || $value === null);
        if ($empty) {
            delete_post_meta($product_id, $def['field_id']);
            return;
        }
        update_post_meta($product_id, $def['field_id'], $value);
    }

    /** Remove a meta key from FluentCart products only, never from other post types. */
    public static function deleteProductMeta(string $key): void
    {
        global $wpdb;
        if ($key === '') {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s",
            $key,
            'fluent-products'
        ));
        foreach ((array) $ids as $id) {
            delete_post_meta((int) $id, $key);
        }
    }

    /** Delete one field's value from every product. */
    public static function deleteValuesForField(array $def): void
    {
        self::deleteProductMeta((string) $def['field_id']);

        // Legacy rows, if any survive.
        if (class_exists(self::LEGACY_MODEL)) {
            call_user_func([self::LEGACY_MODEL, 'query'])
                ->where('meta_key', self::LEGACY_PREFIX . $def['field_id'])
                ->delete();
        }
    }

    // ─── Sanitisation ───

    /**
     * Sanitise a value for a definition (top-level or sub-field). Returns the
     * canonical shape for that type.
     */
    public static function sanitizeValue($value, array $def)
    {
        $type = $def['field_type'] ?? 'text';

        // Types this plugin does not handle itself (media and repeater, in ProDefinitions).
        if (!in_array($type, FieldsService::TYPES, true)) {
            $ext = apply_filters('upfluent_cmf_sanitize_value', null, $value, $def);
            if ($ext !== null) {
                return $ext;
            }
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }
        if (!is_scalar($value)) {
            return '';
        }
        $value = trim((string) $value);

        switch ($type) {
            case 'number':
                if ($value === '' || !is_numeric($value) || !is_finite((float) $value)) {
                    return '';
                }
                return $value;

            case 'checkbox':
                return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true) ? '1' : '0';

            case 'url':
                return esc_url_raw($value);

            case 'email':
                return sanitize_email($value);

            case 'textarea':
                return sanitize_textarea_field($value);

            case 'select': {
                $value   = sanitize_text_field($value);
                $options = FieldsService::optionsList((string) ($def['options'] ?? ''));
                return ($value === '' || in_array($value, $options, true)) ? $value : '';
            }

            case 'date':
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return $value;
                }
                // A date picker may post a full timestamp; keep the calendar day in the site's zone.
                if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) {
                    try {
                        return (new \DateTimeImmutable($value))->setTimezone(wp_timezone())->format('Y-m-d');
                    } catch (\Exception $e) {
                        return '';
                    }
                }
                return '';

            case 'color':
                return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : '';

            default:
                return sanitize_text_field($value);
        }
    }

    // ─── Legacy migration (early pre-release values in fct_product_meta) ───

    /**
     * Copy every _fct_cm_{field_id} row from FluentCart's product meta table
     * into WP post meta, then remove the old rows. Idempotent.
     *
     * @return int rows migrated
     */
    public static function migrateLegacyValues(): int
    {
        if (!class_exists(self::LEGACY_MODEL)) {
            return 0;
        }

        $defs = [];
        foreach (FieldsService::productFields() as $def) {
            $defs[self::LEGACY_PREFIX . $def['field_id']] = $def;
        }
        if (!$defs) {
            return 0;
        }

        $rows = call_user_func([self::LEGACY_MODEL, 'query'])
            ->whereIn('meta_key', array_keys($defs))
            ->orderBy('id', 'asc')
            ->get();

        $migrated = 0;
        $done     = [];
        foreach ($rows as $row) {
            $key = (string) $row->meta_key;
            $pid = (int) $row->object_id;
            $def = $defs[$key] ?? null;
            if (!$def || get_post_type($pid) !== self::PRODUCT_CPT) {
                continue;
            }
            // First row wins for duplicates; never overwrite a value already in post meta.
            $seen = $pid . '|' . $key;
            if (isset($done[$seen]) || metadata_exists('post', $pid, $def['field_id'])) {
                continue;
            }
            $raw = $row->getRawOriginal('meta_value');
            $val = self::sanitizeValue(is_string($raw) ? $raw : (string) $row->meta_value, $def);
            self::write($pid, $def, $val);
            $done[$seen] = true;
            $migrated++;
        }

        call_user_func([self::LEGACY_MODEL, 'query'])
            ->whereIn('meta_key', array_keys($defs))
            ->delete();

        return $migrated;
    }
}
