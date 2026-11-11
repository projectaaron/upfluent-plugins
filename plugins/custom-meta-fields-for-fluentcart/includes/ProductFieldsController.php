<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST: per-product field values. Thin layer over ProductValuesService.
 */
class ProductFieldsController
{
    public function register(): void
    {
        register_rest_route('fct-custom-meta/v1', '/product/(?P<product_id>\d+)/fields', [
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'getProductFields'],
                'permission_callback' => [Permissions::class, 'canViewProduct'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'saveProductFields'],
                'permission_callback' => [Permissions::class, 'canEditProduct'],
            ],
        ]);
    }

    public function getProductFields(WP_REST_Request $request): WP_REST_Response
    {
        $result = ProductValuesService::get($request->get_url_params()['product_id'] ?? 0);
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response($result);
    }

    public function saveProductFields(WP_REST_Request $request): WP_REST_Response
    {
        $result = ProductValuesService::set(
            $request->get_url_params()['product_id'] ?? 0,
            $request->get_param('values')
        );
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response([
            'message' => __('Fields saved.', 'custom-meta-fields-for-fluentcart'),
            'values'  => $result,
        ]);
    }

    private function error(WP_Error $error): WP_REST_Response
    {
        $data   = (array) $error->get_error_data();
        $status = isset($data['status']) ? (int) $data['status'] : 400;
        unset($data['status']);
        return new WP_REST_Response(array_merge(['code' => $error->get_error_code(), 'message' => $error->get_error_message()], $data), $status);
    }
}
