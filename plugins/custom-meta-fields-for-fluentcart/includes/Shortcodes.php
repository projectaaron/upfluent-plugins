<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Front-end rendering.
 *
 *   [upfluent_cmf_field key="FIELD_ID" size="large" post_id="123"]
 *
 * Field types this plugin does not render itself go through the
 * upfluent_cmf_render_value filter (ProRendering renders media and repeaters
 * there and adds [upfluent_cmf_repeater]). Output is an empty string when the field is empty. The stylesheet is
 * enqueued only when a shortcode actually renders something. All output is
 * escaped; no inline styles; classes prefixed cmf-.
 */
class Shortcodes
{
    const STYLE_HANDLE = 'fct-custom-meta-frontend';

    public function register(): void
    {
        add_shortcode('upfluent_cmf_field', [$this, 'field']);
    }

    /**
     * Registers the front-end stylesheet without enqueueing it. Hooked early so
     * the handle exists before Elementor enqueues it for a widget through
     * get_style_depends(). Idempotent.
     */
    public static function registerStyle(): void
    {
        if (wp_style_is(self::STYLE_HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::STYLE_HANDLE, UPFLUENT_CMF_URL . 'assets/frontend.css', [], UPFLUENT_CMF_VERSION);
    }

    // ─── [upfluent_cmf_field] ───

    public function field($atts): string
    {
        $atts = shortcode_atts(['key' => '', 'size' => 'large', 'post_id' => 0, 'product_id' => 0], $atts, 'upfluent_cmf_field');
        $atts['post_id'] = $atts['post_id'] ?: $atts['product_id'];

        $def = FieldsService::find(sanitize_key($atts['key']));
        $pid = self::postId($atts['post_id']);
        if (!$def || !$pid) {
            return '';
        }

        $value = ProductValuesService::read($pid, $def);
        $html  = self::renderScalar($def, $value, sanitize_key($atts['size']) ?: 'large');

        if ($html === '') {
            return '';
        }
        wp_enqueue_style(self::STYLE_HANDLE);
        return $html;
    }

    // ─── Rendering ───

    /** One value. Empty string when there is nothing to show. */
    public static function renderScalar(array $def, $value, string $size = 'large'): string
    {
        $type  = $def['field_type'];
        $class = 'cmf-field cmf-field--' . sanitize_html_class($type) . ' cmf-field--' . sanitize_html_class($def['field_id']);

        if (!in_array($type, FieldsService::TYPES, true)) {
            return (string) apply_filters('upfluent_cmf_render_value', '', $def, $value, $size, $class);
        }

        $value = is_scalar($value) ? (string) $value : '';
        if ($value === '' || ($type === 'checkbox' && $value !== '1')) {
            return '';
        }

        switch ($type) {
            case 'checkbox':
                $inner = '<span class="cmf-check">' . esc_html__('Yes', 'custom-meta-fields-for-fluentcart') . '</span>';
                break;
            case 'url':
                $inner = '<a class="cmf-link" href="' . esc_url($value) . '" rel="noopener">' . esc_html($value) . '</a>';
                break;
            case 'email':
                $inner = '<a class="cmf-link" href="' . esc_url('mailto:' . $value) . '">' . esc_html($value) . '</a>';
                break;
            case 'date': {
                $ts    = strtotime($value . ' 00:00:00');
                $inner = '<time class="cmf-date" datetime="' . esc_attr($value) . '">' . esc_html($ts ? date_i18n(get_option('date_format'), $ts) : $value) . '</time>';
                break;
            }
            case 'color':
                $inner = '<span class="cmf-color" data-color="' . esc_attr($value) . '"><span class="cmf-color-swatch"></span>' . esc_html($value) . '</span>';
                break;
            case 'textarea':
                $inner = '<span class="cmf-text">' . nl2br(esc_html($value)) . '</span>';
                break;
            default:
                $inner = '<span class="cmf-text">' . esc_html($value) . '</span>';
        }

        return '<span class="' . esc_attr($class) . '">' . $inner . '</span>';
    }

    public static function postId($attr): int
    {
        $pid = absint($attr);
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
}
