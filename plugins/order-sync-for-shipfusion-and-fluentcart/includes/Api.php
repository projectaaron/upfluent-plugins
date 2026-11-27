<?php

namespace UpfluentSf;

defined('ABSPATH') || exit;

/**
 * ShipFusion REST API client (https://docs.shipfusion.com, "Shipfusion REST
 * API" v1). The address is fixed: the token is only ever sent to ShipFusion.
 *
 * Auth: the API token, raw, in the X-SHIPFUSION-TOKEN header (a "Bearer "
 * prefix there is refused; verified live, Sept 2026).
 *
 * Every 2xx answer is also checked for responseStatus, which ShipFusion sets
 * to something other than "success" when it refused the request.
 *
 * Retries: GET/PUT/DELETE are retried twice on network errors, 429 and 5xx.
 * POST is retried only on 429, because a POST that timed out may already have
 * created a shipment; callers look it up before treating it as failed.
 */
class Api
{
    const BASE    = 'https://api.shipfusion.com/v1';
    const TIMEOUT = 15;

    /** @var string */
    private $token;

    /** @var bool Using the saved token: only then do answers change the connection status. */
    private $saved;

    public function __construct(?string $token = null)
    {
        $this->saved = $token === null;
        $this->token = $token ?? Settings::token();
    }

    public function configured(): bool
    {
        return $this->token !== '';
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Checks the token with a one-item inventory read (read-only).
     *
     * @return array{ok: bool, message: string}
     */
    public function test(string $warehouse): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'message' => __('Paste your ShipFusion API token first.', 'order-sync-for-shipfusion-and-fluentcart')];
        }
        try {
            $this->get('/inventory', ['warehouse' => $warehouse, 'perPage' => 1, 'page' => 1]);
            return ['ok' => true, 'message' => __('Connected to ShipFusion.', 'order-sync-for-shipfusion-and-fluentcart')];
        } catch (ApiException $e) {
            return ['ok' => false, 'message' => $e->text()];
        }
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if (!$this->configured()) {
            throw new ApiException(esc_html__('ShipFusion is not connected yet. Paste your API token on the ShipFusion screen.', 'order-sync-for-shipfusion-and-fluentcart'), 0, 'auth');
        }

        $url = self::BASE . '/' . ltrim($path, '/');
        if ($query) {
            $url = add_query_arg(array_map('rawurlencode', $query), $url);
        }
        $args = [
            'method'  => $method,
            'timeout' => self::TIMEOUT,
            'headers' => [
                'X-SHIPFUSION-TOKEN' => $this->token,
                'Accept'             => 'application/json',
                'Content-Type'       => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $attempts = $method === 'POST' ? 1 : 3;
        for ($try = 1; ; $try++) {
            $response = wp_safe_remote_request($url, $args);
            try {
                return $this->parse($response, $method);
            } catch (ApiException $e) {
                $code       = $e->getCode();
                $retryable  = $e->kind() === 'temporary' || ($code === 429);
                $postRetry  = $method === 'POST' && $code === 429 && $try < 3;
                if (!($retryable && ($try < $attempts || $postRetry))) {
                    throw $e;
                }
                $wait = $code === 429 ? min(10, max(1, (int) wp_remote_retrieve_header($response, 'retry-after'))) : $try;
                sleep($wait);
            }
        }
    }

    /**
     * @param array|\WP_Error $response
     */
    private function parse($response, string $method): array
    {
        if (is_wp_error($response)) {
            throw new ApiException(
                esc_html__('Couldn\'t reach ShipFusion. It will be tried again automatically.', 'order-sync-for-shipfusion-and-fluentcart'),
                0,
                $method === 'POST' ? 'unsure' : 'temporary'
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);
        $data = is_array($json) ? $json : [];

        if ($code === 401 || $code === 403) {
            $message = __('ShipFusion refused the API token. Paste a current token from Shipfusion 360, Settings, Integrations, API Credentials.', 'order-sync-for-shipfusion-and-fluentcart');
            if ($this->saved) {
                Settings::setConnection('error', $message);
            }
            throw new ApiException(esc_html($message), (int) $code, 'auth');
        }

        if ($code >= 200 && $code < 300) {
            if (!is_array($json)) {
                // A 2xx without a JSON object can't be trusted to mean "done".
                throw new ApiException(esc_html__('ShipFusion sent an answer this plugin couldn\'t read. It will be tried again.', 'order-sync-for-shipfusion-and-fluentcart'), (int) $code, $method === 'POST' ? 'unsure' : 'temporary');
            }
            $status = strtolower((string) ($data['responseStatus'] ?? 'success'));
            if ($status !== 'success') {
                throw new ApiException(esc_html(self::reason($data)), (int) $code, 'data');
            }
            if ($this->saved) {
                Settings::setConnection('ok');
            }
            return $data;
        }

        if ($code === 429 || $code >= 500) {
            throw new ApiException(
                esc_html__('ShipFusion is busy or having trouble. It will be tried again automatically.', 'order-sync-for-shipfusion-and-fluentcart'),
                (int) $code,
                ($method === 'POST' && $code >= 500) ? 'unsure' : 'temporary'
            );
        }

        if ($code === 404) {
            throw new ApiException(esc_html__('ShipFusion has no record of this shipment.', 'order-sync-for-shipfusion-and-fluentcart'), (int) $code, 'data');
        }

        throw new ApiException(esc_html(self::reason($data)), (int) $code, 'data');
    }

    /** ShipFusion's own explanation, prefixed so the owner knows where it came from. */
    private static function reason(array $data): string
    {
        $parts = [];
        if (!empty($data['message']) && is_string($data['message'])) {
            $parts[] = $data['message'];
        }
        if (!empty($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $field => $error) {
                if (is_scalar($error)) {
                    $parts[] = is_string($field) ? $field . ': ' . $error : (string) $error;
                }
            }
        }
        $text = $parts ? implode(' ', $parts) : __('no reason given', 'order-sync-for-shipfusion-and-fluentcart');
        /* translators: %s: ShipFusion's own error message */
        return sprintf(__('ShipFusion said: %s', 'order-sync-for-shipfusion-and-fluentcart'), wp_strip_all_tags($text));
    }
}
