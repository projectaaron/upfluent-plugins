<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Checkout-context fields: rendered into FluentCart's checkout form, validated
 * and saved with the order, shown on the order screen, the thank-you page and
 * in emails.
 *
 * How it fits FluentCart 1.6.x (all verified against source):
 *
 *  render    Every placement in ProDefinitions::PLACEMENTS is a do_action inside
 *            the checkout <form> (CheckoutRenderer). The checkout JS submits the
 *            whole form with `new FormData(this.form)`, so any named input we
 *            print is posted with the order.
 *  validate  `fluent_cart/checkout/validate_data` ($errors, ['data','cart']) in
 *            CheckoutApi::validateData. Errors are $errors[input][rule] = message;
 *            FluentCart shows every message in a toast.
 *  save      `fluent_cart/checkout/prepare_other_data` fires after the draft
 *            order exists and before it is finalised; values go to one
 *            fct_order_meta row via Order::updateMeta() (arrays are JSON there).
 *  admin     `fluent_cart/widgets/single_order_page` ($widgets, ['order', ...])
 *            renders an HTML card on the order screen.
 *  receipt   `fluent_cart/receipt/thank_you/after_order_items` ($config['order']).
 *  emails    `fluent_cart/editor_shortcodes` lists {{checkout.*}} in the editor;
 *            `fluent_cart/smartcode_fallback` resolves them, since "checkout" is
 *            not one of FluentCart's parser groups.
 *
 * Conditional visibility is evaluated in two places with the same rules: in
 * assets/checkout.js against the live form, and here against the submitted
 * data, so a hidden field is never required and never saved.
 */
class CheckoutFields
{
    const ORDER_META_KEY = 'cmf_checkout_fields';
    const SCRIPT_HANDLE  = 'fct-custom-meta-checkout';
    const STYLE_HANDLE   = 'fct-custom-meta-checkout';
    const SMARTCODE_KEY  = 'checkout';

    private bool $assetsQueued = false;

    /** Placements already printed this request, so a fallback hook never prints them twice. */
    private array $rendered = [];

    /**
     * Extra hooks per placement for FluentCart's block checkout, which renders
     * through the same CheckoutRenderer methods but skips the classic form's
     * own before_billing_fields, after_checkout_button and after_order_notes
     * actions (verified against FluentCart 1.6.4). Whichever fires first wins.
     */
    const FALLBACK_HOOKS = [
        'before_billing' => 'fluent_cart/before_billing_fields_section',
        'order_summary'  => 'fluent_cart/after_order_notes_field',
    ];

    public function register(): void
    {
        foreach (ProDefinitions::PLACEMENTS as $placement => $hook) {
            add_action($hook, function ($args = []) use ($placement) {
                $this->renderPlacement($placement, is_array($args) ? $args : []);
            }, 10, 1);
        }
        foreach (self::FALLBACK_HOOKS as $placement => $hook) {
            add_action($hook, function ($args = []) use ($placement) {
                $this->renderPlacement($placement, is_array($args) ? $args : []);
            }, 10, 1);
        }
        // The block checkout's submit button has no action after it.
        add_filter('render_block_fluent-cart/checkout-submit-button', function ($html) {
            ob_start();
            $this->renderPlacement('after_button');
            return $html . ob_get_clean();
        }, 10, 1);

        add_action('wp_enqueue_scripts', [$this, 'registerAssets']);

        add_filter('fluent_cart/checkout/validate_data', [$this, 'validate'], 10, 2);
        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'save'], 10, 1);

        add_filter('fluent_cart/widgets/single_order_page', [$this, 'orderWidget'], 10, 2);
        add_action('fluent_cart/receipt/thank_you/after_order_items', [$this, 'renderReceipt'], 10, 1);

        add_filter('fluent_cart/editor_shortcodes', [$this, 'editorShortcodes']);
        add_filter('fluent_cart/smartcode_fallback', [$this, 'resolveSmartcode'], 10, 4);
    }

    /** Checkout definitions switched on (the switch lives on FluentCart's Checkout Fields screen too). */
    public static function activeFields(): array
    {
        return array_values(array_filter(ProDefinitions::checkoutFields(), function ($f) {
            return !empty($f['enabled']);
        }));
    }

    // ─── Front end ───

    public function registerAssets(): void
    {
        wp_register_script(self::SCRIPT_HANDLE, UPFLUENT_CMF_URL . 'assets/checkout.js', [], UPFLUENT_CMF_VERSION, true);
        wp_register_style(self::STYLE_HANDLE, UPFLUENT_CMF_URL . 'assets/checkout.css', [], UPFLUENT_CMF_VERSION);
    }

    private function enqueueAssets(): void
    {
        if ($this->assetsQueued) {
            return;
        }
        $this->assetsQueued = true;

        // Block themes render the page before wp_enqueue_scripts fires, so the
        // handles may not exist yet; localizing an unregistered handle is
        // silently dropped (and uploads then fail their nonce check).
        if (!wp_script_is(self::SCRIPT_HANDLE, 'registered')) {
            $this->registerAssets();
        }

        wp_enqueue_style(self::STYLE_HANDLE);
        wp_enqueue_script(self::SCRIPT_HANDLE);
        wp_localize_script(self::SCRIPT_HANDLE, 'fctCmfCheckout', [
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'uploadNonce' => wp_create_nonce(CheckoutUploads::NONCE_UPLOAD),
            'i18n' => [
                'uploading'     => __('Uploading…', 'custom-meta-fields-for-fluentcart'),
                'uploadFailed'  => __('The upload failed. Please try again.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'stillUploading' => __('%s is still uploading. Please wait.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: size like 10 MB */
                'tooLarge'      => __('The file must be smaller than %s.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: comma separated extensions */
                'badType'       => __('Allowed file types: %s', 'custom-meta-fields-for-fluentcart'),
                'remove'        => __('Remove', 'custom-meta-fields-for-fluentcart'),
                'chooseFile'    => __('Choose file', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'required'      => __('%s is required.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'invalidEmail'  => __('%s must be a valid email address.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'invalidUrl'    => __('%s must be a valid URL.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'invalidNumber' => __('%s must be a number.', 'custom-meta-fields-for-fluentcart'),
                /* translators: %s: field title */
                'invalidDate'   => __('%s must be a valid date.', 'custom-meta-fields-for-fluentcart'),
                'select'        => __('— Select —', 'custom-meta-fields-for-fluentcart'),
            ],
        ]);
    }

    /**
     * Prints every checkout field assigned to a placement.
     *
     * @param string $placement  key of ProDefinitions::PLACEMENTS
     * @param array  $args       ['cart' => Cart] from FluentCart
     */
    public function renderPlacement(string $placement, array $args = []): void
    {
        if (isset($this->rendered[$placement])) {
            return;
        }
        $fields = array_values(array_filter(self::activeFields(), function ($f) use ($placement) {
            return $f['placement'] === $placement;
        }));

        $fields = apply_filters('upfluent_cmf_checkout_fields_to_render', $fields, $placement, $args);
        if (!$fields) {
            return;
        }
        $this->rendered[$placement] = true;

        $this->enqueueAssets();

        // Values already known to the cart (country, ship-to-different…) let
        // the server pick the right initial visibility, so nothing flashes.
        $known = [];
        $cart  = $args['cart'] ?? null;
        if (is_object($cart) && isset($cart->checkout_data) && is_array($cart->checkout_data)) {
            $known = (array) ($cart->checkout_data['form_data'] ?? []);
        }
        ?>
        <div class="fct_checkout_form_section cmf-checkout-section cmf-checkout-section--<?php echo esc_attr($placement); ?>" data-cmf-section="<?php echo esc_attr($placement); ?>">
            <div class="fct_form_section_body">
                <div class="cmf-checkout-group">
                    <?php foreach ($fields as $def) {
                        $this->renderField($def, $known);
                    } ?>
                </div>
            </div>
        </div>
        <?php
    }

    /** One field, in FluentCart's own wrapper/label/input classes so their CSS applies. */
    private function renderField(array $def, array $known): void
    {
        $type    = $def['field_type'];
        $name    = ProDefinitions::inputName($def);
        $id      = $name;
        $title   = $def['field_title'];
        $visible = self::isVisible($def, $known);

        $wrapClasses = ['fct_input_wrapper', 'fct_input_wrapper_' . $type, 'cmf-checkout-field', 'cmf-checkout-field--' . $type];
        if (!$visible) {
            $wrapClasses[] = 'cmf-hidden';
        }

        $inputClass = 'fct-input fct-input-' . $type;
        $star       = $def['required'] ? ' <span class="cmf-required-mark" aria-hidden="true">*</span>' : '';

        // Extra attributes on the input: disabled while hidden, required, description, placeholder.
        $state = [];
        if (!$visible) {
            $state['disabled'] = 'disabled';
        }
        if ($visible && $def['required']) {
            $state['required']      = 'required';
            $state['aria-required'] = 'true';
        }
        if ($def['description'] !== '') {
            $state['aria-describedby'] = $id . '_desc';
        }
        $text = $state;
        if ($def['placeholder'] !== '') {
            $text['placeholder'] = $def['placeholder'];
        }
        $attrs = function (array $attributes) {
            foreach ($attributes as $attr => $value) {
                echo ' ' . esc_attr($attr) . '="' . esc_attr($value) . '"';
            }
        };
        ?>
        <div class="<?php echo esc_attr(implode(' ', $wrapClasses)); ?>"
             id="fct_wrapper_<?php echo esc_attr($id); ?>"
             data-cmf-field="<?php echo esc_attr($name); ?>"
             data-cmf-type="<?php echo esc_attr($type); ?>"
             data-cmf-title="<?php echo esc_attr($title); ?>"
             data-cmf-required="<?php echo $def['required'] ? '1' : '0'; ?>"
             data-cmf-conditions="<?php echo esc_attr(wp_json_encode($def['conditions'])); ?>">
            <?php if ($type === 'checkbox') : ?>
                <label for="<?php echo esc_attr($id); ?>" class="fct_input_label fct_input_label_checkbox">
                    <input type="checkbox" class="<?php echo esc_attr($inputClass); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" value="1"<?php $attrs($state); ?> />
                    <span><?php echo esc_html($title) . $star; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
                </label>
            <?php else : ?>
                <label for="<?php echo esc_attr($id); ?>" class="fct_input_label fct_input_label_<?php echo esc_attr($type); ?>">
                    <?php echo esc_html($title) . $star; // phpcs:ignore WordPress.Security.EscapeOutput ?>
                </label>
                <?php if ($type === 'file') :
                    $exts  = CheckoutUploads::allowedExtensions($def);
                    $accept = implode(',', array_map(function ($e) { return '.' . $e; }, $exts));
                    ?>
                    <div class="cmf-file" data-cmf-accept="<?php echo esc_attr(implode(',', $exts)); ?>" data-cmf-max="<?php echo esc_attr((string) CheckoutUploads::maxBytes($def)); ?>">
                        <input type="hidden" class="cmf-file-token" name="<?php echo esc_attr($name); ?>" value=""<?php $attrs($visible ? [] : ['disabled' => 'disabled']); ?> />
                        <input type="file" class="fct-input fct-input-file cmf-file-input" id="<?php echo esc_attr($id); ?>" accept="<?php echo esc_attr($accept); ?>"<?php $attrs($state); ?> />
                        <div class="cmf-file-status" aria-live="polite"></div>
                    </div>
                    <p class="cmf-file-hint"><?php echo esc_html(sprintf(
                        /* translators: 1: extensions, 2: size */
                        __('%1$s · up to %2$s', 'custom-meta-fields-for-fluentcart'),
                        implode(', ', $exts),
                        size_format(CheckoutUploads::maxBytes($def))
                    )); ?></p>
                <?php elseif ($type === 'textarea') : ?>
                    <textarea class="<?php echo esc_attr($inputClass); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>" rows="3"<?php $attrs($text); ?>></textarea>
                <?php elseif ($type === 'select') : ?>
                    <select class="<?php echo esc_attr($inputClass); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"<?php $attrs($state); ?>>
                        <option value=""><?php echo esc_html($def['placeholder'] !== '' ? $def['placeholder'] : __('— Select —', 'custom-meta-fields-for-fluentcart')); ?></option>
                        <?php foreach (FieldsService::optionsList($def['options']) as $opt) : ?>
                            <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else :
                    $html_type = in_array($type, ['email', 'number', 'url', 'date'], true) ? $type : 'text';
                    ?>
                    <input type="<?php echo esc_attr($html_type); ?>" class="<?php echo esc_attr($inputClass); ?>" id="<?php echo esc_attr($id); ?>" name="<?php echo esc_attr($name); ?>"<?php $attrs($type === 'number' ? $text + ['step' => 'any'] : $text); ?> />
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($def['description'] !== '') : ?>
                <p class="cmf-description" id="<?php echo esc_attr($id . '_desc'); ?>"><?php echo esc_html($def['description']); ?></p>
            <?php endif; ?>
            <span class="fct_form_error cmf-field-error" data-cmf-error-for="<?php echo esc_attr($name); ?>" role="alert" aria-live="polite"></span>
        </div>
        <?php
    }

    // ─── Conditions ───

    /**
     * Whether a field is shown for the given submitted values. Rule fields are
     * form input names; a missing input (unticked checkbox, disabled hidden
     * field) counts as empty.
     */
    public static function isVisible(array $def, array $values): bool
    {
        $c = $def['conditions'] ?? [];
        if (empty($c['enabled']) || empty($c['rules'])) {
            return true;
        }

        $results = [];
        foreach ($c['rules'] as $rule) {
            $results[] = self::ruleMatches($rule, $values);
        }

        return ($c['relation'] ?? 'all') === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    private static function ruleMatches(array $rule, array $values): bool
    {
        $actual = $values[$rule['field']] ?? '';
        if (is_array($actual)) {
            $actual = implode(',', array_filter($actual, 'is_scalar'));
        }
        $actual   = is_scalar($actual) ? trim((string) $actual) : '';
        $expected = trim((string) ($rule['value'] ?? ''));

        switch ($rule['operator'] ?? 'equals') {
            case 'not_equals':   return strcasecmp($actual, $expected) !== 0;
            case 'contains':     return $expected !== '' && stripos($actual, $expected) !== false;
            case 'not_contains': return $expected === '' || stripos($actual, $expected) === false;
            case 'empty':        return $actual === '';
            case 'not_empty':    return $actual !== '';
            default:             return strcasecmp($actual, $expected) === 0;
        }
    }

    // ─── Validation and saving ───

    /**
     * fluent_cart/checkout/validate_data
     *
     * @param array $errors  [$input => [$rule => $message]]
     * @param array $args    ['data' => submitted form, 'cart' => Cart]
     */
    public function validate($errors, $args = [])
    {
        if (!is_array($errors)) {
            $errors = [];
        }
        $data = is_array($args['data'] ?? null) ? $args['data'] : [];

        foreach (self::activeFields() as $def) {
            if (!self::isVisible($def, $data)) {
                continue;
            }

            $name  = ProDefinitions::inputName($def);
            $title = $def['field_title'];
            $raw   = $data[$name] ?? '';
            $raw   = is_scalar($raw) ? trim((string) $raw) : '';
            $clean = self::sanitize($raw, $def);

            if ($def['required'] && ($clean === '' || ($def['field_type'] === 'checkbox' && $clean !== '1'))) {
                /* translators: %s: field title */
                $errors[$name]['required'] = sprintf(__('%s is required.', 'custom-meta-fields-for-fluentcart'), $title);
                continue;
            }

            // An upload token must still be pending and belong to this field.
            if ($def['field_type'] === 'file' && $clean !== '') {
                $pending = CheckoutUploads::pending($clean);
                if (!$pending || $pending['field_id'] !== $def['field_id'] || CheckoutUploads::tokenTakenByPaidOrder($clean)) {
                    /* translators: %s: field title */
                    $errors[$name]['expired'] = sprintf(__('%s: the uploaded file has expired. Please attach it again.', 'custom-meta-fields-for-fluentcart'), $title);
                }
                continue;
            }

            // Something was typed but did not survive sanitisation: wrong shape.
            if ($raw !== '' && $clean === '') {
                $messages = [
                    /* translators: %s: field title */
                    'email'  => __('%s must be a valid email address.', 'custom-meta-fields-for-fluentcart'),
                    /* translators: %s: field title */
                    'url'    => __('%s must be a valid URL.', 'custom-meta-fields-for-fluentcart'),
                    /* translators: %s: field title */
                    'number' => __('%s must be a number.', 'custom-meta-fields-for-fluentcart'),
                    /* translators: %s: field title */
                    'date'   => __('%s must be a valid date.', 'custom-meta-fields-for-fluentcart'),
                    /* translators: %s: field title */
                    'select' => __('%s has an invalid choice.', 'custom-meta-fields-for-fluentcart'),
                ];
                if (isset($messages[$def['field_type']])) {
                    $errors[$name]['invalid'] = sprintf($messages[$def['field_type']], $title);
                }
            }
        }

        return $errors;
    }

    /**
     * fluent_cart/checkout/prepare_other_data
     *
     * @param array $args ['cart','order','prev_order','request_data','validated_data']
     */
    public function save($args = []): void
    {
        $order = $args['order'] ?? null;
        if (!is_object($order) || !method_exists($order, 'updateMeta')) {
            return;
        }
        $data = is_array($args['request_data'] ?? null) ? $args['request_data'] : [];

        // Files already on this order (a re-submitted draft) keep their record
        // unless the customer attached a new one.
        $previous = self::orderValues($order);

        $values = [];
        foreach (self::activeFields() as $def) {
            if (!self::isVisible($def, $data)) {
                continue;
            }
            $raw   = $data[ProDefinitions::inputName($def)] ?? '';
            $clean = self::sanitize(is_scalar($raw) ? (string) $raw : '', $def);
            if ($clean === '' || ($def['field_type'] === 'checkbox' && $clean !== '1')) {
                continue;
            }

            if ($def['field_type'] === 'file') {
                $record = CheckoutUploads::claim($clean, (int) ($order->id ?? 0));
                if ($record) {
                    if (!empty($previous[$def['field_id']]['file']) && $previous[$def['field_id']]['file']['path'] !== $record['path']) {
                        CheckoutUploads::deleteStored($previous[$def['field_id']]['file']);
                    }
                    $file = $record;
                } elseif (!empty($previous[$def['field_id']]['file'])) {
                    $file = $previous[$def['field_id']]['file'];
                } else {
                    continue;
                }
                $values[$def['field_id']] = [
                    'label' => $def['field_title'],
                    'type'  => 'file',
                    'value' => (string) $file['name'],
                    'file'  => [
                        'driver' => (string) $file['driver'],
                        'path'   => (string) $file['path'],
                        'bucket' => (string) ($file['bucket'] ?? ''),
                        'name'   => (string) $file['name'],
                        'size'   => (int) ($file['size'] ?? 0),
                        'mime'   => (string) ($file['mime'] ?? ''),
                    ],
                ];
                continue;
            }

            $values[$def['field_id']] = [
                'label' => $def['field_title'],
                'type'  => $def['field_type'],
                'value' => $clean,
            ];
        }

        $values = apply_filters('upfluent_cmf_checkout_values_to_save', $values, $order, $data);

        if ($values) {
            $order->updateMeta(self::ORDER_META_KEY, $values);
        } elseif (method_exists($order, 'deleteMeta')) {
            // Re-submitting a draft order after clearing every field.
            $order->deleteMeta(self::ORDER_META_KEY);
        }

        do_action('upfluent_cmf_checkout_fields_saved', $order, $values);
    }

    /** Per-type sanitisation, sharing the product rules and bounding length. */
    public static function sanitize(string $raw, array $def): string
    {
        if ($def['field_type'] === 'file') {
            return sanitize_key($raw); // an upload token
        }
        $limit = $def['field_type'] === 'textarea' ? 5000 : 1000;
        if (function_exists('mb_substr')) {
            $raw = mb_substr($raw, 0, $limit);
        } else {
            $raw = substr($raw, 0, $limit);
        }
        $clean = ProductValuesService::sanitizeValue($raw, $def);
        return is_scalar($clean) ? (string) $clean : '';
    }

    // ─── Reading back ───

    /**
     * Stored values for an order.
     *
     * @param object|int|array $order  Order model, id, or array with id
     * @return array  field_id => ['label','type','value']
     */
    public static function orderValues($order): array
    {
        $order = self::orderModel($order);
        if (!$order) {
            return [];
        }
        $stored = $order->getMeta(self::ORDER_META_KEY, []);
        if (is_string($stored)) {
            $decoded = json_decode($stored, true);
            $stored  = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($stored)) {
            return [];
        }

        $out = [];
        foreach ($stored as $id => $row) {
            if (!is_string($id) || !is_array($row)) {
                continue;
            }
            $entry = [
                'label' => (string) ($row['label'] ?? $id),
                'type'  => (string) ($row['type'] ?? 'text'),
                'value' => is_scalar($row['value'] ?? null) ? (string) $row['value'] : '',
            ];
            if (isset($row['file']) && is_array($row['file']) && !empty($row['file']['path'])) {
                $entry['file'] = [
                    'driver' => (string) ($row['file']['driver'] ?? 'local'),
                    'path'   => (string) $row['file']['path'],
                    'bucket' => (string) ($row['file']['bucket'] ?? ''),
                    'name'   => (string) ($row['file']['name'] ?? $entry['value']),
                    'size'   => (int) ($row['file']['size'] ?? 0),
                    'mime'   => (string) ($row['file']['mime'] ?? ''),
                ];
            }
            $out[sanitize_key($id)] = $entry;
        }
        return $out;
    }

    /** Human form of a stored value. */
    public static function displayValue(array $row): string
    {
        if (($row['type'] ?? '') === 'checkbox') {
            return $row['value'] === '1' ? __('Yes', 'custom-meta-fields-for-fluentcart') : __('No', 'custom-meta-fields-for-fluentcart');
        }
        if (($row['type'] ?? '') === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['value'] ?? ''))) {
            $ts = strtotime($row['value'] . ' 00:00:00 UTC');
            return $ts ? wp_date(get_option('date_format'), $ts, new \DateTimeZone('UTC')) : (string) $row['value'];
        }
        if (($row['type'] ?? '') === 'file' && !empty($row['file'])) {
            $name = (string) ($row['file']['name'] ?? $row['value']);
            $size = (int) ($row['file']['size'] ?? 0);
            return $size > 0 ? $name . ' (' . size_format($size) . ')' : $name;
        }
        return (string) ($row['value'] ?? '');
    }

    private static function orderModel($order)
    {
        if (is_object($order) && method_exists($order, 'getMeta')) {
            return $order;
        }
        $id = 0;
        if (is_array($order)) {
            $id = (int) ($order['id'] ?? 0);
        } elseif (is_numeric($order)) {
            $id = (int) $order;
        }
        if ($id > 0 && class_exists('\FluentCart\App\Models\Order')) {
            return \FluentCart\App\Models\Order::find($id);
        }
        return null;
    }

    // ─── Admin order screen ───

    /**
     * fluent_cart/widgets/single_order_page
     *
     * @param array $widgets
     * @param array $data ['order_id', 'order' => Order]
     */
    public function orderWidget($widgets, $data = [])
    {
        if (!is_array($widgets)) {
            $widgets = [];
        }
        $values = self::orderValues($data['order'] ?? ($data['order_id'] ?? 0));
        if (!$values) {
            return $widgets;
        }

        $order_id = (int) (is_object($data['order'] ?? null) ? ($data['order']->id ?? 0) : ($data['order_id'] ?? 0));

        $rows = '';
        foreach ($values as $field_id => $row) {
            $cell = nl2br(esc_html(self::displayValue($row)));
            if ($row['type'] === 'file' && !empty($row['file']) && $order_id) {
                $cell = '';
                $src = CheckoutUploads::isPreviewable($row['file']) ? CheckoutUploads::previewUrl($order_id, $field_id) : '';
                if ($src !== '') {
                    $cell = '<a href="' . esc_url($src) . '" target="_blank" rel="noopener" style="display:block;margin-bottom:6px;">'
                        . '<img src="' . esc_url($src) . '" alt="' . esc_attr($row['file']['name']) . '" loading="lazy"'
                        . ' style="display:block;max-width:100%;max-height:200px;width:auto;height:auto;border:1px solid rgba(0,0,0,.1);border-radius:4px;" />'
                        . '</a>';
                }
                $cell .= '<a href="' . esc_url(CheckoutUploads::downloadUrl($order_id, $field_id)) . '" target="_blank" rel="noopener">'
                    . esc_html(self::displayValue($row)) . '</a>'
                    . ' <span style="opacity:.6;font-size:12px;">' . esc_html(sprintf(
                        /* translators: %s: storage driver slug */
                        __('stored in %s', 'custom-meta-fields-for-fluentcart'),
                        $row['file']['driver']
                    )) . '</span>';
            }
            $rows .= '<tr>'
                . '<th style="text-align:left;padding:6px 12px 6px 0;vertical-align:top;white-space:nowrap;font-weight:500;">' . esc_html($row['label']) . '</th>'
                . '<td style="padding:6px 0;vertical-align:top;word-break:break-word;">' . $cell . '</td>'
                . '</tr>';
        }

        $widgets[] = [
            'title'    => __('Checkout Fields', 'custom-meta-fields-for-fluentcart'),
            'subtitle' => '',
            'type'     => 'html',
            'content'  => '<table class="cmf-order-fields" style="width:100%;border-collapse:collapse;">' . $rows . '</table>',
        ];

        return $widgets;
    }

    // ─── Thank-you page ───

    /** fluent_cart/receipt/thank_you/after_order_items */
    public function renderReceipt($config = []): void
    {
        $values = self::orderValues(is_array($config) ? ($config['order'] ?? null) : null);
        if (!$values) {
            return;
        }
        if (!apply_filters('upfluent_cmf_checkout_receipt_enabled', true, $values)) {
            return;
        }

        if (!wp_style_is(self::STYLE_HANDLE, 'registered')) {
            $this->registerAssets();
        }
        wp_enqueue_style(self::STYLE_HANDLE);
        $heading  = apply_filters('upfluent_cmf_checkout_receipt_heading', __('Additional details', 'custom-meta-fields-for-fluentcart'));
        $order    = self::orderModel(is_array($config) ? ($config['order'] ?? null) : null);
        $order_id = $order ? (int) ($order->id ?? 0) : 0;
        ?>
        <div class="cmf-receipt">
            <?php if ($heading) : ?>
                <h4 class="cmf-receipt-heading"><?php echo esc_html($heading); ?></h4>
            <?php endif; ?>
            <dl class="cmf-receipt-list">
                <?php foreach ($values as $field_id => $row) : ?>
                    <dt><?php echo esc_html($row['label']); ?></dt>
                    <dd>
                        <?php $src = ($order_id && $row['type'] === 'file' && !empty($row['file']) && CheckoutUploads::isPreviewable($row['file'])) ? CheckoutUploads::previewUrl($order_id, (string) $field_id) : ''; ?>
                        <?php if ($src !== '') : ?>
                            <a class="cmf-receipt-preview" href="<?php echo esc_url($src); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo esc_url($src); ?>" alt="<?php echo esc_attr($row['file']['name']); ?>" loading="lazy" />
                            </a>
                        <?php endif; ?>
                        <?php echo nl2br(esc_html(self::displayValue($row))); ?>
                    </dd>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php
    }

    // ─── Emails ───

    /** fluent_cart/editor_shortcodes: list {{checkout.*}} in the email editor. */
    public function editorShortcodes($groups)
    {
        if (!is_array($groups)) {
            return $groups;
        }
        $fields = ProDefinitions::checkoutFields();
        if (!$fields) {
            return $groups;
        }

        $codes = [
            '{{' . self::SMARTCODE_KEY . '.all}}' => __('All checkout fields (table)', 'custom-meta-fields-for-fluentcart'),
        ];
        foreach ($fields as $def) {
            $codes['{{' . self::SMARTCODE_KEY . '.' . $def['field_id'] . '}}'] = $def['field_title'];
        }

        $groups['checkout_fields'] = [
            'title'      => __('Checkout Fields', 'custom-meta-fields-for-fluentcart'),
            'key'        => self::SMARTCODE_KEY,
            'shortcodes' => $codes,
        ];

        return $groups;
    }

    /**
     * fluent_cart/smartcode_fallback
     *
     * FluentCart calls this filter two ways: ($code, $data) when no parser
     * group matches, and ($value, $code, $data, $conditions) from the value
     * transformer when a parsed value is empty. Both are handled.
     */
    public function resolveSmartcode($a, $b = null, $c = null, $d = null)
    {
        if (func_num_args() >= 4) {
            $code = is_string($b) ? $b : '';
            $data = is_array($c) ? $c : [];
        } else {
            $code = is_string($a) ? $a : '';
            $data = is_array($b) ? $b : [];
        }

        $prefix = self::SMARTCODE_KEY . '.';
        $code   = trim($code);
        if ($code === '' || strpos($code, $prefix) !== 0) {
            return $a;
        }

        $accessor = substr($code, strlen($prefix));
        // Strip transformer / default syntax: {{checkout.x|ucwords}} or {{checkout.x||fallback}}
        $accessor = trim((string) strtok($accessor, '|'));

        $values = self::orderValues($data['order'] ?? null);

        if ($accessor === 'all') {
            return self::emailTable($values);
        }

        $id = sanitize_key($accessor);
        if (!isset($values[$id])) {
            return '';
        }
        $row = $values[$id];
        return $row['type'] === 'textarea'
            ? nl2br(esc_html($row['value']))
            : esc_html(self::displayValue($row));
    }

    private static function emailTable(array $values): string
    {
        if (!$values) {
            return '';
        }
        $rows = '';
        foreach ($values as $row) {
            $rows .= '<tr>'
                . '<td style="padding:6px 12px 6px 0;vertical-align:top;font-weight:600;white-space:nowrap;">' . esc_html($row['label']) . '</td>'
                . '<td style="padding:6px 0;vertical-align:top;">' . nl2br(esc_html(self::displayValue($row))) . '</td>'
                . '</tr>';
        }
        return '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;">' . $rows . '</table>';
    }
}
