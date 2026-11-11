<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST: field definition CRUD. Thin layer over FieldsService.
 */
class FieldDefinitionsController
{
    public function register(): void
    {
        $perm = [Permissions::class, 'canManageDefinitions'];

        register_rest_route('fct-custom-meta/v1', '/fields', [
            ['methods' => 'GET',  'callback' => [$this, 'getFields'],   'permission_callback' => $perm],
            ['methods' => 'POST', 'callback' => [$this, 'createField'], 'permission_callback' => $perm],
        ]);

        register_rest_route('fct-custom-meta/v1', '/fields/reorder', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reorderFields'],
            'permission_callback' => $perm,
        ]);

        register_rest_route('fct-custom-meta/v1', '/fields/(?P<field_id>[a-z0-9_-]+)', [
            ['methods' => 'PUT',    'callback' => [$this, 'updateField'], 'permission_callback' => $perm],
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteField'], 'permission_callback' => $perm],
        ]);
    }

    public function getFields(): WP_REST_Response
    {
        return new WP_REST_Response(['fields' => FieldsService::all()]);
    }

    public function createField(WP_REST_Request $request): WP_REST_Response
    {
        $result = FieldsService::create((array) $request->get_json_params() + (array) $request->get_body_params());
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response([
            'message' => __('Field created.', 'custom-meta-fields-for-fluentcart'),
            'field'   => $result,
            'fields'  => FieldsService::all(),
        ]);
    }

    public function updateField(WP_REST_Request $request): WP_REST_Response
    {
        $field_id = (string) ($request->get_url_params()['field_id'] ?? '');
        $result   = FieldsService::update($field_id, (array) $request->get_json_params() + (array) $request->get_body_params());
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response([
            'message' => __('Field updated.', 'custom-meta-fields-for-fluentcart'),
            'field'   => $result,
            'fields'  => FieldsService::all(),
        ]);
    }

    public function deleteField(WP_REST_Request $request): WP_REST_Response
    {
        $field_id = (string) ($request->get_url_params()['field_id'] ?? '');
        $result   = FieldsService::delete($field_id);
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response([
            'message' => __('Field deleted.', 'custom-meta-fields-for-fluentcart'),
            'fields'  => FieldsService::all(),
        ]);
    }

    public function reorderFields(WP_REST_Request $request): WP_REST_Response
    {
        $result = FieldsService::reorder($request->get_param('order'));
        if ($result instanceof WP_Error) {
            return $this->error($result);
        }
        return new WP_REST_Response([
            'message' => __('Fields reordered.', 'custom-meta-fields-for-fluentcart'),
            'fields'  => $result,
        ]);
    }

    /** Kept for backwards compatibility with 1.0.x callers. */
    public static function loadFields(): array
    {
        return FieldsService::all();
    }

    private function error(WP_Error $error): WP_REST_Response
    {
        $data   = (array) $error->get_error_data();
        $status = isset($data['status']) ? (int) $data['status'] : 400;
        unset($data['status']);
        return new WP_REST_Response(array_merge(['code' => $error->get_error_code(), 'message' => $error->get_error_message()], $data), $status);
    }
}
