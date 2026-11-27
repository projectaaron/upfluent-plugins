<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * REST routes behind the settings screen (assets/admin.js), all under
 * upfluent-sf/v1 and all for people who can change FluentCart's store
 * settings (Permissions::canManageSettings).
 *
 *   GET  state                 everything the screen shows
 *   POST settings              save settings (and a new token)
 *   POST connection/test       test the typed or saved token
 *   POST run/(orders|tracking|stock)
 *   POST orders/{id}/retry     try a stuck order again
 *   POST orders/{id}/dismiss   "Mark as handled"
 *   POST webhook/secret        make a new webhook secret (shown once)
 *   GET  log                   the activity log
 */
class RestController
{
    const NS = 'upfluent-sf/v1';

    public function register(): void
    {
        $allowed = [Permissions::class, 'canManageSettings'];
        register_rest_route(self::NS, '/state', ['methods' => 'GET', 'callback' => [$this, 'state'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/settings', ['methods' => 'POST', 'callback' => [$this, 'saveSettings'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/connection/test', ['methods' => 'POST', 'callback' => [$this, 'testConnection'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/run/(?P<job>orders|tracking|stock)', ['methods' => 'POST', 'callback' => [$this, 'run'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/(?P<action>retry|dismiss)', ['methods' => 'POST', 'callback' => [$this, 'orderAction'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/webhook/secret', ['methods' => 'POST', 'callback' => [$this, 'newSecret'], 'permission_callback' => $allowed]);
        register_rest_route(self::NS, '/log', ['methods' => 'GET', 'callback' => [$this, 'log'], 'permission_callback' => $allowed]);
    }

    public function state(): \WP_REST_Response
    {
        return new \WP_REST_Response(self::stateData());
    }

    public static function stateData(): array
    {
        $s     = Settings::all();
        $state = Settings::state();
        $runs  = (array) ($state['runs'] ?? []);
        $next  = ['orders' => 'upfluent_sf_send_orders', 'tracking' => 'upfluent_sf_check_tracking', 'stock' => 'upfluent_sf_sync_stock'];
        foreach ($next as $job => $hook) {
            $runs[$job] = isset($runs[$job]) ? array_merge($runs[$job], ['next_at' => Cron::nextRun($hook)]) : null;
        }

        $attention = [];
        foreach (Store::attention() as $row) {
            $order       = OrderPayload::load($row['order_id']);
            $attention[] = [
                'order_id'    => $row['order_id'],
                'order_label' => '#' . ($order ? OrderPayload::orderNumber($order) : $row['order_id']),
                'order_url'   => admin_url('admin.php?page=fluent-cart#/orders/' . $row['order_id'] . '/view'),
                'problem'     => (string) $row['problem'],
                'since'       => $row['problem_at'] ? gmdate('c', strtotime($row['problem_at'] . ' UTC')) : null,
                'attempts'    => $row['attempts'],
                'can_retry'   => true,
            ];
        }

        return [
            'version'    => UPFLUENT_SF_VERSION,
            'connected'  => Settings::connected(),
            'connection' => Settings::connection(),
            'settings'   => [
                'token_saved'          => Settings::hasToken(),
                'token_hint'           => Settings::tokenHint() !== '' ? '…' . Settings::tokenHint() : '',
                'warehouses'           => $s['warehouses'],
                'warehouse_options'    => [
                    ['value' => 'IL', 'label' => __('Chicago area, IL (IL)', 'order-sync-for-shipfusion-and-fluentcart')],
                    ['value' => 'LV', 'label' => __('Las Vegas, NV (LV)', 'order-sync-for-shipfusion-and-fluentcart')],
                    ['value' => 'PA', 'label' => __('York, PA (PA)', 'order-sync-for-shipfusion-and-fluentcart')],
                    ['value' => 'TO', 'label' => __('Toronto, Canada (TO)', 'order-sync-for-shipfusion-and-fluentcart')],
                ],
                'fallback_phone'       => $s['fallback_phone'],
                'send_orders'          => $s['send_orders'],
                'check_tracking'       => $s['check_tracking'],
                'tracking_interval'    => $s['tracking_interval'],
                'sync_stock'           => $s['sync_stock'],
                'stock_interval'       => $s['stock_interval'],
                'complete_on_delivery' => $s['complete_on_delivery'],
                'alerts'               => $s['alerts'],
                'alerts_email'         => $s['alerts_email'],
                'alerts_email_default' => (string) get_option('admin_email'),
                'debug'                => $s['debug'],
            ],
            'intervals'  => [
                ['value' => 'upfluent_sf_30_minutes', 'label' => __('Every 30 minutes', 'order-sync-for-shipfusion-and-fluentcart')],
                ['value' => 'hourly', 'label' => __('Every hour', 'order-sync-for-shipfusion-and-fluentcart')],
                ['value' => 'twicedaily', 'label' => __('Twice a day', 'order-sync-for-shipfusion-and-fluentcart')],
            ],
            'counts'     => Store::counts(),
            'runs'       => $runs,
            'webhook'    => [
                'url'           => Webhook::url(),
                'secret_set'    => Settings::hasWebhookSecret(),
                'last_event_at' => $state['webhook_last_at'] ?? null,
                'recent'        => array_values((array) ($state['webhook_recent'] ?? [])),
            ],
            'attention'  => $attention,
        ];
    }

    public function saveSettings(\WP_REST_Request $request)
    {
        $params = (array) $request->get_json_params();
        if (isset($params['token']) && is_string($params['token']) && trim($params['token']) !== '') {
            $token   = Settings::normalizeToken($params['token']);
            $problem = Settings::tokenProblem($token);
            if ($problem !== '') {
                return new \WP_Error('upfluent_sf_bad_token', $problem, ['status' => 422]);
            }
            if (!Secrets::available()) {
                return new \WP_Error('upfluent_sf_no_crypto', __('This server can\'t store the token safely (the PHP OpenSSL extension is missing). Ask your host to enable it.', 'order-sync-for-shipfusion-and-fluentcart'), ['status' => 500]);
            }
            Settings::saveToken($token);
            Settings::setState('connection', ['status' => 'unknown', 'message' => '', 'checked_at' => null]);
        }
        unset($params['token']);
        Settings::update($params);
        return new \WP_REST_Response(self::stateData());
    }

    public function testConnection(\WP_REST_Request $request): \WP_REST_Response
    {
        $params    = (array) $request->get_json_params();
        $typed     = isset($params['token']) && is_string($params['token']) ? Settings::normalizeToken($params['token']) : '';
        $picked    = array_values(array_intersect(Settings::WAREHOUSES, (array) ($params['warehouses'] ?? [])));
        $warehouse = $picked[0] ?? Settings::get('warehouses')[0];

        if ($typed !== '' && ($problem = Settings::tokenProblem($typed)) !== '') {
            return new \WP_REST_Response(['ok' => false, 'message' => $problem]);
        }
        $result = (new Api($typed !== '' ? $typed : null))->test($warehouse);
        if ($typed === '') {
            Settings::setConnection($result['ok'] ? 'ok' : 'error', $result['ok'] ? '' : $result['message']);
        }
        return new \WP_REST_Response($result);
    }

    public function run(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Settings::hasToken()) {
            return new \WP_REST_Response(['ok' => false, 'message' => __('Connect ShipFusion first.', 'order-sync-for-shipfusion-and-fluentcart')]);
        }
        switch ($request['job']) {
            case 'orders':
                return new \WP_REST_Response(Cron::sendOrders());
            case 'tracking':
                return new \WP_REST_Response(Cron::checkTracking());
            default:
                return new \WP_REST_Response(Cron::syncStock());
        }
    }

    public function orderAction(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request['id'];
        return new \WP_REST_Response($request['action'] === 'retry' ? Orders::retry($id) : Orders::dismiss($id));
    }

    public function newSecret(): \WP_REST_Response
    {
        return new \WP_REST_Response(['secret' => Settings::newWebhookSecret()]);
    }

    public function log(): \WP_REST_Response
    {
        return new \WP_REST_Response(['entries' => Log::recent()]);
    }
}
