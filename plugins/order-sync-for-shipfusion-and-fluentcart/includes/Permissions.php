<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * Capability checks that respect FluentCart's role model.
 *
 * FluentCart Pro grants shop roles (manager, worker, accountant) permissions
 * through PermissionManager without manage_options, so PermissionManager is
 * asked first and manage_options is the fallback when FluentCart is not loaded.
 * Permission names: store/settings, products/view, products/edit, orders/view,
 * customers/view, ... (see docs/fluentcart/).
 */
class Permissions
{
    const PM = '\FluentCart\App\Services\Permission\PermissionManager';

    /** Can change this plugin's settings (site-wide configuration). */
    public static function canManageSettings(): bool
    {
        return self::has('store/settings');
    }

    /** Can read orders. */
    public static function canViewOrder(): bool
    {
        return self::has('orders/view');
    }

    /**
     * WP capability for the settings submenu. Mirrors FluentCart's own menu:
     * administrators use manage_options, Pro shop roles use fluent_cart_admin.
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
