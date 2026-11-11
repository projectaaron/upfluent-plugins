<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * The product editor card, as a FluentCart sidebar widget.
 *
 * FluentCart's product editor fills its sidebar from
 * `fluent_cart/widgets/single_product_page`. A widget of type `form` is
 * rendered by FluentCart itself (its own inputs, its own card), and its
 * values are posted with FluentCart's Save as `metaValue[form_name]`, which
 * `fluent_cart/product_updated` then hands to save(). So there is one Save
 * button, FluentCart's, and no markup of ours in the editor.
 *
 * The free build renders its own scalar types. Other types join through
 * filters (see types(), schemaField(), widget() and save()), which is how
 * ProDefinitions adds media and repeater fields to the same card.
 *
 * The widgets endpoint is gated by FluentCart on customers/view or
 * orders/view rather than products/*, so available() tells the admin script
 * whether the widget will show; when it will not, the injected card renders
 * the fields itself as before.
 */
class EditorWidget
{
    /** Key of this widget's values inside FluentCart's metaValue. */
    const FORM_NAME = 'fct_cmf_fields';

    public function register(): void
    {
        add_filter('fluent_cart/widgets/single_product_page', [$this, 'widget'], 10, 2);
        add_action('fluent_cart/product_updated', [$this, 'save']);
    }

    /** Whether FluentCart will show the widget to the current user. */
    public static function available(): bool
    {
        return Permissions::canViewProduct() && Permissions::canLoadEditorWidgets();
    }

    /** Field types this widget renders. Free: the plugin's own scalar types. */
    public static function types(): array
    {
        $types = apply_filters('upfluent_cmf_editor_widget_types', FieldsService::TYPES);
        return is_array($types) ? array_values(array_filter($types, 'is_string')) : FieldsService::TYPES;
    }

    /** Product definitions this widget renders. */
    public static function fields(): array
    {
        $types = self::types();
        return array_values(array_filter(FieldsService::productFields(), function ($def) use ($types) {
            return in_array($def['field_type'], $types, true);
        }));
    }

    /**
     * @param mixed $widgets
     * @param mixed $data    ['product_id' => int]
     */
    public function widget($widgets, $data = [])
    {
        $widgets    = is_array($widgets) ? $widgets : [];
        $product_id = ProductValuesService::validateProduct(is_array($data) ? ($data['product_id'] ?? 0) : 0);
        if ($product_id instanceof \WP_Error || !Permissions::canViewProduct()) {
            return $widgets;
        }

        $fields = self::fields();
        if (!$fields) {
            if (Permissions::canManageDefinitions()) {
                $widgets[] = [
                    'title'   => __('Custom Meta Fields', 'custom-meta-fields-for-fluentcart'),
                    'type'    => 'html',
                    'content' => '<p class="description">' . esc_html__('No custom fields defined.', 'custom-meta-fields-for-fluentcart')
                        . ' <a href="' . esc_url(AdminMenu::url() . '#product') . '">' . esc_html__('Add fields here', 'custom-meta-fields-for-fluentcart') . '</a>.</p>',
                ];
            }
            return $widgets;
        }

        $schema = [];
        $values = [];
        foreach ($fields as $def) {
            $schema[$def['field_id']] = self::schemaField($def);
            $values[$def['field_id']] = self::widgetValue(ProductValuesService::read($product_id, $def), $def);
        }

        /**
         * Extra schema entries and their values, e.g. the media and repeater
         * support. Keys that are not field ids are never saved as fields.
         */
        $extra  = apply_filters('upfluent_cmf_editor_widget_extend', ['schema' => [], 'values' => []], $product_id, $fields);
        if (is_array($extra)) {
            $schema = array_merge($schema, is_array($extra['schema'] ?? null) ? $extra['schema'] : []);
            $values = array_merge($values, is_array($extra['values'] ?? null) ? $extra['values'] : []);
        }

        $widgets[] = [
            'title'         => __('Custom Meta Fields', 'custom-meta-fields-for-fluentcart'),
            'sub_title'     => Permissions::canEditProduct() ? '' : __('Read only', 'custom-meta-fields-for-fluentcart'),
            'type'          => 'form',
            'form_name'     => self::FORM_NAME,
            'reset_on_save' => false,
            'schema'        => $schema,
            'values'        => $values,
        ];

        return $widgets;
    }

    /** One definition as a FluentCart form-renderer field. */
    public static function schemaField(array $def): array
    {
        $types = [
            'text'     => 'text',
            'textarea' => 'textarea',
            'number'   => 'number',
            'select'   => 'select',
            'checkbox' => 'checkbox',
            'url'      => 'text',
            'email'    => 'email',
            'date'     => 'date',
            'color'    => 'color',
        ];
        $field = [
            'type'  => $types[$def['field_type']] ?? 'text',
            'label' => (string) $def['field_title'],
            // The shortcode, where the injected card had a copy button.
            'help'  => '[upfluent_cmf_field key="' . $def['field_id'] . '"]',
        ];
        if (!empty($def['placeholder'])) {
            $field['placeholder'] = (string) $def['placeholder'];
        }
        // Attributes are spread onto the Element Plus control by the renderer.
        if ($def['field_type'] === 'textarea') {
            $field['attributes'] = ['type' => 'textarea', 'rows' => 3];
        } elseif ($def['field_type'] === 'date') {
            // Otherwise the picker posts an ISO timestamp in the browser's zone.
            $field['attributes'] = ['value-format' => 'YYYY-MM-DD'];
        } elseif ($def['field_type'] === 'select') {
            // The renderer's Select only reads an array of {value,label} rows.
            $options = [];
            foreach (FieldsService::optionsList((string) ($def['options'] ?? '')) as $o) {
                $options[] = ['value' => $o, 'label' => $o];
            }
            $field['options']   = $options;
            $field['clearable'] = true;
        }
        if (!Permissions::canEditProduct()) {
            $field['disabled'] = true;
        }
        return apply_filters('upfluent_cmf_editor_widget_field', $field, $def);
    }

    /** Stored value as the form renderer expects it. */
    public static function widgetValue($value, array $def)
    {
        if ($def['field_type'] === 'checkbox') {
            return $value === '1' ? 'yes' : 'no';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * fluent_cart/product_updated: ['data' => request data, 'product' => post].
     * Runs on every product save, including ones without our widget (list
     * page status changes), so nothing happens unless our values are present.
     */
    public function save($args): void
    {
        if (!is_array($args)) {
            return;
        }
        $data   = $args['data'] ?? null;
        $values = is_array($data) && isset($data['metaValue']) && is_array($data['metaValue'])
            ? ($data['metaValue'][self::FORM_NAME] ?? null)
            : null;
        if (is_object($values)) {
            $values = (array) $values;
        }
        if (!is_array($values)) {
            return;
        }

        $product = $args['product'] ?? null;
        $pid     = is_object($product) ? (int) ($product->ID ?? 0) : (is_array($product) ? (int) ($product['ID'] ?? 0) : 0);
        if (!$pid || !Permissions::canEditProduct()) {
            return;
        }

        // Only the fields this widget rendered; anything else in the payload is ignored.
        $allowed = [];
        foreach (self::fields() as $def) {
            $allowed[$def['field_id']] = true;
        }
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && isset($allowed[$key])) {
                $clean[$key] = $value;
            }
        }
        // Lets an extension add values it carried under its own keys.
        $clean = apply_filters('upfluent_cmf_editor_widget_save_values', $clean, $values, $pid);
        if (is_array($clean) && $clean) {
            // ProductValuesService sanitises per type; the checkbox's "yes"/"no" is understood.
            ProductValuesService::set($pid, $clean);
        }
    }
}
