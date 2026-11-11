/**
 * Admin module for Media and Repeater product fields, the Checkout Fields
 * tab and identifier import.
 *
 * admin.js calls window.fctCmfPro(kit) with its
 * shared helpers and uses the returned { contexts, editor } to extend the
 * settings screen and the product editor panel. This file must load before
 * admin.js (it is registered as a dependency).
 */
(function () {
    'use strict';

    window.fctCmfPro = function (kit) {
        const { cfg, esc, t, typeOptions, formField, formCheck, FREE_TYPES, ID_PATTERN, contextOf, nextUid } = kit;

        const MEDIA_TYPES = [
            { value: 'any',   label: 'Any' },
            { value: 'image', label: 'Image' },
            { value: 'video', label: 'Video' },
            { value: 'audio', label: 'Audio' },
            { value: 'file',  label: 'File (documents, archives…)' },
        ];
        const PRO_PRODUCT_TYPES = [
            { value: 'media',    label: 'Media' },
            { value: 'repeater', label: 'Repeater' },
        ];
        const PRODUCT_TYPES   = FREE_TYPES.concat(PRO_PRODUCT_TYPES);
        const SUB_FIELD_TYPES = PRODUCT_TYPES.filter(ft => ft.value !== 'repeater');
        // What a customer can fill in at checkout. Files go to FluentCart's storage driver.
        const CHECKOUT_TYPES = FREE_TYPES
            .filter(ft => ['text', 'textarea', 'number', 'select', 'checkbox', 'url', 'email', 'date'].includes(ft.value))
            .concat([{ value: 'file', label: 'File Upload' }]);
        const OPERATORS = [
            { value: 'equals',       label: 'is' },
            { value: 'not_equals',   label: 'is not' },
            { value: 'contains',     label: 'contains' },
            { value: 'not_contains', label: 'does not contain' },
            { value: 'not_empty',    label: 'is filled / checked' },
            { value: 'empty',        label: 'is empty / unchecked' },
        ];
        const PLACEMENTS = cfg.placements || {
            before_billing: 'Before the billing address',
            after_shipping: 'After the shipping address (physical orders only)',
            before_payment: 'Before the payment methods',
            after_payment:  'After the payment methods',
            after_button:   'After the place-order button',
            order_summary:  'In the order summary, after order notes',
        };
        const BUILTIN_CONDITION_FIELDS = cfg.builtinConditionFields || {};
        const CHECKOUT_PREFIX = 'cmf_';

        // ═══════════════════════════════════════════════════════════════
        // Product tab: Media and Repeater definitions
        // ═══════════════════════════════════════════════════════════════

        const productContext = {
            types: PRODUCT_TYPES,
            noPlaceholder: ['media', 'repeater'],
            rowsBeforePlaceholder: `
                ${formField({
                    id: 'fct-cm-product-allowed-types', label: 'Allowed types', cls: 'fct-cm-allowed-row', hidden: true,
                    control: `<select id="fct-cm-product-allowed-types" class="fct-cm-input fct-cm-allowed-types">${typeOptions(MEDIA_TYPES, 'any')}</select>`,
                    help: 'Restrict what the media picker offers.',
                })}
                ${formField({
                    label: 'Sub-fields', cls: 'fct-cm-subfields-row', hidden: true, wide: true,
                    control: `<div class="fct-cm-subfields"></div>
                              <p class="fct-cm-inline-actions"><button type="button" class="button fct-cm-add-subfield">+ Add sub-field</button></p>`,
                    help: 'Columns of one repeater row. One level only: a repeater cannot contain a repeater.',
                })}`,
            cells: (f) => {
                if (f.field_type === 'select')   return [esc(f.options || '—')];
                if (f.field_type === 'media')    return ['Allowed: ' + esc(f.allowed_types || 'any')];
                if (f.field_type === 'repeater') return [esc((f.sub_fields || []).map(s => s.field_id).join(', ') || '—')];
                return ['—'];
            },
            note: `
                <h3 class="fct-cm-card-title">Shortcodes</h3>
                <p>
                    <code>[upfluent_cmf_field key="FIELD_ID" size="large"]</code> outputs one field (images with srcset, videos with a player).<br>
                    <code>[upfluent_cmf_repeater key="FIELD_ID" layout="list|grid|row" columns="3"]</code> outputs repeater rows.
                    Both accept <code>post_id</code> and output nothing when empty.
                </p>`,
            bind(state) {
                const { q } = state;
                const allowedSelect = q('.fct-cm-allowed-types');
                const allowedRow    = q('.fct-cm-allowed-row');
                const subfieldsRow  = q('.fct-cm-subfields-row');
                const subfieldsBox  = q('.fct-cm-subfields');
                let subFields = [];

                function renderSubFields() {
                    if (!subFields.length) {
                        subfieldsBox.innerHTML = '<p class="description">No sub-fields yet.</p>';
                        return;
                    }
                    subfieldsBox.innerHTML = subFields.map((s, i) => `
                        <div class="fct-cm-subfield" data-index="${i}">
                            <span class="fct-cm-subfield-num">${i + 1}</span>
                            <input type="text" class="fct-cm-sub-id" placeholder="sub_field_id" pattern="[a-z0-9_-]+" value="${esc(s.field_id)}" aria-label="Sub-field ID" />
                            <input type="text" class="fct-cm-sub-title" placeholder="Title" value="${esc(s.field_title)}" aria-label="Sub-field title" />
                            <select class="fct-cm-sub-type" aria-label="Sub-field type">${typeOptions(SUB_FIELD_TYPES, s.field_type)}</select>
                            <input type="text" class="fct-cm-sub-options" placeholder="option1, option2" value="${esc(s.options || '')}" aria-label="Options"${s.field_type === 'select' ? '' : ' hidden'} />
                            <select class="fct-cm-sub-allowed" aria-label="Allowed media types"${s.field_type === 'media' ? '' : ' hidden'}>${typeOptions(MEDIA_TYPES, s.allowed_types || 'any')}</select>
                            <span class="fct-cm-subfield-actions">
                                <button type="button" class="button button-small" data-act="up" aria-label="Move up"${i === 0 ? ' disabled' : ''}>↑</button>
                                <button type="button" class="button button-small" data-act="down" aria-label="Move down"${i === subFields.length - 1 ? ' disabled' : ''}>↓</button>
                                <button type="button" class="button button-small fct-cm-danger" data-act="remove" aria-label="Remove">✕</button>
                            </span>
                        </div>
                    `).join('');
                }

                function readSubFieldsFromDom() {
                    subfieldsBox.querySelectorAll('.fct-cm-subfield').forEach(row => {
                        const i = Number(row.dataset.index);
                        if (!subFields[i]) return;
                        subFields[i].field_id      = row.querySelector('.fct-cm-sub-id').value.trim();
                        subFields[i].field_title   = row.querySelector('.fct-cm-sub-title').value.trim();
                        subFields[i].field_type    = row.querySelector('.fct-cm-sub-type').value;
                        subFields[i].options       = row.querySelector('.fct-cm-sub-options').value.trim();
                        subFields[i].allowed_types = row.querySelector('.fct-cm-sub-allowed').value;
                    });
                }

                q('.fct-cm-add-subfield').addEventListener('click', () => {
                    readSubFieldsFromDom();
                    subFields.push({ field_id: '', field_title: '', field_type: 'text', options: '', allowed_types: 'any' });
                    renderSubFields();
                    const last = subfieldsBox.querySelector('.fct-cm-subfield:last-child .fct-cm-sub-id');
                    if (last) last.focus();
                });

                subfieldsBox.addEventListener('change', (e) => {
                    if (!e.target.classList.contains('fct-cm-sub-type')) return;
                    readSubFieldsFromDom();
                    renderSubFields();
                });

                subfieldsBox.addEventListener('click', (e) => {
                    const btn = e.target.closest('button[data-act]');
                    if (!btn) return;
                    readSubFieldsFromDom();
                    const i = Number(btn.closest('.fct-cm-subfield').dataset.index);
                    if (btn.dataset.act === 'remove') subFields.splice(i, 1);
                    if (btn.dataset.act === 'up' && i > 0) [subFields[i - 1], subFields[i]] = [subFields[i], subFields[i - 1]];
                    if (btn.dataset.act === 'down' && i < subFields.length - 1) [subFields[i + 1], subFields[i]] = [subFields[i], subFields[i + 1]];
                    renderSubFields();
                });

                return {
                    sync(type) {
                        allowedRow.hidden   = type !== 'media';
                        subfieldsRow.hidden = type !== 'repeater';
                    },
                    reset() {
                        subFields = [];
                        allowedSelect.value = 'any';
                        renderSubFields();
                    },
                    load(field) {
                        allowedSelect.value = field.allowed_types || 'any';
                        subFields = (field.sub_fields || []).map(s => ({ options: '', allowed_types: 'any', ...s }));
                        renderSubFields();
                    },
                    read(data, type) {
                        readSubFieldsFromDom();
                        data.allowed_types = allowedSelect.value;
                        data.sub_fields    = type === 'repeater' ? subFields.map(s => ({ ...s })) : [];
                    },
                    validate(data) {
                        if (data.field_type !== 'repeater') return null;
                        if (!data.sub_fields.length) return 'Add at least one sub-field.';
                        for (const s of data.sub_fields) {
                            if (!s.field_id || !ID_PATTERN.test(s.field_id) || !s.field_title) return 'Every sub-field needs a valid ID and a title.';
                        }
                        return null;
                    },
                };
            },
        };

        // ═══════════════════════════════════════════════════════════════
        // Checkout tab
        // ═══════════════════════════════════════════════════════════════

        function conditionSummary(f) {
            const c = f.conditions || {};
            if (!c.enabled || !Array.isArray(c.rules) || !c.rules.length) return '—';
            const n = c.rules.length;
            return esc(`${n} rule${n === 1 ? '' : 's'} (${c.relation === 'any' ? 'any' : 'all'})`);
        }

        const checkoutContext = {
            types: CHECKOUT_TYPES,
            intro: 'Fields the customer fills in at checkout. Values are saved with the order, shown on the order screen and the thank-you page, and available in emails.',
            idPlaceholder: 'e.g. gift_message',
            idHelp: 'Lowercase letters, numbers, underscores, hyphens. Cannot be changed later. Email code: <code>{{checkout.FIELD_ID}}</code>.',
            titlePlaceholder: 'e.g. Gift message',
            titleHelp: 'Label shown to the customer.',
            rowsBeforePlaceholder: '',
            rowsAfterPlaceholder: `
                ${formField({
                    id: 'fct-cm-checkout-description', label: 'Help text', cls: 'fct-cm-description-row',
                    control: '<input type="text" id="fct-cm-checkout-description" class="fct-cm-input fct-cm-description" placeholder="Optional line shown under the field" />',
                })}
                ${formField({
                    id: 'fct-cm-checkout-extensions', label: 'Allowed file types', cls: 'fct-cm-file-row', hidden: true,
                    control: '<input type="text" id="fct-cm-checkout-extensions" class="fct-cm-input fct-cm-extensions" placeholder="jpg, jpeg, png, gif, webp, pdf, doc, docx, txt, zip" />',
                    help: 'Comma-separated extensions; scripts and HTML are always refused. Files go to FluentCart\'s storage driver (Settings → Storage): a remote driver such as S3 when enabled, otherwise local.',
                })}
                ${formField({
                    id: 'fct-cm-checkout-max-size', label: 'Max size (MB)', cls: 'fct-cm-file-row', hidden: true,
                    control: '<input type="number" id="fct-cm-checkout-max-size" class="fct-cm-input fct-cm-input--short fct-cm-max-size" min="1" step="1" placeholder="10" />',
                    help: 'Capped by the server\'s upload limit.',
                })}
                ${formField({
                    id: 'fct-cm-checkout-placement', label: 'Placement',
                    control: `<select id="fct-cm-checkout-placement" class="fct-cm-input fct-cm-placement">
                        ${Object.keys(PLACEMENTS).map(k => `<option value="${esc(k)}"${k === 'before_payment' ? ' selected' : ''}>${esc(PLACEMENTS[k])}</option>`).join('')}
                    </select>`,
                    help: 'Where on the checkout form the field appears.',
                })}
                ${formCheck({ inputClass: 'fct-cm-enabled', checked: true, text: 'Enabled — show on the checkout form (also switchable under FluentCart → Settings → Checkout Fields)' })}
                ${formCheck({ inputClass: 'fct-cm-required', text: 'Required — the customer must fill this in (only while the field is visible)' })}
                <div class="fct-cm-field fct-cm-field--wide fct-cm-conditions-row">
                    <label class="fct-cm-check"><input type="checkbox" class="fct-cm-cond-enabled" /> <span>Conditional logic — show this field only when rules match</span></label>
                    <div class="fct-cm-cond-body" hidden>
                        <p class="fct-cm-cond-relation-line">
                            Show when
                            <select class="fct-cm-input fct-cm-input--short fct-cm-cond-relation">
                                <option value="all">all</option>
                                <option value="any">any</option>
                            </select>
                            of these rules match:
                        </p>
                        <div class="fct-cm-cond-rules"></div>
                        <p class="fct-cm-inline-actions"><button type="button" class="button fct-cm-cond-add">+ Add rule</button></p>
                        <p class="fct-cm-help">Rules can look at other checkout fields you define here, or at FluentCart's own inputs such as the billing country or the chosen payment method. A hidden field is never required and is not saved.</p>
                    </div>
                </div>`,
            noPlaceholder: ['file'],
            head: ['Placement', 'Enabled', 'Required', 'Conditions'],
            cells: (f) => [
                esc(PLACEMENTS[f.placement] || f.placement || ''),
                f.enabled === false ? 'Off' : 'On',
                f.required ? 'Yes' : 'No',
                conditionSummary(f),
            ],
            typeCell: (f) => (f.field_type === 'file'
                ? `file<br><span class="description">${esc(f.allowed_extensions || '')}, ${esc(String(f.max_size_mb || 10))} MB</span>`
                : esc(f.field_type)),
            rowClass: (f) => (f.enabled === false ? 'fct-cm-row-disabled' : ''),
            // The email-editor code that prints a checkout field's value.
            code: (f) => `{{checkout.${f.field_id}}}`,
            copyLabel: t('copySmartcode', 'Copy Email Code'),
            confirmDelete: t('confirmDeleteCheckout', 'Delete this checkout field? Values already saved on orders are kept.'),
            note: `
                <h3 class="fct-cm-card-title">Using the values</h3>
                <p>
                    Values appear on the order screen in FluentCart, on the thank-you page, and in emails through the
                    <strong>Checkout Fields</strong> group of the email editor's code picker:
                    <code>{{checkout.FIELD_ID}}</code> for one field, <code>{{checkout.all}}</code> for a table of every field the customer filled in.
                    Uploaded files are downloadable from the order screen. Every field here also appears as a <strong>Custom Fields</strong> section on FluentCart → Settings → Checkout Fields, with the same on/off and Required switches.
                </p>`,
            bind(state) {
                const { panel, q, fieldIdInput, getAllFields, getEditingId } = state;
                const descriptionInput = q('.fct-cm-description');
                const placementSelect  = q('.fct-cm-placement');
                const requiredInput    = q('.fct-cm-required');
                const enabledInput     = q('.fct-cm-enabled');
                const extensionsInput  = q('.fct-cm-extensions');
                const maxSizeInput     = q('.fct-cm-max-size');
                const fileRows         = Array.from(panel.querySelectorAll('.fct-cm-file-row'));
                const condEnabled      = q('.fct-cm-cond-enabled');
                const condBody         = q('.fct-cm-cond-body');
                const condRelation     = q('.fct-cm-cond-relation');
                const condRulesBox     = q('.fct-cm-cond-rules');
                let rules = [];

                // Rule "field" values are checkout input names: our fields carry the
                // cmf_ prefix, FluentCart's are used as-is. The server reads the same.
                function ruleFieldOptions(selected) {
                    const editing  = getEditingId();
                    const siblings = getAllFields().filter(f => contextOf(f) === 'checkout' && f.field_id !== editing && f.field_id !== fieldIdInput.value.trim());
                    const own = siblings.map(f => {
                        const v = CHECKOUT_PREFIX + f.field_id;
                        return `<option value="${esc(v)}"${v === selected ? ' selected' : ''}>${esc(f.field_title)} (${esc(f.field_id)})</option>`;
                    }).join('');
                    const builtin = Object.keys(BUILTIN_CONDITION_FIELDS).map(k =>
                        `<option value="${esc(k)}"${k === selected ? ' selected' : ''}>${esc(BUILTIN_CONDITION_FIELDS[k])}</option>`
                    ).join('');
                    const known = siblings.some(f => CHECKOUT_PREFIX + f.field_id === selected) || Object.prototype.hasOwnProperty.call(BUILTIN_CONDITION_FIELDS, selected);
                    const stale = selected && !known ? `<option value="${esc(selected)}" selected>${esc(selected)} ${esc(t('notInList', '(not in list)'))}</option>` : '';
                    return `<option value="">${esc(t('select', '— Select —'))}</option>${stale}`
                        + (own ? `<optgroup label="Checkout fields">${own}</optgroup>` : '')
                        + `<optgroup label="FluentCart checkout">${builtin}</optgroup>`;
                }

                function renderRules() {
                    if (!rules.length) {
                        condRulesBox.innerHTML = '<p class="description">No rules yet. Without rules the field is always shown.</p>';
                        return;
                    }
                    condRulesBox.innerHTML = rules.map((r, i) => {
                        const noValue = r.operator === 'empty' || r.operator === 'not_empty';
                        return `
                        <div class="fct-cm-rule" data-index="${i}">
                            <select class="fct-cm-rule-field" aria-label="Field">${ruleFieldOptions(r.field)}</select>
                            <select class="fct-cm-rule-op" aria-label="Operator">${typeOptions(OPERATORS, r.operator)}</select>
                            <input type="text" class="fct-cm-rule-value" placeholder="value" value="${esc(r.value || '')}" aria-label="Value"${noValue ? ' hidden' : ''} />
                            <button type="button" class="button button-small fct-cm-danger" data-act="remove" aria-label="Remove rule">✕</button>
                        </div>`;
                    }).join('');
                }

                function readRulesFromDom() {
                    condRulesBox.querySelectorAll('.fct-cm-rule').forEach(row => {
                        const i = Number(row.dataset.index);
                        if (!rules[i]) return;
                        rules[i].field    = row.querySelector('.fct-cm-rule-field').value;
                        rules[i].operator = row.querySelector('.fct-cm-rule-op').value;
                        rules[i].value    = row.querySelector('.fct-cm-rule-value').value.trim();
                    });
                }

                condEnabled.addEventListener('change', () => { condBody.hidden = !condEnabled.checked; });

                q('.fct-cm-cond-add').addEventListener('click', () => {
                    readRulesFromDom();
                    rules.push({ field: '', operator: 'equals', value: '' });
                    renderRules();
                    const last = condRulesBox.querySelector('.fct-cm-rule:last-child .fct-cm-rule-field');
                    if (last) last.focus();
                });

                condRulesBox.addEventListener('change', (e) => {
                    if (!e.target.classList.contains('fct-cm-rule-op')) return;
                    readRulesFromDom();
                    renderRules();
                });

                condRulesBox.addEventListener('click', (e) => {
                    const btn = e.target.closest('button[data-act="remove"]');
                    if (!btn) return;
                    readRulesFromDom();
                    rules.splice(Number(btn.closest('.fct-cm-rule').dataset.index), 1);
                    renderRules();
                });

                return {
                    sync(type) {
                        fileRows.forEach(r => { r.hidden = type !== 'file'; });
                    },
                    reset() {
                        rules = [];
                        descriptionInput.value = '';
                        placementSelect.value  = 'before_payment';
                        requiredInput.checked  = false;
                        enabledInput.checked   = true;
                        extensionsInput.value  = '';
                        maxSizeInput.value     = '';
                        condEnabled.checked    = false;
                        condBody.hidden        = true;
                        condRelation.value     = 'all';
                        renderRules();
                    },
                    load(field) {
                        descriptionInput.value = field.description || '';
                        placementSelect.value  = field.placement || 'before_payment';
                        requiredInput.checked  = !!field.required;
                        enabledInput.checked   = field.enabled !== false;
                        extensionsInput.value  = field.allowed_extensions || '';
                        maxSizeInput.value     = field.max_size_mb || '';
                        const c = field.conditions || {};
                        condEnabled.checked = !!c.enabled;
                        condBody.hidden     = !c.enabled;
                        condRelation.value  = c.relation === 'any' ? 'any' : 'all';
                        rules = Array.isArray(c.rules) ? c.rules.map(r => ({ field: r.field || '', operator: r.operator || 'equals', value: r.value || '' })) : [];
                        renderRules();
                    },
                    read(data, type) {
                        readRulesFromDom();
                        data.description = descriptionInput.value.trim();
                        data.placement   = placementSelect.value;
                        data.required    = requiredInput.checked;
                        data.enabled     = enabledInput.checked;
                        if (type === 'file') {
                            data.allowed_extensions = extensionsInput.value.trim();
                            data.max_size_mb        = parseInt(maxSizeInput.value, 10) || 0;
                        }
                        data.conditions = {
                            enabled:  condEnabled.checked,
                            relation: condRelation.value,
                            rules:    rules.filter(r => r.field).map(r => ({ ...r })),
                        };
                    },
                    validate(data) {
                        if (!data.conditions.enabled) return null;
                        if (!data.conditions.rules.length) return 'Add at least one rule, or switch conditional logic off.';
                        for (const r of data.conditions.rules) {
                            const needsValue = !['empty', 'not_empty'].includes(r.operator);
                            if (needsValue && r.value === '') return 'Every rule needs a value (or use "is filled" / "is empty").';
                            if (r.field === CHECKOUT_PREFIX + data.field_id) return 'A field cannot depend on itself.';
                        }
                        return null;
                    },
                };
            },
        };

        // ═══════════════════════════════════════════════════════════════
        // Product editor: media pickers and repeater rows
        // ═══════════════════════════════════════════════════════════════

        function previewHtml(info) {
            if (!info) return '';
            if (info.kind === 'image') {
                return `<img src="${esc(info.thumb || info.url)}" alt="${esc(info.title || info.filename || '')}" />`;
            }
            if (info.kind === 'video') {
                return `<video preload="metadata" muted playsinline${info.poster ? ` poster="${esc(info.poster)}"` : ''} src="${esc(info.url)}"></video>
                        <span class="fct-cm-media-name">${esc(info.filename || '')}</span>`;
            }
            return `<span class="fct-cm-media-file">${esc(info.filename || info.title || ('#' + info.id))}</span>`;
        }

        // `key` is the data attribute admin.js built (top-level or sub-field).
        function renderInput(field, value, attachments, id, key) {
            if (field.field_type !== 'media') return null;
            const str   = (value === null || value === undefined) ? '' : String(value);
            const attId = parseInt(str, 10) || 0;
            const info  = attId ? attachments[attId] : null;
            return `
                <div class="fct-cm-media" data-allowed="${esc(field.allowed_types || 'any')}">
                    <input type="hidden" id="${id}" ${key} value="${attId || ''}" />
                    <div class="fct-cm-media-preview">${info ? previewHtml(info) : ''}</div>
                    <div class="fct-cm-media-actions">
                        <button type="button" class="button button-small fct-cm-media-select"${attId ? ' hidden' : ''}>${esc(t('selectMedia', 'Select'))}</button>
                        <button type="button" class="button button-small fct-cm-media-replace"${attId ? '' : ' hidden'}>${esc(t('replaceMedia', 'Replace'))}</button>
                        <button type="button" class="button button-small fct-cm-danger fct-cm-media-remove"${attId ? '' : ' hidden'}>${esc(t('removeMedia', 'Remove'))}</button>
                    </div>
                </div>`;
        }

        function renderRepeater(field, rows, attachments) {
            return `
                <div class="fct-cm-repeater" data-fct-repeater="${esc(field.field_id)}">
                    <div class="fct-cm-repeater-rows">${rows.map(r => renderRow(field, r, attachments)).join('')}</div>
                    <p class="fct-cm-repeater-foot"><button type="button" class="button button-small fct-cm-row-add">+ ${esc(t('addRow', 'Add Row'))}</button></p>
                    <template class="fct-cm-row-template">${renderRow(field, {}, attachments)}</template>
                </div>`;
        }

        function renderRow(field, row, attachments) {
            const r = nextUid();
            const cells = (field.sub_fields || []).map(s => {
                const id = `fct-cm-input-${esc(field.field_id)}-${r}-${esc(s.field_id)}`;
                const labelFor = s.field_type === 'media' ? '' : ` for="${id}"`;
                return `<div class="fct-cm-cell" data-fct-cell="${esc(s.field_id)}">
                            <label class="fct-cm-cell-label"${labelFor}>${esc(s.field_title)}</label>
                            ${kit.renderInput(s, row ? row[s.field_id] : '', attachments, id, true)}
                        </div>`;
            }).join('');
            return `
                <div class="fct-cm-repeater-row" draggable="true">
                    <div class="fct-cm-row-bar">
                        <span class="fct-cm-row-handle" title="${esc(t('dragToReorder', 'Drag to reorder'))}" aria-hidden="true">⋮⋮</span>
                        <span class="fct-cm-row-actions">
                            <button type="button" class="button button-small fct-cm-row-up" aria-label="Move up">↑</button>
                            <button type="button" class="button button-small fct-cm-row-down" aria-label="Move down">↓</button>
                            <button type="button" class="button button-small fct-cm-danger fct-cm-row-remove">${esc(t('removeRow', 'Remove Row'))}</button>
                        </span>
                    </div>
                    <div class="fct-cm-row-cells">${cells}</div>
                </div>`;
        }

        function libraryFor(allowed) {
            switch (allowed) {
                case 'image': return { type: 'image' };
                case 'video': return { type: 'video' };
                case 'audio': return { type: 'audio' };
                case 'file':  return { type: ['application', 'text'] };
                default:      return {};
            }
        }

        function setMedia(box, info) {
            box.querySelector('input[type=hidden]').value = info ? String(info.id) : '';
            box.querySelector('.fct-cm-media-preview').innerHTML = info ? previewHtml(info) : '';
            box.querySelector('.fct-cm-media-select').hidden  = !!info;
            box.querySelector('.fct-cm-media-replace').hidden = !info;
            box.querySelector('.fct-cm-media-remove').hidden  = !info;
        }

        function openMediaPicker(box, markDirty) {
            if (!window.wp || !wp.media) {
                alert(t('mediaUnavailable', 'The WordPress media library is not available on this page.'));
                return;
            }
            const allowed = box.dataset.allowed || 'any';
            const frame = wp.media({
                title:    t('chooseMedia', 'Choose media'),
                button:   { text: t('useMedia', 'Use this file') },
                multiple: false,
                library:  libraryFor(allowed),
            });
            frame.on('select', () => {
                const att = frame.state().get('selection').first();
                if (!att) return;
                const a = att.toJSON();
                const kind = ['image', 'video', 'audio'].includes(a.type) ? a.type : 'file';
                if (allowed !== 'any' && kind !== allowed) {
                    alert(t('wrongMediaType', 'That file type is not allowed for this field.'));
                    return;
                }
                const poster = a.image && a.image.src && !/\/wp-includes\/images\/media\//.test(a.image.src) ? a.image.src : '';
                setMedia(box, {
                    id: a.id, kind, url: a.url, title: a.title, filename: a.filename,
                    thumb: (a.sizes && a.sizes.thumbnail && a.sizes.thumbnail.url) || a.url,
                    poster,
                });
                markDirty();
            });
            frame.open();
        }

        function bind(body, markDirty) {
            body.addEventListener('click', (e) => {
                const btn = e.target.closest('button');
                if (!btn) return;

                // Media
                if (btn.classList.contains('fct-cm-media-select') || btn.classList.contains('fct-cm-media-replace')) {
                    e.preventDefault();
                    openMediaPicker(btn.closest('.fct-cm-media'), markDirty);
                    return;
                }
                if (btn.classList.contains('fct-cm-media-remove')) {
                    e.preventDefault();
                    setMedia(btn.closest('.fct-cm-media'), null);
                    markDirty();
                    return;
                }

                // Repeater rows
                if (btn.classList.contains('fct-cm-row-add')) {
                    e.preventDefault();
                    const rep  = btn.closest('.fct-cm-repeater');
                    const tpl  = rep.querySelector('.fct-cm-row-template');
                    const rows = rep.querySelector('.fct-cm-repeater-rows');
                    // Fresh ids per row instance.
                    const html = tpl.innerHTML.replace(/fct-cm-input-([a-z0-9_-]+)-r\d+-/g, (m, fid) => `fct-cm-input-${fid}-${nextUid()}-`);
                    rows.insertAdjacentHTML('beforeend', html);
                    const first = rows.lastElementChild.querySelector('input:not([type=hidden]), select, textarea');
                    if (first) first.focus();
                    markDirty();
                    return;
                }
                const row = btn.closest('.fct-cm-repeater-row');
                if (row && btn.classList.contains('fct-cm-row-remove')) {
                    e.preventDefault();
                    row.remove();
                    markDirty();
                    return;
                }
                if (row && btn.classList.contains('fct-cm-row-up') && row.previousElementSibling) {
                    e.preventDefault();
                    row.parentNode.insertBefore(row, row.previousElementSibling);
                    markDirty();
                    return;
                }
                if (row && btn.classList.contains('fct-cm-row-down') && row.nextElementSibling) {
                    e.preventDefault();
                    row.parentNode.insertBefore(row.nextElementSibling, row);
                    markDirty();
                }
            });

            // Drag-to-reorder rows (HTML5 DnD, scoped to one repeater)
            let dragging = null;
            body.addEventListener('dragstart', (e) => {
                const row = e.target.closest && e.target.closest('.fct-cm-repeater-row');
                if (!row) return;
                dragging = row;
                row.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', ''); } catch (err) { /* IE */ }
            });
            body.addEventListener('dragover', (e) => {
                if (!dragging) return;
                const over = e.target.closest && e.target.closest('.fct-cm-repeater-row');
                if (!over || over === dragging || over.parentNode !== dragging.parentNode) return;
                e.preventDefault();
                const rect = over.getBoundingClientRect();
                const after = (e.clientY - rect.top) > rect.height / 2;
                over.parentNode.insertBefore(dragging, after ? over.nextSibling : over);
            });
            body.addEventListener('drop', (e) => { if (dragging) e.preventDefault(); });
            body.addEventListener('dragend', () => {
                if (!dragging) return;
                dragging.classList.remove('is-dragging');
                dragging = null;
                markDirty();
            });
        }

        function readValue(el, type) {
            if (type === 'media') return el ? (parseInt(el.value, 10) || 0) : 0;
            return kit.readScalar(el, type);
        }

        function collect(wrap, field) {
            if (field.field_type === 'repeater') {
                const rows = [];
                wrap.querySelectorAll('.fct-cm-repeater-rows > .fct-cm-repeater-row').forEach(row => {
                    const obj = {};
                    (field.sub_fields || []).forEach(s => {
                        const el = row.querySelector(`[data-fct-sub="${CSS.escape(s.field_id)}"]`);
                        obj[s.field_id] = readValue(el, s.field_type);
                    });
                    rows.push(obj);
                });
                return rows;
            }
            if (field.field_type === 'media') {
                return readValue(wrap.querySelector(`[data-fct-field="${CSS.escape(field.field_id)}"]`), 'media');
            }
            return undefined;
        }

        // ─── Identifier import (WooCommerce, CSV) ───

        function importer(slot) {
            const { api } = kit;
            slot.innerHTML = `
                <section class="fct-cm-card">
                    <div class="fct-cm-card-head"><h2 class="fct-cm-card-title">${esc(t('importWooTitle', 'Import from WooCommerce'))}</h2></div>
                    <div class="fct-cm-card-body">
                        <p class="fct-cm-help">${esc(t('importWooHelp', 'Reads GTINs and MPNs your WooCommerce store left in this database (WooCommerce’s own GTIN field and the common GTIN plugins) and matches them to FluentCart variations by SKU.'))}</p>
                        <div class="fct-cm-inline-actions">
                            <button type="button" class="button" data-cmf-import="woocommerce" data-dry="1">${esc(t('preview', 'Preview'))}</button>
                            <button type="button" class="button button-primary" data-cmf-import="woocommerce" data-dry="0" disabled>${esc(t('importNow', 'Import'))}</button>
                        </div>
                        <div class="fct-cm-import-report" data-report="woocommerce"></div>
                    </div>
                </section>
                <section class="fct-cm-card">
                    <div class="fct-cm-card-head"><h2 class="fct-cm-card-title">${esc(t('importCsvTitle', 'Import a CSV'))}</h2></div>
                    <div class="fct-cm-card-body">
                        <p class="fct-cm-help">${esc(t('importCsvHelp', 'Columns: sku, gtin, mpn, brand. A header row is optional; gtin may also be called ean, upc, isbn or barcode.'))}</p>
                        <p><input type="file" accept=".csv,text/csv" data-cmf-csv-file /></p>
                        <textarea rows="6" data-cmf-csv placeholder="sku,gtin,mpn,brand"></textarea>
                        <div class="fct-cm-inline-actions">
                            <button type="button" class="button" data-cmf-import="csv" data-dry="1">${esc(t('preview', 'Preview'))}</button>
                            <button type="button" class="button button-primary" data-cmf-import="csv" data-dry="0" disabled>${esc(t('importNow', 'Import'))}</button>
                        </div>
                        <div class="fct-cm-import-report" data-report="csv"></div>
                    </div>
                </section>`;

            const csvText = slot.querySelector('[data-cmf-csv]');
            slot.querySelector('[data-cmf-csv-file]').addEventListener('change', (e) => {
                const file = e.target.files && e.target.files[0];
                if (!file) return;
                const reader = new FileReader();
                reader.onload = () => { csvText.value = String(reader.result || ''); resetApply('csv'); };
                reader.readAsText(file);
            });
            csvText.addEventListener('input', () => resetApply('csv'));

            function resetApply(source) {
                const btn = slot.querySelector(`[data-cmf-import="${source}"][data-dry="0"]`);
                if (btn) btn.disabled = true;
            }

            function list(items, fmt) {
                return items.length ? `<ul>${items.map(i => `<li>${fmt(i)}</li>`).join('')}</ul>` : '';
            }

            function renderReport(res) {
                const head = res.dry_run
                    ? t('previewDone', 'Preview only, nothing saved yet.')
                    : t('importDone', 'Imported.');
                return `
                    <p><strong>${esc(head)}</strong> ${esc(res.message || '')}</p>
                    <p>${esc(String(res.matched_count))} ${esc(t('matched', 'matched'))} · ${esc(String(res.products))} ${esc(t('productsLabel', 'products'))} · ${esc(String(res.errors_count))} ${esc(t('withErrors', 'with errors'))} · ${esc(String(res.unmatched_count))} ${esc(t('unmatchedSku', 'SKUs not found'))}</p>
                    ${res.errors_count ? `<details open><summary>${esc(t('errors', 'Errors'))}</summary>${list(res.errors, e => `${esc(t('line', 'Line'))} ${esc(String(e.line))}, ${esc(e.sku)}: ${esc(e.message)}`)}</details>` : ''}
                    ${res.matched_count ? `<details><summary>${esc(t('matchedRows', 'Matched rows'))}</summary>${list(res.matched, m => `${esc(m.sku)} → ${esc(m.product)} ${m.gtin ? '· GTIN ' + esc(m.gtin) : ''} ${m.mpn ? '· MPN ' + esc(m.mpn) : ''}`)}</details>` : ''}
                    ${res.unmatched_count ? `<details><summary>${esc(t('unmatchedRows', 'SKUs not found in FluentCart'))}</summary>${list(res.unmatched, u => esc(u.sku || '(empty)'))}</details>` : ''}`;
            }

            slot.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-cmf-import]');
                if (!btn) return;
                const source = btn.dataset.cmfImport;
                const dry    = btn.dataset.dry === '1';
                const report = slot.querySelector(`[data-report="${source}"]`);
                const body   = { dry_run: dry };
                if (source === 'csv') body.csv = csvText.value;

                btn.disabled = true;
                report.innerHTML = `<p>${esc(t('working', 'Working…'))}</p>`;
                api.post('identifiers/import/' + source, body).then(res => {
                    report.innerHTML = renderReport(res);
                    const apply = slot.querySelector(`[data-cmf-import="${source}"][data-dry="0"]`);
                    if (apply) apply.disabled = !(dry && res.matched_count > 0);
                }).catch(err => {
                    report.innerHTML = `<p class="fct-cm-error">${esc(err.message || t('importFailed', 'Import failed.'))}</p>`;
                }).finally(() => {
                    if (dry) btn.disabled = false;
                });
            });
        }

        // Whole-row rendering for types with their own block (repeater); null lets admin.js render an input.
        function renderTopLevel(field, value, attachments) {
            if (field.field_type === 'repeater') {
                return renderRepeater(field, Array.isArray(value) ? value : [], attachments);
            }
            return null;
        }

        function shortcodeFor(field) {
            return field.field_type === 'repeater' ? `[upfluent_cmf_repeater key="${field.field_id}"]` : null;
        }

        // ═══════════════════════════════════════════════════════════════
        // Product editor sidebar: media and repeater in FluentCart's card
        // ═══════════════════════════════════════════════════════════════
        //
        // EditorWidget.php (with ProDefinitions::widget*) adds, inside
        // FluentCart's own form card: one `html` mount per media/repeater
        // field, a hidden element with the initial state, and a hidden text
        // input (the bridge). The inputs here are the same ones the old card
        // used. Every edit writes all media/repeater values as JSON into the
        // bridge and fires an input event, so FluentCart marks the product
        // changed and posts the JSON with its own Save.

        const SIDEBAR_ATTR = 'fctInit';

        function cardOf(el) {
            return el.closest('.fct-card') || el.closest('.vue-form-wrap') || el.parentElement;
        }

        function bridgeOf(card) {
            const wrap = card.querySelector('.fct-cmf-pro-bridge');
            if (!wrap) return null;
            return wrap.matches('input') ? wrap : wrap.querySelector('input');
        }

        function sidebarFieldHtml(field, value, attachments) {
            const inner = field.field_type === 'repeater'
                ? renderRepeater(field, Array.isArray(value) ? value : [], attachments)
                : renderInput(field, value, attachments, 'fct-cm-input-' + field.field_id + '-' + nextUid(), `data-fct-field="${esc(field.field_id)}"`);
            return `
                <div class="setting-fields-inner fct-cmf-pro-field" data-fct-wrap="${esc(field.field_id)}" data-fct-type="${esc(field.field_type)}">
                    <span class="fct-label-hint setting-label"><span class="label">${esc(field.field_title)}</span></span>
                    ${inner || ''}
                </div>`;
        }

        function syncBridge(st) {
            st.card.querySelectorAll('.fct-cmf-pro-field').forEach(wrap => {
                const field = st.fields[wrap.dataset.fctWrap];
                if (!field) return;
                const v = collect(wrap, field);
                if (v !== undefined) st.values[field.field_id] = v;
            });
            const bridge = bridgeOf(st.card);
            if (!bridge) return;
            bridge.value = JSON.stringify(st.values);
            // Element Plus reads the event target's value into the form state.
            bridge.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Renders from the card's current state. Listeners are bound once per
        // mount element and look the state up when they fire, so a re-render
        // (new product, remount) never doubles them or edits stale values.
        function mountSidebarField(st, mount) {
            mount.dataset[SIDEBAR_ATTR] = '1';
            const field = st.fields[mount.dataset.field];
            if (!field) return;
            mount.innerHTML = sidebarFieldHtml(field, st.values[field.field_id], st.attachments);
            if (!st.canEdit) {
                mount.querySelectorAll('input, select, textarea, button').forEach(el => { el.disabled = true; });
                mount.querySelectorAll('[draggable]').forEach(el => { el.draggable = false; });
                return;
            }
            if (mount.dataset.fctBound) return;
            mount.dataset.fctBound = '1';
            const markDirty = () => {
                const current = cardOf(mount) && cardOf(mount).fctCmfPro;
                if (current && current.canEdit) syncBridge(current);
            };
            mount.addEventListener('input', markDirty);
            mount.addEventListener('change', markDirty);
            kit.bindEditorBehaviour(mount, markDirty);
        }

        function initSidebarCard(stateEl) {
            stateEl.dataset[SIDEBAR_ATTR] = '1';
            let data;
            try { data = JSON.parse(stateEl.dataset.state || '{}'); } catch (e) { return; }
            const card = cardOf(stateEl);
            if (!card) return;

            const st = {
                card,
                fields: {},
                values: Object.assign({}, data.values || {}),
                attachments: data.attachments || {},
                canEdit: data.canEdit !== false && cfg.canEdit !== false,
            };
            (data.fields || []).forEach(f => { st.fields[f.field_id] = f; });

            // Remounted after edits (Vue re-rendered the card): keep the edits.
            const bridge = bridgeOf(card);
            if (bridge && bridge.value) {
                try { Object.assign(st.values, JSON.parse(bridge.value)); } catch (e) { /* ignore */ }
            }

            card.fctCmfPro = st;
            card.querySelectorAll('.fct-cmf-pro-mount').forEach(m => mountSidebarField(st, m));
        }

        const sidebar = {
            start(root) {
                const scan = () => {
                    root.querySelectorAll('.fct-cmf-pro-state:not([data-fct-init])').forEach(initSidebarCard);
                    root.querySelectorAll('.fct-cmf-pro-mount:not([data-fct-init])').forEach(m => {
                        const card = cardOf(m);
                        if (card && card.fctCmfPro) mountSidebarField(card.fctCmfPro, m);
                    });
                };
                // Mounts are guarded by data-fct-init, so our own DOM writes end quietly.
                new MutationObserver(scan).observe(root, { childList: true, subtree: true });
                scan();
            },
        };

        return {
            contexts: { product: productContext, checkout: checkoutContext },
            editor: { renderInput, renderRepeater, renderTopLevel, shortcodeFor, bind, collect },
            sidebar,
            importer,
        };
    };
})();
