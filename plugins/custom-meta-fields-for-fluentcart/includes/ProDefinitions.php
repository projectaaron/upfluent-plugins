<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Media and Repeater product types, the checkout context (placement,
 * required, conditions, file uploads) and the values, post-meta registration
 * and MCP schema those need.
 *
 * Everything plugs into FieldsService, ProductValuesService,
 * PostMetaRegistrar and Abilities through their filters. (The class name is
 * from when these features were a separate Pro plugin.)
 */
class ProDefinitions
{
    const PRODUCT_TYPES = ['media', 'repeater'];

    /** Types allowed inside a repeater row. */
    const SUB_FIELD_TYPES = [
        'text', 'textarea', 'number', 'select', 'checkbox', 'url', 'email', 'date', 'color', 'media',
    ];

    const MEDIA_TYPES = ['any', 'image', 'video', 'audio', 'file'];

    /**
     * Types a customer can fill in at checkout. `file` uploads through
     * FluentCart's storage driver (see CheckoutUploads); color and repeater
     * make no sense on an order form, and media (a library attachment) is
     * a product concept.
     */
    const CHECKOUT_TYPES = ['text', 'textarea', 'number', 'select', 'checkbox', 'url', 'email', 'date', 'file'];

    /** Default extension whitelist for a checkout file field. */
    const DEFAULT_EXTENSIONS = 'jpg, jpeg, png, gif, webp, pdf, doc, docx, txt, zip';

    const DEFAULT_MAX_SIZE_MB = 10;

    /**
     * Checkout placements → the FluentCart action that fires there. Every hook
     * is inside the checkout <form>, outside any fragment that the checkout
     * JS re-renders over AJAX, so a field placed there survives address and
     * shipping changes.
     */
    const PLACEMENTS = [
        'before_billing' => 'fluent_cart/before_billing_fields',
        'after_shipping' => 'fluent_cart/after_shipping_fields_section',
        'before_payment' => 'fluent_cart/before_payment_methods',
        'after_payment'  => 'fluent_cart/after_payment_methods',
        'after_button'   => 'fluent_cart/after_checkout_button',
        'order_summary'  => 'fluent_cart/after_order_notes',
    ];

    const DEFAULT_PLACEMENT = 'before_payment';

    const CONDITION_RELATIONS = ['all', 'any'];

    const CONDITION_OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'empty', 'not_empty',
    ];

    /**
     * FluentCart inputs a condition may depend on, by their form input name.
     * Values are what the checkout form actually submits.
     */
    const BUILTIN_CONDITION_FIELDS = [
        'billing_country'             => 'Billing country (ISO code, e.g. US)',
        'billing_state'               => 'Billing state',
        'shipping_country'            => 'Shipping country (ISO code)',
        'shipping_state'              => 'Shipping state',
        'ship_to_different'           => 'Ship to a different address ("yes" when ticked)',
        'fc_selected_shipping_method' => 'Selected shipping method ID',
        '_fct_pay_method'             => 'Selected payment method (e.g. stripe, paypal)',
        'is_business'                 => 'Buying as a business ("yes" when ticked)',
        'allow_create_account'        => 'Create an account ("yes" when ticked)',
    ];

    /** Prefix for checkout input names, keeping them clear of FluentCart's own. */
    const CHECKOUT_NAME_PREFIX = 'cmf_';

    public static function register(): void
    {
        add_filter('upfluent_cmf_contexts', [self::class, 'contexts']);
        add_filter('upfluent_cmf_field_types', [self::class, 'fieldTypes'], 10, 2);
        add_filter('upfluent_cmf_sanitize_definition_input', [self::class, 'sanitizeInput'], 10, 4);
        add_filter('upfluent_cmf_normalize_definition', [self::class, 'normalize'], 10, 2);
        add_filter('upfluent_cmf_validate_definition', [self::class, 'validateShape'], 10, 2);
        add_filter('upfluent_cmf_definition_keys', [self::class, 'definitionKeys']);
        add_filter('upfluent_cmf_sanitize_value', [self::class, 'sanitizeValue'], 10, 3);
        add_filter('upfluent_cmf_product_values', [self::class, 'productValues'], 10, 2);
        add_filter('upfluent_cmf_register_meta_args', [self::class, 'metaArgs'], 10, 2);
        add_filter('upfluent_cmf_ability_field_properties', [self::class, 'abilityFieldProperties']);
        add_filter('upfluent_cmf_ability_definitions', [self::class, 'abilityDefinitions'], 10, 3);
        add_filter('upfluent_cmf_admin_config', [self::class, 'adminConfig'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'registerAdminScript'], 5);

        add_filter('upfluent_cmf_editor_widget_types', [self::class, 'widgetTypes']);
        add_filter('upfluent_cmf_editor_widget_field', [self::class, 'widgetField'], 10, 2);
        add_filter('upfluent_cmf_editor_widget_extend', [self::class, 'widgetExtend'], 10, 3);
        add_filter('upfluent_cmf_editor_widget_save_values', [self::class, 'widgetSaveValues'], 10, 3);
    }

    // ─── Contexts and types ───

    public static function contexts($contexts): array
    {
        $contexts   = is_array($contexts) ? $contexts : ['product'];
        $contexts[] = 'checkout';
        return $contexts;
    }

    public static function fieldTypes($types, string $context): array
    {
        $types = is_array($types) ? $types : [];
        if ($context === 'product') {
            return array_merge($types, self::PRODUCT_TYPES);
        }
        if ($context === 'checkout') {
            return self::CHECKOUT_TYPES;
        }
        return $types;
    }

    public static function checkoutFields(): array
    {
        return FieldsService::forContext('checkout');
    }

    /** The checkout form input name for a checkout definition. */
    public static function inputName(array $def): string
    {
        return self::CHECKOUT_NAME_PREFIX . $def['field_id'];
    }

    /**
     * Fast path for one checkout field's on/off and required flags, used by
     * FluentCart's own Checkout Fields settings screen.
     * @return array|WP_Error
     */
    public static function setFlags(string $field_id, $enabled, $required)
    {
        return FieldsService::update($field_id, ['enabled' => $enabled, 'required' => $required]);
    }

    /** Raw read, so it is safe to call while definitions are being normalised. */
    private static function isStoredCheckoutId(string $id): bool
    {
        foreach ((array) get_option(FieldsService::OPTION_KEY, []) as $f) {
            if (is_array($f) && ($f['field_id'] ?? '') === $id && ($f['context'] ?? '') === 'checkout') {
                return true;
            }
        }
        return false;
    }

    // ─── Definition shape ───

    public static function definitionKeys($keys): array
    {
        return array_merge((array) $keys, ['allowed_types', 'sub_fields', 'placement', 'enabled', 'required', 'description', 'conditions', 'allowed_extensions', 'max_size_mb']);
    }

    /**
     * Adds the extra keys to the cleaned input: allowed_types (media),
     * sub_fields (repeater) and the checkout-only settings.
     * @return array|WP_Error
     */
    public static function sanitizeInput($clean, array $input, ?array $existing, string $context)
    {
        if (!is_array($clean)) {
            return $clean;
        }

        // allowed_types (media)
        $allowed = $input['allowed_types'] ?? ($existing['allowed_types'] ?? 'any');
        $clean['allowed_types'] = is_string($allowed) && in_array($allowed, self::MEDIA_TYPES, true) ? $allowed : 'any';

        // sub_fields (repeater)
        $subs = array_key_exists('sub_fields', $input) ? $input['sub_fields'] : ($existing['sub_fields'] ?? []);
        if (is_object($subs)) {
            $subs = (array) $subs;
        }
        $subsClean = [];
        if (is_array($subs)) {
            foreach ($subs as $s) {
                if (is_object($s)) {
                    $s = (array) $s;
                }
                if (!is_array($s)) {
                    continue;
                }
                $sid = isset($s['field_id']) && is_string($s['field_id']) ? trim($s['field_id']) : '';
                $st  = isset($s['field_type']) && is_string($s['field_type']) ? sanitize_text_field($s['field_type']) : 'text';
                if ($st === 'repeater') {
                    return new WP_Error('nested_repeater', __('A repeater cannot contain a repeater.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
                if (!in_array($st, self::SUB_FIELD_TYPES, true)) {
                    return new WP_Error('invalid_sub_field_type', sprintf(
                        /* translators: %s: comma separated list */
                        __('Invalid sub-field type. Allowed: %s', 'custom-meta-fields-for-fluentcart'),
                        implode(', ', self::SUB_FIELD_TYPES)
                    ), ['status' => 400]);
                }
                if ($sid === '' || $sid !== sanitize_key($sid) || !preg_match(FieldsService::ID_PATTERN, $sid)) {
                    return new WP_Error('invalid_sub_field_id', __('Sub-field IDs may only contain lowercase letters, numbers, underscores and hyphens.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
                $sopts = $s['options'] ?? '';
                if (is_array($sopts)) {
                    $sopts = implode(',', array_filter($sopts, 'is_scalar'));
                }
                $sopts = is_string($sopts) ? implode(', ', FieldsService::optionsList(sanitize_text_field($sopts))) : '';
                $sat   = $s['allowed_types'] ?? 'any';
                $subsClean[] = [
                    'field_id'      => $sid,
                    'field_type'    => $st,
                    'field_title'   => isset($s['field_title']) && is_string($s['field_title']) ? sanitize_text_field($s['field_title']) : '',
                    'options'       => $st === 'select' ? $sopts : '',
                    'allowed_types' => $st === 'media' && is_string($sat) && in_array($sat, self::MEDIA_TYPES, true) ? $sat : 'any',
                ];
            }
        }
        $clean['sub_fields'] = $subsClean;

        // checkout-only props
        $placement = $input['placement'] ?? ($existing['placement'] ?? self::DEFAULT_PLACEMENT);
        if (!is_string($placement) || !isset(self::PLACEMENTS[$placement])) {
            if (array_key_exists('placement', $input)) {
                return new WP_Error('invalid_placement', sprintf(
                    /* translators: %s: comma separated list */
                    __('Invalid placement. Allowed: %s', 'custom-meta-fields-for-fluentcart'),
                    implode(', ', array_keys(self::PLACEMENTS))
                ), ['status' => 400]);
            }
            $placement = self::DEFAULT_PLACEMENT;
        }
        $clean['placement'] = $placement;

        $required = array_key_exists('required', $input) ? $input['required'] : ($existing['required'] ?? false);
        $clean['required'] = FieldsService::truthy($required);

        $enabled = array_key_exists('enabled', $input) ? $input['enabled'] : ($existing['enabled'] ?? true);
        $clean['enabled'] = FieldsService::truthy($enabled);

        $description = $input['description'] ?? ($existing['description'] ?? '');
        $clean['description'] = is_string($description) ? sanitize_text_field($description) : '';

        $conditions = array_key_exists('conditions', $input) ? $input['conditions'] : ($existing['conditions'] ?? null);
        $clean['conditions'] = self::normalizeConditions($conditions);

        // file-only props
        $exts = $input['allowed_extensions'] ?? ($existing['allowed_extensions'] ?? '');
        if (is_array($exts)) {
            $exts = implode(',', array_filter($exts, 'is_scalar'));
        }
        $exts = is_string($exts) ? sanitize_text_field($exts) : '';
        $clean['allowed_extensions'] = implode(', ', array_map(function ($e) {
            return strtolower(ltrim($e, '.'));
        }, FieldsService::optionsList($exts)));

        $mb = $input['max_size_mb'] ?? ($existing['max_size_mb'] ?? 0);
        $clean['max_size_mb'] = is_scalar($mb) ? (int) $mb : 0;

        return $clean;
    }

    /** Canonical shape for the extra keys of a definition. */
    public static function normalize($def, $f): array
    {
        if (!is_array($def) || !is_array($f)) {
            return $def;
        }
        $type    = $def['field_type'];
        $context = $def['context'];

        if ($type === 'media') {
            $at = $f['allowed_types'] ?? 'any';
            $def['allowed_types'] = in_array($at, self::MEDIA_TYPES, true) ? $at : 'any';
        }

        if ($type === 'repeater') {
            $subs = [];
            foreach ((array) ($f['sub_fields'] ?? []) as $s) {
                if (!is_array($s) || empty($s['field_id']) || !is_string($s['field_id'])) {
                    continue;
                }
                $stype = in_array($s['field_type'] ?? '', self::SUB_FIELD_TYPES, true) ? $s['field_type'] : 'text';
                $sub   = [
                    'field_id'    => sanitize_key($s['field_id']),
                    'field_type'  => $stype,
                    'field_title' => (string) ($s['field_title'] ?? ''),
                    'options'     => $stype === 'select' ? (string) ($s['options'] ?? '') : '',
                ];
                if ($stype === 'media') {
                    $sat = $s['allowed_types'] ?? 'any';
                    $sub['allowed_types'] = in_array($sat, self::MEDIA_TYPES, true) ? $sat : 'any';
                }
                $subs[] = $sub;
            }
            $def['sub_fields']  = $subs;
            $def['placeholder'] = '';
        }

        if ($context === 'checkout') {
            $placement = $f['placement'] ?? self::DEFAULT_PLACEMENT;
            $def['placement']   = isset(self::PLACEMENTS[$placement]) ? $placement : self::DEFAULT_PLACEMENT;
            $def['enabled']     = !array_key_exists('enabled', $f) || !empty($f['enabled']);
            $def['required']    = !empty($f['required']);
            $def['description'] = (string) ($f['description'] ?? '');
            $def['conditions']  = self::normalizeConditions($f['conditions'] ?? null);

            if ($type === 'file') {
                $exts = implode(', ', FieldsService::optionsList((string) ($f['allowed_extensions'] ?? '')));
                $def['allowed_extensions'] = $exts !== '' ? $exts : self::DEFAULT_EXTENSIONS;
                $mb = (int) ($f['max_size_mb'] ?? 0);
                $def['max_size_mb'] = $mb > 0 ? $mb : self::DEFAULT_MAX_SIZE_MB;
                $def['placeholder'] = '';
            }
        }

        return $def;
    }

    /** Canonical conditions block; anything malformed collapses to disabled. */
    public static function normalizeConditions($c): array
    {
        $out = ['enabled' => false, 'relation' => 'all', 'rules' => []];
        if (is_object($c)) {
            $c = (array) $c;
        }
        if (!is_array($c)) {
            return $out;
        }

        $out['enabled']  = !empty($c['enabled']);
        $out['relation'] = in_array($c['relation'] ?? '', self::CONDITION_RELATIONS, true) ? $c['relation'] : 'all';

        foreach ((array) ($c['rules'] ?? []) as $r) {
            if (is_object($r)) {
                $r = (array) $r;
            }
            if (!is_array($r)) {
                continue;
            }
            $field = isset($r['field']) && is_string($r['field']) ? sanitize_key($r['field']) : '';
            $op    = isset($r['operator']) && in_array($r['operator'], self::CONDITION_OPERATORS, true) ? $r['operator'] : 'equals';
            $value = isset($r['value']) && is_scalar($r['value']) ? sanitize_text_field((string) $r['value']) : '';
            if ($field === '') {
                continue;
            }
            // Rules name the form input. Another checkout field is posted as
            // cmf_<id>; accept its bare id too (REST and AI callers use it).
            if (!isset(self::BUILTIN_CONDITION_FIELDS[$field]) && strpos($field, self::CHECKOUT_NAME_PREFIX) !== 0
                && self::isStoredCheckoutId($field)) {
                $field = self::CHECKOUT_NAME_PREFIX . $field;
            }
            $out['rules'][] = ['field' => $field, 'operator' => $op, 'value' => $value];
        }

        if (!$out['rules']) {
            $out['enabled'] = false;
        }

        return $out;
    }

    /**
     * Cross-field rules for the extra keys.
     * @return true|WP_Error
     */
    public static function validateShape($ok, array $def)
    {
        if ($ok instanceof WP_Error) {
            return $ok;
        }

        if (($def['context'] ?? 'product') === 'checkout') {
            if (!in_array($def['field_type'], self::CHECKOUT_TYPES, true)) {
                return new WP_Error('invalid_field_type', sprintf(
                    /* translators: %s: comma separated list */
                    __('Checkout fields may be: %s', 'custom-meta-fields-for-fluentcart'),
                    implode(', ', self::CHECKOUT_TYPES)
                ), ['status' => 400]);
            }
            foreach ((array) ($def['conditions']['rules'] ?? []) as $r) {
                if ($r['field'] === $def['field_id'] || $r['field'] === self::CHECKOUT_NAME_PREFIX . $def['field_id']) {
                    return new WP_Error('self_condition', __('A field cannot depend on itself.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
            }
            if ($def['field_type'] === 'file') {
                $exts    = FieldsService::optionsList((string) ($def['allowed_extensions'] ?? ''));
                $blocked = class_exists(CheckoutUploads::class) ? CheckoutUploads::BLOCKED_EXTENSIONS : ['php', 'phtml', 'phar', 'html', 'htm', 'svg', 'exe', 'sh', 'bat', 'cmd', 'dll', 'js', 'com', 'msi'];
                $bad     = array_intersect(array_map('strtolower', $exts), $blocked);
                if ($bad) {
                    return new WP_Error('blocked_extension', sprintf(
                        /* translators: %s: comma separated extensions */
                        __('These file types can never be uploaded: %s', 'custom-meta-fields-for-fluentcart'),
                        implode(', ', $bad)
                    ), ['status' => 400]);
                }
            }
        }

        if ($def['field_type'] === 'repeater') {
            $subs = (array) ($def['sub_fields'] ?? []);
            if (!$subs) {
                return new WP_Error('missing_sub_fields', __('Repeater fields need at least one sub-field.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
            }
            $seen = [];
            foreach ($subs as $s) {
                $sid = (string) ($s['field_id'] ?? '');
                if ($sid === '' || !preg_match(FieldsService::ID_PATTERN, $sid)) {
                    return new WP_Error('invalid_sub_field_id', __('Sub-field IDs may only contain lowercase letters, numbers, underscores and hyphens.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
                if (isset($seen[$sid])) {
                    return new WP_Error('duplicate_sub_field', sprintf(
                        /* translators: %s: sub-field id */
                        __('Duplicate sub-field ID: %s', 'custom-meta-fields-for-fluentcart'),
                        $sid
                    ), ['status' => 400]);
                }
                $seen[$sid] = true;
                if (($s['field_type'] ?? '') === 'repeater') {
                    return new WP_Error('nested_repeater', __('A repeater cannot contain a repeater.', 'custom-meta-fields-for-fluentcart'), ['status' => 400]);
                }
                if (($s['field_type'] ?? '') === 'select' && trim((string) ($s['options'] ?? '')) === '') {
                    return new WP_Error('missing_options', sprintf(
                        /* translators: %s: sub-field id */
                        __('Select sub-field "%s" needs at least one option.', 'custom-meta-fields-for-fluentcart'),
                        $sid
                    ), ['status' => 400]);
                }
                if (trim((string) ($s['field_title'] ?? '')) === '') {
                    return new WP_Error('missing_sub_field_title', sprintf(
                        /* translators: %s: sub-field id */
                        __('Sub-field "%s" needs a title.', 'custom-meta-fields-for-fluentcart'),
                        $sid
                    ), ['status' => 400]);
                }
            }
        }

        return true;
    }

    // ─── Values ───

    /**
     * Media: attachment ID (0 = none). Repeater: list of rows keyed by
     * sub-field id, each sub-value sanitised by its own type. Null for any
     * other type, which ProductValuesService then handles itself.
     */
    public static function sanitizeValue($result, $value, array $def)
    {
        if ($result !== null) {
            return $result;
        }
        $type = $def['field_type'] ?? 'text';

        if ($type === 'repeater') {
            return self::sanitizeRows($value, $def);
        }
        if ($type === 'media') {
            $id = is_scalar($value) ? absint($value) : 0;
            return MediaHelper::isAllowed($id, (string) ($def['allowed_types'] ?? 'any')) ? $id : 0;
        }
        return null;
    }

    public static function sanitizeRows($rows, array $def): array
    {
        if (is_object($rows)) {
            $rows = (array) $rows;
        }
        if (!is_array($rows)) {
            return [];
        }

        $subs = [];
        foreach ((array) ($def['sub_fields'] ?? []) as $s) {
            $subs[$s['field_id']] = $s;
        }
        if (!$subs) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (!is_array($row)) {
                continue;
            }
            $clean    = [];
            $hasValue = false;
            foreach ($subs as $sid => $sdef) {
                $v = ProductValuesService::sanitizeValue($row[$sid] ?? '', $sdef);
                $clean[$sid] = $v;
                if ($v !== '' && $v !== 0 && $v !== '0') {
                    $hasValue = true;
                }
            }
            if ($hasValue) {
                $out[] = $clean;
            }
        }
        return array_values($out);
    }

    /** Adds attachment preview info for media values (top-level and in rows). */
    public static function productValues($result, int $product_id): array
    {
        if (!is_array($result)) {
            return $result;
        }
        $attachments = [];
        foreach ((array) ($result['fields'] ?? []) as $def) {
            $value = $result['values'][$def['field_id']] ?? null;
            if ($def['field_type'] === 'media' && $value) {
                if (!isset($attachments[$value]) && ($info = MediaHelper::info((int) $value))) {
                    $attachments[$value] = $info;
                }
                continue;
            }
            if ($def['field_type'] === 'repeater' && is_array($value)) {
                foreach ((array) ($def['sub_fields'] ?? []) as $s) {
                    if ($s['field_type'] !== 'media') {
                        continue;
                    }
                    foreach ($value as $row) {
                        $id = (int) ($row[$s['field_id']] ?? 0);
                        if ($id && !isset($attachments[$id]) && ($info = MediaHelper::info($id))) {
                            $attachments[$id] = $info;
                        }
                    }
                }
            }
        }
        $result['attachments'] = $attachments;
        return $result;
    }

    // ─── Product editor sidebar (FluentCart form widget) ───

    /**
     * Schema key of the hidden value that carries media and repeater values
     * inside FluentCart's own save. The colon cannot occur in a field id.
     */
    const WIDGET_BRIDGE_KEY = 'cmf:pro';

    /** Schema key of the markup holding the initial state for admin-pro.js. */
    const WIDGET_STATE_KEY = 'cmf:pro-state';

    /** Media and repeater fields join the sidebar card. */
    public static function widgetTypes($types): array
    {
        $types = is_array($types) ? $types : [];
        foreach (self::PRODUCT_TYPES as $type) {
            if (!in_array($type, $types, true)) {
                $types[] = $type;
            }
        }
        return $types;
    }

    /**
     * FluentCart's form renderer has no media-with-any-file-type or repeater
     * input, so each such field becomes an `html` entry: a mount point that
     * admin-pro.js fills with the same inputs the old card used. `html`
     * entries render in schema order and carry no value of their own.
     */
    public static function widgetField($field, $def)
    {
        if (!is_array($def) || !in_array($def['field_type'] ?? '', self::PRODUCT_TYPES, true)) {
            return $field;
        }
        return [
            'type'  => 'html',
            'value' => '<div class="fct-cmf-pro-mount" data-field="' . esc_attr($def['field_id']) . '"></div>',
        ];
    }

    /**
     * Adds the hidden bridge input and the initial state (definitions,
     * values, attachment previews) when the card has any Pro fields.
     */
    public static function widgetExtend($extra, $product_id, $fields)
    {
        $extra = is_array($extra) ? $extra : ['schema' => [], 'values' => []];
        $pro   = array_values(array_filter((array) $fields, function ($def) {
            return in_array($def['field_type'] ?? '', self::PRODUCT_TYPES, true);
        }));
        if (!$pro) {
            return $extra;
        }

        $values = [];
        foreach ($pro as $def) {
            $values[$def['field_id']] = ProductValuesService::read((int) $product_id, $def);
        }
        $withPreviews = self::productValues(['fields' => $pro, 'values' => $values], (int) $product_id);

        $state = [
            'productId'   => (int) $product_id,
            'fields'      => $pro,
            'values'      => $values,
            'attachments' => (object) ($withPreviews['attachments'] ?? []),
            'canEdit'     => Permissions::canEditProduct(),
        ];

        $extra['schema'][self::WIDGET_STATE_KEY] = [
            'type'  => 'html',
            // htmlspecialchars, not esc_attr: esc_attr skips existing entities, so a
            // literal "&amp;" in a title would come back as "&".
            'value' => '<div class="fct-cmf-pro-state" hidden data-state="' . htmlspecialchars((string) wp_json_encode($state), ENT_QUOTES, 'UTF-8') . '"></div>',
        ];
        $extra['schema'][self::WIDGET_BRIDGE_KEY] = [
            'type'  => 'text',
            'class' => 'fct-cmf-pro-bridge',
        ];
        // Empty until an edit: an untouched card sends nothing for these fields.
        $extra['values'][self::WIDGET_BRIDGE_KEY] = '';

        return $extra;
    }

    /**
     * Decodes the bridge value into media and repeater field values. Only
     * Pro field ids the widget rendered are accepted; each value is then
     * sanitised by ProductValuesService like any other.
     */
    public static function widgetSaveValues($clean, $raw, $product_id)
    {
        $clean = is_array($clean) ? $clean : [];
        $json  = is_array($raw) ? ($raw[self::WIDGET_BRIDGE_KEY] ?? '') : '';
        if (!is_string($json) || $json === '') {
            return $clean;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $clean;
        }

        foreach (EditorWidget::fields() as $def) {
            $id = $def['field_id'];
            if (in_array($def['field_type'], self::PRODUCT_TYPES, true) && array_key_exists($id, $decoded)) {
                $clean[$id] = $decoded[$id];
            }
        }
        return $clean;
    }

    // ─── register_post_meta() ───

    public static function metaArgs($args, array $def): array
    {
        $type = $def['field_type'];
        if ($type === 'media') {
            $args['type']    = 'integer';
            $args['default'] = 0;
        } elseif ($type === 'repeater') {
            $args['type']         = 'array';
            $args['default']      = [];
            $args['show_in_rest'] = [
                'schema' => [
                    'type'  => 'array',
                    'items' => self::rowSchema($def),
                ],
            ];
        }
        return $args;
    }

    /** JSON schema for one repeater row. */
    public static function rowSchema(array $def): array
    {
        $props = [];
        foreach ((array) ($def['sub_fields'] ?? []) as $s) {
            $props[$s['field_id']] = $s['field_type'] === 'media'
                ? ['type' => 'integer', 'description' => $s['field_title']]
                : ['type' => 'string',  'description' => $s['field_title']];
            if ($s['field_type'] === 'select') {
                $props[$s['field_id']]['enum'] = array_merge([''], FieldsService::optionsList((string) $s['options']));
            }
        }
        return [
            'type'                 => 'object',
            'properties'           => $props ?: new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    // ─── Abilities (MCP) ───

    public static function abilityFieldProperties($props): array
    {
        $props = is_array($props) ? $props : [];

        $subFieldSchema = [
            'type'       => 'object',
            'properties' => [
                'field_id'      => ['type' => 'string'],
                'field_type'    => ['type' => 'string', 'enum' => self::SUB_FIELD_TYPES],
                'field_title'   => ['type' => 'string'],
                'options'       => ['type' => 'string', 'description' => 'Comma-separated options (select type only).'],
                'allowed_types' => ['type' => 'string', 'enum' => self::MEDIA_TYPES, 'description' => 'Media type restriction (media type only).'],
            ],
            'required'   => ['field_id', 'field_title', 'field_type'],
        ];

        $conditionsSchema = [
            'type'        => 'object',
            'description' => 'Checkout only. Show the field only when rules match. Rule "field" is a checkout input name: another checkout field as cmf_{field_id}, or a FluentCart input such as billing_country, shipping_country, ship_to_different ("yes"), is_business ("yes"), _fct_pay_method, fc_selected_shipping_method.',
            'properties'  => [
                'enabled'  => ['type' => 'boolean'],
                'relation' => ['type' => 'string', 'enum' => self::CONDITION_RELATIONS],
                'rules'    => ['type' => 'array', 'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'field'    => ['type' => 'string'],
                        'operator' => ['type' => 'string', 'enum' => self::CONDITION_OPERATORS],
                        'value'    => ['type' => 'string'],
                    ],
                    'required'   => ['field', 'operator'],
                ]],
            ],
        ];

        $props['context']    = ['type' => 'string', 'enum' => FieldsService::contexts(), 'description' => 'Where the field lives: "product" (product editor, default) or "checkout" (customer fills it in at checkout). Fixed after creation.'];
        $props['field_type'] = ['type' => 'string', 'enum' => array_values(array_unique(array_merge(FieldsService::types('product'), self::CHECKOUT_TYPES))), 'description' => 'Checkout fields may only be: ' . implode(', ', self::CHECKOUT_TYPES) . '.'];
        $props['allowed_types'] = ['type' => 'string', 'enum' => self::MEDIA_TYPES, 'description' => 'Media type restriction: any, image, video, audio, file (product media type only).'];
        $props['sub_fields']    = ['type' => 'array', 'items' => $subFieldSchema, 'description' => 'Row columns (product repeater type only). A repeater cannot contain a repeater.'];
        $props['placement']     = ['type' => 'string', 'enum' => array_keys(self::PLACEMENTS), 'description' => 'Checkout only. Where on the checkout form the field renders. Default before_payment.'];
        $props['enabled']       = ['type' => 'boolean', 'description' => 'Checkout only. Off hides the field from the checkout form without deleting it. Also switchable on FluentCart\'s Settings → Checkout Fields screen. Default true.'];
        $props['required']      = ['type' => 'boolean', 'description' => 'Checkout only. Enforced only while the field is visible.'];
        $props['allowed_extensions'] = ['type' => 'string', 'description' => 'Checkout file type only. Comma-separated extensions. Default: ' . self::DEFAULT_EXTENSIONS];
        $props['max_size_mb']   = ['type' => 'integer', 'description' => 'Checkout file type only. Default 10, capped by the server upload limit. Files are stored through FluentCart\'s storage driver.'];
        $props['description']   = ['type' => 'string', 'description' => 'Checkout only. Help text under the input.'];
        $props['conditions']    = $conditionsSchema;

        return $props;
    }

    public static function abilityDefinitions($defs, string $ns, array $fieldSchema): array
    {
        $defs = is_array($defs) ? $defs : [];

        if (isset($defs[$ns . 'list-fields'])) {
            $defs[$ns . 'list-fields']['description'] = __('List every custom field definition, product and checkout, in display order. Each has a context of "product" or "checkout". Call this before creating a field to avoid duplicate ids.', 'custom-meta-fields-for-fluentcart');
        }
        if (isset($defs[$ns . 'create-field'])) {
            $defs[$ns . 'create-field']['description'] = __('Create a custom field. context "product" (default) puts it on every FluentCart product edit page; context "checkout" puts it on the checkout form, where placement, required, description and conditions apply and the value is saved with the order. field_id is a permanent machine name (lowercase letters, numbers, underscores, hyphens), unique across both contexts. Select fields require comma-separated options.', 'custom-meta-fields-for-fluentcart');
        }
        if (isset($defs[$ns . 'delete-field'])) {
            $defs[$ns . 'delete-field']['description'] = __('Delete a custom field definition. A product field also loses its stored values on every product; a checkout field keeps the values already saved on orders. This cannot be undone.', 'custom-meta-fields-for-fluentcart');
        }
        if (isset($defs[$ns . 'get-product-fields'])) {
            $defs[$ns . 'get-product-fields']['output_schema']['properties']['attachments'] = ['type' => 'object', 'additionalProperties' => true, 'description' => 'Preview info for referenced media attachments, keyed by attachment id.'];
        }
        if (isset($defs[$ns . 'set-product-fields'])) {
            $defs[$ns . 'set-product-fields']['description'] = __('Write custom field values on one FluentCart product. values is an object of field_id => value. Values are sanitised per field type (checkbox: "1"/"0"; date: YYYY-MM-DD; color: #rrggbb; select: must be a defined option; media: attachment ID integer; repeater: array of row objects keyed by sub-field id). Unknown field ids are ignored and reported.', 'custom-meta-fields-for-fluentcart');
        }

        $storedValueSchema = [
            'type'       => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'type'  => ['type' => 'string'],
                'value' => ['type' => 'string', 'description' => 'For file fields: the original file name.'],
                'file'  => ['type' => 'object', 'description' => 'File fields only: driver, path, bucket, name, size, mime in FluentCart storage.', 'additionalProperties' => true],
            ],
        ];

        $defs[$ns . 'get-order-checkout-fields'] = [
            'label'               => __('Get Order Checkout Field Values', 'custom-meta-fields-for-fluentcart'),
            'description'         => __('Read the checkout field values a customer entered on one FluentCart order. order_id is the numeric order ID. Each value carries the label and type the field had when the order was placed.', 'custom-meta-fields-for-fluentcart'),
            'input_schema'        => [
                'type'       => 'object',
                'properties' => ['order_id' => ['type' => 'integer']],
                'required'   => ['order_id'],
            ],
            'output_schema'       => ['type' => 'object', 'properties' => [
                'order_id' => ['type' => 'integer'],
                'values'   => ['type' => 'object', 'additionalProperties' => $storedValueSchema, 'description' => 'Keyed by field_id.'],
            ]],
            'execute_callback'    => [self::class, 'getOrderCheckoutFields'],
            'permission_callback' => [Permissions::class, 'canViewOrder'],
            'annotations'         => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
        ];

        return $defs;
    }

    public static function getOrderCheckoutFields($params = [])
    {
        if (is_object($params)) {
            $params = (array) $params;
        }
        $order_id = absint(is_array($params) ? ($params['order_id'] ?? 0) : 0);
        if (!$order_id || !class_exists('\FluentCart\App\Models\Order') || !class_exists(CheckoutFields::class)) {
            return new WP_Error('order_not_found', __('Order not found.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }
        $order = \FluentCart\App\Models\Order::find($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('Order not found.', 'custom-meta-fields-for-fluentcart'), ['status' => 404]);
        }
        return ['order_id' => $order_id, 'values' => CheckoutFields::orderValues($order)];
    }

    // ─── Admin ───

    /**
     * The admin module for Media, Repeater and the Checkout tab. Registered
     * early so the main script can depend on it; it must run before admin.js.
     */
    public static function registerAdminScript(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== AdminMenu::PAGE_SLUG && $page !== 'fluent-cart') {
            return;
        }

        $deps = [];
        if ($page === 'fluent-cart') {
            // Media fields use the standard WP media modal.
            wp_enqueue_media();
            $deps = ['jquery', 'media-editor'];
        }
        wp_register_script('fct-custom-meta-admin-pro', UPFLUENT_CMF_URL . 'assets/admin-pro.js', $deps, UPFLUENT_CMF_VERSION, true);
    }

    /** Settings admin-pro.js reads from the shared config object. */
    public static function adminConfig($config, bool $is_plugin_page): array
    {
        $config = is_array($config) ? $config : [];
        $config['placements'] = [
            'before_billing' => __('Before the billing address', 'custom-meta-fields-for-fluentcart'),
            'after_shipping' => __('After the shipping address (physical orders only)', 'custom-meta-fields-for-fluentcart'),
            'before_payment' => __('Before the payment methods', 'custom-meta-fields-for-fluentcart'),
            'after_payment'  => __('After the payment methods', 'custom-meta-fields-for-fluentcart'),
            'after_button'   => __('After the place-order button', 'custom-meta-fields-for-fluentcart'),
            'order_summary'  => __('In the order summary, after order notes', 'custom-meta-fields-for-fluentcart'),
        ];
        $config['builtinConditionFields'] = self::BUILTIN_CONDITION_FIELDS;
        $config['editorWidgetTypes'] = EditorWidget::types();
        $config['i18n'] = array_merge((array) ($config['i18n'] ?? []), [
            'selectMedia'      => __('Select', 'custom-meta-fields-for-fluentcart'),
            'replaceMedia'     => __('Replace', 'custom-meta-fields-for-fluentcart'),
            'removeMedia'      => __('Remove', 'custom-meta-fields-for-fluentcart'),
            'chooseMedia'      => __('Choose media', 'custom-meta-fields-for-fluentcart'),
            'useMedia'         => __('Use this file', 'custom-meta-fields-for-fluentcart'),
            'wrongMediaType'   => __('That file type is not allowed for this field.', 'custom-meta-fields-for-fluentcart'),
            'mediaUnavailable' => __('The WordPress media library is not available on this page.', 'custom-meta-fields-for-fluentcart'),
            'addRow'           => __('Add Row', 'custom-meta-fields-for-fluentcart'),
            'removeRow'        => __('Remove Row', 'custom-meta-fields-for-fluentcart'),
            'dragToReorder'    => __('Drag to reorder', 'custom-meta-fields-for-fluentcart'),
            'checkoutTab'      => __('Checkout Fields', 'custom-meta-fields-for-fluentcart'),
            'confirmDeleteCheckout' => __('Delete this checkout field? Values already saved on orders are kept.', 'custom-meta-fields-for-fluentcart'),
        ]);
        return $config;
    }
}
