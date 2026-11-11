/**
 * Custom Fields admin: settings screen (tabs, field builder, table) and the
 * panel injected into FluentCart's product editor.
 *
 * admin-pro.js (Media and Repeater fields, the Checkout tab, identifier
 * import) plugs in through window.fctCmfPro(kit): it receives the shared
 * helpers below and returns { contexts: { product, checkout }, editor, importer }.
 */
(function () {
    'use strict';

    const cfg = window.fctCustomMeta || {};
    const { restUrl, nonce } = cfg;
    if (!restUrl || !nonce) return;

    const I18N = cfg.i18n || {};
    const t = (key, fallback) => (Object.prototype.hasOwnProperty.call(I18N, key) ? I18N[key] : fallback);

    // ─── REST client: throws on any non-2xx ───

    const api = {
        fetch(endpoint, options = {}) {
            return fetch(restUrl + endpoint, {
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                ...options,
            }).then(async (r) => {
                let data = {};
                try { data = await r.json(); } catch (e) { /* non-JSON body */ }
                if (!r.ok) {
                    const err = new Error(data.message || r.statusText || ('HTTP ' + r.status));
                    err.status = r.status;
                    err.data = data;
                    throw err;
                }
                return data;
            });
        },
        get(endpoint)        { return this.fetch(endpoint); },
        post(endpoint, data) { return this.fetch(endpoint, { method: 'POST', body: JSON.stringify(data) }); },
        put(endpoint, data)  { return this.fetch(endpoint, { method: 'PUT',  body: JSON.stringify(data) }); },
        del(endpoint)        { return this.fetch(endpoint, { method: 'DELETE' }); },
    };

    // ─── Escaping for every dynamic value that reaches innerHTML ───

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /** Field types the free plugin handles itself. */
    const FREE_TYPES = [
        { value: 'text',     label: 'Text' },
        { value: 'textarea', label: 'Textarea' },
        { value: 'number',   label: 'Number' },
        { value: 'select',   label: 'Select Dropdown' },
        { value: 'checkbox', label: 'Checkbox' },
        { value: 'url',      label: 'URL' },
        { value: 'email',    label: 'Email' },
        { value: 'date',     label: 'Date' },
        { value: 'color',    label: 'Color Picker' },
    ];
    const FREE_TYPE_VALUES = FREE_TYPES.map(ft => ft.value);

    const ID_PATTERN = /^[a-z0-9_-]+$/;

    const typeOptions = (list, selected) =>
        list.map(ft => `<option value="${esc(ft.value)}"${ft.value === selected ? ' selected' : ''}>${esc(ft.label)}</option>`).join('');

    const contextOf = (f) => (f.context === 'checkout' ? 'checkout' : 'product');

    let uid = 0;
    const nextUid = () => 'r' + (++uid);

    // One labelled control in the builder form. `cls` marks rows shown per type.
    const formField = (opts) => `
        <div class="fct-cm-field${opts.cls ? ' ' + opts.cls : ''}${opts.wide ? ' fct-cm-field--wide' : ''}"${opts.hidden ? ' hidden' : ''}>
            <label class="fct-cm-label"${opts.id ? ` for="${opts.id}"` : ''}>${opts.label}</label>
            <div class="fct-cm-control">${opts.control}</div>
            ${opts.help ? `<p class="fct-cm-help">${opts.help}</p>` : ''}
        </div>`;
    const formCheck = (opts) => `
        <div class="fct-cm-field fct-cm-field--wide${opts.cls ? ' ' + opts.cls : ''}"${opts.hidden ? ' hidden' : ''}>
            <label class="fct-cm-check"><input type="checkbox" class="${opts.inputClass}"${opts.checked ? ' checked' : ''} /> <span>${opts.text}</span></label>
        </div>`;

    // ─── Shortcode copying ───

    function onShortcodeCopy(e) {
        const btn = e.target.closest('.fct-cm-copy');
        if (!btn) return;
        e.preventDefault();

        const text = btn.dataset.shortcode || '';
        if (!text) return;

        // The editor button shows the code itself; the table button shows a caption.
        const label = btn.dataset.label || text;

        const flash = () => {
            btn.classList.add('is-copied');
            btn.textContent = t('copied', 'Copied');
            clearTimeout(btn.fctCopyTimer);
            btn.fctCopyTimer = setTimeout(() => {
                btn.textContent = label;
                btn.classList.remove('is-copied');
            }, 1200);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(flash).catch(() => legacyCopy(text, flash));
        } else {
            legacyCopy(text, flash);
        }
    }

    // execCommand is deprecated but still the only option without a secure context.
    function legacyCopy(text, done) {
        const field = document.createElement('textarea');
        field.value = text;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.left = '-9999px';
        document.body.appendChild(field);
        field.select();
        try {
            document.execCommand('copy');
            done();
        } catch (err) { /* clipboard unavailable */ }
        document.body.removeChild(field);
    }

    // The shortcode that renders a product field on the front end.
    function shortcodeFor(field) {
        const pro = PRO && PRO.editor && PRO.editor.shortcodeFor ? PRO.editor.shortcodeFor(field) : null;
        return pro || `[upfluent_cmf_field key="${field.field_id}"]`;
    }

    // ─── Product editor inputs (free types) ───

    // One input for a definition. `id` is the DOM id; `sub` marks inputs inside
    // repeater rows so top-level collection skips them. Types this file does
    // not know go to admin-pro.js; without it the value is left alone.
    function renderInput(field, value, attachments, id, sub) {
        const key    = sub ? `data-fct-sub="${esc(field.field_id)}"` : `data-fct-field="${esc(field.field_id)}"`;
        const ph     = field.placeholder ? ` placeholder="${esc(field.placeholder)}"` : '';
        const str    = (value === null || value === undefined) ? '' : String(value);
        const safeV  = esc(str);

        if (!FREE_TYPE_VALUES.includes(field.field_type)) {
            if (PRO && PRO.editor && PRO.editor.renderInput) {
                const html = PRO.editor.renderInput(field, value, attachments, id, key);
                if (html !== null && html !== undefined) return html;
            }
            return `<p class="fct-cm-help fct-cm-locked-field">${esc(field.field_type)} fields cannot be edited here. The stored value is kept.</p>`;
        }

        switch (field.field_type) {
            case 'textarea':
                return `<textarea id="${id}" ${key} class="large-text" rows="3"${ph}>${esc(str)}</textarea>`;

            case 'select': {
                const opts  = (field.options || '').split(',').map(o => o.trim()).filter(Boolean);
                const stale = str && !opts.includes(str);
                return `<select id="${id}" ${key}>
                    <option value="">${esc(t('select', '— Select —'))}</option>
                    ${stale ? `<option value="${safeV}" selected>${safeV} ${esc(t('notInList', '(not in list)'))}</option>` : ''}
                    ${opts.map(o => `<option value="${esc(o)}"${str === o ? ' selected' : ''}>${esc(o)}</option>`).join('')}
                </select>`;
            }

            case 'checkbox':
                return `<label><input type="checkbox" id="${id}" ${key} value="1"${str === '1' ? ' checked' : ''} /> ${esc(t('enabled', 'Enabled'))}</label>`;

            case 'color': {
                const has = /^#[0-9a-fA-F]{6}$/.test(str);
                return `<span class="fct-cm-colorwrap"><input type="color" id="${id}" ${key} value="${esc(has ? str : '#000000')}"${has ? '' : ' disabled'} />
                    <label class="fct-cm-inline"><input type="checkbox" class="fct-cm-color-clear"${has ? '' : ' checked'} /> ${esc(t('noColor', 'No color'))}</label></span>`;
            }

            case 'date':   return `<input type="date" id="${id}" ${key} value="${safeV}"${ph} />`;
            case 'number': return `<input type="number" id="${id}" ${key} class="regular-text" value="${safeV}"${ph} step="any" />`;
            case 'url':    return `<input type="url" id="${id}" ${key} class="regular-text" value="${safeV}"${ph} />`;
            case 'email':  return `<input type="email" id="${id}" ${key} class="regular-text" value="${safeV}"${ph} />`;
            default:       return `<input type="text" id="${id}" ${key} class="regular-text" value="${safeV}"${ph} />`;
        }
    }

    /** Value of one free-type input; undefined for anything else. */
    function readScalar(el, type) {
        if (!el) return '';
        if (type === 'checkbox') return el.checked ? '1' : '0';
        if (type === 'color') {
            const clear = el.closest('.fct-cm-colorwrap').querySelector('.fct-cm-color-clear');
            return (clear && clear.checked) ? '' : el.value;
        }
        return el.value;
    }

    // ─── admin-pro.js ───

    // The kit is everything admin-pro.js is allowed to build on.
    const kit = {
        cfg, api, esc, t, typeOptions, formField, formCheck,
        FREE_TYPES, ID_PATTERN, contextOf, nextUid, renderInput, readScalar, restUrl,
        renderTopLevel, bindEditorBehaviour, collectValue, onShortcodeCopy,
    };
    const PRO = typeof window.fctCmfPro === 'function' ? window.fctCmfPro(kit) : null;

    // ═══════════════════════════════════════════════════════════════════
    // Settings page: Product / Checkout / Identifiers tabs
    // ═══════════════════════════════════════════════════════════════════

    function initDefinitionsApp() {
        const container = document.getElementById('fct-custom-meta-app');
        if (!container) return;

        // Inside the FluentHub portal the shell provides navigation; the
        // "Back to FluentCart" link only makes sense on the bare WP page.
        const inHub = !!cfg.inHub || !!document.querySelector('.fluent_uui');
        const backLink = inHub
            ? ''
            : `<a class="fct-cm-btn fct-cm-btn--ghost" href="${esc(cfg.adminUrl || '')}admin.php?page=fluent-cart#/">&larr; ${esc(t('backToFluentCart', 'Back to FluentCart'))}</a>`;

        // admin-pro.js adds the checkout field builder.
        const hasCheckout = !!(PRO && PRO.contexts && PRO.contexts.checkout);

        container.innerHTML = `
            <div class="fct-cm-app${inHub ? ' fct-cm-app--hub' : ''}">
                <header class="fct-cm-header">
                    <div class="fct-cm-header-main">
                        <h1 class="fct-cm-title">Custom Fields</h1>
                        <p class="fct-cm-subtitle">${hasCheckout ? 'Extra fields for FluentCart products and the checkout form.' : 'Extra fields for FluentCart products.'}</p>
                    </div>
                    <div class="fct-cm-header-actions">${backLink}</div>
                </header>
                ${welcomePanel()}
                <nav class="fct-cm-tabs" role="tablist" aria-label="Field contexts">
                    <a href="#product" class="fct-cm-tab" role="tab" data-tab="product">${esc(t('productTab', 'Product Fields'))}</a>
                    <a href="#checkout" class="fct-cm-tab" role="tab" data-tab="checkout">${esc(t('checkoutTab', 'Checkout Fields'))}</a>
                    <a href="#identifiers" class="fct-cm-tab" role="tab" data-tab="identifiers">${esc(t('identifiersTab', 'Identifiers'))}</a>
                </nav>
                <div class="fct-cm-tab-panel" data-panel="product"></div>
                <div class="fct-cm-tab-panel" data-panel="checkout" hidden></div>
                <div class="fct-cm-tab-panel" data-panel="identifiers" hidden></div>
            </div>
        `;

        const panels = {
            product:     container.querySelector('[data-panel="product"]'),
            checkout:    container.querySelector('[data-panel="checkout"]'),
            identifiers: container.querySelector('[data-panel="identifiers"]'),
        };
        const apps = {
            product:     initContextPanel(panels.product, 'product'),
            checkout:    initContextPanel(panels.checkout, 'checkout'),
            identifiers: initIdentifiersPanel(panels.identifiers),
        };
        const tabs = Array.from(container.querySelectorAll('.fct-cm-tab'));

        // Welcome panel: "Add your first field" jumps to the form; Dismiss drops ?welcome from the URL.
        const welcome = container.querySelector('.fct-cm-welcome');
        if (welcome) {
            const closeWelcome = () => {
                welcome.remove();
                const url = new URL(window.location.href);
                url.searchParams.delete('welcome');
                window.history.replaceState(null, '', url.toString());
            };
            welcome.querySelector('[data-welcome-dismiss]').addEventListener('click', closeWelcome);
            welcome.querySelector('[data-welcome-start]').addEventListener('click', (e) => {
                e.preventDefault();
                closeWelcome();
                if (window.location.hash && window.location.hash !== '#product') {
                    window.location.hash = '#product';
                }
                const form = panels.product.querySelector('.fct-cm-add-form');
                if (form) {
                    form.scrollIntoView({ behavior: 'smooth' });
                    const first = form.querySelector('input');
                    if (first) first.focus({ preventScroll: true });
                }
            });
        }

        const tabFromHash = () => {
            const h = window.location.hash;
            if (h === '#checkout') return 'checkout';
            if (h === '#identifiers') return 'identifiers';
            return 'product';
        };

        function activate(tab) {
            tabs.forEach(a => {
                const on = a.dataset.tab === tab;
                a.classList.toggle('is-active', on);
                a.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            Object.keys(panels).forEach(k => { panels[k].hidden = k !== tab; });
            apps[tab].refresh();
        }

        window.addEventListener('hashchange', () => activate(tabFromHash()));
        activate(tabFromHash());
    }

    // Shown after activation (the plugin redirects to ?page=fct-custom-meta&welcome=1).
    function welcomePanel() {
        if (new URLSearchParams(window.location.search).get('welcome') !== '1') return '';
        const steps = [
            [t('welcomeStep1Title', 'Define a field'), t('welcomeStep1', 'Give it an ID, a title and a type below. It appears on every FluentCart product.')],
            [t('welcomeStep2Title', 'Fill it in'), t('welcomeStep2', 'Open any product. Your field is in the Custom Meta Fields card in the editor sidebar.')],
            [t('welcomeStep3Title', 'Show it'), t('welcomeStep3', 'Copy the shortcode next to the field, like [upfluent_cmf_field key="subtitle"], into your product template or page.')],
        ];
        const extras = t('welcomeExtra', 'Media and Repeater fields are in the type list. Add questions to the checkout on the Checkout Fields tab, and GTINs for Google on the Identifiers tab.');
        return `
            <section class="fct-cm-card fct-cm-welcome" aria-labelledby="fct-cm-welcome-title">
                <div class="fct-cm-card-head">
                    <h2 class="fct-cm-card-title" id="fct-cm-welcome-title">${esc(t('welcomeTitle', 'Welcome to Custom Meta Fields'))}</h2>
                    <button type="button" class="fct-cm-btn fct-cm-btn--ghost" data-welcome-dismiss>${esc(t('dismiss', 'Dismiss'))}</button>
                </div>
                <div class="fct-cm-card-body">
                    <ol class="fct-cm-welcome-steps">
                        ${steps.map(([title, text], i) => `<li><span class="fct-cm-welcome-num">${i + 1}</span><strong>${esc(title)}</strong><p>${esc(text)}</p></li>`).join('')}
                    </ol>
                    <p class="fct-cm-welcome-extra">${esc(extras)}</p>
                </div>
                <div class="fct-cm-card-foot">
                    <a href="#product" class="fct-cm-btn fct-cm-btn--primary" data-welcome-start>${esc(t('welcomeStart', 'Add your first field'))}</a>
                    <a href="https://upfluent.io/fluentcart-custom-meta-fields/welcome/" class="fct-cm-btn fct-cm-btn--ghost" target="_blank" rel="noopener">${esc(t('welcomeGuide', 'Getting started guide'))}</a>
                </div>
            </section>`;
    }

    function initIdentifiersPanel(panel) {
        let built = false;
        return {
            refresh() {
                if (built) return;
                built = true;
                panel.innerHTML = `
                    <section class="fct-cm-card">
                        <div class="fct-cm-card-head"><h2 class="fct-cm-card-title">${esc(t('identifiersTitle', 'Product identifiers'))}</h2></div>
                        <div class="fct-cm-card-body">
                            <p class="fct-cm-help">${esc(t('identifiersIntro', 'Add a GTIN (EAN, UPC or ISBN) and MPN to each FluentCart variation, and a brand to each product, in the Custom Meta Fields card on the product page. They are added to your product structured data for Google, and [upfluent_cmf_gtin] shows the selected variation’s GTIN on the product page.'))}</p>
                        </div>
                    </section>
                    <div class="fct-cm-identifiers-import"></div>`;
                const slot = panel.querySelector('.fct-cm-identifiers-import');
                if (PRO && PRO.importer) {
                    PRO.importer(slot);
                }
            },
        };
    }

    /**
     * Defaults for a context. The admin-pro.js context object, when
     * present, overrides any of these keys and adds a bind() controller for
     * its extra form rows.
     */
    function contextSpec(context) {
        const ext = PRO && PRO.contexts ? PRO.contexts[context] : null;

        if (context === 'checkout') {
            return ext || null;
        }

        const base = {
            types: FREE_TYPES,
            intro: 'Fields that appear on every FluentCart product edit page. Values are post meta on the product.',
            idPlaceholder: 'e.g. warranty_info',
            idHelp: 'Lowercase letters, numbers, underscores, hyphens. Cannot be changed later. Also the post-meta key and the shortcode <code>key</code>.',
            titlePlaceholder: 'e.g. Warranty Information',
            titleHelp: 'Label shown in the product editor.',
            rowsBeforePlaceholder: '',
            rowsAfterPlaceholder: '',
            noPlaceholder: [],
            head: ['Details'],
            cells: (f) => [f.field_type === 'select' ? esc(f.options || '—') : '—'],
            rowClass: () => '',
            code: shortcodeFor,
            copyLabel: t('copyShortcode', 'Copy Shortcode'),
            confirmDelete: t('confirmDelete', 'Delete this field? Its stored values will be removed from all products.'),
            note: `
                <h3 class="fct-cm-card-title">Shortcode</h3>
                <p><code>[upfluent_cmf_field key="FIELD_ID"]</code> outputs one field anywhere. It accepts <code>post_id</code> and outputs nothing when empty.</p>`,
            bind: null,
        };
        return ext ? Object.assign({}, base, ext) : base;
    }

    /**
     * One tab: an add/edit form plus the table for a single context.
     * Returns { refresh } so the tab can reload when shown.
     */
    function initContextPanel(panel, context) {
        const spec = contextSpec(context);

        if (!spec) {
            return { refresh() {} };
        }

        const cols = 4 + spec.head.length;

        panel.innerHTML = `
            <p class="fct-cm-intro">${esc(spec.intro)}</p>

            <section class="fct-cm-card fct-cm-add-form">
                <div class="fct-cm-card-head">
                    <h2 class="fct-cm-card-title fct-cm-form-title">${esc(t('addNewField', 'Add New Field'))}</h2>
                </div>
                <div class="fct-cm-card-body">
                    <div class="fct-cm-form-grid">
                        ${formField({
                            id: `fct-cm-${context}-field-id`, label: 'Field ID',
                            control: `<input type="text" id="fct-cm-${context}-field-id" class="fct-cm-input fct-cm-field-id" required placeholder="${esc(spec.idPlaceholder)}" pattern="[a-z0-9_-]+" />`,
                            help: spec.idHelp,
                        })}
                        ${formField({
                            id: `fct-cm-${context}-field-title`, label: 'Field Title',
                            control: `<input type="text" id="fct-cm-${context}-field-title" class="fct-cm-input fct-cm-field-title" required placeholder="${esc(spec.titlePlaceholder)}" />`,
                            help: spec.titleHelp,
                        })}
                        ${formField({
                            id: `fct-cm-${context}-field-type`, label: 'Field Type',
                            control: `<select id="fct-cm-${context}-field-type" class="fct-cm-input fct-cm-field-type">${typeOptions(spec.types, 'text')}</select>`,
                        })}
                        ${formField({
                            id: `fct-cm-${context}-options`, label: 'Options', cls: 'fct-cm-options-row', hidden: true,
                            control: `<input type="text" id="fct-cm-${context}-options" class="fct-cm-input fct-cm-options" placeholder="option1, option2, option3" />`,
                            help: 'Comma-separated list of options for select fields.',
                        })}
                        ${spec.rowsBeforePlaceholder}
                        ${formField({
                            id: `fct-cm-${context}-placeholder`, label: 'Placeholder', cls: 'fct-cm-placeholder-row',
                            control: `<input type="text" id="fct-cm-${context}-placeholder" class="fct-cm-input fct-cm-placeholder" placeholder="Optional placeholder text" />`,
                        })}
                        ${spec.rowsAfterPlaceholder}
                    </div>
                </div>
                <div class="fct-cm-card-foot">
                    <button type="button" class="button button-primary fct-cm-save-btn">${esc(t('addField', 'Add Field'))}</button>
                    <button type="button" class="button fct-cm-cancel-btn" hidden>Cancel</button>
                    <span class="fct-cm-product-status fct-cm-form-status" role="status" aria-live="polite"></span>
                </div>
            </section>

            <section class="fct-cm-card">
                <div class="fct-cm-card-head"><h2 class="fct-cm-card-title">Defined Fields</h2></div>
                <div class="fct-cm-table-wrap">
                    <table class="fct-cm-table fct-cm-fields-table">
                        <thead>
                            <tr>
                                <th>Field ID</th>
                                <th>Title</th>
                                <th>Type</th>
                                ${spec.head.map(h => `<th>${esc(h)}</th>`).join('')}
                                <th class="fct-cm-th-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="fct-cm-fields-body"><tr><td colspan="${cols}">${esc(t('loading', 'Loading…'))}</td></tr></tbody>
                    </table>
                </div>
            </section>

            <section class="fct-cm-card fct-cm-card--note">${spec.note}</section>
        `;

        const q = (sel) => panel.querySelector(sel);
        const fieldIdInput     = q('.fct-cm-field-id');
        const fieldTitleInput  = q('.fct-cm-field-title');
        const fieldTypeSelect  = q('.fct-cm-field-type');
        const optionsInput     = q('.fct-cm-options');
        const placeholderInput = q('.fct-cm-placeholder');
        const saveBtn          = q('.fct-cm-save-btn');
        const cancelBtn        = q('.fct-cm-cancel-btn');
        const formTitle        = q('.fct-cm-form-title');
        const formStatus       = q('.fct-cm-form-status');
        const tbody            = q('.fct-cm-fields-body');
        const optionsRow       = q('.fct-cm-options-row');
        const placeholderRow   = q('.fct-cm-placeholder-row');

        let editingFieldId = null;
        let allFields = [];   // every definition, both contexts

        const mine = () => allFields.filter(f => contextOf(f) === context);

        // admin-pro.js rows (its field types, checkout settings) are driven by this.
        const ctl = spec.bind ? spec.bind({
            panel, q, fieldIdInput,
            getAllFields: () => allFields,
            getEditingId: () => editingFieldId,
        }) : null;

        function showStatus(msg, isError) {
            formStatus.innerHTML = msg ? `<span class="${isError ? 'fct-cm-error' : 'fct-cm-success'}">${esc(msg)}</span>` : '';
            if (msg && !isError) setTimeout(() => { formStatus.textContent = ''; }, 3000);
        }

        function syncTypeRows() {
            const type = fieldTypeSelect.value;
            optionsRow.hidden     = type !== 'select';
            placeholderRow.hidden = type === 'checkbox' || type === 'color' || spec.noPlaceholder.includes(type);
            if (ctl && ctl.sync) ctl.sync(type);
        }
        fieldTypeSelect.addEventListener('change', syncTypeRows);

        function renderFields() {
            const fields = mine();
            if (!fields.length) {
                tbody.innerHTML = `<tr><td colspan="${cols}">${esc(t('noneDefined', 'No custom fields defined yet. Add one above.'))}</td></tr>`;
                return;
            }
            tbody.innerHTML = fields.map(f => {
                const code = spec.code(f);
                const actions = `
                    <button class="button button-small fct-cm-edit" data-id="${esc(f.field_id)}" aria-label="Edit ${esc(f.field_id)}">Edit</button>
                    <button class="button button-small fct-cm-delete" data-id="${esc(f.field_id)}" aria-label="Delete ${esc(f.field_id)}">Delete</button>
                    <button type="button" class="button button-small fct-cm-copy" data-shortcode="${esc(code)}" data-label="${esc(spec.copyLabel)}" title="${esc(code)}">${esc(spec.copyLabel)}</button>`;
                const rowClass = spec.rowClass(f);
                return `
                <tr data-id="${esc(f.field_id)}"${rowClass ? ` class="${esc(rowClass)}"` : ''}>
                    <td><code>${esc(f.field_id)}</code></td>
                    <td>${esc(f.field_title)}</td>
                    <td>${spec.typeCell ? spec.typeCell(f) : esc(f.field_type)}</td>
                    ${spec.cells(f).map(c => `<td>${c}</td>`).join('')}
                    <td>${actions}</td>
                </tr>`;
            }).join('');
        }

        function resetForm() {
            editingFieldId = null;
            fieldIdInput.value = '';
            fieldIdInput.disabled = false;
            fieldTitleInput.value = '';
            fieldTypeSelect.value = 'text';
            optionsInput.value = '';
            placeholderInput.value = '';
            if (ctl && ctl.reset) ctl.reset();
            syncTypeRows();
            saveBtn.textContent = t('addField', 'Add Field');
            cancelBtn.hidden = true;
            formTitle.textContent = t('addNewField', 'Add New Field');
        }

        function loadFields() {
            return api.get('fields')
                .then(res => { allFields = res.fields || []; renderFields(); })
                .catch(err => { tbody.innerHTML = `<tr><td colspan="${cols}"><span class="fct-cm-error">${esc(err.message)}</span></td></tr>`; });
        }

        function collectPayload() {
            const type = fieldTypeSelect.value;
            const data = {
                context,
                field_id:    fieldIdInput.value.trim(),
                field_title: fieldTitleInput.value.trim(),
                field_type:  type,
                options:     optionsInput.value.trim(),
                placeholder: placeholderInput.value.trim(),
            };
            if (ctl && ctl.read) ctl.read(data, type);
            return data;
        }

        saveBtn.addEventListener('click', () => {
            const data = collectPayload();

            if (!data.field_id || !data.field_title) { showStatus(t('required', 'Field ID and Title are required.'), true); return; }
            if (!editingFieldId && !ID_PATTERN.test(data.field_id)) { showStatus(t('invalidId', 'Field ID may only contain lowercase letters, numbers, underscores and hyphens.'), true); return; }
            if (data.field_type === 'select' && !data.options) { showStatus('Select fields need at least one option.', true); return; }
            const problem = ctl && ctl.validate ? ctl.validate(data) : null;
            if (problem) { showStatus(problem, true); return; }

            saveBtn.disabled = true;
            showStatus('');
            const promise = editingFieldId ? api.put('fields/' + encodeURIComponent(editingFieldId), data) : api.post('fields', data);
            promise.then(res => {
                allFields = res.fields || [];
                renderFields();
                resetForm();
                showStatus(res.message || '', false);
            }).catch(err => {
                showStatus(err.message || t('errorSaving', 'Error saving field.'), true);
            }).finally(() => { saveBtn.disabled = false; });
        });

        cancelBtn.addEventListener('click', resetForm);

        tbody.addEventListener('click', onShortcodeCopy);

        tbody.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-id]');
            if (!btn) return;
            const id = btn.dataset.id;

            if (btn.classList.contains('fct-cm-delete')) {
                if (!confirm(spec.confirmDelete)) return;
                api.del('fields/' + encodeURIComponent(id))
                    .then(res => { allFields = res.fields || []; renderFields(); if (editingFieldId === id) resetForm(); })
                    .catch(err => showStatus(err.message, true));
            }

            if (btn.classList.contains('fct-cm-edit')) {
                const field = allFields.find(f => f.field_id === id);
                if (!field) return;
                editingFieldId = field.field_id;
                fieldIdInput.value = field.field_id;
                fieldIdInput.disabled = true;
                fieldTitleInput.value = field.field_title;
                fieldTypeSelect.value = field.field_type;
                optionsInput.value = field.options || '';
                placeholderInput.value = field.placeholder || '';
                if (ctl && ctl.load) ctl.load(field);
                syncTypeRows();
                saveBtn.textContent = t('updateField', 'Update Field');
                cancelBtn.hidden = false;
                formTitle.textContent = t('editField', 'Edit Field') + ': ' + field.field_id;
                panel.querySelector('.fct-cm-add-form').scrollIntoView({ behavior: 'smooth' });
            }
        });

        resetForm();

        return {
            refresh() { loadFields(); },
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // Product editor panel
    // ═══════════════════════════════════════════════════════════════════
    //
    // Injected into FluentCart's product editor: the app root is watched for
    // the main column and the panel is appended there once. Fallback: the
    // right-hand aside if the main column is not found.

    const CONTAINER_ID = 'fct-cm-product-fields';

    let dirty = false;

    function productIdFromHash() {
        const m = window.location.hash.match(/#\/products\/(\d+)/);
        return m ? m[1] : null;
    }

    function initProductEditor() {
        const root = document.getElementById('fluent_cart_plugin_app') || document.body;

        // The panel belongs at the end of the main (left) column. FluentCart's
        // only product-editor widget slot renders into the right-hand aside, so
        // the panel is injected rather than registered as a widget.
        const tryPlace = () => {
            const productId = productIdFromHash();
            if (!productId) return;

            const existing = document.getElementById(CONTAINER_ID);
            if (existing) {
                if (!existing.dataset.fctInit) initPanel(existing);
                return;
            }

            const host = document.querySelector('.fct-product-main')
                || document.querySelector('.fct-product-aside');
            if (!host) return;

            host.insertAdjacentHTML('beforeend', shellHtml(productId));

            const fresh = document.getElementById(CONTAINER_ID);
            if (fresh) initPanel(fresh);
        };

        // Observer callbacks are async, so the insert above cannot re-enter this
        // synchronously; the id guard handles the mutation it causes.
        new MutationObserver(tryPlace).observe(root, { childList: true, subtree: true });
        tryPlace();

        // Media and repeater inputs inside FluentCart's sidebar card (admin-pro.js).
        if (PRO && PRO.sidebar) PRO.sidebar.start(root);

        window.addEventListener('hashchange', () => {
            dirty = false;
            INLINE.productId = null;
            INLINE.active = false;
            const old = document.getElementById(CONTAINER_ID);
            if (old) old.remove();
        });

        window.addEventListener('beforeunload', (e) => {
            if (!dirty) return;
            e.preventDefault();
            e.returnValue = t('unsaved', 'You have unsaved custom field changes.');
        });
    }

    function shellHtml(productId) {
        const addLink = cfg.canManage
            ? `<a class="fct-cm-add-link" href="${esc(cfg.adminUrl || '')}admin.php?page=fct-custom-meta#product">+ ${esc(t('addNewField', 'Add New Field'))}</a>`
            : '';

        return `
            <div id="${CONTAINER_ID}" class="fct-cm-product-section" data-product-id="${esc(productId)}">
                <div class="fct-cm-product-header">
                    <h3>${esc(t('sectionTitle', 'Custom Meta Fields'))}</h3>
                    <span class="fct-cm-header-actions">
                        ${addLink}
                        <button type="button" class="button button-primary button-small fct-cm-product-save" disabled>${esc(t('save', 'Save Custom Fields'))}</button>
                    </span>
                </div>
                <div class="fct-cm-product-body"><p>${esc(t('loading', 'Loading custom fields…'))}</p></div>
                <div class="fct-cm-identifiers"></div>
                <div class="fct-cm-product-status" role="status" aria-live="polite"></div>
            </div>`;
    }

    function initPanel(wrapper) {
        wrapper.dataset.fctInit = '1';

        const productId = wrapper.dataset.productId || productIdFromHash();
        const body      = wrapper.querySelector('.fct-cm-product-body');
        const saveBtn   = wrapper.querySelector('.fct-cm-product-save');
        const statusEl  = wrapper.querySelector('.fct-cm-product-status');
        if (!productId || !body || !saveBtn || !statusEl) return;

        const idsEl   = wrapper.querySelector('.fct-cm-identifiers');
        const canEdit = cfg.canEdit !== false;
        let fields = [];
        let attachments = {};
        let idsLoaded = false;

        const enableSave = () => {
            if (!canEdit) return;
            saveBtn.disabled = false;
        };

        if (idsEl) {
            api.get('product/' + encodeURIComponent(productId) + '/identifiers').then(res => {
                idsEl.innerHTML = renderIdentifiers(res);
                idsLoaded = !!(res.rows && res.rows.length);
                startInlineIdentifiers(productId, res, canEdit, idsEl);
                if (!canEdit) {
                    idsEl.querySelectorAll('input').forEach(i => { i.disabled = true; });
                    return;
                }
                const markDirty = () => { dirty = true; };
                idsEl.addEventListener('input', (e) => {
                    markDirty();
                    if (e.target.matches('[data-cmf-gtin-input]')) showGtinHint(e.target);
                });
                if (idsLoaded) enableSave();
            }).catch(() => { idsEl.innerHTML = ''; });
        }

        const setStatus = (msg, isError) => {
            statusEl.innerHTML = msg ? `<span class="${isError ? 'fct-cm-error' : 'fct-cm-success'}">${esc(msg)}</span>` : '';
            if (msg && !isError) setTimeout(() => { statusEl.textContent = ''; }, 3000);
        };

        api.get('product/' + encodeURIComponent(productId) + '/fields').then(res => {
            const all   = res.fields || [];
            attachments = res.attachments || {};
            const values = res.values || {};

            // FluentCart's sidebar widget (EditorWidget.php) renders these
            // types and saves them with FluentCart's own Save; admin-pro.js
            // adds media and repeater there. This card keeps any other types
            // and the product identifiers.
            const sidebarTypes = Array.isArray(cfg.editorWidgetTypes) ? cfg.editorWidgetTypes : FREE_TYPE_VALUES;
            fields = cfg.editorWidget ? all.filter(f => !sidebarTypes.includes(f.field_type)) : all;

            if (!fields.length) {
                let note;
                if (all.length) {
                    note = esc(t('inSidebar', 'Your custom fields are in the sidebar, saved with the product.'));
                } else {
                    const link = cfg.canManage
                        ? ` <a href="${esc(cfg.adminUrl || '')}admin.php?page=fct-custom-meta#product">${esc(t('addFieldsHere', 'Add fields here'))}</a>.`
                        : '';
                    note = esc(t('noFields', 'No custom fields defined.')) + link;
                }
                body.innerHTML = `<p class="description">${note}</p>`;
                return;
            }

            body.innerHTML = fields.map(f => renderTopLevel(f, values[f.field_id], attachments)).join('');

            // Bound before the read-only check: copying a shortcode is not editing.
            body.addEventListener('click', onShortcodeCopy);

            if (!canEdit) {
                body.querySelectorAll('input, select, textarea, button:not(.fct-cm-copy)').forEach(i => { i.disabled = true; });
                saveBtn.hidden = true;
                setStatus(t('readOnly', 'You can view but not edit these fields.'), false);
                return;
            }

            enableSave();
            const markDirty = () => { dirty = true; };
            body.addEventListener('input',  markDirty);
            body.addEventListener('change', markDirty);
            bindEditorBehaviour(body, markDirty);
        }).catch(err => {
            body.innerHTML = `<p class="fct-cm-error">${esc(err.message || t('loadError', 'Could not load custom fields.'))}</p>`;
        });

        saveBtn.addEventListener('click', () => {
            const values = {};
            fields.forEach(f => {
                const wrap = body.querySelector(`[data-fct-wrap="${CSS.escape(f.field_id)}"]`);
                if (!wrap) return;
                const v = collectValue(wrap, f);
                // undefined: a media or repeater field while admin-pro.js is not loaded; leave it untouched.
                if (v !== undefined) values[f.field_id] = v;
            });

            saveBtn.disabled = true;
            saveBtn.textContent = t('saving', 'Saving…');
            setStatus('');

            const jobs = [];
            if (fields.length) {
                jobs.push(api.post('product/' + encodeURIComponent(productId) + '/fields', { values }));
            }
            if (idsLoaded && idsEl) {
                idsEl.querySelectorAll('.fct-cm-id-error').forEach(el => { el.textContent = ''; });
                const payload = INLINE.active ? { brand: collectIdentifiers(idsEl).brand } : collectIdentifiers(idsEl);
                jobs.push(api.post('product/' + encodeURIComponent(productId) + '/identifiers', payload).then(res => {
                    idsEl.innerHTML = renderIdentifiers(res);
                    INLINE.rows = res.rows || INLINE.rows;
                    if (placedInline()) toggleCardTable(idsEl, inlineUnmatched());
                    return res;
                }).catch(err => {
                    const rowErrors = (err.data && err.data.errors) || {};
                    Object.keys(rowErrors).forEach(vid => {
                        const cell = idsEl.querySelector(`[data-cmf-id-error="${CSS.escape(vid)}"]`);
                        if (cell) cell.textContent = rowErrors[vid];
                    });
                    throw err;
                }));
            }

            Promise.all(jobs).then(() => {
                dirty = false;
                setStatus(t('saved', 'Saved.'), false);
            }).catch(err => {
                setStatus(err.message || t('saveError', 'Could not save.'), true);
            }).finally(() => {
                saveBtn.disabled = false;
                saveBtn.textContent = t('save', 'Save Custom Fields');
            });
        });
    }

    // ─── Product identifiers (GTIN / MPN per variation, brand per product) ───

    function renderIdentifiers(data) {
        const rows = (data && data.rows) || [];
        if (!rows.length) return '';
        const body = rows.map(r => `
            <tr data-cmf-variation="${esc(String(r.variation_id))}">
                <td>${esc(r.title || '—')}</td>
                <td><code>${esc(r.sku || '—')}</code></td>
                <td>
                    <input type="text" inputmode="numeric" data-cmf-gtin-input value="${esc(r.gtin || '')}" placeholder="${esc(t('gtinPlaceholder', 'EAN/UPC/ISBN'))}" />
                    <div class="fct-cm-id-error fct-cm-error" data-cmf-id-error="${esc(String(r.variation_id))}"></div>
                </td>
                <td><input type="text" data-cmf-mpn-input value="${esc(r.mpn || '')}" /></td>
            </tr>`).join('');
        return `
            <div class="fct-cm-identifiers-block">
                <div class="fct-cm-label-wrap">
                    <label class="fct-cm-label" for="fct-cm-brand">${esc(t('identifiersTitle', 'Product identifiers'))}</label>
                    <span class="fct-cm-help">${esc(t('identifiersHelp', 'Used in Google structured data. [upfluent_cmf_gtin] shows the selected variation’s GTIN.'))}</span>
                </div>
                <div class="fct-cm-field-row">
                    <div class="fct-cm-label-wrap"><label class="fct-cm-label" for="fct-cm-brand">${esc(t('brand', 'Brand'))}</label></div>
                    <div class="fct-cm-input"><input type="text" id="fct-cm-brand" data-cmf-brand-input value="${esc(data.brand || '')}" placeholder="${esc(t('brandPlaceholder', 'Defaults to your store name'))}" /></div>
                </div>
                <div class="fct-cm-table-wrap">
                    <table class="fct-cm-table fct-cm-identifiers-table">
                        <thead><tr>
                            <th>${esc(t('variation', 'Variation'))}</th>
                            <th>${esc(t('sku', 'SKU'))}</th>
                            <th>${esc(t('gtin', 'GTIN'))}</th>
                            <th>${esc(t('mpn', 'MPN'))}</th>
                        </tr></thead>
                        <tbody>${body}</tbody>
                    </table>
                </div>
            </div>`;
    }

    // GTIN / MPN inputs placed directly below FluentCart's own SKU fields
    // (Inventory Management), saved per variation as soon as they change. The
    // card's table stays as a fallback when no SKU field is on screen.
    const INLINE = { productId: null, rows: [], active: false, canEdit: true, observer: null, card: null };

    function startInlineIdentifiers(productId, data, canEdit, cardEl) {
        INLINE.productId = productId;
        INLINE.rows      = (data && data.rows) || [];
        INLINE.canEdit   = canEdit;
        INLINE.card      = cardEl;
        INLINE.active    = false;
        if (!INLINE.rows.length) return;
        if (!INLINE.observer) {
            // FluentCart re-renders its tables often; batch to one pass per frame.
            let queued = false;
            INLINE.observer = new MutationObserver(() => {
                if (queued) return;
                queued = true;
                requestAnimationFrame(() => { queued = false; placeInline(); });
            });
            INLINE.observer.observe(document.body, { childList: true, subtree: true });
            document.addEventListener('input', onInlineInput, true);
            document.addEventListener('change', onInlineChange, true);
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && e.target.dataset && e.target.dataset.cmfInline) {
                    e.preventDefault();
                    e.target.blur();
                }
            }, true);
        }
        placeInline();
    }

    /**
     * The variation behind a SKU field. FluentCart's rows carry no variation
     * ID, so the SKU (unique per store) is the only safe key: a row without
     * one is never matched by position, since reordering or deleting a
     * variation would then put the codes on the wrong one. Those variations
     * are edited in the card's table instead, which is keyed by ID.
     */
    function inlineRowFor(sku) {
        if (!sku) return null;
        const hits = INLINE.rows.filter(r => r.sku && r.sku === sku);
        return hits.length === 1 ? hits[0] : null;
    }

    /** Any inline inputs on screen at all. */
    function placedInline() {
        return !!document.querySelector('[data-cmf-vid] [data-cmf-inline]');
    }

    /** Variations that have no inline inputs on screen (no SKU, or a SKU not yet saved). */
    function inlineUnmatched() {
        return INLINE.rows.filter(r => !document.querySelector(`[data-cmf-vid="${CSS.escape(String(r.variation_id))}"]`));
    }

    function inlineInput(row, kind) {
        const dis = INLINE.canEdit ? '' : ' disabled';
        return kind === 'gtin'
            ? `<input type="text" inputmode="numeric" data-cmf-inline="gtin" value="${esc(row.gtin || '')}" placeholder="${esc(t('gtinPlaceholder', 'EAN/UPC/ISBN'))}" aria-label="${esc(t('gtin', 'GTIN'))}"${dis} />`
            : `<input type="text" data-cmf-inline="mpn" value="${esc(row.mpn || '')}" aria-label="${esc(t('mpn', 'MPN'))}"${dis} />`;
    }

    // Stacked form, for a lone SKU field outside a table.
    function inlineHtml(row) {
        return `
            <div class="cmf-inline-ids cmf-inline-ids--row" data-cmf-vid="${esc(String(row.variation_id))}">
                <label><span>${esc(t('gtin', 'GTIN'))}</span>${inlineInput(row, 'gtin')}</label>
                <label><span>${esc(t('mpn', 'MPN'))}</span>${inlineInput(row, 'mpn')}</label>
                <div class="cmf-inline-status" aria-live="polite"></div>
            </div>`;
    }

    const INLINE_COLS = [
        { kind: 'gtin', width: 140, title: () => t('gtin', 'GTIN') },
        { kind: 'mpn',  width: 84, title: () => t('mpn', 'MPN') },
    ];

    /**
     * The two extra columns must fit the card, which clips rather than
     * scrolls. FluentCart's columns are narrowed proportionally (never below a
     * floor, SKU and Total Stock keep room for their inputs) through a
     * stylesheet, because CSS width beats the width attribute Vue re-applies.
     */
    function fitColumns(table, skuCol) {
        const headerTable = table.querySelector('.el-table__header');
        const total = parseFloat((headerTable && headerTable.style.width) || '') || table.clientWidth;
        const cols = Array.from(table.querySelector('colgroup').querySelectorAll('col[name]'));
        if (!total || !cols.length) return;
        const key = cols.map(c => c.getAttribute('name') + ':' + c.getAttribute('width')).join(',') + '|' + total;
        if (table.dataset.cmfFit === key) return;
        table.dataset.cmfFit = key;

        const ours = INLINE_COLS.reduce((sum, c) => sum + c.width, 0);
        const available = total - ours;
        const skuIndex = cols.findIndex(x => x.getAttribute('name') === skuCol);
        // SKU keeps room for a 13-digit code plus FluentCart's Generate button;
        // Total Stock for its input group; the count columns after it shrink most.
        const floors = cols.map((c, i) => {
            if (i === skuIndex) return 185;
            if (i === skuIndex + 1) return 104;
            return i > skuIndex + 1 ? 61 : 110;
        });
        // Count columns go straight to their floor so Title keeps the room.
        let widths = cols.map((c, i) => (i > skuIndex + 1 ? floors[i] : parseFloat(c.getAttribute('width')) || 80));
        for (let pass = 0; pass < 3; pass++) {
            const fixed = widths.reduce((sum, w, i) => sum + (w <= floors[i] ? floors[i] : 0), 0);
            const flex  = widths.reduce((sum, w, i) => sum + (w > floors[i] ? w : 0), 0);
            const factor = flex ? Math.max(0, available - fixed) / flex : 1;
            widths = widths.map((w, i) => Math.max(floors[i], w > floors[i] ? Math.floor(w * factor) : floors[i]));
        }

        let id = table.dataset.cmfTable;
        if (!id) { id = 'cmf' + Math.random().toString(36).slice(2, 8); table.dataset.cmfTable = id; }
        const counts = cols.slice(skuIndex + 2).map(c => c.getAttribute('name'));
        const rules = cols.map((c, i) => `.el-table[data-cmf-table="${id}"] col[name="${c.getAttribute('name')}"]{width:${widths[i]}px}`)
            .concat(counts.map(n => `.el-table[data-cmf-table="${id}"] .${n} > .cell{padding-left:4px;padding-right:4px}`))
            .concat(counts.map(n => `.el-table[data-cmf-table="${id}"] th.${n} > .cell{font-size:12px}`))
            .concat(INLINE_COLS.map(c => `.el-table[data-cmf-table="${id}"] col[data-cmf-col="${c.kind}"]{width:${c.width}px}`));
        let style = document.getElementById('cmf-fit-' + id);
        if (!style) {
            style = document.createElement('style');
            style.id = 'cmf-fit-' + id;
            document.head.appendChild(style);
        }
        style.textContent = rules.join('\n');
    }

    function placeInline() {
        if (!INLINE.rows.length || !INLINE.productId || productIdFromHash() !== INLINE.productId) return;
        let placed = 0;

        // Variation table: real GTIN and MPN columns right after FluentCart's
        // SKU column. Extra <col>s make the fixed-layout table wider than its
        // box, and el-table's own horizontal scroll takes over.
        document.querySelectorAll('.el-table').forEach(table => {
            if (table.closest('#' + CONTAINER_ID)) return;
            const th = Array.from(table.querySelectorAll('.el-table__header th')).find(h => !h.dataset.cmfCol && (h.textContent || '').trim() === 'SKU');
            if (!th) return;
            const col = Array.from(th.classList).find(c => /^el-table_\d+_column_\d+$/.test(c));
            if (!col) return;

            table.querySelectorAll('colgroup').forEach(cg => {
                const skuCol = cg.querySelector(`col[name="${col}"]`);
                if (!skuCol || cg.querySelector('col[data-cmf-col]')) return;
                let after = skuCol;
                INLINE_COLS.forEach(c => {
                    const el = document.createElement('col');
                    el.setAttribute('width', String(c.width));
                    el.dataset.cmfCol = c.kind;
                    after.insertAdjacentElement('afterend', el);
                    after = el;
                });
            });

            if (!th.parentElement.querySelector('th[data-cmf-col]')) {
                let after = th;
                INLINE_COLS.forEach(c => {
                    const h = document.createElement('th');
                    h.className = Array.from(th.classList).filter(x => x !== col).join(' ');
                    h.dataset.cmfCol = c.kind;
                    h.innerHTML = `<div class="cell">${esc(c.title())}</div>`;
                    after.insertAdjacentElement('afterend', h);
                    after = h;
                });
            }

            fitColumns(table, col);

            table.querySelectorAll('.el-table__body tr.el-table__row').forEach((tr, i) => {
                const td = tr.querySelector('td.' + col);
                if (!td) return;
                if (tr.querySelector('td[data-cmf-col]')) { placed++; return; }
                const skuInput = td.querySelector('input');
                const row = inlineRowFor(skuInput ? skuInput.value.trim() : '');
                // Unmatched rows still get cells, so the columns stay aligned;
                // they point to the card's table instead of guessing a variation.
                let after = td;
                INLINE_COLS.forEach((c, ci) => {
                    const cell = document.createElement('td');
                    cell.className = Array.from(td.classList).filter(x => x !== col).join(' ') + ' cmf-inline-cell';
                    cell.dataset.cmfCol = c.kind;
                    if (row) {
                        cell.dataset.cmfVid = String(row.variation_id);
                        cell.innerHTML = `<div class="cell">${inlineInput(row, c.kind)}${c.kind === 'gtin' ? '<div class="cmf-inline-status" aria-live="polite"></div>' : ''}</div>`;
                    } else {
                        cell.dataset.cmfUnmatched = '1';
                        cell.innerHTML = `<div class="cell">${ci === 0 ? `<span class="cmf-inline-note">${esc(t('identifiersNoSku', 'Save a SKU first, or use the Identifiers card below.'))}</span>` : ''}</div>`;
                    }
                    after.insertAdjacentElement('afterend', cell);
                    after = cell;
                });
                if (row) placed++;
            });
        });

        // Single variation: FluentCart's SKU input (id ends in ".sku") outside a
        // table, only when there is no SKU table (a simple product can show both,
        // the table plus a hidden "More details" SKU field).
        if (!placed) document.querySelectorAll('input[id$=".sku"]').forEach(input => {
            if (input.closest('.el-table') || input.closest('#' + CONTAINER_ID)) return;
            const item = input.closest('.el-form-item') || input.parentElement;
            if (!item || !item.parentElement) return;
            if (item.nextElementSibling && item.nextElementSibling.classList.contains('cmf-inline-ids')) { placed++; return; }
            const row = inlineRowFor(input.value.trim());
            if (!row) return;
            item.insertAdjacentHTML('afterend', inlineHtml(row));
            placed++;
        });

        // The card's table (keyed by variation ID) stays for every variation
        // the inline inputs could not claim.
        if (placed && INLINE.card) {
            const unmatched = inlineUnmatched();
            INLINE.active = unmatched.length === 0;
            toggleCardTable(INLINE.card, unmatched);
        }
    }

    function toggleCardTable(cardEl, unmatched) {
        const wrap = cardEl.querySelector('.fct-cm-identifiers-table');
        if (wrap && wrap.parentElement) wrap.parentElement.hidden = unmatched.length === 0;
        const help = cardEl.querySelector('.fct-cm-identifiers-block > .fct-cm-label-wrap .fct-cm-help');
        if (help) {
            help.textContent = unmatched.length === 0
                ? t('identifiersInlineHelp', 'GTIN and MPN are entered below each variation’s SKU in Inventory Management. [upfluent_cmf_gtin] shows the selected variation’s GTIN.')
                : t('identifiersPartialHelp', 'Variations with a saved SKU are edited next to it in Inventory Management. The others are edited here. [upfluent_cmf_gtin] shows the selected variation’s GTIN.');
        }
        // Rows already editable inline are hidden here so a code is edited in one place.
        cardEl.querySelectorAll('tr[data-cmf-variation]').forEach(tr => {
            tr.hidden = !unmatched.some(r => String(r.variation_id) === tr.dataset.cmfVariation);
        });
    }

    // The GTIN / MPN inputs of one variation: a table row's two cells, or one stacked block.
    function inlineScope(target) {
        const holder = target.closest('[data-cmf-vid]');
        if (!holder) return null;
        const vid = holder.dataset.cmfVid;
        const scope = holder.classList.contains('cmf-inline-ids') ? holder : holder.closest('tr');
        return scope ? { vid, scope } : null;
    }

    function inlineStatus(scope, msg, isError) {
        const el = scope.querySelector('.cmf-inline-status');
        if (!el) return;
        el.textContent = msg || '';
        el.classList.toggle('fct-cm-error', !!isError);
        el.classList.toggle('fct-cm-success', !!msg && !isError);
    }

    function onInlineInput(e) {
        if (!e.target.dataset || !e.target.dataset.cmfInline) return;
        const hit = inlineScope(e.target);
        if (!hit) return;
        e.stopPropagation(); // keep FluentCart's form from treating it as its own field
        if (e.target.dataset.cmfInline === 'gtin') inlineStatus(hit.scope, gtinProblem(e.target.value), true);
    }

    function onInlineChange(e) {
        if (!e.target.dataset || !e.target.dataset.cmfInline || !INLINE.canEdit) return;
        const hit = inlineScope(e.target);
        if (!hit) return;
        e.stopPropagation();
        const { vid, scope } = hit;
        const gtinEl = scope.querySelector('[data-cmf-inline="gtin"]');
        const mpnEl  = scope.querySelector('[data-cmf-inline="mpn"]');
        const gtin = gtinEl ? gtinEl.value : '';
        const mpn  = mpnEl ? mpnEl.value : '';
        const problem = gtinProblem(gtin);
        if (problem) { inlineStatus(scope, problem, true); return; }
        inlineStatus(scope, t('saving', 'Saving…'), false);
        api.post('product/' + encodeURIComponent(INLINE.productId) + '/identifiers/variation/' + encodeURIComponent(vid), { gtin, mpn }).then(res => {
            const row = INLINE.rows.find(r => String(r.variation_id) === String(vid));
            if (row) { row.gtin = res.gtin; row.mpn = res.mpn; }
            document.querySelectorAll(`[data-cmf-vid="${CSS.escape(String(vid))}"]`).forEach(h => {
                const box = h.classList.contains('cmf-inline-ids') ? h : h.closest('tr');
                if (!box) return;
                const g = box.querySelector('[data-cmf-inline="gtin"]');
                const m = box.querySelector('[data-cmf-inline="mpn"]');
                if (g) g.value = res.gtin || '';
                if (m) m.value = res.mpn || '';
            });
            inlineStatus(scope, t('saved', 'Saved.'), false);
            setTimeout(() => { if (scope.isConnected) inlineStatus(scope, '', false); }, 2500);
        }).catch(err => {
            inlineStatus(scope, err.message || t('saveError', 'Could not save.'), true);
        });
    }

    // Every variation's codes. A variation edited inline (next to its SKU)
    // reports the inline values, so the card's Save never overwrites them
    // with the card's stale copy.
    function collectIdentifiers(el) {
        const brand = el.querySelector('[data-cmf-brand-input]');
        const rows = [];
        el.querySelectorAll('tr[data-cmf-variation]').forEach(tr => {
            const vid = tr.dataset.cmfVariation;
            const inlineGtin = document.querySelector(`[data-cmf-vid="${CSS.escape(vid)}"] [data-cmf-inline="gtin"]`);
            const inlineMpn  = document.querySelector(`[data-cmf-vid="${CSS.escape(vid)}"] [data-cmf-inline="mpn"]`);
            rows.push({
                variation_id: parseInt(vid, 10),
                gtin: inlineGtin ? inlineGtin.value : ((tr.querySelector('[data-cmf-gtin-input]') || {}).value || ''),
                mpn:  inlineMpn  ? inlineMpn.value  : ((tr.querySelector('[data-cmf-mpn-input]') || {}).value || ''),
            });
        });
        return { brand: brand ? brand.value : '', rows };
    }

    // Same rules as the server (ProductIdentifiers::normalizeGtin), for instant feedback.
    function gtinProblem(raw) {
        const code = String(raw || '').replace(/[\s-]/g, '').toUpperCase();
        if (!code) return '';
        const digit = (d) => {
            let sum = 0;
            for (let i = 0; i < d.length; i++) sum += Number(d[d.length - 1 - i]) * (i % 2 === 0 ? 3 : 1);
            return String((10 - sum % 10) % 10);
        };
        if (/^\d{9}[\dX]$/.test(code)) {
            let sum = 0;
            for (let i = 0; i < 10; i++) sum += (code[i] === 'X' ? 10 : Number(code[i])) * (10 - i);
            return sum % 11 === 0 ? '' : t('isbnBad', 'This ISBN-10 has an invalid check digit.');
        }
        if (!/^\d+$/.test(code) || ![8, 12, 13, 14].includes(code.length)) {
            return t('gtinLength', 'A GTIN has 8, 12, 13 or 14 digits.');
        }
        return digit(code.slice(0, -1)) === code.slice(-1) ? '' : t('gtinCheck', 'Invalid check digit. Please check for a typo.');
    }

    function showGtinHint(input) {
        const cell = input.parentElement.querySelector('.fct-cm-id-error');
        if (cell) cell.textContent = gtinProblem(input.value);
    }

    function renderTopLevel(field, value, attachments) {
        const isFree = FREE_TYPE_VALUES.includes(field.field_type);
        let inner = null;
        if (!isFree && PRO && PRO.editor && PRO.editor.renderTopLevel) {
            inner = PRO.editor.renderTopLevel(field, value, attachments);
        }
        if (inner === null || inner === undefined) {
            inner = renderInput(field, value, attachments, 'fct-cm-input-' + field.field_id);
        }

        const labelFor = isFree ? ` for="fct-cm-input-${esc(field.field_id)}"` : '';
        const code = shortcodeFor(field);

        return `
            <div class="fct-cm-field-row fct-cm-field-row--${esc(field.field_type)}" data-fct-wrap="${esc(field.field_id)}" data-fct-type="${esc(field.field_type)}">
                <div class="fct-cm-label-wrap">
                    <label class="fct-cm-label"${labelFor}>${esc(field.field_title)}</label>
                    <button type="button" class="fct-cm-shortcode fct-cm-copy" data-shortcode="${esc(code)}" title="${esc(t('copyShortcode', 'Copy shortcode'))}">${esc(code)}</button>
                </div>
                <div class="fct-cm-input">${inner}</div>
            </div>`;
    }

    function bindEditorBehaviour(body, markDirty) {
        // Color "no color" toggle
        body.addEventListener('change', (e) => {
            if (!e.target.classList.contains('fct-cm-color-clear')) return;
            const picker = e.target.closest('.fct-cm-colorwrap').querySelector('input[type=color]');
            if (picker) picker.disabled = e.target.checked;
        });

        if (PRO && PRO.editor && PRO.editor.bind) PRO.editor.bind(body, markDirty);
    }

    function collectValue(wrap, field) {
        if (!FREE_TYPE_VALUES.includes(field.field_type)) {
            return PRO && PRO.editor && PRO.editor.collect ? PRO.editor.collect(wrap, field) : undefined;
        }
        const el = wrap.querySelector(`[data-fct-field="${CSS.escape(field.field_id)}"]`);
        return readScalar(el, field.field_type);
    }

    // ─── Init ───

    function boot() {
        if (cfg.context === 'settings') {
            initDefinitionsApp();
            return;
        }
        if (new URLSearchParams(window.location.search).get('page') === 'fluent-cart') {
            initProductEditor();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
