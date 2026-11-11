<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Watches FluentCart updates for core features that overlap this plugin.
 *
 * When the installed FluentCart version changes (auto-update, manual update or
 * upload), a background scan looks for signs that FluentCart now ships its own:
 *
 *   identifiers  GTIN / barcode columns on its product tables, or GTIN code
 *   fields       a custom product / checkout fields feature
 *
 * Anything found is stored (upfluent_cmf_core_features filter) and shown to admins
 * as a dismissible notice saying the data is safe and a migration update will
 * follow. Nothing is changed or migrated automatically. FluentCart's own product
 * schema needs no notice: ProductSchema merges into it.
 */
class CoreFeatureWatch
{
    const OPTION    = 'fct_cmf_core_watch';
    const CRON_HOOK = 'fct_cmf_core_watch_scan';
    const DISMISS   = 'fct_cmf_core_watch_dismiss';

    /** Signal => [code pattern, file extensions, label]. */
    const SIGNALS = [
        'identifiers' => ['/\b(gtin(8|12|13|14)?|global_unique_id|barcode)\b/i', ['php', 'js'], 'GTIN / barcode fields'],
        'fields'      => ['/\b(product_custom_fields?|custom_product_fields?|checkout_custom_fields?|customFields)\b/', ['php'], 'custom product or checkout fields'],
    ];

    /** Identifier-like column names on FluentCart's product tables. */
    const COLUMN_PATTERN = '/gtin|ean|upc|isbn|mpn|barcode|global_unique/i';

    public function register(): void
    {
        add_action('admin_init', [$this, 'maybeSchedule']);
        add_action('upgrader_process_complete', [$this, 'onUpgrade'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'scan']);
        add_action('admin_notices', [$this, 'notice']);
        add_action('admin_post_' . self::DISMISS, [$this, 'dismiss']);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /** Features found by the last scan, keyed by signal. */
    public static function found(): array
    {
        $state = get_option(self::OPTION, []);
        return (array) apply_filters('upfluent_cmf_core_features', is_array($state) ? ($state['found'] ?? []) : []);
    }

    /** Queue a scan whenever the installed FluentCart version differs from the last scanned one. */
    public function maybeSchedule(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }
        $state = get_option(self::OPTION, []);
        if (($state['version'] ?? '') !== FLUENTCART_VERSION) {
            $this->queue();
        }
    }

    /** Scan right after WordPress updates FluentCart. */
    public function onUpgrade($upgrader, $extra): void
    {
        if (($extra['type'] ?? '') !== 'plugin') {
            return;
        }
        $plugins = (array) ($extra['plugins'] ?? (isset($extra['plugin']) ? [$extra['plugin']] : []));
        if (in_array('fluent-cart/fluent-cart.php', $plugins, true)) {
            $this->queue();
        }
    }

    private function queue(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time(), self::CRON_HOOK);
        }
    }

    public function scan(): void
    {
        if (!defined('FLUENTCART_VERSION') || !defined('FLUENT_CART_DIR_FILE')) {
            return;
        }

        $found   = [];
        $columns = $this->identifierColumns();
        if ($columns) {
            $found['identifiers'][] = 'columns: ' . implode(', ', $columns);
        }

        foreach ($this->codeMatches(dirname(FLUENT_CART_DIR_FILE)) as $signal => $files) {
            foreach ($files as $file) {
                $found[$signal][] = $file;
            }
        }

        $state = get_option(self::OPTION, []);
        $state = is_array($state) ? $state : [];
        $state['version'] = FLUENTCART_VERSION;
        $state['checked'] = time();
        $state['found']   = $found;
        update_option(self::OPTION, $state, false);

        if ($found) {
            do_action('upfluent_cmf_core_features_detected', $found, FLUENTCART_VERSION);
        }
    }

    private function identifierColumns(): array
    {
        global $wpdb;
        $hits = [];
        foreach (['fct_product_variations', 'fct_product_details'] as $table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}{$table}"); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- fixed FluentCart table names
            foreach ((array) $cols as $col) {
                if (preg_match(self::COLUMN_PATTERN, $col)) {
                    $hits[] = $table . '.' . $col;
                }
            }
        }
        return $hits;
    }

    /** Up to three matching files per signal, relative to the FluentCart folder. */
    private function codeMatches(string $root): array
    {
        $matches = [];
        if (!is_dir($root)) {
            return $matches;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            $ext  = strtolower($file->getExtension());
            if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false
                || !in_array($ext, ['php', 'js'], true) || $file->getSize() > 4 * MB_IN_BYTES) {
                continue;
            }
            $wanted = [];
            foreach (self::SIGNALS as $signal => [, $exts]) {
                if (in_array($ext, $exts, true) && count($matches[$signal] ?? []) < 3) {
                    $wanted[] = $signal;
                }
            }
            if (!$wanted) {
                continue;
            }
            $code = (string) file_get_contents($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            foreach ($wanted as $signal) {
                if (preg_match(self::SIGNALS[$signal][0], $code)) {
                    $matches[$signal][] = ltrim(substr($path, strlen($root)), '/\\');
                }
            }
        }
        return $matches;
    }

    /** Signals found and not yet dismissed for the current FluentCart version. */
    public static function pending(): array
    {
        $state = get_option(self::OPTION, []);
        $found = array_intersect(array_keys(self::found()), array_keys(self::SIGNALS));
        return array_values(array_diff($found, (array) ($state['dismissed'][$state['version'] ?? ''] ?? [])));
    }

    public function notice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $new = self::pending();
        if (!$new) {
            return;
        }
        $state = get_option(self::OPTION, []);

        $version = (string) ($state['version'] ?? '');
        $dismiss = wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS), self::DISMISS);
        $labels  = array_map(function ($signal) {
            return self::SIGNALS[$signal][2];
        }, $new);
        $line = sprintf(
            /* translators: 1: FluentCart version, 2: list of features */
            __('FluentCart %1$s appears to include its own %2$s. Your custom field data is safe and everything keeps working as before. We will release an update that moves your values into FluentCart\'s own fields and avoids duplicates.', 'custom-meta-fields-for-fluentcart'),
            $version,
            implode(', ', $labels)
        );

        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Custom Meta Fields for FluentCart:', 'custom-meta-fields-for-fluentcart') . '</strong></p>';
        echo '<p>' . esc_html($line) . '</p>';
        printf(
            '<p>%s <a href="%s" target="_blank" rel="noopener">%s</a> &middot; <a href="%s">%s</a></p></div>',
            esc_html__('Questions?', 'custom-meta-fields-for-fluentcart'),
            esc_url('https://upfluent.io/support/'),
            esc_html__('Contact support', 'custom-meta-fields-for-fluentcart'),
            esc_url($dismiss),
            esc_html__('Dismiss', 'custom-meta-fields-for-fluentcart')
        );
    }

    public function dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'custom-meta-fields-for-fluentcart'), 403);
        }
        check_admin_referer(self::DISMISS);

        $state = get_option(self::OPTION, []);
        $state = is_array($state) ? $state : [];
        $state['dismissed'][$state['version'] ?? ''] = array_keys(self::found());
        update_option(self::OPTION, $state, false);

        wp_safe_redirect(wp_get_referer() ?: admin_url());
        exit;
    }
}
