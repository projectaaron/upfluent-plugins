<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Puts the checkout fields on FluentCart's own Settings → Checkout Fields
 * screen, as a "Custom Fields" section with the same on/off switch and
 * Required checkbox as the built-in fields.
 *
 * FluentCart 1.6.3 offers no PHP filter on that screen: the Vue page reads
 * GET fluent-cart/v2/checkout-fields/get-fields (`fields` schema + `settings`
 * map, rendered generically per group) and posts the whole `settings` map
 * back to save-fields, where CheckoutFieldsController keeps only the keys it
 * knows. So the bridge works at the REST layer instead:
 *
 *   rest_request_after_callbacks on get-fields   adds our group to both maps
 *   rest_request_after_callbacks on save-fields  reads settings.custom_fields
 *                                                from the request and stores
 *                                                the flags on our definitions
 *
 * The save hook only runs once FluentCart's own permission check and
 * callback have succeeded (the response is not an error), so it inherits the
 * store-settings permission that route already enforces.
 */
class CheckoutSettingsBridge
{
    const GROUP = 'custom_fields';
    const ROUTE_GET  = '/fluent-cart/v2/checkout-fields/get-fields';
    const ROUTE_SAVE = '/fluent-cart/v2/checkout-fields/save-fields';

    public function register(): void
    {
        add_filter('rest_request_after_callbacks', [$this, 'afterCallbacks'], 10, 3);
    }

    /**
     * @param WP_REST_Response|\WP_Error|mixed $response
     * @param array                            $handler
     * @param WP_REST_Request                  $request
     */
    public function afterCallbacks($response, $handler, $request)
    {
        if (!($request instanceof WP_REST_Request) || is_wp_error($response)) {
            return $response;
        }
        $route = $request->get_route();

        if ($route === self::ROUTE_GET && $request->get_method() === 'GET') {
            return $this->injectGroup($response);
        }
        if ($route === self::ROUTE_SAVE && $request->get_method() === 'POST') {
            $this->captureSave($request);
        }
        return $response;
    }

    private function injectGroup($response)
    {
        $fields = ProDefinitions::checkoutFields();
        if (!$fields) {
            return $response;
        }

        $data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
        if (!is_array($data) || !isset($data['fields']) || !is_array($data['fields'])) {
            return $response;
        }

        $schema   = [];
        $settings = [];
        foreach ($fields as $def) {
            $schema[$def['field_id']] = [
                'label'     => $def['field_title'],
                'help_text' => self::helpText($def),
                'type'      => $def['field_type'],
                'can_alter' => 'yes',
            ];
            $settings[$def['field_id']] = [
                'enabled'  => $def['enabled'] ? 'yes' : 'no',
                'required' => $def['required'] ? 'yes' : 'no',
            ];
        }

        $data['fields'][self::GROUP]   = $schema;
        $data['settings'][self::GROUP] = $settings;

        if ($response instanceof WP_REST_Response) {
            $response->set_data($data);
            return $response;
        }
        return $data;
    }

    private function captureSave(WP_REST_Request $request): void
    {
        $settings = $request->get_param('settings');
        if (!is_array($settings) || !isset($settings[self::GROUP]) || !is_array($settings[self::GROUP])) {
            return;
        }
        foreach ($settings[self::GROUP] as $field_id => $flags) {
            if (!is_string($field_id) || !is_array($flags)) {
                continue;
            }
            $def = FieldsService::find(sanitize_key($field_id));
            if (!$def || $def['context'] !== 'checkout') {
                continue;
            }
            $enabled  = array_key_exists('enabled', $flags) ? FieldsService::truthy($flags['enabled']) : $def['enabled'];
            $required = array_key_exists('required', $flags) ? FieldsService::truthy($flags['required']) : $def['required'];
            if ($enabled !== $def['enabled'] || $required !== $def['required']) {
                ProDefinitions::setFlags($def['field_id'], $enabled, $required);
            }
        }
    }

    /** One line under the switch: type, placement, and a hint about conditions. */
    private static function helpText(array $def): string
    {
        $placements = [
            'before_billing' => __('before the billing address', 'custom-meta-fields-for-fluentcart'),
            'after_shipping' => __('after the shipping address', 'custom-meta-fields-for-fluentcart'),
            'before_payment' => __('before the payment methods', 'custom-meta-fields-for-fluentcart'),
            'after_payment'  => __('after the payment methods', 'custom-meta-fields-for-fluentcart'),
            'after_button'   => __('after the place-order button', 'custom-meta-fields-for-fluentcart'),
            'order_summary'  => __('in the order summary', 'custom-meta-fields-for-fluentcart'),
        ];
        $parts = [
            sprintf(
                /* translators: 1: field type, 2: placement */
                __('Custom %1$s field, shown %2$s.', 'custom-meta-fields-for-fluentcart'),
                $def['field_type'],
                $placements[$def['placement']] ?? $def['placement']
            ),
        ];
        if (!empty($def['conditions']['enabled'])) {
            $parts[] = __('Has conditional logic.', 'custom-meta-fields-for-fluentcart');
        }
        $parts[] = sprintf(
            /* translators: %s: link text */
            __('Edit it under More → Custom Fields → Checkout Fields (%s).', 'custom-meta-fields-for-fluentcart'),
            $def['field_id']
        );
        return implode(' ', $parts);
    }
}
