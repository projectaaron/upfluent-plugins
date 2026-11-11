<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * WordPress Abilities (WP 6.9+) so an AI agent can manage custom fields.
 *
 * Exposure paths, all automatic once registered:
 *  - FluentCart's MCP server (/wp-json/fluent-cart/mcp) when its MCP module is
 *    enabled: we push our ability names through `fluent_cart/mcp_ability_names`.
 *  - The generic WordPress MCP adapter (mcp-adapter plugin / FluentHub) which
 *    exposes every registered ability.
 *  - The Abilities REST API (show_in_rest).
 *
 * Every ability delegates to FieldsService / ProductValuesService, so it obeys
 * the same validation as the admin UI and the REST routes, and checks the same
 * FluentCart permissions (store/settings for definitions, products/view|edit
 * for values).
 */
class Abilities
{
    const NAMESPACE = 'fct-custom-meta';

    public function register(): void
    {
        if (!function_exists('wp_register_ability')) {
            return; // WordPress < 6.9: no Abilities API
        }

        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

        // Expose our abilities on FluentCart's MCP server too.
        add_filter('fluent_cart/mcp_ability_names', function ($names) {
            return array_merge((array) $names, array_keys(self::definitions()));
        });
    }

    public function registerCategory(): void
    {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category(self::NAMESPACE, [
                'label'       => __('FluentCart Custom Meta Fields', 'custom-meta-fields-for-fluentcart'),
                'description' => __('Define custom product fields and read or write their values on FluentCart products.', 'custom-meta-fields-for-fluentcart'),
            ]);
        }
    }

    public function registerAbilities(): void
    {
        foreach (self::definitions() as $name => $def) {
            try {
                wp_register_ability($name, [
                    'label'               => $def['label'],
                    'description'         => $def['description'],
                    'category'            => self::NAMESPACE,
                    'input_schema'        => $def['input_schema'],
                    'output_schema'       => $def['output_schema'] ?? ['type' => 'object'],
                    'execute_callback'    => $def['execute_callback'],
                    'permission_callback' => $def['permission_callback'],
                    'meta'                => [
                        'show_in_rest' => true,
                        'mcp'          => ['public' => true],
                        // The WP MCP adapter forwards these verbatim, so use
                        // the MCP spec's hint names.
                        'annotations'  => [
                            'readOnlyHint'    => (bool) ($def['annotations']['readonly'] ?? false),
                            'destructiveHint' => (bool) ($def['annotations']['destructive'] ?? false),
                            'idempotentHint'  => (bool) ($def['annotations']['idempotent'] ?? false),
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                // One bad definition must not take down the abilities system.
                if (function_exists('fluent_cart_error_log')) {
                    fluent_cart_error_log('Custom Meta ability registration failed: ' . $name, $e->getMessage());
                }
            }
        }
    }

    /** @return array<string, array> */
    public static function definitions(): array
    {
        $ns   = self::NAMESPACE . '/';
        $view = [Permissions::class, 'canViewProduct'];
        $edit = [Permissions::class, 'canEditProduct'];
        $mng  = [Permissions::class, 'canManageDefinitions'];

        $fieldSchema = [
            'type'       => 'object',
            'properties' => apply_filters('upfluent_cmf_ability_field_properties', [
                'context'     => ['type' => 'string', 'enum' => FieldsService::contexts(), 'description' => 'Where the field lives. "product" puts it on every FluentCart product edit page. Fixed after creation.'],
                'field_id'    => ['type' => 'string'],
                'field_type'  => ['type' => 'string', 'enum' => FieldsService::types('product')],
                'field_title' => ['type' => 'string'],
                'options'     => ['type' => 'string', 'description' => 'Comma-separated options (select type only).'],
                'placeholder' => ['type' => 'string'],
            ]),
        ];

        $defs = [
            $ns . 'list-fields' => [
                'label'               => __('List Custom Fields', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('List every custom field definition in display order. Call this before creating a field to avoid duplicate ids.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => ['type' => 'object', 'properties' => new \stdClass()],
                'output_schema'       => ['type' => 'object', 'properties' => ['fields' => ['type' => 'array', 'items' => $fieldSchema]]],
                'execute_callback'    => [self::class, 'listFields'],
                'permission_callback' => $view,
                'annotations'         => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'create-field' => [
                'label'               => __('Create Custom Field', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Create a custom field on every FluentCart product edit page. field_id is a permanent machine name (lowercase letters, numbers, underscores, hyphens). Select fields require comma-separated options.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => $fieldSchema['properties'],
                    'required'   => ['field_id', 'field_title', 'field_type'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => ['field' => $fieldSchema, 'fields' => ['type' => 'array', 'items' => $fieldSchema]]],
                'execute_callback'    => [self::class, 'createField'],
                'permission_callback' => $mng,
                'annotations'         => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],

            $ns . 'update-field' => [
                'label'               => __('Update Custom Field', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Update the title, type, options, or placeholder of an existing custom field. field_id cannot be changed. Changing the type does not convert stored values.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => $fieldSchema['properties'],
                    'required'   => ['field_id'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => ['field' => $fieldSchema, 'fields' => ['type' => 'array', 'items' => $fieldSchema]]],
                'execute_callback'    => [self::class, 'updateField'],
                'permission_callback' => $mng,
                'annotations'         => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'delete-field' => [
                'label'               => __('Delete Custom Field', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Delete a custom field definition. A product field also loses its stored values on every product. This cannot be undone.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => ['field_id' => ['type' => 'string']],
                    'required'   => ['field_id'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => ['deleted' => ['type' => 'string'], 'fields' => ['type' => 'array', 'items' => $fieldSchema]]],
                'execute_callback'    => [self::class, 'deleteField'],
                'permission_callback' => $mng,
                'annotations'         => ['readonly' => false, 'destructive' => true, 'idempotent' => true],
            ],

            $ns . 'reorder-fields' => [
                'label'               => __('Reorder Custom Fields', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Set the display order of custom fields by listing field ids. Fields not listed keep their relative order at the end.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => ['order' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    'required'   => ['order'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => ['fields' => ['type' => 'array', 'items' => $fieldSchema]]],
                'execute_callback'    => [self::class, 'reorderFields'],
                'permission_callback' => $mng,
                'annotations'         => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'get-product-fields' => [
                'label'               => __('Get Product Custom Field Values', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Read the custom field values stored on one FluentCart product, together with the field definitions. product_id is the product post ID.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => ['product_id' => ['type' => 'integer']],
                    'required'   => ['product_id'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => [
                    'product_id'  => ['type' => 'integer'],
                    'fields'      => ['type' => 'array', 'items' => $fieldSchema],
                    'values'      => ['type' => 'object', 'additionalProperties' => true],
                ]],
                'execute_callback'    => [self::class, 'getProductFields'],
                'permission_callback' => $view,
                'annotations'         => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'set-product-fields' => [
                'label'               => __('Set Product Custom Field Values', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Write custom field values on one FluentCart product. values is an object of field_id => value. Values are sanitised per field type (checkbox: "1"/"0"; date: YYYY-MM-DD; color: #rrggbb; select: must be a defined option). Unknown field ids are ignored and reported.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'values'     => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required'   => ['product_id', 'values'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => [
                    'product_id' => ['type' => 'integer'],
                    'saved'      => ['type' => 'object', 'additionalProperties' => true],
                ]],
                'execute_callback'    => [self::class, 'setProductFields'],
                'permission_callback' => $edit,
                'annotations'         => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'get-product-identifiers' => [
                'label'               => __('Get Product Identifiers', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Read the brand of one FluentCart product and the GTIN (EAN, UPC, ISBN) and MPN of each of its variations, with each variation\'s title and SKU. product_id is the product post ID.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => ['product_id' => ['type' => 'integer']],
                    'required'   => ['product_id'],
                ],
                'output_schema'       => ['type' => 'object', 'properties' => [
                    'product_id' => ['type' => 'integer'],
                    'brand'      => ['type' => 'string'],
                    'rows'       => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                ]],
                'execute_callback'    => [self::class, 'getProductIdentifiers'],
                'permission_callback' => $view,
                'annotations'         => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],

            $ns . 'set-product-identifiers' => [
                'label'               => __('Set Product Identifiers', 'custom-meta-fields-for-fluentcart'),
                'description'         => __('Replace the brand of one FluentCart product and the GTIN and MPN of its variations. rows is an array of {variation_id, gtin, mpn}; a variation left out is cleared. GTINs must have 8, 12, 13 or 14 digits with a valid check digit (an ISBN-10 is converted to GTIN-13) and be unique across products. Invalid rows are reported and nothing is saved.', 'custom-meta-fields-for-fluentcart'),
                'input_schema'        => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'brand'      => ['type' => 'string'],
                        'rows'       => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                            'variation_id' => ['type' => 'integer'],
                            'gtin'         => ['type' => 'string'],
                            'mpn'          => ['type' => 'string'],
                        ], 'required' => ['variation_id']]],
                    ],
                    'required'   => ['product_id', 'rows'],
                ],
                'output_schema'       => ['type' => 'object', 'additionalProperties' => true],
                'execute_callback'    => [self::class, 'setProductIdentifiers'],
                'permission_callback' => $edit,
                'annotations'         => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],

        ];

        // ProDefinitions adds checkout-order abilities and its field types here.
        $defs = apply_filters('upfluent_cmf_ability_definitions', $defs, $ns, $fieldSchema);

        return $defs;
    }

    // ─── Callbacks ───

    public static function listFields($params = [])
    {
        return ['fields' => FieldsService::all()];
    }

    public static function createField($params = [])
    {
        $result = FieldsService::create(self::arr($params));
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['field' => $result, 'fields' => FieldsService::all()];
    }

    public static function updateField($params = [])
    {
        $params = self::arr($params);
        $result = FieldsService::update((string) ($params['field_id'] ?? ''), $params);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['field' => $result, 'fields' => FieldsService::all()];
    }

    public static function deleteField($params = [])
    {
        $params = self::arr($params);
        $id     = sanitize_key((string) ($params['field_id'] ?? ''));
        $result = FieldsService::delete($id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['deleted' => $id, 'fields' => FieldsService::all()];
    }

    public static function reorderFields($params = [])
    {
        $params = self::arr($params);
        $result = FieldsService::reorder($params['order'] ?? null);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['fields' => $result];
    }

    public static function getProductFields($params = [])
    {
        $params = self::arr($params);
        $result = ProductValuesService::get($params['product_id'] ?? 0);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['product_id' => absint($params['product_id'] ?? 0)] + $result;
    }

    public static function setProductFields($params = [])
    {
        $params = self::arr($params);
        $values = $params['values'] ?? null;
        if (is_object($values)) {
            $values = (array) $values;
        }
        $result = ProductValuesService::set($params['product_id'] ?? 0, $values);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['product_id' => absint($params['product_id'] ?? 0), 'saved' => $result];
    }

    public static function getProductIdentifiers($params = [])
    {
        $params = self::arr($params);
        $result = ProductIdentifiers::get($params['product_id'] ?? 0);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['product_id' => absint($params['product_id'] ?? 0)] + $result;
    }

    public static function setProductIdentifiers($params = [])
    {
        $params = self::arr($params);
        $rows   = array_map(function ($r) {
            return is_object($r) ? (array) $r : $r;
        }, (array) ($params['rows'] ?? []));
        $result = ProductIdentifiers::set($params['product_id'] ?? 0, ['brand' => $params['brand'] ?? '', 'rows' => $rows]);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['product_id' => absint($params['product_id'] ?? 0)] + $result;
    }

    private static function arr($params): array
    {
        if (is_object($params)) {
            $params = (array) $params;
        }
        return is_array($params) ? $params : [];
    }
}
