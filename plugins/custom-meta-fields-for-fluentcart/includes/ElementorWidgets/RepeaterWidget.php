<?php

namespace UpfluentCmf\ElementorWidgets;

defined('ABSPATH') || exit;

use UpfluentCmf\ProductValuesService;
use UpfluentCmf\Shortcodes;

/**
 * Loops a Repeater custom field's rows in a list, grid, or horizontal strip.
 * Each row renders its sub-fields, media sub-fields included.
 */
class RepeaterWidget extends BaseWidget
{
    protected function fieldType(): string
    {
        return 'repeater';
    }

    public function get_name()
    {
        return 'cmf-repeater';
    }

    public function get_title()
    {
        return esc_html__('Custom Field: Repeater', 'custom-meta-fields-for-fluentcart');
    }

    public function get_icon()
    {
        return 'eicon-bullet-list';
    }

    public function get_keywords()
    {
        return ['custom field', 'repeater', 'list', 'grid', 'loop', 'rows', 'fluentcart', 'cmf'];
    }

    // ─── Controls ───

    protected function register_controls()
    {
        $this->start_controls_section('section_content', [
            'label' => esc_html__('Content', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        if ($this->addFieldControls()) {
            // One sub-field picker per repeater, shown only for its own field.
            foreach ($this->typedFields() as $field) {
                $options = [];
                foreach ((array) ($field['sub_fields'] ?? []) as $sub) {
                    $options[$sub['field_id']] = $sub['field_title'] !== '' ? $sub['field_title'] : $sub['field_id'];
                }
                if (!$options) {
                    continue;
                }

                $this->add_control('sub_fields_' . $field['field_id'], [
                    'label'       => esc_html__('Sub-fields to show', 'custom-meta-fields-for-fluentcart'),
                    'type'        => \Elementor\Controls_Manager::SELECT2,
                    'multiple'    => true,
                    'options'     => $options,
                    'default'     => array_keys($options),
                    'condition'   => ['field_id' => $field['field_id']],
                    'description' => esc_html__('Leave empty to show all.', 'custom-meta-fields-for-fluentcart'),
                ]);
            }

            $this->add_control('max_rows', [
                'label'       => esc_html__('Maximum rows', 'custom-meta-fields-for-fluentcart'),
                'type'        => \Elementor\Controls_Manager::NUMBER,
                'min'         => 0,
                'default'     => 0,
                'description' => esc_html__('0 shows every row.', 'custom-meta-fields-for-fluentcart'),
            ]);

            $this->add_control('show_labels', [
                'label'        => esc_html__('Show sub-field labels', 'custom-meta-fields-for-fluentcart'),
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'default'      => 'yes',
                'return_value' => 'yes',
            ]);

            $this->add_control('image_size', [
                'label'       => esc_html__('Image size', 'custom-meta-fields-for-fluentcart'),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $this->imageSizeOptions(),
                'default'     => 'medium',
                'description' => esc_html__('Applies to media sub-fields holding images.', 'custom-meta-fields-for-fluentcart'),
            ]);

            $this->addEmptyControls();
        }

        $this->end_controls_section();

        // ── Layout ──

        $this->start_controls_section('section_layout', [
            'label' => esc_html__('Layout', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('layout', [
            'label'   => esc_html__('Layout', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                'list' => esc_html__('List (stacked rows)', 'custom-meta-fields-for-fluentcart'),
                'grid' => esc_html__('Grid', 'custom-meta-fields-for-fluentcart'),
                'row'  => esc_html__('Row (scrolls sideways)', 'custom-meta-fields-for-fluentcart'),
            ],
            'default' => 'list',
        ]);

        $this->add_responsive_control('columns', [
            'label'          => esc_html__('Columns', 'custom-meta-fields-for-fluentcart'),
            'type'           => \Elementor\Controls_Manager::SLIDER,
            'range'          => ['px' => ['min' => 1, 'max' => 6, 'step' => 1]],
            'default'        => ['size' => 3],
            'tablet_default' => ['size' => 2],
            'mobile_default' => ['size' => 1],
            'condition'      => ['layout' => 'grid'],
            'selectors'      => [
                '{{WRAPPER}} .cmf-repeater--grid' => 'grid-template-columns: repeat({{SIZE}}, minmax(0, 1fr));',
            ],
        ]);

        $this->add_responsive_control('card_width', [
            'label'      => esc_html__('Card width', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', '%'],
            'range'      => [
                'px' => ['min' => 120, 'max' => 800],
                '%'  => ['min' => 20, 'max' => 100],
            ],
            'default'    => ['unit' => 'px', 'size' => 320],
            'condition'  => ['layout' => 'row'],
            'selectors'  => [
                '{{WRAPPER}} .cmf-repeater--row > .cmf-row' => 'width: {{SIZE}}{{UNIT}}; flex: 0 0 auto;',
            ],
        ]);

        $this->add_responsive_control('column_gap', [
            'label'      => esc_html__('Column gap', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range'      => ['px' => ['min' => 0, 'max' => 100]],
            'selectors'  => ['{{WRAPPER}} .cmf-repeater' => 'column-gap: {{SIZE}}{{UNIT}};'],
        ]);

        $this->add_responsive_control('row_gap', [
            'label'      => esc_html__('Row gap', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range'      => ['px' => ['min' => 0, 'max' => 100]],
            'selectors'  => ['{{WRAPPER}} .cmf-repeater' => 'row-gap: {{SIZE}}{{UNIT}};'],
        ]);

        $this->add_responsive_control('cell_gap', [
            'label'      => esc_html__('Gap between sub-fields', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range'      => ['px' => ['min' => 0, 'max' => 60]],
            'selectors'  => ['{{WRAPPER}} .cmf-row' => 'gap: {{SIZE}}{{UNIT}};'],
        ]);

        $this->end_controls_section();

        // ── Style: card ──

        $this->start_controls_section('section_style_card', [
            'label' => esc_html__('Card', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(\Elementor\Group_Control_Background::get_type(), [
            'name'     => 'card_background',
            'types'    => ['classic', 'gradient'],
            'selector' => '{{WRAPPER}} .cmf-row',
        ]);

        $this->add_responsive_control('card_padding', [
            'label'      => esc_html__('Padding', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', 'em', '%'],
            'selectors'  => [
                '{{WRAPPER}} .cmf-row' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_responsive_control('card_radius', [
            'label'      => esc_html__('Border radius', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .cmf-row' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);

        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
            'name'     => 'card_border',
            'selector' => '{{WRAPPER}} .cmf-row',
        ]);

        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'card_shadow',
            'selector' => '{{WRAPPER}} .cmf-row',
        ]);

        $this->addAlignControl('card_align', '.cmf-row');

        $this->end_controls_section();

        // ── Style: labels ──

        $this->start_controls_section('section_style_label', [
            'label'     => esc_html__('Labels', 'custom-meta-fields-for-fluentcart'),
            'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
            'condition' => ['show_labels' => 'yes'],
        ]);

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'label_typography',
            'selector' => '{{WRAPPER}} .cmf-cell-label',
        ]);

        $this->add_control('label_color', [
            'label'     => esc_html__('Colour', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .cmf-cell-label' => 'color: {{VALUE}}; opacity: 1;'],
        ]);

        $this->add_responsive_control('label_spacing', [
            'label'      => esc_html__('Spacing', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', 'em'],
            'range'      => ['px' => ['min' => 0, 'max' => 40]],
            'selectors'  => ['{{WRAPPER}} .cmf-cell-label' => 'margin-bottom: {{SIZE}}{{UNIT}};'],
        ]);

        $this->end_controls_section();

        // ── Style: values ──

        $this->start_controls_section('section_style_value', [
            'label' => esc_html__('Values', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name'     => 'value_typography',
            'selector' => '{{WRAPPER}} .cmf-cell .cmf-field',
        ]);

        $this->add_control('value_color', [
            'label'     => esc_html__('Colour', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .cmf-cell .cmf-field' => 'color: {{VALUE}};'],
        ]);

        $this->add_control('link_color', [
            'label'     => esc_html__('Link colour', 'custom-meta-fields-for-fluentcart'),
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .cmf-cell .cmf-link' => 'color: {{VALUE}};'],
        ]);

        $this->end_controls_section();

        // ── Style: media inside cards ──

        $this->start_controls_section('section_style_media', [
            'label' => esc_html__('Media', 'custom-meta-fields-for-fluentcart'),
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ]);

        $this->add_responsive_control('cell_media_width', [
            'label'      => esc_html__('Width', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['%', 'px'],
            'range'      => [
                '%'  => ['min' => 10, 'max' => 100],
                'px' => ['min' => 20, 'max' => 800],
            ],
            'default'    => ['unit' => '%', 'size' => 100],
            'selectors'  => ['{{WRAPPER}} .cmf-cell .cmf-media' => 'width: {{SIZE}}{{UNIT}};'],
        ]);

        $this->add_control('cell_media_ratio', [
            'label'   => esc_html__('Aspect ratio', 'custom-meta-fields-for-fluentcart'),
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => [
                ''      => esc_html__('Original', 'custom-meta-fields-for-fluentcart'),
                '1'     => '1:1',
                '1.333' => '4:3',
                '1.5'   => '3:2',
                '1.777' => '16:9',
            ],
            'default'   => '',
            'selectors' => [
                '{{WRAPPER}} .cmf-cell .cmf-media' => 'aspect-ratio: {{VALUE}}; object-fit: cover;',
            ],
        ]);

        $this->add_responsive_control('cell_media_radius', [
            'label'      => esc_html__('Border radius', 'custom-meta-fields-for-fluentcart'),
            'type'       => \Elementor\Controls_Manager::DIMENSIONS,
            'size_units' => ['px', '%'],
            'selectors'  => [
                '{{WRAPPER}} .cmf-cell .cmf-media' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}}; overflow: hidden;',
            ],
        ]);

        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name'     => 'cell_media_shadow',
            'selector' => '{{WRAPPER}} .cmf-cell .cmf-media',
        ]);

        $this->end_controls_section();
    }

    // ─── Render ───

    protected function render()
    {
        $settings = $this->get_settings_for_display();

        $def = $this->resolveField($settings);
        if (!$def) {
            $this->editorNotice(__('Select a Repeater field for this widget.', 'custom-meta-fields-for-fluentcart'));
            return;
        }

        $post_id = $this->resolvePostId($settings);
        if (!$post_id) {
            $this->editorNotice(__('No FluentCart product in context. Set a Product ID, or place this widget in a product template.', 'custom-meta-fields-for-fluentcart'));
            return;
        }

        $rows = ProductValuesService::read($post_id, $def);
        $rows = is_array($rows) ? $rows : [];

        $max = isset($settings['max_rows']) ? absint($settings['max_rows']) : 0;
        if ($max > 0) {
            $rows = array_slice($rows, 0, $max);
        }

        $subs = $this->visibleSubFields($def, $settings);

        if (!$rows || !$subs) {
            $this->renderEmpty($settings, sprintf(
                /* translators: %s: field title */
                __('"%s" has no rows on this product.', 'custom-meta-fields-for-fluentcart'),
                $def['field_title']
            ));
            return;
        }

        $layout      = in_array($settings['layout'] ?? 'list', ['list', 'grid', 'row'], true) ? $settings['layout'] : 'list';
        $size        = isset($settings['image_size']) && $settings['image_size'] !== '' ? $settings['image_size'] : 'medium';
        $show_labels = ($settings['show_labels'] ?? 'yes') === 'yes';

        $cards = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = '';
            foreach ($subs as $sub) {
                $rendered = Shortcodes::renderScalar($sub, $row[$sub['field_id']] ?? '', $size);
                if ($rendered === '') {
                    continue;
                }

                $label = ($show_labels && $sub['field_type'] !== 'media' && $sub['field_title'] !== '')
                    ? '<span class="cmf-cell-label">' . esc_html($sub['field_title']) . '</span>'
                    : '';

                $cells .= sprintf(
                    '<div class="cmf-cell cmf-cell--%1$s cmf-cell--%2$s">%3$s%4$s</div>',
                    esc_attr(sanitize_html_class($sub['field_type'])),
                    esc_attr(sanitize_html_class($sub['field_id'])),
                    $label,
                    $rendered
                );
            }

            if ($cells !== '') {
                $cards .= '<div class="cmf-row">' . $cells . '</div>';
            }
        }

        if ($cards === '') {
            $this->renderEmpty($settings, __('Every row in this repeater is empty.', 'custom-meta-fields-for-fluentcart'));
            return;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $cards is built from escaped parts above
        printf(
            '<div class="cmf-repeater cmf-repeater--%1$s cmf-repeater--%2$s">%3$s</div>',
            esc_attr($layout),
            esc_attr(sanitize_html_class($def['field_id'])),
            $cards
        );
        // phpcs:enable
    }

    /**
     * Sub-field definitions to render, in their defined order, filtered by the
     * per-field picker. An empty selection means all of them.
     */
    private function visibleSubFields(array $def, array $settings): array
    {
        $all = (array) ($def['sub_fields'] ?? []);
        if (!$all) {
            return [];
        }

        $chosen = $settings['sub_fields_' . $def['field_id']] ?? [];
        $chosen = is_array($chosen) ? array_filter(array_map('sanitize_key', $chosen)) : [];

        if (!$chosen) {
            return $all;
        }

        return array_values(array_filter($all, function ($sub) use ($chosen) {
            return in_array($sub['field_id'], $chosen, true);
        }));
    }
}
