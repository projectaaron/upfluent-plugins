<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * One import session per user. The parsed products live in a private
 * folder under wp-content/uploads as JSON Lines (one product per line) with
 * a small offset index, so a single product can be read without decoding
 * the whole catalog. The uploaded CSV itself is deleted as soon as it has
 * been parsed. Only counts and per-product results are kept in one
 * non-autoloaded option per user.
 */
class Session
{
    const OPTION_PREFIX = 's2fc_session_';

    public static function option_name(): string
    {
        return self::OPTION_PREFIX . get_current_user_id();
    }

    public static function dir(): string
    {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 's2fc-migrator';
    }

    public static function ensure_dir(): string
    {
        return self::protect_dir(self::dir());
    }

    /**
     * Create the folder and keep it out of reach of the web server on
     * Apache. Files inside also get random names, which is the only
     * protection on nginx hosts.
     */
    private static function protect_dir(string $dir): string
    {
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n");
        }
        return $dir;
    }

    /** Route wp_handle_upload() into the private folder under a random name. */
    public static function upload_dir_filter(array $dirs): array
    {
        $dir = self::protect_dir(trailingslashit($dirs['basedir']) . 's2fc-migrator');
        $dirs['path']   = $dir;
        $dirs['url']    = trailingslashit($dirs['baseurl']) . 's2fc-migrator';
        $dirs['subdir'] = '/s2fc-migrator';
        return $dirs;
    }

    public static function random_filename($dir, $name, $ext): string
    {
        return 'export-' . wp_generate_password(16, false) . ($ext ?: '.csv');
    }

    public static function get(): array
    {
        $s = get_option(self::option_name(), []);
        return is_array($s) ? $s : [];
    }

    public static function active(): bool
    {
        $s = self::get();
        return !empty($s['data_file']) && file_exists($s['data_file']) && !empty($s['index_file']) && file_exists($s['index_file']);
    }

    /**
     * Store the parsed export.
     *
     * @return true|\WP_Error
     */
    public static function start(array $summary, array $products)
    {
        self::clear();
        $dir   = self::ensure_dir();
        $token = wp_generate_password(16, false);
        $data  = $dir . '/products-' . $token . '.jsonl';
        $index = $dir . '/products-' . $token . '.idx';

        $fh = fopen($data, 'w');
        if (!$fh) {
            return new \WP_Error('not_writable', __('The uploads folder is not writable.', 'shopify-to-fluentcart-migrator'));
        }
        $offsets = [];
        foreach ($products as $product) {
            $line = wp_json_encode($product);
            if ($line === false) {
                fclose($fh);
                @unlink($data);
                return new \WP_Error('encode', __('A product contains text that could not be encoded. Re-export the CSV from Shopify without opening it in a spreadsheet.', 'shopify-to-fluentcart-migrator'));
            }
            $offsets[] = ftell($fh);
            fwrite($fh, $line . "\n");
        }
        fclose($fh);
        if (file_put_contents($index, wp_json_encode($offsets)) === false) {
            @unlink($data);
            return new \WP_Error('not_writable', __('The uploads folder is not writable.', 'shopify-to-fluentcart-migrator'));
        }

        $summary['data_file']  = $data;
        $summary['index_file'] = $index;
        $summary['created']    = time();
        $summary['results']    = [];
        update_option(self::option_name(), $summary, false);
        return true;
    }

    /** Every product; used by the review screen only. */
    public static function products(): array
    {
        $s = self::get();
        if (empty($s['data_file']) || !file_exists($s['data_file'])) {
            return [];
        }
        $out = [];
        $fh  = fopen($s['data_file'], 'r');
        if (!$fh) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        fclose($fh);
        return $out;
    }

    /** One product by index, without reading the rest of the file. */
    public static function product(int $index)
    {
        $s = self::get();
        if ($index < 0 || empty($s['data_file']) || empty($s['index_file']) || !file_exists($s['data_file'])) {
            return null;
        }
        $offsets = json_decode((string) file_get_contents($s['index_file']), true);
        if (!is_array($offsets) || !isset($offsets[$index])) {
            return null;
        }
        $fh = fopen($s['data_file'], 'r');
        if (!$fh) {
            return null;
        }
        fseek($fh, (int) $offsets[$index]);
        $line = fgets($fh);
        fclose($fh);
        $row = $line === false ? null : json_decode($line, true);
        return is_array($row) ? $row : null;
    }

    /** Remember the outcome of one product so a reload still shows it. */
    public static function record_result(int $index, array $result): void
    {
        $s = self::get();
        if (!$s) {
            return;
        }
        // Keep the option small: only what the screen needs.
        $s['results'][$index] = [
            'status'   => (string) ($result['status'] ?? 'failed'),
            'post_id'  => (int) ($result['post_id'] ?? 0),
            'message'  => (string) ($result['message'] ?? ''),
            'warnings' => array_slice(array_map('strval', (array) ($result['warnings'] ?? [])), 0, 5),
        ];
        update_option(self::option_name(), $s, false);
    }

    public static function update(array $changes): void
    {
        $s = array_merge(self::get(), $changes);
        update_option(self::option_name(), $s, false);
    }

    public static function clear(): void
    {
        $s = self::get();
        foreach (['data_file', 'index_file', 'csv_file'] as $key) {
            if (!empty($s[$key]) && file_exists($s[$key])) {
                @unlink($s[$key]);
            }
        }
        delete_option(self::option_name());
    }
}
