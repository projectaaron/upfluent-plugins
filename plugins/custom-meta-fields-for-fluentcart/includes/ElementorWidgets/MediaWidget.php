<?php

namespace UpfluentCmf\ElementorWidgets;

defined('ABSPATH') || exit;

use UpfluentCmf\MediaHelper;
use UpfluentCmf\ProductValuesService;

/**
 * Renders a Media custom field: image, video, audio, or file link,
 * chosen automatically from the attachment's own type.
 */
class MediaWidget extends BaseWidget
{
    protected function fieldType(): string
    {
        return 'media';
    }

    public function get_name()
    {
        return 'cmf-media';
    }

    public function get_title()
    {
        return esc_html__('Custom Field: Media', 'custom-meta-fields-for-fluentcart');
    }

    public function get_icon()
    {
        return 'eicon-image';
    }

    public function get_keywords()
    {
        return ['custom field', 'media', 'image', 'video', 'audio', 'fluentcart', 'cmf'];
    }

    // ─── Controls ───

    protected function register_controls()
    {
        $this->start_controls_section('section_content', [
            'label' => esc_html__('Content', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        if ($this->addFieldControls()) {
            $this->add_control('image_size', [
                'label'       => esc_html__('Image size', 'custom-meta-fields-for-fluentcart'),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $this->imageSizeOptions(),
                'default'     => 'large',
                'description' => esc_html__('Applies to images only.', 'custom-meta-fields-for-fluentcart'),
            ]);

            $this->add_control('link_to', [
                'label'   => esc_html__('Link to', 'custom-meta-fields-for-fluentcart'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    ''       => esc_html__('None', 'custom-meta-fields-for-fluentcart'),
                    'file'   => esc_html__('Media file', 'custom-meta-fields-for-fluentcart'),
                    'post'   => esc_html__('Product page', 'custom-meta-fields-for-fluentcart'),
                    'custom' => esc_html__('Custom URL', 'custom-meta-fields-for-fluentcart'),
                ],
                'default' => '',
            ]);

            $this->add_control('custom_link', [
                'label'       => esc_html__('Custom URL', 'custom-meta-fields-for-fluentcart'),
                'type'        => \Elementor\Controls_Manager::URL,
                'condition'   => ['link_to' => 'custom'],
                'placeholder' => 'https://',
            ]);

            $this->add_control('caption_source', [
                'label'   => esc_html__('Caption', 'custom-meta-fields-for-fluentcart'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    ''         => esc_html__('None', 'custom-meta-fields-for-fluentcart'),
                    'caption'  => esc_html__('Attachment caption', 'custom-meta-fields-for-fluentcart'),
                    'title'    => esc_html__('Attachment title', 'custom-meta-fields-for-fluentcart'),
                    'filename' => esc_html__('File name', 'custom-meta-fields-for-fluentcart'),
                    'custom'   => esc_html__('Custom text', 'custom-meta-fields-for-fluentcart'),
                ],
                'default' => '',
            ]);

            $this->add_control('custom_caption', [
                'label'     => esc_html__('Caption text', 'custom-meta-fields-for-fluentcart'),
                'type'      => \Elementor\Controls_Manager::TEXT,
                'condition' => ['caption_source' => 'custom'],
            ]);

            $this->addEmptyControls();
        }

        $this->end_controls_section();

        // ── Video / audio playback ──

        $this->start_controls_section('section_playback', [
            'label' => esc_html__('Playback', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('playback_note', [
            'type'            => \Elementor\Controls_Manager::RAW_HTML,
            'raw'             => esc_html__('Used when the selected file is a video or audio file.', 'custom-meta-fields-for-fluentcart'),
            'content_classes' => 'elementor-descriptor',
        ]);

        foreach ([
            'video_controls'    => [esc_html__('Controls', 'custom-meta-fields-for-fluentcart'), 'yes'],
            'video_autoplay'    => [esc_html__('Autoplay', 'custom-meta-fields-for-fluentcart'), ''],
            'video_loop'        => [esc_html__('Loop', 'custom-meta-fields-for-fluentcart'), ''],
            'video_muted'       => [esc_html__('Muted', 'custom-meta-fields-for-fluentcart'), ''],
            'video_playsinline' => [esc_html__('Play inline', 'custom-meta-fields-for-fluentcart'), 'yes'],
        ] as $name => $conf) {
            $this->add_control($name, [
                'label'        => $conf[0],
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'default'      => $conf[1],
                'return_value' => 'yes',
            ]);
        }

        $this->add_control('video_poster', [
            'label'   => esc_html__('Video poster', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                'attachment' => esc_html__("The video's own featured image", 'custom-meta-fields-for-fluentcart'),
                'custom'     => esc_html__('Custom image', 'custom-meta-fields-for-fluentcart'),
                ''           => esc_html__('None', 'custom-meta-fields-for-fluentcart'),
            ],
            'default' => 'attachment',
        ]);

        $this->add_control('video_poster_image', [
            'label'     => esc_html__('Poster image', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::MEDIA,
            'condition' => ['video_poster' => 'custom'],
        ]);

        $this->end_controls_section();

        // ── Style: media ──

        $this->start_controls_section('section_style_media', [
            'label' => esc_html__('Media', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->addAlignControl('media_align', '.cmf-media-wrap');

        $this->add_responsive_control('media_width', [
            'label'      => esc_html__('Width', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['%', 'px', 'vw'],
            'range'      => [
                '%'  => ['min' => 10, 'max' => 100],
                'px' => ['min' => 20, 'max' => 1200],
            ],
            'selectors'  => [
                '{{WRAPPER}} .cmf-media' => 'width: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('media_max_width', [
            'label'      => esc_html__('Max width', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['%', 'px'],
            'range'      => [
                '%'  => ['min' => 10, 'max' => 100],
                'px' => ['min' => 20, 'max' => 1600],
            ],
            'selectors'  => [
                '{{WRAPPER}} .cmf-media' => 'max-width: {{SIZE}}{{UNIT}};',
            ],
        ]);

        $this->add_control('media_ratio', [
            'label'   => esc_html__('Aspect ratio', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                ''      => esc_html__('Original', 'custom-meta-fields-for-fluentcart'),
                '1'     => '1:1',
                '1.333' => '4:3',
                '1.5'   => '3:2',
                '1.777' => '16:9',
                '2.333' => '21:9',
            ],
            'default' => '',
            'selectors' => [
                '{{WRAPPER}} .cmf-media' => 'aspect-ratio: {{VALUE}}; object-fit: cover;',
            ],
        ]);

        $this->add_responsive_control('media_radius', [
            'label'      => esc_html__('Border radius', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .cmf-media' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
            ],
        ]);

        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
            'name'     => 'media_border',
            'selector' => '{{WRAPPER}} .cmf-media',
        ]);

        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'media_shadow',
            'selector' => '{{WRAPPER}} .cmf-media',
        ]);

        $this->start_controls_tabs('media_effects');

        $this->start_controls_tab('media_effects_normal', ['label' => esc_html__('Normal', 'custom-meta-fields-for-fluentcart')]);

        $this->add_group_control(\Elementor\Group_Control_Css_Filter::get_type(), [
            'name'     => 'media_filters',
            'selector' => '{{WRAPPER}} .cmf-media',
        ]);

        $this->add_control('media_opacity', [
            'label'     => esc_html__('Opacity', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => ['px' => ['min' => 0, 'max' => 1, 'step' => 0.05]],
            'selectors' => ['{{WRAPPER}} .cmf-media' => 'opacity: {{SIZE}};'],
        ]);

        $this->end_controls_tab();

        $this->start_controls_tab('media_effects_hover', ['label' => esc_html__('Hover', 'custom-meta-fields-for-fluentcart')]);

        $this->add_group_control(\Elementor\Group_Control_Css_Filter::get_type(), [
            'name'     => 'media_filters_hover',
            'selector' => '{{WRAPPER}} .cmf-media:hover',
        ]);

        $this->add_control('media_opacity_hover', [
            'label'     => esc_html__('Opacity', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::SLIDER,
            'range'     => ['px' => ['min' => 0, 'max' => 1, 'step' => 0.05]],
            'selectors' => ['{{WRAPPER}} .cmf-media:hover' => 'opacity: {{SIZE}};'],
        ]);

        $this->add_control('media_transition', [
            'label'      => esc_html__('Transition duration', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['s'],
            'range'      => ['s' => ['min' => 0, 'max' => 3, 'step' => 0.1]],
            'default'    => ['unit' => 's', 'size' => 0.3],
            'selectors'  => ['{{WRAPPER}} .cmf-media' => 'transition: all {{SIZE}}s;'],
        ]);

        $this->end_controls_tab();
        $this->end_controls_tabs();

        $this->end_controls_section();

        // ── Style: caption ──

        $this->start_controls_section('section_style_caption', [
            'label'     => esc_html__('Caption', 'custom-meta-fields-for-fluentcart'),
            'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
            'condition' => ['caption_source!' => ''],
        ]);

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'caption_typography',
            'selector' => '{{WRAPPER}} .cmf-caption',
        ]);

        $this->add_control('caption_color', [
            'label'     => esc_html__('Colour', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .cmf-caption' => 'color: {{VALUE}};'],
        ]);

        $this->add_responsive_control('caption_align', [
            'label'   => esc_html__('Alignment', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::CHOOSE,
            'options' => [
                'left'   => ['title' => esc_html__('Left', 'custom-meta-fields-for-fluentcart'),   'icon' => 'eicon-text-align-left'],
                'center' => ['title' => esc_html__('Center', 'custom-meta-fields-for-fluentcart'), 'icon' => 'eicon-text-align-center'],
                'right'  => ['title' => esc_html__('Right', 'custom-meta-fields-for-fluentcart'),  'icon' => 'eicon-text-align-right'],
            ],
            'selectors' => ['{{WRAPPER}} .cmf-caption' => 'text-align: {{VALUE}};'],
        ]);

        $this->add_responsive_control('caption_spacing', [
            'label'      => esc_html__('Spacing', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range'      => ['px' => ['min' => 0, 'max' => 80]],
            'selectors'  => ['{{WRAPPER}} .cmf-caption' => 'margin-top: {{SIZE}}{{UNIT}};'],
        ]);

        $this->end_controls_section();
    }

    // ─── Render ───

    protected function render()
    {
        $settings = $this->get_settings_for_display();

        $def = $this->resolveField($settings);
        if (!$def) {
            $this->editorNotice(__('Select a Media field for this widget.', 'custom-meta-fields-for-fluentcart'));
            return;
        }

        $post_id = $this->resolvePostId($settings);
        if (!$post_id) {
            $this->editorNotice(__('No FluentCart product in context. Set a Product ID, or place this widget in a product template.', 'custom-meta-fields-for-fluentcart'));
            return;
        }

        $attachment_id = (int) ProductValuesService::read($post_id, $def);
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            $this->renderEmpty($settings, sprintf(
                /* translators: %s: field title */
                __('"%s" has no file on this product.', 'custom-meta-fields-for-fluentcart'),
                $def['field_title']
            ));
            return;
        }

        $media = $this->mediaHtml($attachment_id, $settings);
        if ($media === '') {
            return;
        }

        $media  = $this->wrapInLink($media, $attachment_id, $post_id, $settings);
        $caption = $this->captionText($attachment_id, $settings);

        echo '<div class="cmf-field cmf-field--media cmf-media-wrap">';
        echo $media; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core attachment markup or tags built from escaped parts in mediaHtml()/wrapInLink()
        if ($caption !== '') {
            echo '<div class="cmf-caption">' . esc_html($caption) . '</div>';
        }
        echo '</div>';
    }

    /** Markup for the attachment, chosen by its own kind. */
    private function mediaHtml(int $attachment_id, array $settings): string
    {
        $kind = MediaHelper::kind($attachment_id);
        $url  = (string) wp_get_attachment_url($attachment_id);
        $mime = (string) get_post_mime_type($attachment_id);

        if ($kind === 'image') {
            $size = isset($settings['image_size']) && $settings['image_size'] !== '' ? $settings['image_size'] : 'large';
            return (string) wp_get_attachment_image($attachment_id, $size, false, [
                'class'   => 'cmf-media cmf-media--image',
                'loading' => 'lazy',
            ]);
        }

        if ($kind === 'video') {
            $attrs = ' preload="metadata"';
            foreach ([
                'video_controls'    => 'controls',
                'video_autoplay'    => 'autoplay',
                'video_loop'        => 'loop',
                'video_muted'       => 'muted',
                'video_playsinline' => 'playsinline',
            ] as $setting => $attribute) {
                if (($settings[$setting] ?? '') === 'yes') {
                    $attrs .= ' ' . $attribute;
                }
            }

            // Autoplay only works muted in every current browser.
            if (($settings['video_autoplay'] ?? '') === 'yes' && ($settings['video_muted'] ?? '') !== 'yes') {
                $attrs .= ' muted';
            }

            $poster = '';
            if (($settings['video_poster'] ?? '') === 'attachment') {
                $poster = MediaHelper::posterUrl($attachment_id, 'large');
            } elseif (($settings['video_poster'] ?? '') === 'custom' && !empty($settings['video_poster_image']['url'])) {
                $poster = (string) $settings['video_poster_image']['url'];
            }

            return sprintf(
                '<video class="cmf-media cmf-media--video"%1$s%2$s><source src="%3$s" type="%4$s"></video>',
                $attrs,
                $poster !== '' ? ' poster="' . esc_url($poster) . '"' : '',
                esc_url($url),
                esc_attr($mime)
            );
        }

        if ($kind === 'audio') {
            $attrs = ' preload="metadata"';
            if (($settings['video_controls'] ?? 'yes') === 'yes') {
                $attrs .= ' controls';
            }
            if (($settings['video_loop'] ?? '') === 'yes') {
                $attrs .= ' loop';
            }

            return sprintf(
                '<audio class="cmf-media cmf-media--audio"%1$s><source src="%2$s" type="%3$s"></audio>',
                $attrs,
                esc_url($url),
                esc_attr($mime)
            );
        }

        $name = wp_basename((string) get_attached_file($attachment_id));

        return sprintf(
            '<a class="cmf-media cmf-media--file" href="%1$s" download>%2$s</a>',
            esc_url($url),
            esc_html($name !== '' ? $name : get_the_title($attachment_id))
        );
    }

    /** Wraps the markup in an anchor when a link target is configured. */
    private function wrapInLink(string $html, int $attachment_id, int $post_id, array $settings): string
    {
        $link_to = $settings['link_to'] ?? '';
        $href    = '';
        $extra   = '';

        if ($link_to === 'file') {
            $href = (string) wp_get_attachment_url($attachment_id);
        } elseif ($link_to === 'post') {
            $href = (string) get_permalink($post_id);
        } elseif ($link_to === 'custom' && !empty($settings['custom_link']['url'])) {
            $href = (string) $settings['custom_link']['url'];
            if (!empty($settings['custom_link']['is_external'])) {
                $extra .= ' target="_blank"';
            }
            if (!empty($settings['custom_link']['nofollow'])) {
                $extra .= ' rel="nofollow"';
            }
        }

        if ($href === '') {
            return $html;
        }

        // A file link is already an anchor; do not nest one inside another.
        if (strpos($html, '<a ') === 0) {
            return $html;
        }

        return '<a class="cmf-media-link" href="' . esc_url($href) . '"' . $extra . '>' . $html . '</a>';
    }

    private function captionText(int $attachment_id, array $settings): string
    {
        switch ($settings['caption_source'] ?? '') {
            case 'caption':
                return (string) wp_get_attachment_caption($attachment_id);
            case 'title':
                return (string) get_the_title($attachment_id);
            case 'filename':
                return wp_basename((string) get_attached_file($attachment_id));
            case 'custom':
                return (string) ($settings['custom_caption'] ?? '');
        }

        return '';
    }
}
