<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * The settings screen. It is reached only from the FluentCart submenu in the
 * WordPress sidebar (owner, 26 Nov 2026): no entry in FluentCart's own More
 * menu and no FluentHub app.
 *
 * The page itself is a plain WordPress screen with a link back into FluentCart.
 * See render() for why FluentCart's own menu is not reproduced on it.
 */
class AdminMenu
{
    const PAGE_SLUG = 'fct-custom-meta';

    /** FluentCart's submenu keys in its own order, captured before anything is added. */
    private $fluentCartOrder = [];

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addSubmenuPage'], 99);
        add_action('admin_menu', [$this, 'restoreSubmenuOrder'], PHP_INT_MAX);
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE_SLUG);
    }

    /** The page, under FluentCart in the WordPress sidebar. */
    public function addSubmenuPage(): void
    {
        global $submenu;
        $this->fluentCartOrder = array_keys($submenu['fluent-cart'] ?? []);

        add_submenu_page(
            'fluent-cart',
            __('Custom Meta Fields', 'custom-meta-fields-for-fluentcart'),
            __('Custom Fields', 'custom-meta-fields-for-fluentcart'),
            Permissions::menuCapability(),
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * add_submenu_page() ksort()s the parent's items. FluentCart keys its own
     * items by name ('dashboard', 'orders', ...), so that sort alphabetises its
     * menu and puts numerically keyed pages (ours)
     * first, which also repoints the top-level "FluentCart" link. Put
     * FluentCart's items back in their order, with added pages after them.
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
        $submenu['fluent-cart'] = $sorted;
    }

    /**
     * A plain WordPress admin screen. FluentCart's own menu is deliberately not
     * rendered here: AdminHelper::pushGlobalAdminAssets() enqueues
     * assets/admin/global_admin.css, which FluentCart 1.6.3 does not ship, so
     * the markup arrives unstyled and its inline SVGs render full size. The way
     * back is a link in the page header instead.
     */
    public function render(): void
    {
        if (!Permissions::canManageDefinitions()) {
            echo '<div class="fct-cm-app"><section class="fct-cm-card fct-cm-card--note"><p>'
                . esc_html__('You do not have permission to manage custom fields.', 'custom-meta-fields-for-fluentcart')
                . '</p></section></div>';
            return;
        }

        echo ReviewNotice::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in ReviewNotice::html()
        echo '<div id="fct-custom-meta-app"></div>';
        echo '<div class="fct-cm-app fct-cm-app--legal"><p class="fct-cm-legal">'
            . esc_html__('Custom Meta Fields for FluentCart is free and open source software, licensed under the GPL v2 or later. It is provided as is, without warranty of any kind.', 'custom-meta-fields-for-fluentcart')
            . '</p></div>';
    }

    public static function isCurrentPage(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === self::PAGE_SLUG;
    }
}
