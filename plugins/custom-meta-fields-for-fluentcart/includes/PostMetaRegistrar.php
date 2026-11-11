<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Registers every field definition with register_post_meta() on the
 * fluent-products post type, and runs the one-time migration of values
 * stored by early pre-release builds in FluentCart's product meta table.
 */
class PostMetaRegistrar
{
    const DB_VERSION_OPTION = 'fct_custom_meta_db_version';
    const DB_VERSION        = '4';

    public function register(): void
    {
        add_action('init', [$this, 'registerMeta'], 20);
        add_action('init', [$this, 'maybeMigrate'], 21);
    }

    public function registerMeta(): void
    {
        foreach (FieldsService::productFields() as $def) {
            register_post_meta(ProductValuesService::PRODUCT_CPT, $def['field_id'], self::metaArgs($def));
        }
    }

    /** register_post_meta() args for a definition. Media and repeater adjust them through upfluent_cmf_register_meta_args. */
    public static function metaArgs(array $def): array
    {
        $args = [
            'type'              => 'string',
            'default'           => '',
            'description'       => $def['field_title'],
            'single'            => true,
            'show_in_rest'      => true,
            'sanitize_callback' => function ($value) use ($def) {
                return ProductValuesService::sanitizeValue($value, $def);
            },
            'auth_callback'     => function () {
                return Permissions::canEditProduct();
            },
        ];

        $args = apply_filters('upfluent_cmf_register_meta_args', $args, $def);
        return is_array($args) ? $args : [];
    }

    public function maybeMigrate(): void
    {
        if (get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }
        // Only administrators trigger the migration, and only once.
        if (!current_user_can('manage_options') && !wp_doing_cron()) {
            return;
        }
        ProductValuesService::migrateLegacyValues();
        self::renameIdentifierMeta();
        self::removeLicenceData();
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    /** The former Pro edition's licence and its daily check; everything is free since 1.1.0. */
    private static function removeLicenceData(): void
    {
        delete_option('__fct_cmf_license');
        wp_clear_scheduled_hook('fct_cmf_license_check');
    }

    /** Pre-release builds used unprefixed identifier meta keys (_cmf_* to _fct_cmf_*). */
    private static function renameIdentifierMeta(): void
    {
        global $wpdb;
        foreach (['_cmf_identifiers' => ProductIdentifiers::META_KEY, '_cmf_gtin' => ProductIdentifiers::LOOKUP_KEY] as $old => $new) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id SET pm.meta_key = %s WHERE pm.meta_key = %s AND p.post_type = %s",
                $new,
                $old,
                ProductValuesService::PRODUCT_CPT
            ));
        }
    }
}
