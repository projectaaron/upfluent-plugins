<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Capability checks that respect FluentCart's role model.
 *
 * FluentCart Pro grants shop roles (manager, worker, accountant) product
 * permissions through PermissionManager without manage_options, so we
 * ask PermissionManager first and fall back to manage_options only when
 * FluentCart is not loaded.
 */
class Permissions
{
    const PM = '\FluentCart\App\Services\Permission\PermissionManager';

    /** Can read custom field values on a product. */
    public static function canViewProduct(): bool
    {
        return self::has('products/view');
    }

    /** Can write custom field values on a product. */
    public static function canEditProduct(): bool
    {
        return self::has('products/edit');
    }

    /** Can read an order, and so the checkout field values stored on it. */
    public static function canViewOrder(): bool
    {
        return self::has('orders/view');
    }

    /**
     * Can load FluentCart's admin widget slots. FluentCart gates its
     * /widgets endpoint on customers/view or orders/view, not products/*.
     */
    public static function canLoadEditorWidgets(): bool
    {
        return self::has('customers/view') || self::has('orders/view');
    }

    /** Can create / edit / delete field definitions (site-wide config). */
    public static function canManageDefinitions(): bool
    {
        return self::has('store/settings');
    }

    /**
     * WP capability to use for the settings submenu. Mirrors FluentCart's own
     * menu: administrators use manage_options, Pro shop roles use
     * fluent_cart_admin.
     */
    public static function menuCapability(): string
    {
        if (current_user_can('manage_options')) {
            return 'manage_options';
        }

        if (defined('FLUENTCART_PRO_PLUGIN_VERSION') && class_exists(self::PM)) {
            return constant(self::PM . '::ADMIN_CAP');
        }

        return 'manage_options';
    }

    private static function has(string $permission): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }

        // PermissionManager grants every permission to manage_options users
        // and reads Pro shop roles for everyone else.
        if (class_exists(self::PM) && method_exists(self::PM, 'hasPermission')) {
            return (bool) call_user_func([self::PM, 'hasPermission'], $permission);
        }

        return current_user_can('manage_options');
    }
}
