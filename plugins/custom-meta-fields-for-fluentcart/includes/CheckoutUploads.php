<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * File uploads for checkout fields, stored through FluentCart's own storage
 * drivers so the file lands wherever the store keeps its files.
 *
 * Storage (verified against FluentCart 1.6.3):
 *   FluentCart\Api\StorageDrivers::getActive(true) lists the drivers enabled
 *   under Settings → Storage. Product files are uploaded through
 *   FluentCart\App\Services\FileSystem\FileManager, which resolves a driver by
 *   slug (local, s3, or a Pro driver such as r2) and exposes uploadFile /
 *   downloadFile / deleteFile with the same signature for each. We pick the
 *   first enabled remote driver, otherwise Local (FluentCart's own default),
 *   and route everything through FileManager so nothing here is driver-specific.
 *
 * Flow:
 *   The checkout form is posted as URL-encoded data by FluentCart's script,
 *   so a file can never ride along with the order. Instead the browser uploads
 *   the file the moment it is chosen (admin-ajax, nonce, active cart required),
 *   the file is stored, and a random token goes into a hidden input. When the
 *   order is placed, CheckoutFields claims the token and records the file on
 *   the order. Tokens not claimed within a day are deleted by WP-Cron.
 *
 * Local storage lives in uploads/fluent-cart/cmf-checkout/. The folder gets an
 * .htaccess deny and an index.php, and stored names are random, so files are
 * not reachable by URL. Admins download through an authenticated endpoint.
 */
class CheckoutUploads
{
    const PENDING_OPTION  = 'fct_cmf_pending_uploads';
    const PENDING_PREFIX  = 'fct_cmf_pu_';
    const CRON_HOOK       = 'fct_cmf_cleanup_uploads';
    const PENDING_TTL     = DAY_IN_SECONDS;
    const NONCE_UPLOAD    = 'fct_cmf_upload';
    const NONCE_DOWNLOAD  = 'fct_cmf_download';
    const AJAX_UPLOAD     = 'fct_cmf_upload';
    const AJAX_REMOVE     = 'fct_cmf_upload_remove';
    const AJAX_DOWNLOAD   = 'fct_cmf_download';
    const AJAX_PREVIEW    = 'fct_cmf_preview';
    const LOCAL_DIR       = 'fluent-cart/cmf-checkout';
    const REMOTE_PREFIX   = 'cmf-checkout/';

    /** Extensions shown as an inline preview on the receipt. */
    const PREVIEW_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Always refused, whatever the field allows. Mirrors FluentCart's local driver. */
    const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'html', 'htm', 'svg', 'exe', 'sh', 'bat', 'cmd', 'dll', 'js', 'com', 'msi'];

    public function register(): void
    {
        add_action('wp_ajax_' . self::AJAX_UPLOAD, [$this, 'handleUpload']);
        add_action('wp_ajax_nopriv_' . self::AJAX_UPLOAD, [$this, 'handleUpload']);
        add_action('wp_ajax_' . self::AJAX_REMOVE, [$this, 'handleRemove']);
        add_action('wp_ajax_nopriv_' . self::AJAX_REMOVE, [$this, 'handleRemove']);
        add_action('wp_ajax_' . self::AJAX_DOWNLOAD, [$this, 'handleDownload']);
        add_action('wp_ajax_' . self::AJAX_PREVIEW, [$this, 'handlePreview']);
        add_action('wp_ajax_nopriv_' . self::AJAX_PREVIEW, [$this, 'handlePreview']);

        // Files live and die with their order.
        add_action('fluent_cart/order_before_delete', [$this, 'collectOrderFiles'], 10, 1);
        add_action('fluent_cart/order_deleted', [$this, 'deleteCollectedFiles'], 10, 0);
        add_action('fluent_cart/renewal_order_deleted', [$this, 'deleteCollectedFiles'], 10, 0);

        // WordPress personal data export and erase (Tools → Export/Erase Personal Data).
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);

        add_action(self::CRON_HOOK, [$this, 'cleanup']);
        add_action('init', [$this, 'scheduleCleanup']);
    }

    public function scheduleCleanup(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    // ─── Storage driver ───

    /**
     * Slug of the FluentCart storage driver uploads go to: the first enabled
     * remote driver, otherwise local. Filter `upfluent_cmf_upload_storage_driver`.
     */
    public static function driverSlug(): string
    {
        $slug = 'local';
        if (class_exists('\FluentCart\Api\StorageDrivers')) {
            try {
                $active = (new \FluentCart\Api\StorageDrivers())->getActive(true);
                foreach ((array) $active as $key => $driver) {
                    $instance = $driver['instance'] ?? null;
                    if ($key !== 'local' && is_object($instance) && method_exists($instance, 'getDriverClass')) {
                        $slug = (string) ($driver['route'] ?: $key);
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $slug = 'local';
            }
        }
        return (string) apply_filters('upfluent_cmf_upload_storage_driver', $slug);
    }

    /**
     * FileManager for a driver slug, or null when FluentCart's file system is
     * unavailable. Local gets its own protected sub-folder.
     */
    public static function manager(string $slug)
    {
        if (!class_exists('\FluentCart\App\Services\FileSystem\FileManager')) {
            return null;
        }
        try {
            if ($slug === 'local') {
                self::prepareLocalDir();
                return new \FluentCart\App\Services\FileSystem\FileManager('local', null, self::LOCAL_DIR, true);
            }
            return new \FluentCart\App\Services\FileSystem\FileManager($slug, null, null, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function localDir(): string
    {
        return trailingslashit(wp_get_upload_dir()['basedir']) . self::LOCAL_DIR;
    }

    private static function prepareLocalDir(): void
    {
        $dir = self::localDir();
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        if (!file_exists($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"); // phpcs:ignore
        }
        if (!file_exists($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n"); // phpcs:ignore
        }
    }

    // ─── Per-field limits ───

    public static function allowedExtensions(array $def): array
    {
        $list = FieldsService::optionsList((string) ($def['allowed_extensions'] ?? ''));
        if (!$list) {
            $list = FieldsService::optionsList(ProDefinitions::DEFAULT_EXTENSIONS);
        }
        $out = [];
        foreach ($list as $ext) {
            $ext = strtolower(ltrim(trim($ext), '.'));
            if ($ext !== '' && !in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                $out[] = $ext;
            }
        }
        return array_values(array_unique($out));
    }

    public static function maxBytes(array $def): int
    {
        $mb    = (int) ($def['max_size_mb'] ?? 0);
        $bytes = $mb > 0 ? $mb * MB_IN_BYTES : 10 * MB_IN_BYTES;
        $wpMax = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        if ($wpMax > 0) {
            $bytes = min($bytes, $wpMax);
        }
        return (int) apply_filters('upfluent_cmf_upload_max_bytes', $bytes, $def);
    }

    // ─── AJAX: upload / remove (customers) ───

    public function handleUpload(): void
    {
        nocache_headers();

        $nonce = isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!wp_verify_nonce($nonce, self::NONCE_UPLOAD)) {
            wp_send_json(['message' => __('Your session expired. Please reload the page and try again.', 'custom-meta-fields-for-fluentcart')], 403);
        }
        if (!self::hasCart()) {
            wp_send_json(['message' => __('No active cart.', 'custom-meta-fields-for-fluentcart')], 403);
        }

        $field_id = isset($_POST['field_id']) ? sanitize_key(wp_unslash($_POST['field_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $def      = FieldsService::find($field_id);
        // Only a field that is switched on takes files.
        if (!$def || $def['context'] !== 'checkout' || $def['field_type'] !== 'file'
            || empty($def['enabled'])) {
            wp_send_json(['message' => __('Unknown upload field.', 'custom-meta-fields-for-fluentcart')], 400);
        }
        if (!class_exists('\\FluentCart\\Framework\\Http\\Request\\File')) {
            wp_send_json(['message' => __('File storage is not available right now.', 'custom-meta-fields-for-fluentcart')], 500);
        }

        $upload = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below: upload error, size, extension and MIME type
        if (!is_array($upload) || empty($upload['tmp_name']) || !is_string($upload['tmp_name'])) {
            wp_send_json(['message' => __('No file received.', 'custom-meta-fields-for-fluentcart')], 400);
        }
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int) $upload['error'];
            $msg  = in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? __('That file is too large.', 'custom-meta-fields-for-fluentcart')
                : __('The upload failed. Please try again.', 'custom-meta-fields-for-fluentcart');
            wp_send_json(['message' => $msg], 400);
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            wp_send_json(['message' => __('The upload failed. Please try again.', 'custom-meta-fields-for-fluentcart')], 400);
        }

        $originalName = sanitize_file_name((string) ($upload['name'] ?? 'file'));
        $size         = (int) ($upload['size'] ?? filesize($upload['tmp_name']));
        $maxBytes     = self::maxBytes($def);
        if ($size <= 0 || $size > $maxBytes) {
            wp_send_json(['message' => sprintf(
                /* translators: %s: size like 10 MB */
                __('The file must be smaller than %s.', 'custom-meta-fields-for-fluentcart'),
                size_format($maxBytes)
            )], 400);
        }

        $ext     = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = self::allowedExtensions($def);
        $checked = wp_check_filetype_and_ext($upload['tmp_name'], $originalName);
        $realExt = strtolower((string) ($checked['ext'] ?? ''));
        $mime    = (string) ($checked['type'] ?? '');

        if ($ext === '' || $realExt === '' || $mime === '' || $realExt !== $ext
            || in_array($ext, self::BLOCKED_EXTENSIONS, true) || !in_array($ext, $allowed, true)) {
            wp_send_json(['message' => sprintf(
                /* translators: %s: comma separated extensions */
                __('Allowed file types: %s', 'custom-meta-fields-for-fluentcart'),
                implode(', ', $allowed)
            )], 400);
        }

        $slug    = self::driverSlug();
        $manager = self::manager($slug);
        if (!$manager) {
            wp_send_json(['message' => __('File storage is not available right now.', 'custom-meta-fields-for-fluentcart')], 500);
        }

        // Random stored name; the customer's name is kept in the record.
        // Lowercase on purpose: the token comes back through sanitize_key(),
        // which lowercases, so the lookup key must match what was stored.
        $token      = strtolower(wp_generate_password(32, false, false));
        $storedName = 'cmf-' . $token;
        if ($slug !== 'local') {
            $storedName = self::REMOTE_PREFIX . $storedName . '.' . $ext;
        }

        $fileObj = new \FluentCart\Framework\Http\Request\File(
            $upload['tmp_name'],
            $originalName,
            $mime,
            $size,
            UPLOAD_ERR_OK
        );

        try {
            $result = $manager->uploadFile($upload['tmp_name'], $storedName, $fileObj, []);
        } catch (\Throwable $e) {
            $result = new \WP_Error('upload_failed', $e->getMessage());
        }
        if (is_wp_error($result)) {
            wp_send_json(['message' => $result->get_error_message() ?: __('The upload failed. Please try again.', 'custom-meta-fields-for-fluentcart')], 400);
        }

        $record = [
            'token'    => $token,
            'field_id' => $field_id,
            'driver'   => (string) ($result['file']['driver'] ?? $slug),
            'path'     => (string) ($result['path'] ?? ($result['file']['name'] ?? '')),
            'bucket'   => (string) ($result['file']['bucket'] ?? ''),
            'name'     => $originalName,
            'size'     => $size,
            'mime'     => $mime,
            'time'     => time(),
        ];
        if ($record['path'] === '') {
            wp_send_json(['message' => __('The upload failed. Please try again.', 'custom-meta-fields-for-fluentcart')], 500);
        }

        self::addPending($record);

        wp_send_json([
            'token' => $token,
            'name'  => $originalName,
            'size'  => size_format($size),
        ]);
    }

    public function handleRemove(): void
    {
        nocache_headers();

        $nonce = isset($_POST['_nonce']) ? sanitize_text_field(wp_unslash($_POST['_nonce'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (!wp_verify_nonce($nonce, self::NONCE_UPLOAD)) {
            wp_send_json(['message' => __('Invalid request.', 'custom-meta-fields-for-fluentcart')], 403);
        }
        $token  = isset($_POST['token']) ? sanitize_key(wp_unslash($_POST['token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $record = self::pending($token);
        // Only an upload no order has taken yet can be removed by the customer.
        if ($record && empty($record['order_id'])) {
            self::claim($token);
            self::deleteStored($record);
            wp_send_json(['removed' => true]);
        }
        wp_send_json(['removed' => false]);
    }

    // ─── AJAX: download (admin) ───

    public static function downloadUrl(int $order_id, string $field_id): string
    {
        return add_query_arg([
            'action'   => self::AJAX_DOWNLOAD,
            'order_id' => $order_id,
            'field_id' => $field_id,
            '_wpnonce' => wp_create_nonce(self::NONCE_DOWNLOAD),
        ], admin_url('admin-ajax.php'));
    }

    public function handleDownload(): void
    {
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_DOWNLOAD) || !Permissions::canViewOrder()) {
            wp_die(esc_html__('You are not allowed to download this file.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 403]);
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $field_id = isset($_GET['field_id']) ? sanitize_key(wp_unslash($_GET['field_id'])) : '';
        $values   = CheckoutFields::orderValues($order_id);
        $file     = $values[$field_id]['file'] ?? null;
        if (!is_array($file) || empty($file['path'])) {
            wp_die(esc_html__('File not found.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 404]);
        }

        $manager = self::manager((string) ($file['driver'] ?? 'local'));
        if (!$manager) {
            wp_die(esc_html__('File storage is not available.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 500]);
        }

        // Local streams and exits; remote drivers redirect to a signed URL.
        $result = $manager->downloadFile((string) $file['path'], (string) ($file['name'] ?? 'file'), $file['bucket'] ?: null);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '', ['response' => 404]);
        }
        exit;
    }

    // ─── Image preview (receipt) ───

    /** Whether a stored file is an image the receipt can show inline. */
    public static function isPreviewable(array $file): bool
    {
        $ext  = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $mime = (string) ($file['mime'] ?? '');
        return in_array($ext, self::PREVIEW_EXTENSIONS, true)
            && ($mime === '' || strpos($mime, 'image/') === 0);
    }

    /** Bound to the stored path, so a link stops working if the file behind it changes. */
    private static function previewSignature(int $order_id, string $field_id, string $path): string
    {
        return hash_hmac('sha256', 'cmf-preview|' . $order_id . '|' . $field_id . '|' . $path, wp_salt('auth'));
    }

    private static function storedFile(int $order_id, string $field_id): ?array
    {
        $values = CheckoutFields::orderValues($order_id);
        $file   = $values[$field_id]['file'] ?? null;
        return is_array($file) && !empty($file['path']) ? $file : null;
    }

    /**
     * Signed image URL for the receipt. Customers (often guests) cannot use
     * the admin download endpoint, so the signature stands in for a login:
     * it is printed only on that order's receipt, which FluentCart already
     * guards with the transaction hash, and it opens nothing but that image.
     */
    public static function previewUrl(int $order_id, string $field_id): string
    {
        $file = self::storedFile($order_id, $field_id);
        if (!$file) {
            return '';
        }
        return add_query_arg([
            'action'   => self::AJAX_PREVIEW,
            'order_id' => $order_id,
            'field_id' => $field_id,
            'sig'      => self::previewSignature($order_id, $field_id, (string) $file['path']),
        ], admin_url('admin-ajax.php'));
    }

    public function handlePreview(): void
    {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $field_id = isset($_GET['field_id']) ? sanitize_key(wp_unslash($_GET['field_id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $sig      = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $file = ($order_id && $field_id !== '') ? self::storedFile($order_id, $field_id) : null;
        if (!$file || !hash_equals(self::previewSignature($order_id, $field_id, (string) $file['path']), $sig)) {
            wp_die(esc_html__('You are not allowed to view this file.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 403]);
        }
        $type = wp_check_filetype((string) $file['name']);
        if (!self::isPreviewable($file) || strpos((string) $type['type'], 'image/') !== 0) {
            wp_die(esc_html__('File not found.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 404]);
        }

        $driver  = (string) ($file['driver'] ?? 'local');
        $manager = self::manager($driver);
        if (!$manager) {
            wp_die(esc_html__('File storage is not available.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 500]);
        }

        if ($driver !== 'local') {
            // Remote drivers answer with a redirect to a short-lived signed URL.
            $result = $manager->downloadFile((string) $file['path'], (string) ($file['name'] ?? 'file'), $file['bucket'] ?: null);
            if (is_wp_error($result)) {
                wp_die(esc_html($result->get_error_message()), '', ['response' => 404]);
            }
            exit;
        }

        $abs = $manager->getFilePath((string) $file['path']);
        if (!$abs || !is_file($abs)) {
            wp_die(esc_html__('File not found.', 'custom-meta-fields-for-fluentcart'), '', ['response' => 404]);
        }
        $mime = (string) $type['type'];

        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $file['name']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=86400');
        readfile($abs); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    // ─── Pending records ───

    /*
     * One option per token (PENDING_PREFIX . token), so two uploads finishing
     * at the same moment cannot overwrite each other's record. PENDING_OPTION
     * is the single array used up to 2.5.5; it is still read, and drained by
     * cleanup.
     */

    private static function pendingAll(): array
    {
        $all = get_option(self::PENDING_OPTION, []);
        return is_array($all) ? $all : [];
    }

    private static function addPending(array $record): void
    {
        add_option(self::PENDING_PREFIX . $record['token'], $record, '', false);
    }

    /** Pending record for a token, without removing it. */
    public static function pending(string $token): ?array
    {
        $token = sanitize_key($token);
        if ($token === '') {
            return null;
        }
        $record = get_option(self::PENDING_PREFIX . $token, null);
        if (is_array($record)) {
            return $record;
        }
        $all = self::pendingAll();
        if (isset($all[$token]) && is_array($all[$token])) {
            return $all[$token];
        }
        // Records written by 2.1.0 carry mixed-case tokens.
        foreach ($all as $key => $record) {
            if (is_array($record) && strtolower((string) $key) === $token) {
                $record['token'] = (string) $key;
                return $record;
            }
        }
        return null;
    }

    /** Store a changed record where it came from; null removes it. */
    private static function putPending(array $record, ?array $value): void
    {
        $key = self::PENDING_PREFIX . $record['token'];
        if (is_array(get_option($key, null))) {
            if ($value === null) {
                delete_option($key);
            } else {
                update_option($key, $value, false);
            }
            return;
        }
        $all = self::pendingAll();
        if ($value === null) {
            unset($all[$record['token']]);
        } else {
            $all[$record['token']] = $value;
        }
        update_option(self::PENDING_OPTION, $all, false);
    }

    /**
     * Attach a token to an order. The record stays, marked with the order id,
     * because a declined payment re-submits the same form (and the same token)
     * and must still validate; cleanup drops it later without touching the
     * file. Returns null for an unknown token or one already attached to a
     * different order.
     */
    public static function claim(string $token, int $order_id = 0): ?array
    {
        $record = self::pending($token);
        if (!$record) {
            return null;
        }
        $owner = (int) ($record['order_id'] ?? 0);
        if ($owner && $order_id && $owner !== $order_id) {
            // FluentCart starts a new order when the previous one can no longer
            // be retried. The upload moves with the customer to the new order,
            // unless the earlier order was paid (then it is not theirs to take).
            if (self::orderIsPaid($owner)) {
                return null;
            }
            self::detachFromOrder($owner, $record);
            $record['order_id'] = 0;
            $owner = 0;
        }
        if ($order_id && !$owner) {
            $record['order_id'] = $order_id;
            self::putPending($record, $record);
        }
        if (!$order_id) {
            self::putPending($record, null);
        }
        return $record;
    }

    /** Whether a token is tied to an order that has been paid, so it cannot be reused. */
    public static function tokenTakenByPaidOrder(string $token, int $order_id = 0): bool
    {
        $record = self::pending($token);
        $owner  = (int) ($record['order_id'] ?? 0);
        return $owner && $owner !== $order_id && self::orderIsPaid($owner);
    }

    private static function orderIsPaid(int $order_id): bool
    {
        if (!class_exists('\\FluentCart\\App\\Models\\Order')) {
            return false;
        }
        $order = \FluentCart\App\Models\Order::find($order_id);
        if (!$order) {
            return false;
        }
        $success = class_exists('\\FluentCart\\App\\Helpers\\Status')
            ? (array) \FluentCart\App\Helpers\Status::getOrderSuccessStatuses()
            : ['completed', 'processing'];
        return in_array((string) $order->status, $success, true) || (string) ($order->payment_status ?? '') === 'paid';
    }

    /** Drop a moved upload from the order it was first attached to, so deleting that order spares the file. */
    private static function detachFromOrder(int $order_id, array $record): void
    {
        if (!class_exists('\\FluentCart\\App\\Models\\Order')) {
            return;
        }
        $order = \FluentCart\App\Models\Order::find($order_id);
        if (!$order) {
            return;
        }
        $values = CheckoutFields::orderValues($order);
        $field  = (string) ($record['field_id'] ?? '');
        if (!isset($values[$field]['file']['path']) || $values[$field]['file']['path'] !== (string) ($record['path'] ?? '')) {
            return;
        }
        $stored = $order->getMeta(CheckoutFields::ORDER_META_KEY, []);
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (!is_array($stored)) {
            return;
        }
        unset($stored[$field]);
        if ($stored) {
            $order->updateMeta(CheckoutFields::ORDER_META_KEY, $stored);
        } else {
            $order->deleteMeta(CheckoutFields::ORDER_META_KEY);
        }
    }

    // ─── Order deletion ───

    /** Files of orders being deleted, held until FluentCart confirms the delete. */
    private array $doomed = [];

    /** fluent_cart/order_before_delete: runs inside FluentCart's transaction, before the meta goes. */
    public function collectOrderFiles($data = []): void
    {
        $ids = [];
        if (is_array($data)) {
            $ids = array_map('intval', (array) ($data['connected_order_ids'] ?? []));
            $order = $data['order'] ?? null;
            if (is_object($order) && !empty($order->id)) {
                $ids[] = (int) $order->id;
            }
        }
        foreach (array_unique(array_filter($ids)) as $id) {
            foreach (CheckoutFields::orderValues($id) as $row) {
                if (!empty($row['file']['path'])) {
                    $this->doomed[] = $row['file'];
                }
            }
        }
    }

    /** fluent_cart/order_deleted: the delete committed, so the files can go. */
    public function deleteCollectedFiles(): void
    {
        $files        = $this->doomed;
        $this->doomed = [];
        if (!apply_filters('upfluent_cmf_delete_files_with_order', true, $files)) {
            return;
        }
        foreach ($files as $file) {
            self::deleteStored($file);
        }
    }

    // ─── Personal data (GDPR) ───

    public function registerExporter($exporters)
    {
        $exporters['fct-custom-meta-fields'] = [
            'exporter_friendly_name' => __('FluentCart checkout fields', 'custom-meta-fields-for-fluentcart'),
            'callback'               => [$this, 'exportPersonalData'],
        ];
        return $exporters;
    }

    public function registerEraser($erasers)
    {
        $erasers['fct-custom-meta-fields'] = [
            'eraser_friendly_name' => __('FluentCart checkout fields', 'custom-meta-fields-for-fluentcart'),
            'callback'             => [$this, 'erasePersonalData'],
        ];
        return $erasers;
    }

    /** Orders placed with an email address, one page at a time. */
    private static function ordersForEmail(string $email, int $page): array
    {
        if (!class_exists('\\FluentCart\\App\\Models\\Customer') || !class_exists('\\FluentCart\\App\\Models\\Order')) {
            return [];
        }
        $customer = \FluentCart\App\Models\Customer::query()->where('email', $email)->first();
        if (!$customer) {
            return [];
        }
        return \FluentCart\App\Models\Order::query()
            ->where('customer_id', (int) $customer->id)
            ->orderBy('id', 'ASC')
            ->offset(($page - 1) * 20)
            ->limit(20)
            ->get()
            ->all();
    }

    public function exportPersonalData($email, $page = 1): array
    {
        $orders = self::ordersForEmail((string) $email, max(1, (int) $page));
        $items  = [];
        foreach ($orders as $order) {
            $data = [];
            foreach (CheckoutFields::orderValues($order) as $row) {
                $data[] = ['name' => $row['label'], 'value' => CheckoutFields::displayValue($row)];
            }
            if ($data) {
                $items[] = [
                    'group_id'    => 'fct-checkout-fields',
                    'group_label' => __('FluentCart checkout fields', 'custom-meta-fields-for-fluentcart'),
                    'item_id'     => 'fct-order-' . (int) $order->id,
                    'data'        => array_merge([['name' => __('Order', 'custom-meta-fields-for-fluentcart'), 'value' => '#' . (int) $order->id]], $data),
                ];
            }
        }
        return ['data' => $items, 'done' => count($orders) < 20];
    }

    public function erasePersonalData($email, $page = 1): array
    {
        $orders  = self::ordersForEmail((string) $email, max(1, (int) $page));
        $removed = false;
        foreach ($orders as $order) {
            $values = CheckoutFields::orderValues($order);
            if (!$values) {
                continue;
            }
            foreach ($values as $row) {
                if (!empty($row['file']['path'])) {
                    self::deleteStored($row['file']);
                }
            }
            $order->deleteMeta(CheckoutFields::ORDER_META_KEY);
            $removed = true;
        }
        return [
            'items_removed'  => $removed,
            'items_retained' => false,
            'messages'       => [],
            'done'           => count($orders) < 20,
        ];
    }

    /** WP-Cron: forget old records; delete the file only if no order took it. */
    public function cleanup(): void
    {
        global $wpdb;
        $cutoff = time() - self::PENDING_TTL;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::PENDING_PREFIX) . '%'
        ));
        foreach ((array) $keys as $key) {
            $record = get_option($key, null);
            if (!is_array($record) || (int) ($record['time'] ?? 0) < $cutoff) {
                if (is_array($record) && empty($record['order_id'])) {
                    self::deleteStored($record);
                }
                delete_option($key);
            }
        }

        $all     = self::pendingAll();
        $changed = false;
        foreach ($all as $token => $record) {
            if (!is_array($record) || (int) ($record['time'] ?? 0) < $cutoff) {
                if (is_array($record) && empty($record['order_id'])) {
                    self::deleteStored($record);
                }
                unset($all[$token]);
                $changed = true;
            }
        }
        if ($changed) {
            update_option(self::PENDING_OPTION, $all, false);
        }
    }

    /** Remove a stored file, whichever driver holds it. */
    public static function deleteStored(array $file): void
    {
        $driver = (string) ($file['driver'] ?? 'local');
        $path   = (string) ($file['path'] ?? '');
        if ($path === '') {
            return;
        }
        $manager = self::manager($driver);
        if (!$manager) {
            return;
        }
        try {
            if ($driver === 'local') {
                // LocalDriver::deleteFile() demands manage_options, which cron
                // and customers lack; the resolved path is still contained to
                // our folder by the driver.
                $abs = $manager->getFilePath($path);
                if ($abs && is_file($abs)) {
                    wp_delete_file($abs);
                }
            } else {
                $manager->deleteFile($path, $file['bucket'] ?: null);
            }
        } catch (\Throwable $e) {
            // Best effort; a stray file is not worth breaking a request over.
        }
    }

    private static function hasCart(): bool
    {
        if (!class_exists('\FluentCart\App\Helpers\CartHelper')) {
            return false;
        }
        try {
            return (bool) \FluentCart\App\Helpers\CartHelper::getCart();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
