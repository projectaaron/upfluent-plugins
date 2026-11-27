<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * The plugin's settings (one option), the encrypted API token, the hashed
 * webhook secret, and the small runtime state the status card shows.
 */
class Settings
{
    const OPTION        = 'upfluent_sf_settings';
    const OPTION_TOKEN  = 'upfluent_sf_token';
    const OPTION_SECRET = 'upfluent_sf_webhook_secret';
    const OPTION_STATE  = 'upfluent_sf_state';

    /** ShipFusion warehouses (Create Shipment / List Inventory docs), in the order orders try them. */
    const WAREHOUSES = ['IL', 'LV', 'PA', 'TO'];

    /** The ShipFusion warehouse outside the US (Toronto). */
    const CANADA_WAREHOUSE = 'TO';

    /** Cron intervals the user can pick for tracking and stock. */
    const INTERVALS = ['upfluent_sf_30_minutes', 'hourly', 'twicedaily'];

    public static function defaults(): array
    {
        return [
            'warehouses'           => ['IL'],
            'fallback_phone'       => '',
            'send_orders'          => false,
            'check_tracking'       => true,
            'tracking_interval'    => 'hourly',
            'sync_stock'           => false,
            'stock_interval'       => 'hourly',
            'complete_on_delivery' => true,
            'alerts'               => true,
            'alerts_email'         => '',
            'debug'                => false,
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        $saved = is_array($saved) ? $saved : [];
        // Before multi-warehouse support the setting was one code.
        if (!isset($saved['warehouses']) && !empty($saved['warehouse'])) {
            $saved['warehouses'] = [(string) $saved['warehouse']];
        }
        unset($saved['warehouse']);
        return array_merge(self::defaults(), $saved);
    }

    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    /**
     * Saves the given keys (others keep their value). Values are sanitized
     * here, whatever the caller.
     */
    public static function update(array $values): array
    {
        $current = self::all();
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $current)) {
                continue;
            }
            switch ($key) {
                case 'warehouses':
                    $codes = array_map(function ($code) {
                        return strtoupper(sanitize_key((string) $code));
                    }, (array) $value);
                    $value = array_values(array_intersect(self::WAREHOUSES, $codes));
                    $value = $value ?: $current[$key];
                    break;
                case 'tracking_interval':
                case 'stock_interval':
                    $value = in_array($value, self::INTERVALS, true) ? $value : $current[$key];
                    break;
                case 'fallback_phone':
                    $value = substr(preg_replace('/[^0-9+().\-\s]/', '', (string) $value), 0, 30);
                    break;
                case 'alerts_email':
                    $value = sanitize_email((string) $value);
                    break;
                default:
                    $value = (bool) $value;
            }
            $current[$key] = $value;
        }
        update_option(self::OPTION, $current, true);
        return $current;
    }

    public static function token(): string
    {
        return Secrets::decrypt((string) get_option(self::OPTION_TOKEN, ''));
    }

    public static function hasToken(): bool
    {
        return self::token() !== '';
    }

    /** Last four characters, for "a token is saved (ends in …)". */
    public static function tokenHint(): string
    {
        $token = self::token();
        return $token === '' ? '' : substr($token, -4);
    }

    /**
     * Strips what people paste around a token: whitespace and a leading
     * "Bearer " (ShipFusion rejects that prefix in X-SHIPFUSION-TOKEN).
     */
    public static function normalizeToken(string $token): string
    {
        return trim((string) preg_replace('/^\s*Bearer\s+/i', '', $token));
    }

    /** Why a token can't be right, or '' when it looks usable. */
    public static function tokenProblem(string $token): string
    {
        if (preg_match('/\s/', $token)) {
            return __('That isn\'t an API token: it contains spaces. Copy only the token from Shipfusion 360, Settings, Integrations, API Credentials.', 'order-sync-for-shipfusion-and-fluentcart');
        }
        if (strlen($token) < 20) {
            return __('That API token is too short. Copy the whole token from Shipfusion 360, Settings, Integrations, API Credentials.', 'order-sync-for-shipfusion-and-fluentcart');
        }
        return '';
    }

    public static function saveToken(string $token): void
    {
        update_option(self::OPTION_TOKEN, Secrets::encrypt($token), false);
    }

    /** Makes a new webhook secret, stores only its hash and returns it once. */
    public static function newWebhookSecret(): string
    {
        $secret = wp_generate_password(40, false, false);
        update_option(self::OPTION_SECRET, hash('sha256', $secret), false);
        return $secret;
    }

    public static function hasWebhookSecret(): bool
    {
        return (string) get_option(self::OPTION_SECRET, '') !== '';
    }

    public static function webhookSecretMatches(string $given): bool
    {
        $stored = (string) get_option(self::OPTION_SECRET, '');
        return $stored !== '' && $given !== '' && hash_equals($stored, hash('sha256', $given));
    }

    public static function alertsEmail(): string
    {
        $email = (string) self::get('alerts_email');
        return is_email($email) ? $email : (string) get_option('admin_email');
    }

    /** Runtime state: last runs, connection health, webhook activity. */
    public static function state(): array
    {
        $state = get_option(self::OPTION_STATE, []);
        return is_array($state) ? $state : [];
    }

    public static function setState(string $key, $value): void
    {
        $state       = self::state();
        $state[$key] = $value;
        update_option(self::OPTION_STATE, $state, false);
    }

    /** Records a run: ok/failed, a plain message, when. */
    public static function recordRun(string $job, bool $ok, string $message): void
    {
        $runs       = (array) (self::state()['runs'] ?? []);
        $runs[$job] = ['last_at' => gmdate('c'), 'ok' => $ok, 'message' => $message];
        self::setState('runs', $runs);
    }

    /**
     * Connection health, set by every ShipFusion call that authenticates or
     * is refused (Api) and by the connection test.
     */
    public static function setConnection(string $status, string $message = ''): void
    {
        $previous = (array) (self::state()['connection'] ?? []);
        if (($previous['status'] ?? '') === $status && ($previous['message'] ?? '') === $message
            && strtotime((string) ($previous['checked_at'] ?? '')) > time() - 300) {
            return;
        }
        self::setState('connection', ['status' => $status, 'message' => $message, 'checked_at' => gmdate('c')]);
        if ($status === 'error') {
            do_action('upfluent_sf_connection_failed', $message);
        }
    }

    public static function connection(): array
    {
        $conn = (array) (self::state()['connection'] ?? []);
        if (!self::hasToken()) {
            return ['status' => 'not_set', 'message' => '', 'checked_at' => null];
        }
        return [
            'status'     => (string) ($conn['status'] ?? 'unknown'),
            'message'    => (string) ($conn['message'] ?? ''),
            'checked_at' => $conn['checked_at'] ?? null,
        ];
    }

    public static function connected(): bool
    {
        return self::connection()['status'] === 'ok';
    }
}
