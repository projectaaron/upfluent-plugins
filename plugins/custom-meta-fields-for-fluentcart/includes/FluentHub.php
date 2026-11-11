<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Loads the settings screen inside the FluentHub portal (the unified
 * workspace shell) when FluentHub is active and its Unified UI is on.
 *
 * Contract, read from fluent-toolkit 2.1.x `Classes/UnifiedUiHandler.php`:
 *
 *  - The shell is active when `_fluent_kit_settings['uinified_ui'] === 'yes'`
 *    (the key is misspelled upstream; it must be matched as-is).
 *  - Apps register through the `fluent_toolkit/unified_apps` filter, which is
 *    applied inside FluentHub's `init` (priority 10) callback. The array is
 *    keyed by the admin page slug (`$plugin_page`), and the shell wraps the
 *    current request only when `isset($apps[$plugin_page])`, so the key must
 *    be exactly this plugin's `?page=` slug.
 *  - A sub-menu page must declare `parent_slug` so FluentHub can compute the
 *    right load-hook name with get_plugin_page_hookname().
 *  - Descriptor keys the shell reads: disabled, title, icon (image URL),
 *    logo, items ([key => title, url, icon_svg, sub_menu, external]),
 *    has_dark_mode, dashboard_url, group ('others' = flat link), parent_slug.
 *    Items whose URL carries a hash get `data-fui-hash`, and the shell's JS
 *    marks them active on hashchange — which is how the two tabs light up.
 *  - With `merge_admin_menus` on, FluentHub also lists every enabled app under
 *    its own WordPress menu (`fluent_toolkit/admin_apps`, using dashboard_url)
 *    and hides the app's own WordPress menu entry.
 *
 * Everything fails silently when misregistered (the page simply is not
 * wrapped), so the filter is added from plugins_loaded, independent of
 * FluentCart detection, to be certain it exists before FluentHub's init.
 */
class FluentHub
{
    public function register(): void
    {
        add_filter('fluent_toolkit/unified_apps', [$this, 'registerApp']);
    }

    /** FluentHub (plugin folder fluent-toolkit) is installed and active. */
    public static function isActive(): bool
    {
        return defined('FLUENT_TOOLKIT_VERSION');
    }

    /** The unified portal is switched on in FluentHub's settings. */
    public static function unifiedUiEnabled(): bool
    {
        if (!self::isActive()) {
            return false;
        }
        $settings = get_option('_fluent_kit_settings', []);
        return is_array($settings) && (($settings['uinified_ui'] ?? '') === 'yes');
    }

    /** Whether the current request is our page rendered inside the portal. */
    public static function isPortalRequest(): bool
    {
        return self::unifiedUiEnabled() && AdminMenu::isCurrentPage();
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
            'disabled'      => !Permissions::canManageDefinitions(),
            'title'         => __('Custom Fields', 'custom-meta-fields-for-fluentcart'),
            'icon'          => UPFLUENT_CMF_URL . 'assets/icon.svg',
            'items'         => [
                'product'  => [
                    'title'    => __('Product Fields', 'custom-meta-fields-for-fluentcart'),
                    'url'      => $base . '#product',
                    'icon_svg' => self::icon('products'),
                ],
                'checkout' => [
                    'title'    => __('Checkout Fields', 'custom-meta-fields-for-fluentcart'),
                    'url'      => $base . '#checkout',
                    'icon_svg' => self::icon('orders'),
                ],
                'commerce' => [
                    'title'    => __('Back to Commerce', 'custom-meta-fields-for-fluentcart'),
                    'url'      => admin_url('admin.php?page=fluent-cart#/'),
                    'icon_svg' => self::icon('dashboard'),
                ],
            ],
            'has_dark_mode' => true,
            'parent_slug'   => 'fluent-cart',
            'dashboard_url' => $base . '#product',
        ];

        return $apps;
    }

    /**
     * Sidebar item icon: FluentHub's own set when available (same strokes as
     * the Commerce items), otherwise a neutral inline fallback.
     */
    private static function icon(string $key): string
    {
        if (class_exists('\FluentToolkit\Classes\UnifiedUi\Icons') && method_exists('\FluentToolkit\Classes\UnifiedUi\Icons', 'get')) {
            $svg = \FluentToolkit\Classes\UnifiedUi\Icons::get($key);
            if (is_string($svg) && $svg !== '') {
                return $svg;
            }
        }
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 10h8M8 14h5"/></svg>';
    }
}
