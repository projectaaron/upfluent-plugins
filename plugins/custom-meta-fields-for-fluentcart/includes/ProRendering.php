<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Front-end rendering for media values, repeater rows and
 *
 *   [upfluent_cmf_repeater key="FIELD_ID" layout="list|grid|row" columns="3" post_id="123"]
 *
 * Plugs into [upfluent_cmf_field] through the upfluent_cmf_render_value filter.
 */
class ProRendering
{
    public function register(): void
    {
        add_shortcode('upfluent_cmf_repeater', [$this, 'repeater']);
        add_filter('upfluent_cmf_render_value', [self::class, 'value'], 10, 5);
    }

    /** Media or repeater value for [upfluent_cmf_field] and the Elementor widgets. */
    public static function value($html, array $def, $value, string $size = 'large', string $class = ''): string
    {
        if ($def['field_type'] === 'repeater') {
            return self::renderRepeater($def, $value, 'list', 3, $size);
        }
        if ($def['field_type'] === 'media') {
            $media = self::media((int) $value, $size);
            return $media === '' ? '' : '<div class="' . esc_attr($class) . '">' . $media . '</div>';
        }
        return (string) $html;
    }

    // ─── [upfluent_cmf_repeater] ───

    public function repeater($atts): string
    {
        $atts = shortcode_atts(['key' => '', 'layout' => 'list', 'columns' => 3, 'post_id' => 0, 'product_id' => 0, 'size' => 'large'], $atts, 'upfluent_cmf_repeater');
        $atts['post_id'] = $atts['post_id'] ?: $atts['product_id'];

        $def = FieldsService::find(sanitize_key($atts['key']));
        $pid = Shortcodes::postId($atts['post_id']);
        if (!$def || !$pid || $def['field_type'] !== 'repeater') {
            return '';
        }

        $layout  = in_array($atts['layout'], ['list', 'grid', 'row'], true) ? $atts['layout'] : 'list';
        $columns = max(1, min(6, (int) $atts['columns']));
        $rows    = ProductValuesService::read($pid, $def);

        $html = self::renderRepeater($def, $rows, $layout, $columns, sanitize_key($atts['size']) ?: 'large');
        if ($html === '') {
            return '';
        }
        wp_enqueue_style(Shortcodes::STYLE_HANDLE);
        return $html;
    }

    /** Repeater rows in one of three layouts. Empty string when no rows. */
    public static function renderRepeater(array $def, $rows, string $layout, int $columns, string $size = 'large'): string
    {
        $rows = is_array($rows) ? $rows : [];
        $subs = (array) ($def['sub_fields'] ?? []);
        if (!$rows || !$subs) {
            return '';
        }

        $cells = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rowHtml = '';
            foreach ($subs as $s) {
                $v = $row[$s['field_id']] ?? '';
                $rendered = Shortcodes::renderScalar($s, $v, $size);
                if ($rendered === '') {
                    continue;
                }
                $label = $s['field_type'] === 'media' ? '' : '<span class="cmf-cell-label">' . esc_html($s['field_title']) . '</span>';
                $rowHtml .= '<div class="cmf-cell cmf-cell--' . esc_attr(sanitize_html_class($s['field_type'])) . ' cmf-cell--' . esc_attr(sanitize_html_class($s['field_id'])) . '">' . $label . $rendered . '</div>';
            }
            if ($rowHtml !== '') {
                $cells[] = '<div class="cmf-row">' . $rowHtml . '</div>';
            }
        }

        if (!$cells) {
            return '';
        }

        $class = 'cmf-repeater cmf-repeater--' . $layout . ' cmf-repeater--' . sanitize_html_class($def['field_id']);
        if ($layout === 'grid') {
            $class .= ' cmf-cols-' . $columns;
        }

        return '<div class="' . esc_attr($class) . '">' . implode('', $cells) . '</div>';
    }

    /**
     * Front-end markup for one attachment. Images get srcset via
     * wp_get_attachment_image(); videos a <video> with poster; audio an
     * <audio>; anything else a download link with the filename.
     */
    public static function media(int $attachment_id, string $size = 'large', array $classes = []): string
    {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return '';
        }

        $kind  = MediaHelper::kind($attachment_id);
        $class = trim('cmf-media cmf-media--' . $kind . ' ' . implode(' ', array_map('sanitize_html_class', $classes)));
        $url   = (string) wp_get_attachment_url($attachment_id);
        $mime  = (string) get_post_mime_type($attachment_id);

        switch ($kind) {
            case 'image':
                return (string) wp_get_attachment_image($attachment_id, $size ?: 'large', false, ['class' => $class, 'loading' => 'lazy']);

            case 'video': {
                $poster = MediaHelper::posterUrl($attachment_id, 'large');
                return sprintf(
                    '<video class="%1$s" controls preload="metadata"%2$s><source src="%3$s" type="%4$s"></video>',
                    esc_attr($class),
                    $poster ? ' poster="' . esc_url($poster) . '"' : '',
                    esc_url($url),
                    esc_attr($mime)
                );
            }

            case 'audio':
                return sprintf(
                    '<audio class="%1$s" controls preload="metadata"><source src="%2$s" type="%3$s"></audio>',
                    esc_attr($class),
                    esc_url($url),
                    esc_attr($mime)
                );

            default: {
                $name = wp_basename((string) get_attached_file($attachment_id));
                return sprintf(
                    '<a class="%1$s" href="%2$s" download>%3$s</a>',
                    esc_attr($class),
                    esc_url($url),
                    esc_html($name ?: get_the_title($attachment_id))
                );
            }
        }
    }
}
