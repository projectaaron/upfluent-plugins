<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Loads the settings screen inside the FluentHub portal (the unified
 * workspace shell) when FluentHub is active and its Unified UI is on.
 *
 * Contract, read from fluent-toolkit 2.1.x `Classes/UnifiedUiHandler.php`
 * (same as plugins/custom-meta-fields):
 *
 *  - The shell is active when `_fluent_kit_settings['uinified_ui'] === 'yes'`
 *    (the key is misspelled upstream; it must be matched as-is).
 *  - Apps register through the `fluent_toolkit/unified_apps` filter, applied
 *    inside FluentHub's `init` (priority 10). The array is keyed by the admin
 *    page slug, and the shell wraps the request only when that key matches.
 *  - A sub-menu page must declare `parent_slug` so FluentHub can compute the
 *    load-hook name with get_plugin_page_hookname().
 *  - A disabled app is wrapped but not listed (FluentHub 2.2.1); this one is
 *    always disabled so it shows only under FluentCart's menu.
 *  - Descriptor keys the shell reads: disabled, title, icon (image URL), items
 *    ([key => title, url, icon_svg]), has_dark_mode, dashboard_url, parent_slug.
 *
 * Everything fails silently when misregistered, so the filter is added from
 * plugins_loaded, independent of FluentCart detection.
 */
class FluentHub
{
    public function register(): void
    {
        add_filter('fluent_toolkit/unified_apps', [$this, 'registerApp']);
    }

    /**
     * fluent_toolkit/unified_apps
     *
     * @param array $apps keyed by page slug
     * @return array
     */
    public function registerApp($apps)
    {
        if (!is_array($apps)) {
            return $apps;
        }

        $base = AdminMenu::url();

        $apps[AdminMenu::PAGE_SLUG] = [
            // Always "disabled": FluentHub 2.2.1 then leaves it out of its own
            // menus (UnifiedUiHandler: fluent_toolkit/admin_apps and the shell's
            // app list skip disabled apps) but still wraps this page in the shell
            // (it checks only that the page slug is registered). The screen is
            // reached from FluentCart's menu, so it isn't listed a second time.
            'disabled'      => true,
            'title'         => __('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'),
            'icon'          => UPFLUENT_SF_URL . 'images/ui/icon.svg',
            'items'         => [
                'shipfusion' => [
                    'title'    => __('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'),
                    'url'      => $base,
                    'icon_svg' => self::icon('orders'),
                ],
                'commerce'   => [
                    'title'    => __('Back to Commerce', 'order-sync-for-shipfusion-and-fluentcart'),
                    'url'      => admin_url('admin.php?page=fluent-cart#/'),
                    'icon_svg' => self::icon('dashboard'),
                ],
            ],
            'has_dark_mode' => true,
            'parent_slug'   => 'fluent-cart',
            'dashboard_url' => $base,
        ];

        return $apps;
    }

    /**
     * Sidebar item icon: FluentHub's own set when available, otherwise a
     * neutral inline fallback.
     */
    private static function icon(string $key): string
    {
        if (class_exists('\FluentToolkit\Classes\UnifiedUi\Icons') && method_exists('\FluentToolkit\Classes\UnifiedUi\Icons', 'get')) {
            $svg = \FluentToolkit\Classes\UnifiedUi\Icons::get($key);
            if (is_string($svg) && $svg !== '') {
                return $svg;
            }
        }
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="7" width="12" height="10" rx="1"/><path d="M15 10h3l3 3v4h-6M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg>';
    }
}
