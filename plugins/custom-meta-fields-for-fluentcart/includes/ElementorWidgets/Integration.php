<?php

namespace UpfluentCmf\ElementorWidgets;

defined('ABSPATH') || exit;

/**
 * Elementor integration.
 *
 * Registers a "FluentCart Fields" panel category and two widgets:
 *   Custom Field: Media     — renders a Media field (image / video / audio / file)
 *   Custom Field: Repeater  — loops a Repeater field's rows
 *
 * Why two widgets with a field picker rather than one widget per field:
 * Elementor instantiates a widget by its PHP class name (Widget_Base::get_class_name),
 * so one class can only ever be one widget type. Generating a class per field would
 * need eval'd classes, and a page that used a since-deleted field would render
 * Elementor's "widget not found" placeholder. A field picker keeps published pages
 * intact when a field is renamed or removed.
 *
 * Scalar fields (text, number, date, colour, URL, email) are deliberately not
 * covered here: Elementor Pro's own "Post Custom Field" dynamic tag already reads
 * them, because every value is stored as post meta keyed by the field ID.
 */
class Integration
{
    const CATEGORY = 'fct-custom-meta';

    public function register(): void
    {
        // Elementor sets this once its core has loaded.
        if (!did_action('elementor/loaded')) {
            return;
        }

        add_action('elementor/elements/categories_registered', [$this, 'registerCategory']);
        add_action('elementor/widgets/register', [$this, 'registerWidgets']);
    }

    /**
     * @param \Elementor\Elements_Manager $elements_manager
     */
    public function registerCategory($elements_manager): void
    {
        if (!is_object($elements_manager) || !method_exists($elements_manager, 'add_category')) {
            return;
        }

        $elements_manager->add_category(self::CATEGORY, [
            'title' => __('FluentCart Fields', 'custom-meta-fields-for-fluentcart'),
            'icon'  => 'eicon-database',
        ]);
    }

    /**
     * @param \Elementor\Widgets_Manager $widgets_manager
     */
    public function registerWidgets($widgets_manager): void
    {
        if (!is_object($widgets_manager) || !method_exists($widgets_manager, 'register')) {
            return;
        }

        // Required only now: these extend \Elementor\Widget_Base, which does not
        // exist unless Elementor is running.
        require_once __DIR__ . '/BaseWidget.php';

        require_once __DIR__ . '/MediaWidget.php';
        require_once __DIR__ . '/RepeaterWidget.php';
        $widgets_manager->register(new MediaWidget());
        $widgets_manager->register(new RepeaterWidget());
    }
}
