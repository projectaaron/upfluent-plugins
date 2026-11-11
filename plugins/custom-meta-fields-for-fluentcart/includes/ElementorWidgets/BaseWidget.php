<?php

namespace UpfluentCmf\ElementorWidgets;

defined('ABSPATH') || exit;

use UpfluentCmf\FieldsService;
use UpfluentCmf\ProductValuesService;
use UpfluentCmf\Shortcodes;

/**
 * Shared behaviour for the custom-field Elementor widgets: field picker,
 * product resolution, empty-state handling, and editor-only notices.
 */
abstract class BaseWidget extends \Elementor\Widget_Base
{
    /** Field type this widget renders ('media' or 'repeater'). */
    abstract protected function fieldType(): string;

    public function get_categories()
    {
        return [Integration::CATEGORY];
    }

    /** Elementor enqueues this for us wherever the widget appears. */
    public function get_style_depends()
    {
        return [Shortcodes::STYLE_HANDLE];
    }

    // ─── Field picker ───

    /** @return array<string,string> field_id => title, for this widget's type */
    protected function fieldOptions(): array
    {
        $out = [];
        foreach (FieldsService::all() as $f) {
            if ($f['field_type'] === $this->fieldType()) {
                $out[$f['field_id']] = $f['field_title'] !== '' ? $f['field_title'] : $f['field_id'];
            }
        }
        return $out;
    }

    /** Definitions of this widget's type, full shape. */
    protected function typedFields(): array
    {
        return array_values(array_filter(FieldsService::all(), function ($f) {
            return $f['field_type'] === $this->fieldType();
        }));
    }

    /**
     * Adds the field picker plus the product override. Call inside a content
     * section. Returns false when no field of this type exists, having already
     * rendered an explanatory control.
     */
    protected function addFieldControls(): bool
    {
        $options = $this->fieldOptions();

        if (!$options) {
            $this->add_control('cmf_no_fields', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => sprintf(
                    /* translators: %s: field type name */
                    esc_html__('No %s fields are defined yet. Create one under FluentCart, Custom Fields.', 'custom-meta-fields-for-fluentcart'),
                    esc_html($this->fieldType())
                ),
                'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
            ]);
            return false;
        }

        $this->add_control('field_id', [
            'label'   => esc_html__('Field', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => $options,
            'default' => (string) array_key_first($options),
        ]);

        $this->add_control('post_id', [
            'label'       => esc_html__('Product ID', 'custom-meta-fields-for-fluentcart'),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'min'         => 0,
            'placeholder' => esc_html__('Current product', 'custom-meta-fields-for-fluentcart'),
            'description' => esc_html__('Leave empty to use the product being displayed.', 'custom-meta-fields-for-fluentcart'),
        ]);

        return true;
    }

    /** Empty-state controls, shared by both widgets. */
    protected function addEmptyControls(): void
    {
        $this->add_control('hide_if_empty', [
            'label'        => esc_html__('Hide when empty', 'custom-meta-fields-for-fluentcart'),
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => esc_html__('Hide', 'custom-meta-fields-for-fluentcart'),
            'label_off'    => esc_html__('Show', 'custom-meta-fields-for-fluentcart'),
            'default'      => 'yes',
            'return_value' => 'yes',
        ]);

        $this->add_control('fallback_text', [
            'label'     => esc_html__('Fallback text', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => '',
            'condition' => ['hide_if_empty' => ''],
        ]);
    }

    // ─── Resolution ───

    /** The chosen definition, or null when it is gone or changed type. */
    protected function resolveField(array $settings): ?array
    {
        $id = isset($settings['field_id']) ? sanitize_key((string) $settings['field_id']) : '';
        if ($id === '') {
            return null;
        }
        $def = FieldsService::find($id);

        return ($def && $def['field_type'] === $this->fieldType()) ? $def : null;
    }

    /** The product post ID in context, or 0 when there is not one. */
    protected function resolvePostId(array $settings): int
    {
        $pid = isset($settings['post_id']) ? absint($settings['post_id']) : 0;
        if (!$pid) {
            $pid = (int) get_the_ID();
        }

        if (!$pid || get_post_type($pid) !== ProductValuesService::PRODUCT_CPT) {
            return 0;
        }
        // Another product named by ID must be one the visitor could read.
        if ($pid !== (int) get_the_ID() && (get_post_status($pid) !== 'publish' || post_password_required($pid))
            && !current_user_can('read_post', $pid)) {
            return 0;
        }
        return $pid;
    }

    protected function isEditor(): bool
    {
        if (!class_exists('\Elementor\Plugin')) {
            return false;
        }
        $plugin = \Elementor\Plugin::$instance;

        if (isset($plugin->editor) && method_exists($plugin->editor, 'is_edit_mode') && $plugin->editor->is_edit_mode()) {
            return true;
        }

        return isset($plugin->preview) && method_exists($plugin->preview, 'is_preview_mode') && $plugin->preview->is_preview_mode();
    }

    /** Visible only while editing, so the front end stays clean. */
    protected function editorNotice(string $message): void
    {
        if (!$this->isEditor()) {
            return;
        }
        echo '<div class="cmf-editor-notice">' . esc_html($message) . '</div>';
    }

    /**
     * Renders the empty state. Returns nothing; the caller should return after.
     */
    protected function renderEmpty(array $settings, string $editorHint): void
    {
        $fallback = isset($settings['fallback_text']) ? trim((string) $settings['fallback_text']) : '';

        if (($settings['hide_if_empty'] ?? 'yes') !== 'yes' && $fallback !== '') {
            echo '<div class="cmf-field cmf-field--empty">' . esc_html($fallback) . '</div>';
            return;
        }

        $this->editorNotice($editorHint);
    }

    // ─── Helpers ───

    /** Registered image sizes for a SELECT control. */
    protected function imageSizeOptions(): array
    {
        $sizes = [];
        foreach (get_intermediate_image_sizes() as $size) {
            $sizes[$size] = ucwords(str_replace(['-', '_'], ' ', $size));
        }
        $sizes['full'] = esc_html__('Full', 'custom-meta-fields-for-fluentcart');

        return $sizes;
    }

    /** Alignment control shared by both widgets. */
    protected function addAlignControl(string $name, string $selector, string $default = ''): void
    {
        $this->add_responsive_control($name, [
            'label'     => esc_html__('Alignment', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::CHOOSE,
            'options'   => [
                'flex-start' => ['title' => esc_html__('Left', 'custom-meta-fields-for-fluentcart'),   'icon' => 'eicon-text-align-left'],
                'center'     => ['title' => esc_html__('Center', 'custom-meta-fields-for-fluentcart'), 'icon' => 'eicon-text-align-center'],
                'flex-end'   => ['title' => esc_html__('Right', 'custom-meta-fields-for-fluentcart'),  'icon' => 'eicon-text-align-right'],
            ],
            'default'   => $default,
            'selectors' => [
                '{{WRAPPER}} ' . $selector => 'display: flex; flex-direction: column; align-items: {{VALUE}};',
            ],
        ]);
    }
}
