<?php

namespace S2FC;

defined('ABSPATH') || exit;

/**
 * The upload form posts to admin-post.php; the import runs one product per
 * admin-ajax request so a big catalog never hits a PHP time limit and the
 * screen can show progress.
 */
class Ajax
{
    const NONCE = 's2fc_nonce';

    public function register(): void
    {
        add_action('admin_post_s2fc_upload', [$this, 'upload']);
        add_action('admin_post_s2fc_reset', [$this, 'reset']);
        add_action('wp_ajax_s2fc_import_one', [$this, 'import_one']);
        add_action('wp_ajax_s2fc_finish', [$this, 'finish']);
    }

    private function guard(string $action): void
    {
        if (!Helpers::user_can_migrate()) {
            wp_die(esc_html__('You are not allowed to run the migrator.', 'shopify-to-fluentcart-migrator'), 403);
        }
        check_admin_referer($action, self::NONCE);
    }

    public function upload(): void
    {
        $this->guard('s2fc_upload'); // nonce + capability; phpcs cannot see it from here.

        if (!Helpers::fluentcart_ready()) {
            $this->back('no_fluentcart');
        }
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verified in guard(); $_FILES is handled by wp_handle_upload().
        if (empty($_FILES['s2fc_csv']) || !is_array($_FILES['s2fc_csv'])) {
            $this->back('no_file');
        }
        $file = $_FILES['s2fc_csv'];
        // phpcs:enable
        if (!empty($file['error'])) {
            $this->back('upload_' . (int) $file['error']);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        add_filter('upload_dir', [Session::class, 'upload_dir_filter']);
        $moved = wp_handle_upload($file, [
            'test_form'                => false,
            'unique_filename_callback' => [Session::class, 'random_filename'],
            'mimes'                    => [
                'csv' => 'text/csv',
                'txt' => 'text/plain',
            ],
        ]);
        remove_filter('upload_dir', [Session::class, 'upload_dir_filter']);

        if (!empty($moved['error'])) {
            // WordPress often reports CSVs as text/plain or application/vnd.ms-excel; retry without the type check.
            $moved = $this->move_manually($file);
            if (is_wp_error($moved)) {
                $this->back('upload_failed', $moved->get_error_message());
            }
        }

        // The CSV is only needed to parse; nothing reads it afterwards.
        $parsed = Csv_Parser::parse_file($moved['file']);
        @unlink($moved['file']);
        if (is_wp_error($parsed)) {
            $this->back('parse', $parsed->get_error_message());
        }

        $summary = $parsed['summary'];
        $summary['source_name'] = sanitize_file_name($file['name']);
        $summary['options']     = Importer::default_options();

        $started = Session::start($summary, $parsed['products']);
        if (is_wp_error($started)) {
            $this->back('parse', $started->get_error_message());
        }
        $this->back();
    }

    /** @return array|\WP_Error ['file' => path] */
    private function move_manually(array $file)
    {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            return new \WP_Error('type', __('Please upload the .csv file Shopify emailed you.', 'shopify-to-fluentcart-migrator'));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return new \WP_Error('tmp', __('Upload failed.', 'shopify-to-fluentcart-migrator'));
        }
        $dir  = Session::ensure_dir();
        $dest = $dir . '/export-' . wp_generate_password(12, false) . '.csv';
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            return new \WP_Error('move', __('The file could not be saved to the uploads folder.', 'shopify-to-fluentcart-migrator'));
        }
        return ['file' => $dest];
    }

    public function reset(): void
    {
        $this->guard('s2fc_reset');
        Session::clear();
        $this->back();
    }

    public function import_one(): void
    {
        // A JSON body with a code, not WordPress' bare "-1", so the screen
        // can tell an expired session apart from a failed product.
        if (!check_ajax_referer('s2fc_import', 'nonce', false)) {
            wp_send_json_error(['code' => 'session', 'message' => __('Your session has expired. Reload the page and import again; products already imported are skipped.', 'shopify-to-fluentcart-migrator')], 403);
        }
        if (!Helpers::user_can_migrate()) {
            wp_send_json_error(['code' => 'forbidden', 'message' => __('Not allowed.', 'shopify-to-fluentcart-migrator')], 403);
        }

        $index   = isset($_POST['index']) ? (int) $_POST['index'] : -1;
        $options = isset($_POST['options']) && is_array($_POST['options']) ? wp_unslash($_POST['options']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        $options = Importer::sanitize_options($options);

        $product = Session::product($index);
        if (!$product) {
            wp_send_json_error(['message' => __('Product not found in the uploaded file. Please upload it again.', 'shopify-to-fluentcart-migrator')], 404);
        }

        $result = Importer::import($product, $options);
        if (is_wp_error($result)) {
            $record = ['status' => 'failed', 'message' => $result->get_error_message(), 'warnings' => []];
            Session::record_result($index, $record);
            wp_send_json_error(['index' => $index, 'title' => $product['title'], 'message' => $result->get_error_message()]);
        }

        $result['title']    = $product['title'];
        $result['edit_url'] = Helpers::edit_url((int) $result['post_id']);
        Session::record_result($index, $result);
        Session::update(['options' => $options]);

        wp_send_json_success($result);
    }

    /** Called once after the last product: marks the run finished. */
    public function finish(): void
    {
        if (!check_ajax_referer('s2fc_import', 'nonce', false) || !Helpers::user_can_migrate()) {
            wp_send_json_error([], 403);
        }
        Session::update(['finished' => time()]);
        wp_send_json_success();
    }

    /**
     * Redirect back to the screen. An error is parked in a short-lived
     * transient for the current user rather than in the URL, so nothing
     * user-controlled is ever reflected from the query string.
     */
    private function back(string $error = '', string $detail = ''): void
    {
        if ($error !== '') {
            set_transient(self::flash_key(), ['code' => $error, 'detail' => $detail], 5 * MINUTE_IN_SECONDS);
        }
        wp_safe_redirect(Helpers::admin_url());
        exit;
    }

    public static function flash_key(): string
    {
        return 's2fc_flash_' . get_current_user_id();
    }

    /** Read and clear the parked error, if any. */
    public static function take_flash(): array
    {
        $flash = get_transient(self::flash_key());
        if ($flash) {
            delete_transient(self::flash_key());
        }
        return is_array($flash) ? $flash : [];
    }
}
