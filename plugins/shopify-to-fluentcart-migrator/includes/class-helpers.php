<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * Small shared helpers: FluentCart detection, permissions, money.
 */
class Helpers
{
    const POST_TYPE     = 'fluent-products';
    const GALLERY_META  = 'fluent-products-gallery-image';
    const CATEGORY_TAX  = 'product-categories';
    const BRAND_TAX     = 'product-brands';
    const TAG_TAX       = 'product-tags';
    const HANDLE_META   = '_s2fc_shopify_handle';
    const SOURCE_META   = '_s2fc_shopify_source';

    /** FluentCart is active and its product tables exist. */
    public static function fluentcart_ready(): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        global $wpdb;
        if (!defined('FLUENTCART_VERSION') && !class_exists('\FluentCart\App\App') && !post_type_exists(self::POST_TYPE)) {
            return $ready = false;
        }
        foreach (['fct_product_details', 'fct_product_variations'] as $table) {
            $name = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($name))) !== $name) {
                return $ready = false;
            }
        }
        return $ready = true;
    }

    public static function fluentcart_version(): string
    {
        return defined('FLUENTCART_VERSION') ? (string) FLUENTCART_VERSION : '';
    }

    /** Custom Meta Fields for FluentCart (free or Pro) is active with its GTIN module. */
    public static function meta_fields_active(): bool
    {
        return class_exists('\FctCustomMeta\ProductIdentifiers');
    }

    /** Custom Meta Fields is installed (folder present) but not active. */
    public static function meta_fields_installed(): bool
    {
        foreach (['custom-meta-fields-for-fluentcart', 'custom-meta-fields-for-fluentcart-pro', 'fct-custom-meta-fields', 'fct-custom-meta-fields-pro'] as $dir) {
            if (file_exists(WP_PLUGIN_DIR . '/' . $dir . '/fct-custom-meta-fields.php')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Who may run the migrator: FluentCart's products/create permission when
     * FluentCart can answer, otherwise manage_options.
     */
    public static function user_can_migrate(): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        $pm = '\FluentCart\App\Services\Permission\PermissionManager';
        if (class_exists($pm) && method_exists($pm, 'hasPermission')) {
            return (bool) call_user_func([$pm, 'hasPermission'], ['products/create', 'products/edit']);
        }
        return false;
    }

    /**
     * The menu is registered with a capability every FluentCart product
     * manager has, and the screen itself re-checks user_can_migrate(), so
     * the link and the page agree on who may use it.
     */
    public static function menu_capability(): string
    {
        return self::user_can_migrate() ? 'read' : 'manage_options';
    }

    /** Dollars string ("12.50") to integer cents. */
    public static function to_cents($value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }
        $value = preg_replace('/[^0-9.\-]/', '', $value);
        return (int) round(((float) $value) * 100);
    }

    public static function format_money(int $cents): string
    {
        $symbol = '$';
        $helper = '\\FluentCart\\App\\Helpers\\Helper';
        if (class_exists($helper) && method_exists($helper, 'getCurrencySign')) {
            try {
                $symbol = html_entity_decode((string) call_user_func([$helper, 'getCurrencySign']));
            } catch (\Throwable $e) {
                $symbol = '$';
            }
        }
        return $symbol . number_format($cents / 100, 2);
    }

    /** Grams to the requested unit, rounded to 2 decimals. */
    public static function convert_grams(float $grams, string $unit): float
    {
        switch ($unit) {
            case 'kg':
                return round($grams / 1000, 3);
            case 'lb':
                return round($grams / 453.59237, 2);
            case 'oz':
                return round($grams / 28.349523125, 2);
            default:
                return round($grams, 2);
        }
    }

    public static function truthy($value): bool
    {
        $v = strtolower(trim((string) $value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    /** Edit link for an imported product; FluentCart products edit through post.php. */
    public static function edit_url(int $post_id): string
    {
        $link = get_edit_post_link($post_id, 'raw');
        return $link ? $link : admin_url('post.php?post=' . $post_id . '&action=edit');
    }

    public static function admin_url(array $args = []): string
    {
        return add_query_arg($args, admin_url('admin.php?page=' . S2FC_PAGE));
    }
}
