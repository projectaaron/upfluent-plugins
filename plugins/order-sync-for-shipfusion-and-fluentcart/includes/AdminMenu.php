<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * The settings screen and the two ways of reaching it.
 *
 * FluentCart's admin chrome is its own sidebar, rendered server side; a
 * WordPress submenu alone is not visible from inside the app. So the page is
 * registered both ways:
 *
 *   fluent_cart/global_admin_menu_more_items  adds an entry to FluentCart's
 *                                             "More" group, beside other add-ons
 *   add_submenu_page()                        keeps it in the WordPress sidebar
 */
class AdminMenu
{
    const PAGE_SLUG = 'upfluent-sf';
    const MENU_KEY  = 'upfluent_sf';

    /** FluentCart's submenu keys in its own order, captured before anything is added. */
    private $fluentCartOrder = [];

    public function register(): void
    {
        add_filter('fluent_cart/global_admin_menu_more_items', [$this, 'addMenuItem'], 10, 2);
        add_action('admin_menu', [$this, 'addSubmenuPage'], 99);
        add_action('admin_menu', [$this, 'restoreSubmenuOrder'], PHP_INT_MAX);
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    public static function isCurrentPage(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === self::PAGE_SLUG;
    }

    /**
     * Adds the entry to FluentCart's own sidebar, under More. Item shape:
     * label, link, and a permission array checked by PermissionManager.
     */
    public function addMenuItem($items, $args = [])
    {
        if (!is_array($items)) {
            return $items;
        }
        $items[self::MENU_KEY] = [
            'label'      => __('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'),
            'link'       => self::url(),
            'permission' => ['store/settings'],
        ];
        return $items;
    }

    public function addSubmenuPage(): void
    {
        global $submenu;
        $this->fluentCartOrder = array_keys($submenu['fluent-cart'] ?? []);

        add_submenu_page(
            'fluent-cart',
            __('Order Sync for ShipFusion and FluentCart', 'order-sync-for-shipfusion-and-fluentcart'),
            __('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'),
            Permissions::menuCapability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Workaround: add_submenu_page() ksort()s the parent's items, which
     * alphabetises FluentCart's menu and repoints its top-level link; WordPress
     * has no option to append without sorting. Put FluentCart's items back in
     * their order, with added pages after them. Can go when the page is reached
     * only through FluentCart's own menu.
     */
    public function restoreSubmenuOrder(): void
    {
        global $submenu;
        if (!$this->fluentCartOrder || empty($submenu['fluent-cart']) || !is_array($submenu['fluent-cart'])) {
            return;
        }
        $items  = $submenu['fluent-cart'];
        $sorted = [];
        foreach ($this->fluentCartOrder as $key) {
            if (isset($items[$key])) {
                $sorted[$key] = $items[$key];
                unset($items[$key]);
            }
        }
        foreach ($items as $key => $item) {
            $sorted[$key] = $item;
        }
        $submenu['fluent-cart'] = $sorted; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    }

    /** The admin app mounts here (assets/admin.js); it explains itself to people who can't change settings. */
    public function render(): void
    {
        echo '<div id="upfluent-sf-app" class="upfluent-sf-app"><noscript>'
            . esc_html__('This screen needs JavaScript.', 'order-sync-for-shipfusion-and-fluentcart')
            . '</noscript></div>';
    }
}
