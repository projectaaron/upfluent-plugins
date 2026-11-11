<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Field definition storage and validation. Shared by the REST controller and
 * the MCP abilities so both paths apply identical rules.
 *
 * Definitions live in wp_options (autoload off) as a list of:
 *   context ('product'), field_id, field_type, field_title, options, placeholder
 *
 * ProDefinitions extends the same store through filters: it adds contexts
 * (checkout), field types (media, repeater) and the extra keys those carry.
 * Definitions of an unknown type are kept in storage untouched and simply
 * not listed.
 *
 *   upfluent_cmf_contexts                   contexts a definition may live in
 *   upfluent_cmf_field_types                types allowed for a context
 *   upfluent_cmf_sanitize_definition_input  clean input array (or WP_Error) for extra keys
 *   upfluent_cmf_normalize_definition       canonical shape for extra keys
 *   upfluent_cmf_validate_definition        true|WP_Error cross-field rules
 *   upfluent_cmf_definition_keys            keys an update may replace
 */
class FieldsService
{
    const OPTION_KEY = 'fct_custom_meta_field_definitions';

    /** Field types this plugin handles on its own. */
    const TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'url', 'email', 'date', 'color'];

    const ID_PATTERN = '/^[a-z0-9_-]+$/';

    /** @return string[] */
    public static function contexts(): array
    {
        $contexts = apply_filters('upfluent_cmf_contexts', ['product']);
        return is_array($contexts) ? array_values(array_unique(array_filter($contexts, 'is_string'))) : ['product'];
    }

    /** @return string[] field types allowed in a context */
    public static function types(string $context): array
    {
        $types = apply_filters('upfluent_cmf_field_types', $context === 'product' ? self::TYPES : [], $context);
        return is_array($types) ? array_values(array_unique(array_filter($types, 'is_string'))) : [];
    }

    /** A stored definition this build can list, edit and render. */
    private static function supported($f): bool
    {
        if (!is_array($f) || empty($f['field_id']) || !is_string($f['field_id'])) {
            return false;
        }
        $context = (string) ($f['context'] ?? 'product');
        return in_array($context, self::contexts(), true)
            && in_array((string) ($f['field_type'] ?? ''), self::types($context), true);
    }

    /** @return array[] normalised definitions in display order */
    public static function all(): array
    {
        $fields = get_option(self::OPTION_KEY, []);
        if (!is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $f) {
            if (!self::supported($f)) {
                continue;
            }
            $norm = self::normalize($f);
            if ($norm) {
                $out[] = $norm;
            }
        }
        return $out;
    }

    /** Definitions for one context, in display order. */
    public static function forContext(string $context): array
    {
        return array_values(array_filter(self::all(), function ($f) use ($context) {
            return $f['context'] === $context;
        }));
    }

    public static function productFields(): array
    {
        return self::forContext('product');
    }

    public static function find(string $field_id): ?array
    {
        foreach (self::all() as $f) {
            if ($f['field_id'] === $field_id) {
                return $f;
            }
        }
        return null;
    }

    /** Whether any post already carries this meta key. */
    private static function metaKeyInUse(string $key): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $key));
    }

    /**
     * @param array $input  context?, field_id, field_title, field_type, options?, placeholder?, plus any ProDefinitions keys
     * @return array|WP_Error  the created definition
     */
    public static function create(array $input)
    {
        $raw_id   = isset($input['field_id']) && is_string($input['field_id']) ? trim($input['field_id']) : '';
        $field_id = sanitize_key($raw_id);

        if ($field_id === '' || $field_id !== $raw_id || !preg_match(self::ID_PATTERN, $field_id)) {
            return new WP_Error('invalid_field_id', __('Field ID may only contain lowercase letters, numbers, underscores and hyphens.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        // Values are stored as post meta under the field ID, so the ID must not
        // be a key WordPress, FluentCart or another plugin already writes.
        if ($field_id[0] === '_' || self::metaKeyInUse($field_id)) {
            return new WP_Error('reserved_field_id', __('That field ID is already used by WordPress, FluentCart or another plugin. Please choose a different one.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        $context = $input['context'] ?? 'product';
        if (!is_string($context) || !in_array($context, self::contexts(), true)) {
            return new WP_Error('invalid_context', sprintf(
                /* translators: %s: comma separated list */
                __('Invalid context. Allowed: %s', 'custom-meta-fields-for-fluentcart'),
                implode(', ', self::contexts())
            ), ['status' => 400]);
        }

        $clean = self::sanitizeInput($input, null, $context);
        if ($clean instanceof WP_Error) {
            return $clean;
        }
        if ($clean['field_type'] === '' || $clean['field_title'] === '') {
            return new WP_Error('missing_field', __('Field type and title are required.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        $fields = self::all();
        foreach (self::stored() as $existing) {
            if (is_array($existing) && ($existing['field_id'] ?? '') === $field_id) {
                return new WP_Error('duplicate_field', __('A field with this ID already exists.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
            }
        }

        $new = self::normalize(array_merge(['field_id' => $field_id, 'context' => $context], $clean));
        $fields[] = $new;
        self::save($fields);

        return $new;
    }

    /**
     * @return array|WP_Error  the updated definition
     */
    public static function update(string $field_id, array $input)
    {
        $field_id = sanitize_key($field_id);
        $fields   = self::all();
        $index    = null;
        foreach ($fields as $i => $f) {
            if ($f['field_id'] === $field_id) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return new WP_Error('field_not_found', __('Field not found.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }

        // The context is fixed: stored values live in different places.
        $clean = self::sanitizeInput($input, $fields[$index], $fields[$index]['context']);
        if ($clean instanceof WP_Error) {
            return $clean;
        }

        $def = $fields[$index];
        if ($clean['field_type'] !== '') {
            $def['field_type'] = $clean['field_type'];
        }
        if ($clean['field_title'] !== '') {
            $def['field_title'] = $clean['field_title'];
        }
        foreach ((array) apply_filters('upfluent_cmf_definition_keys', ['options', 'placeholder']) as $key) {
            if (array_key_exists($key, $input) && array_key_exists($key, $clean)) {
                $def[$key] = $clean[$key];
            }
        }

        $check = self::validateShape($def);
        if ($check instanceof WP_Error) {
            return $check;
        }

        $fields[$index] = self::normalize($def);
        self::save($fields);

        return $fields[$index];
    }

    /**
     * Deletes the definition. Product fields also lose their stored values.
     * @return true|WP_Error
     */
    public static function delete(string $field_id)
    {
        $field_id = sanitize_key($field_id);
        $def      = self::find($field_id);
        if (!$def) {
            return new WP_Error('field_not_found', __('Field not found.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }

        $fields = array_values(array_filter(self::all(), function ($f) use ($field_id) {
            return $f['field_id'] !== $field_id;
        }));
        self::save($fields);

        if ($def['context'] === 'product') {
            ProductValuesService::deleteValuesForField($def);
        }

        return true;
    }

    /**
     * Reorders by the given ids; any field not mentioned keeps its relative
     * order at the end. Unknown ids are ignored.
     * @return array[]|WP_Error
     */
    public static function reorder($order)
    {
        if (!is_array($order)) {
            return new WP_Error('invalid_order', __('Invalid order.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        $indexed = [];
        foreach (self::all() as $f) {
            $indexed[$f['field_id']] = $f;
        }

        $reordered = [];
        foreach ($order as $id) {
            if (!is_string($id)) {
                continue;
            }
            $id = sanitize_key($id);
            if (isset($indexed[$id])) {
                $reordered[] = $indexed[$id];
                unset($indexed[$id]);
            }
        }
        foreach ($indexed as $f) {
            $reordered[] = $f;
        }

        self::save($reordered);

        return $reordered;
    }

    /** "yes", "1", true… → true. */
    public static function truthy($value): bool
    {
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    /** Comma-separated option string → clean list. */
    public static function optionsList(string $options): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $options)), 'strlen'));
    }

    // ─── internals ───

    /** The raw stored list, including definitions this build does not handle. */
    private static function stored(): array
    {
        $fields = get_option(self::OPTION_KEY, []);
        return is_array($fields) ? $fields : [];
    }

    /**
     * Writes the supported definitions and keeps every stored definition this
     * build cannot handle (kept after them, in their stored order), so nothing
     * is lost by editing fields with a plugin that lacks some types.
     */
    private static function save(array $fields): void
    {
        $ids = [];
        foreach ($fields as $f) {
            $ids[$f['field_id']] = true;
        }
        $kept = [];
        foreach (self::stored() as $f) {
            if (is_array($f) && !self::supported($f) && !empty($f['field_id']) && !isset($ids[$f['field_id']])) {
                $kept[] = $f;
            }
        }
        update_option(self::OPTION_KEY, array_merge(array_values($fields), $kept), false);
    }

    /**
     * Bring a stored/derived definition into canonical shape. Returns null if
     * it is unusable. Type-irrelevant keys are dropped.
     */
    private static function normalize($f): ?array
    {
        if (!is_array($f) || empty($f['field_id']) || !is_string($f['field_id'])) {
            return null;
        }

        $context = in_array($f['context'] ?? '', self::contexts(), true) ? $f['context'] : 'product';
        $type    = in_array($f['field_type'] ?? '', self::types($context), true) ? $f['field_type'] : 'text';

        $def = [
            'context'     => $context,
            'field_id'    => sanitize_key($f['field_id']),
            'field_type'  => $type,
            'field_title' => (string) ($f['field_title'] ?? ''),
            'options'     => '',
            'placeholder' => (string) ($f['placeholder'] ?? ''),
        ];

        if ($type === 'select') {
            $def['options'] = (string) ($f['options'] ?? '');
        }

        $def = apply_filters('upfluent_cmf_normalize_definition', $def, $f);

        return is_array($def) ? $def : null;
    }

    /**
     * Cross-field rules that must hold for a complete definition.
     * @return true|WP_Error
     */
    private static function validateShape(array $def)
    {
        if ($def['field_type'] === 'select' && trim((string) ($def['options'] ?? '')) === '') {
            return new WP_Error('missing_options', __('Select fields need at least one option.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        $check = apply_filters('upfluent_cmf_validate_definition', true, $def);
        return $check instanceof WP_Error ? $check : true;
    }

    /**
     * @param array      $input
     * @param array|null $existing  current definition when updating
     * @param string     $context   the definition's (fixed) context
     * @return array|WP_Error  field_type, field_title, options, placeholder, plus any ProDefinitions keys
     */
    private static function sanitizeInput(array $input, ?array $existing, string $context)
    {
        $allowedTypes = self::types($context);

        $type = $input['field_type'] ?? '';
        $type = is_string($type) ? sanitize_text_field($type) : '';
        if ($type !== '' && !in_array($type, $allowedTypes, true)) {
            return new WP_Error('invalid_field_type', sprintf(
                /* translators: %s: comma separated list */
                __('Invalid field type. Allowed: %s', 'custom-meta-fields-for-fluentcart'),
                implode(', ', $allowedTypes)
            ), ['status' => 400]);
        }
        $effectiveType = $type !== '' ? $type : ($existing['field_type'] ?? 'text');

        $options = $input['options'] ?? ($existing['options'] ?? '');
        if (is_array($options)) {
            $options = implode(',', array_filter($options, 'is_scalar'));
        }
        $options = is_string($options) ? sanitize_text_field($options) : '';
        $options = implode(', ', self::optionsList($options));

        $title       = $input['field_title'] ?? '';
        $placeholder = $input['placeholder'] ?? ($existing['placeholder'] ?? '');

        $clean = [
            'field_type'  => $type,
            'field_title' => is_string($title) ? sanitize_text_field($title) : '',
            'options'     => $options,
            'placeholder' => is_string($placeholder) ? sanitize_text_field($placeholder) : '',
        ];

        $clean = apply_filters('upfluent_cmf_sanitize_definition_input', $clean, $input, $existing, $context);
        if ($clean instanceof WP_Error) {
            return $clean;
        }
        if (!is_array($clean)) {
            return new WP_Error('invalid_definition', __('Invalid field definition.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
        }

        // On create, validate the complete shape now.
        if ($existing === null) {
            $check = self::validateShape(array_merge(
                ['field_id' => (string) ($input['field_id'] ?? 'x'), 'context' => $context],
                $clean,
                ['field_type' => $effectiveType]
            ));
            if ($check instanceof WP_Error) {
                return $check;
            }
        }

        return $clean;
    }
}
