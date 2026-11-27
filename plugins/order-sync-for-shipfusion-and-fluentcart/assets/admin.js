/**
 * Order Sync for ShipFusion and FluentCart: settings screen.
 *
 * Plain JavaScript (ES2017), no build step, no framework. Mounts into
 * #upfluent-sf-app (AdminMenu::render) and reads its config from
 * window.upfluentSfAdmin: { restUrl, nonce, pageUrl, version, canManage }.
 * Every string goes through wp.i18n with the plugin's text domain.
 *
 * Layout, top to bottom:
 *   1. header: name, outcome line, version
 *   2. setup steps, only until the connection test passes
 *   3. status            4. needs attention (only when something does)
 *   5. run now           6. automatic sync (own Save)
 *   7. connection (Save and test)
 *   8. tracking updates from ShipFusion (webhook address and secret)
 *   9. troubleshooting (collapsed): detailed logging, activity log
 *
 * Rendering: each card is a slot whose HTML is rebuilt from `state` and only
 * written when it changed, with focus restored by element id. Unsaved form
 * values live in `draft`, so a status refresh never wipes what was typed.
 * Result lines are persistent aria-live nodes moved into each re-render, so
 * screen readers hear every result.
 */
(function () {
    'use strict';

    const cfg = window.upfluentSfAdmin || {};
    const root = document.getElementById('upfluent-sf-app');
    if (!root) return;

    const i18n = (window.wp && window.wp.i18n) || {};
    const __ = i18n.__ || ((text) => text);
    const _n = i18n._n || ((single, plural, number) => (number === 1 ? single : plural));
    const sprintf = i18n.sprintf || fallbackSprintf;

    function fallbackSprintf(format) {
        const args = Array.prototype.slice.call(arguments, 1);
        let i = 0;
        return String(format).replace(/%(?:(\d+)\$)?([sd%])/g, (m, pos, type) => {
            if (type === '%') return '%';
            const v = pos ? args[parseInt(pos, 10) - 1] : args[i++];
            return type === 'd' ? String(parseInt(v, 10) || 0) : String(v);
        });
    }

    // ─── Helpers ───

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /** Only http(s) links reach an href. */
    function safeUrl(u) {
        if (!u) return '';
        try {
            const url = new URL(String(u), window.location.href);
            return (url.protocol === 'http:' || url.protocol === 'https:') ? url.href : '';
        } catch (e) {
            return '';
        }
    }

    const idPart = (v) => String(v == null ? '' : v).replace(/[^A-Za-z0-9_-]/g, '');
    const num = (v) => (typeof v === 'number' && isFinite(v) ? v : parseInt(v, 10) || 0);
    const norm = (v) => (v == null ? '' : String(v));

    const locale = (document.documentElement.getAttribute('lang') || navigator.language || 'en').replace('_', '-');

    function fmtNumber(n) {
        try { return new Intl.NumberFormat(locale).format(n); } catch (e) { return String(n); }
    }

    function absTime(d) {
        try {
            return d.toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' });
        } catch (e) {
            return d.toLocaleString();
        }
    }

    function relTime(iso) {
        const d = new Date(iso);
        if (!iso || isNaN(d.getTime())) return '';
        const diff = (d.getTime() - Date.now()) / 1000;
        if (Math.abs(diff) < 45) return __('just now', 'order-sync-for-shipfusion-and-fluentcart');
        const units = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
        let rtf = null;
        try { rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }); } catch (e) { rtf = null; }
        if (!rtf) return absTime(d);
        for (let i = 0; i < units.length; i++) {
            if (Math.abs(diff) >= units[i][1] || units[i][0] === 'minute') {
                return rtf.format(Math.round(diff / units[i][1]), units[i][0]);
            }
        }
        return absTime(d);
    }

    /** <time> with a relative label and the absolute time on hover. Returns safe HTML. */
    function timeTag(iso) {
        const d = new Date(iso);
        if (!iso || isNaN(d.getTime())) return '';
        return `<time datetime="${esc(d.toISOString())}" title="${esc(absTime(d))}" data-rel>${esc(relTime(iso))}</time>`;
    }

    const isPast = (iso) => { const d = new Date(iso); return !isNaN(d.getTime()) && d.getTime() <= Date.now(); };

    // ─── REST client ───

    function endpoint(path) {
        const base = String(cfg.restUrl || '');
        if (base.indexOf('rest_route=') !== -1) {
            // Plain permalinks: the route is a query value, so the path goes there.
            const url = new URL(base, window.location.href);
            const route = (url.searchParams.get('rest_route') || '/').replace(/\/?$/, '/');
            url.searchParams.set('rest_route', route + path);
            return url.toString();
        }
        return base.replace(/\/?$/, '/') + path;
    }

    async function request(method, path, body) {
        const headers = { 'X-WP-Nonce': cfg.nonce || '', Accept: 'application/json' };
        const options = { method, credentials: 'same-origin', headers };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        let res;
        try {
            res = await fetch(endpoint(path), options);
        } catch (e) {
            throw new Error(__("Couldn't reach your site. Check your connection and try again.", 'order-sync-for-shipfusion-and-fluentcart'));
        }
        let data = null;
        try { data = await res.json(); } catch (e) { data = null; }
        if (!res.ok) {
            let message = data && typeof data.message === 'string' ? data.message : '';
            if (data && data.code === 'rest_cookie_invalid_nonce') {
                message = __('Your login has expired. Reload the page and try again.', 'order-sync-for-shipfusion-and-fluentcart');
            }
            if (!message) {
                /* translators: %d: HTTP status code returned by the site. */
                message = sprintf(__('Your site returned an error (%d). Reload the page and try again.', 'order-sync-for-shipfusion-and-fluentcart'), res.status);
            }
            throw new Error(message);
        }
        if (data === null || typeof data !== 'object') {
            throw new Error(__("Your site sent an answer this page couldn't read. Reload the page and try again.", 'order-sync-for-shipfusion-and-fluentcart'));
        }
        return data;
    }

    // ─── Screen state ───

    let state = null;
    let loadError = '';
    const draft = { auto: {}, conn: {} };
    const busy = {};
    const rowBusy = {};
    const rowNote = {};
    let newSecret = '';
    let troubleOpen = false;
    let warehousesOpen = false;
    const log = { loaded: false, loading: false, entries: [], error: '' };

    const AUTO_KEYS = ['send_orders', 'check_tracking', 'tracking_interval', 'sync_stock', 'stock_interval', 'complete_on_delivery', 'alerts', 'alerts_email'];

    const settings = () => (state && state.settings) || {};
    const val = (form, key) => (Object.prototype.hasOwnProperty.call(draft[form], key) ? draft[form][key] : settings()[key]);
    const isLocked = () => !(state && state.connected);

    function isDirty(form) {
        if (!state) return false;
        const s = settings();
        return Object.keys(draft[form]).some((k) => {
            if (k === 'token') return norm(draft.conn.token).trim() !== '';
            return norm(draft[form][k]) !== norm(s[k]);
        });
    }

    // ─── Persistent live regions ───

    const liveNodes = {};
    const liveText = {};

    function liveNode(key) {
        if (!liveNodes[key]) {
            const p = document.createElement('p');
            p.id = 'upf-sf-live-' + key;
            p.className = 'upf-result';
            p.setAttribute('role', 'status');
            p.setAttribute('aria-live', 'polite');
            liveNodes[key] = p;
        }
        return liveNodes[key];
    }

    /** Placeholder swapped for the persistent node after each render. */
    const liveSlot = (key) => `<span data-live="${esc(key)}"></span>`;

    function setLive(key, text, tone) {
        liveText[key] = { text: text || '', tone: tone || '' };
        const node = liveNode(key);
        node.className = 'upf-result' + (tone ? ' is-' + tone : '');
        node.textContent = text || '';
    }

    const hasLive = (key) => !!(liveText[key] && liveText[key].text);

    // ─── Slots and rendering ───

    const SLOTS = ['setup', 'status', 'attention', 'run', 'auto', 'connection', 'webhook', 'trouble'];
    const slotEls = {};
    const prevHtml = {};

    function mountShell(extra) {
        const version = cfg.version || (state && state.version) || '';
        root.innerHTML = `
            <header class="upf-header">
                <div class="upf-header-main">
                    <h1 class="upf-title">${esc(__('ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'))}</h1>
                    <p class="upf-subtitle">${esc(__('Paid orders go to your ShipFusion warehouse. Tracking and stock come back to your store.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                </div>
                ${version ? `<p class="upf-version">${esc(sprintf(
                    /* translators: %s: plugin version number. */
                    __('Version %s', 'order-sync-for-shipfusion-and-fluentcart'), version))}</p>` : ''}
            </header>
            ${liveSlot('page')}
            ${extra || SLOTS.map((s) => `<div class="upf-slot" data-slot="${s}"></div>`).join('')}`;
        root.querySelectorAll('[data-slot]').forEach((el) => { slotEls[el.dataset.slot] = el; });
        mountLive(root);
    }

    function mountLive(scope) {
        scope.querySelectorAll('[data-live]').forEach((ph) => {
            const key = ph.getAttribute('data-live');
            const node = liveNode(key);
            const t = liveText[key] || { text: '', tone: '' };
            node.className = 'upf-result' + (t.tone ? ' is-' + t.tone : '');
            if (node.textContent !== t.text) node.textContent = t.text;
            ph.replaceWith(node);
        });
    }

    function patch(name, html) {
        const el = slotEls[name];
        if (!el || prevHtml[name] === html) return;
        const active = document.activeElement;
        const focusId = active && active.id && el.contains(active) ? active.id : '';
        let sel = null;
        if (focusId) {
            try {
                if (typeof active.selectionStart === 'number') sel = [active.selectionStart, active.selectionEnd];
            } catch (e) { sel = null; }
        }
        el.innerHTML = html;
        prevHtml[name] = html;
        mountLive(el);
        if (focusId) {
            let target = document.getElementById(focusId);
            if (!target) target = el.querySelector('[data-focus-fallback]') || null;
            if (target) {
                target.focus({ preventScroll: true });
                if (sel && typeof target.setSelectionRange === 'function') {
                    try { target.setSelectionRange(sel[0], sel[1]); } catch (e) { /* not a text field */ }
                }
            }
        }
    }

    function render() {
        if (!state) {
            const body = loadError
                ? `<p class="upf-help">${esc(loadError)}</p>
                   <p class="upf-actions"><button type="button" class="button button-primary" id="upf-sf-reload" data-action="reload">${esc(__('Try again', 'order-sync-for-shipfusion-and-fluentcart'))}</button></p>`
                : `<p class="upf-help upf-loading">${esc(__('Loading…', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`;
            patch('status', card('status', loadError ? __("Couldn't load ShipFusion settings", 'order-sync-for-shipfusion-and-fluentcart') : __('Status', 'order-sync-for-shipfusion-and-fluentcart'), body));
            return;
        }
        patch('setup', setupCard());
        patch('status', statusCard());
        patch('attention', attentionCard());
        patch('run', runCard());
        patch('auto', autoCard());
        patch('connection', connectionCard());
        patch('webhook', webhookCard());
        patch('trouble', troubleCard());
    }

    /** Card frame. `body` and `foot` are safe HTML. */
    function card(key, title, body, foot, extraClass) {
        const tid = 'upf-sf-' + key + '-title';
        return `
            <section class="upf-card${extraClass ? ' ' + extraClass : ''}" id="upf-sf-${key}" aria-labelledby="${tid}">
                <div class="upf-card-head"><h2 class="upf-card-title" id="${tid}" tabindex="-1" data-focus-fallback>${esc(title)}</h2></div>
                <div class="upf-card-body">${body}</div>
                ${foot ? `<div class="upf-card-foot">${foot}</div>` : ''}
            </section>`;
    }

    /** "Connect ShipFusion first." line other controls point at with aria-describedby. */
    function lockedNote(id) {
        return `<p class="upf-locked"><span id="${id}">${esc(__('Connect ShipFusion first.', 'order-sync-for-shipfusion-and-fluentcart'))}</span>
            <a href="#upf-sf-token" data-action="goto" data-target="upf-sf-token">${esc(__('Go to Connection', 'order-sync-for-shipfusion-and-fluentcart'))}</a></p>`;
    }

    // ─── 2. Setup ───

    function setupCard() {
        const conn = state.connection || {};
        if (conn.status === 'ok') return '';
        const s = settings();
        const steps = [
            {
                done: !!s.token_saved,
                title: __('Get your API token', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('In Shipfusion 360, open Settings → Integrations → API Credentials and copy the token.', 'order-sync-for-shipfusion-and-fluentcart'),
            },
            {
                done: !!s.token_saved,
                title: __('Paste it and choose your warehouses', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Use the Connection card further down this page.', 'order-sync-for-shipfusion-and-fluentcart'),
                target: 'upf-sf-token',
            },
            {
                done: conn.status === 'ok',
                title: __('Click Save and test', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('You see right away whether ShipFusion accepts the token.', 'order-sync-for-shipfusion-and-fluentcart'),
                target: 'upf-sf-save-conn',
            },
            {
                done: !!(state.webhook && state.webhook.secret_set),
                title: __('Send tracking back to your store', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Under Tracking updates from ShipFusion, follow the steps to add the webhooks in Shipfusion 360.', 'order-sync-for-shipfusion-and-fluentcart'),
                target: 'upf-sf-webhook-url',
            },
            {
                done: !!s.send_orders,
                title: __('Turn on automatic sending', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Under Automatic sync, turn on sending paid orders and click Save.', 'order-sync-for-shipfusion-and-fluentcart'),
                target: 'upf-sf-send_orders',
            },
        ];
        const doneLabel = esc(__('Done', 'order-sync-for-shipfusion-and-fluentcart'));
        const problem = conn.status === 'error' && conn.message
            ? `<p class="upf-notice is-error">${esc(conn.message)}</p>`
            : '';
        const body = `
            ${problem}
            <ol class="upf-steps">
                ${steps.map((st, i) => `
                    <li class="upf-step${st.done ? ' is-done' : ''}">
                        <span class="upf-step-num" aria-hidden="true">${i + 1}</span>
                        <div class="upf-step-text">
                            <strong>${st.target ? `<a href="#${st.target}" data-action="goto" data-target="${st.target}">${esc(st.title)}</a>` : esc(st.title)}</strong>
                            ${st.done ? `<span class="upf-badge is-success">${doneLabel}</span>` : ''}
                            <p>${esc(st.text)}</p>
                        </div>
                    </li>`).join('')}
            </ol>`;
        return card('setup', __('Connect your ShipFusion warehouse', 'order-sync-for-shipfusion-and-fluentcart'), body, '', 'upf-card--setup');
    }

    // ─── 3. Status ───

    function connectionSummary() {
        const conn = state.connection || {};
        const map = {
            ok: ['success', __('Working', 'order-sync-for-shipfusion-and-fluentcart')],
            error: ['error', __('Not working', 'order-sync-for-shipfusion-and-fluentcart')],
            not_set: ['neutral', __('Not set up yet', 'order-sync-for-shipfusion-and-fluentcart')],
        };
        const m = map[conn.status] || map.not_set;
        const checked = conn.checked_at
            /* translators: %s: relative time, for example "5 minutes ago". */
            ? `<span class="upf-meta">${sprintf(esc(__('Checked %s', 'order-sync-for-shipfusion-and-fluentcart')), timeTag(conn.checked_at))}</span>`
            : '';
        return `
            <div class="upf-status-line">
                <span class="upf-badge is-${m[0]}"><span class="upf-dot" aria-hidden="true"></span>${esc(m[1])}</span>
                ${checked}
            </div>
            ${conn.message ? `<p class="upf-help">${esc(conn.message)}</p>` : ''}`;
    }

    function runLine(run, withNext) {
        if (!run || !run.last_at) {
            return `<span class="upf-meta">${esc(__('Not run yet', 'order-sync-for-shipfusion-and-fluentcart'))}</span>`;
        }
        /* translators: %s: relative time, for example "5 minutes ago". */
        let out = `<span class="upf-meta">${sprintf(esc(__('Last run %s', 'order-sync-for-shipfusion-and-fluentcart')), timeTag(run.last_at))}</span>`;
        if (run.message) {
            out += ` <span class="upf-run-msg${run.ok === false ? ' is-error' : ''}">${run.ok === false ? `<span class="upf-sr-only">${esc(__('Problem:', 'order-sync-for-shipfusion-and-fluentcart'))} </span>` : ''}${esc(run.message)}</span>`;
        }
        if (withNext && run.next_at) {
            out += isPast(run.next_at)
                ? ` <span class="upf-meta">${esc(__('Next run is due now', 'order-sync-for-shipfusion-and-fluentcart'))}</span>`
                /* translators: %s: relative time, for example "in 10 minutes". */
                : ` <span class="upf-meta">${sprintf(esc(__('Next run %s', 'order-sync-for-shipfusion-and-fluentcart')), timeTag(run.next_at))}</span>`;
        }
        return out;
    }

    function statusCard() {
        const c = state.counts || {};
        const runs = state.runs || {};
        const wh = state.webhook || {};
        const attention = num(c.attention);
        const stats = [
            [num(c.waiting), __('Orders waiting to send', 'order-sync-for-shipfusion-and-fluentcart'), ''],
            [num(c.sent_7d), __('Sent in the last 7 days', 'order-sync-for-shipfusion-and-fluentcart'), ''],
            [num(c.shipped_7d), __('Shipped in the last 7 days', 'order-sync-for-shipfusion-and-fluentcart'), ''],
            [attention, __('Need attention', 'order-sync-for-shipfusion-and-fluentcart'), attention > 0 ? 'is-alert' : ''],
        ];
        const statHtml = stats.map(([n, label, cls]) => `
            <div class="upf-stat ${cls}">
                <dt class="upf-stat-label">${esc(label)}</dt>
                <dd class="upf-stat-num">${cls && n > 0 ? `<a href="#upf-sf-attention" data-action="goto" data-target="upf-sf-attention-title">${esc(fmtNumber(n))}</a>` : esc(fmtNumber(n))}</dd>
            </div>`).join('');
        const runRows = [
            ['orders', __('Sending orders', 'order-sync-for-shipfusion-and-fluentcart')],
            ['tracking', __('Checking tracking', 'order-sync-for-shipfusion-and-fluentcart')],
            ['stock', __('Updating stock', 'order-sync-for-shipfusion-and-fluentcart')],
        ].map(([k, label]) => `
            <div class="upf-run-row">
                <dt>${esc(label)}</dt>
                <dd>${runLine(runs[k], true)}</dd>
            </div>`).join('');
        const lastUpdate = wh.last_event_at
            ? timeTag(wh.last_event_at)
            : esc(__('None received yet', 'order-sync-for-shipfusion-and-fluentcart'));
        const body = `
            <div class="upf-status-block">
                <h3 class="upf-subhead">${esc(__('Connection', 'order-sync-for-shipfusion-and-fluentcart'))}</h3>
                ${connectionSummary()}
            </div>
            <dl class="upf-stats">${statHtml}</dl>
            <dl class="upf-runs">
                ${runRows}
                <div class="upf-run-row">
                    <dt>${esc(__('Last tracking update from ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'))}</dt>
                    <dd><span class="upf-meta">${lastUpdate}</span></dd>
                </div>
            </dl>`;
        return card('status', __('Status', 'order-sync-for-shipfusion-and-fluentcart'), body);
    }

    // ─── 4. Needs attention ───

    function attentionCard() {
        const items = Array.isArray(state.attention) ? state.attention : [];
        if (!items.length && !hasLive('attention')) return '';
        const locked = isLocked();
        const list = items.map((it) => {
            const id = idPart(it.order_id);
            const label = it.order_label || ('#' + id);
            const url = safeUrl(it.order_url);
            const rb = rowBusy[id];
            /* translators: %s: order number, for example "#1042". */
            const orderText = esc(sprintf(__('Order %s', 'order-sync-for-shipfusion-and-fluentcart'), label));
            const hidden = `<span class="upf-sr-only"> ${esc(label)}</span>`;
            const attempts = num(it.attempts);
            const meta = [];
            /* translators: %s: relative time, for example "2 days ago". */
            if (it.since) meta.push(sprintf(esc(__('Since %s', 'order-sync-for-shipfusion-and-fluentcart')), timeTag(it.since)));
            /* translators: %d: number of times the order was tried. */
            if (attempts) meta.push(esc(sprintf(_n('%d try', '%d tries', attempts, 'order-sync-for-shipfusion-and-fluentcart'), attempts)));
            const note = rowNote[id];
            const retryBtn = it.can_retry ? `
                <button type="button" class="button button-primary" id="upf-sf-retry-${id}" data-action="retry" data-order="${id}" data-label="${esc(label)}"
                    ${rb || locked ? 'aria-disabled="true"' : ''}${locked ? ' aria-describedby="upf-sf-locked-attention"' : ''}>
                    ${rb === 'retry' ? esc(__('Retrying…', 'order-sync-for-shipfusion-and-fluentcart')) : esc(__('Retry', 'order-sync-for-shipfusion-and-fluentcart')) + hidden}
                </button>` : '';
            return `
                <li class="upf-attn-item">
                    <div class="upf-attn-main">
                        <p class="upf-attn-order">${url ? `<a href="${esc(url)}">${orderText}</a>` : orderText}</p>
                        <p class="upf-attn-problem">${esc(it.problem)}</p>
                        ${meta.length ? `<p class="upf-meta">${meta.join(' · ')}</p>` : ''}
                        ${note ? `<p class="upf-row-note is-${note.ok ? 'success' : 'error'}">${esc(note.message)}</p>` : ''}
                    </div>
                    <div class="upf-attn-actions">
                        ${retryBtn}
                        <button type="button" class="button" id="upf-sf-dismiss-${id}" data-action="dismiss" data-order="${id}" data-label="${esc(label)}"
                            ${rb ? 'aria-disabled="true"' : ''}>
                            ${rb === 'dismiss' ? esc(__('Saving…', 'order-sync-for-shipfusion-and-fluentcart')) : esc(__('Mark as handled', 'order-sync-for-shipfusion-and-fluentcart')) + hidden}
                        </button>
                    </div>
                </li>`;
        }).join('');
        const body = `
            ${items.length ? `<p class="upf-help">${esc(__("These orders didn't reach ShipFusion. Fix the problem, then click Retry. Mark an order as handled if you shipped it another way.", 'order-sync-for-shipfusion-and-fluentcart'))}</p>` : `<p class="upf-help">${esc(__('Nothing else needs your attention.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`}
            ${items.length && locked ? lockedNote('upf-sf-locked-attention') : ''}
            ${items.length ? `<ul class="upf-attn">${list}</ul>` : ''}
            ${liveSlot('attention')}`;
        return card('attention', __('Needs attention', 'order-sync-for-shipfusion-and-fluentcart'), body, '', items.length ? 'upf-card--alert' : '');
    }

    // ─── 5. Run now ───

    function runCard() {
        const locked = isLocked();
        const runs = state.runs || {};
        const waiting = num((state.counts || {}).waiting);
        const tiles = [
            {
                key: 'orders',
                title: __('Orders', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Send paid orders that are waiting to ShipFusion now.', 'order-sync-for-shipfusion-and-fluentcart'),
                /* translators: %s: number of orders waiting to be sent. */
                button: sprintf(__('Send waiting orders (%s)', 'order-sync-for-shipfusion-and-fluentcart'), fmtNumber(waiting)),
                busyLabel: __('Sending…', 'order-sync-for-shipfusion-and-fluentcart'),
            },
            {
                key: 'tracking',
                title: __('Tracking', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Get tracking for orders ShipFusion has shipped.', 'order-sync-for-shipfusion-and-fluentcart'),
                button: __('Check tracking now', 'order-sync-for-shipfusion-and-fluentcart'),
                busyLabel: __('Checking…', 'order-sync-for-shipfusion-and-fluentcart'),
            },
            {
                key: 'stock',
                title: __('Stock', 'order-sync-for-shipfusion-and-fluentcart'),
                text: __('Copy stock levels from ShipFusion to your products.', 'order-sync-for-shipfusion-and-fluentcart'),
                button: __('Update stock now', 'order-sync-for-shipfusion-and-fluentcart'),
                busyLabel: __('Updating…', 'order-sync-for-shipfusion-and-fluentcart'),
            },
        ];
        const html = tiles.map((t) => {
            const b = !!busy['run-' + t.key];
            const attrs = locked
                ? 'aria-disabled="true" aria-describedby="upf-sf-locked-run"'
                : (b ? 'aria-disabled="true" aria-busy="true"' : '');
            return `
                <div class="upf-tile">
                    <h3 class="upf-tile-title">${esc(t.title)}</h3>
                    <p class="upf-help">${esc(t.text)}</p>
                    <p class="upf-tile-action">
                        <button type="button" class="button button-primary" id="upf-sf-run-${t.key}" data-action="run" data-run="${t.key}" ${attrs}>${esc(b ? t.busyLabel : t.button)}</button>
                    </p>
                    ${liveSlot('run-' + t.key)}
                    <p class="upf-last">${runLine(runs[t.key], false)}</p>
                </div>`;
        }).join('');
        const body = `${locked ? lockedNote('upf-sf-locked-run') : ''}<div class="upf-tiles">${html}</div>`;
        return card('run', __('Run now', 'order-sync-for-shipfusion-and-fluentcart'), body);
    }

    // ─── 6. Automatic sync ───

    function intervalOptions(selected) {
        const list = Array.isArray(state.intervals) ? state.intervals : [];
        return list.map((o) => `<option value="${esc(o.value)}"${norm(o.value) === norm(selected) ? ' selected' : ''}>${esc(o.label)}</option>`).join('');
    }

    /** One switch row. opts: { form, key, label, help, locked, lockedBy, busy, extra } */
    function switchRow(opts) {
        const id = 'upf-sf-' + opts.key;
        const on = opts.form ? !!val(opts.form, opts.key) : !!opts.checked;
        const described = [];
        if (opts.help) described.push(id + '-help');
        if (opts.locked) described.push(opts.lockedBy);
        const disabled = opts.locked || opts.busy;
        return `
            <div class="upf-field upf-field--switch${opts.locked ? ' is-locked' : ''}">
                <div class="upf-switch-row">
                    <input type="checkbox" role="switch" class="upf-switch" id="${id}"
                        ${opts.form ? `data-form="${opts.form}" data-key="${opts.key}"` : ''}
                        ${opts.dataAttr || ''}
                        ${on ? 'checked' : ''}
                        ${disabled ? 'aria-disabled="true"' : ''}
                        ${described.length ? `aria-describedby="${described.join(' ')}"` : ''} />
                    <label for="${id}" class="upf-switch-label">${esc(opts.label)}</label>
                </div>
                ${opts.help ? `<p class="upf-help" id="${id}-help">${esc(opts.help)}</p>` : ''}
                ${opts.extra || ''}
            </div>`;
    }

    function intervalField(interval, locked) {
        const id = 'upf-sf-' + interval;
        return `
            <div class="upf-sub">
                <label class="upf-label" for="${id}">${esc(__('How often', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                <select id="${id}" class="upf-input upf-input--short" data-form="auto" data-key="${interval}"${locked ? ' disabled aria-describedby="upf-sf-locked-auto"' : ''}>
                    ${intervalOptions(val('auto', interval))}
                </select>
            </div>`;
    }

    function autoCard() {
        const locked = isLocked();
        const s = settings();
        const saving = !!busy.auto;
        const dflt = s.alerts_email_default || '';
        const alertsExtra = val('auto', 'alerts') ? `
            <div class="upf-sub">
                <label class="upf-label" for="upf-sf-alerts_email">${esc(__('Send to', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                <input type="email" id="upf-sf-alerts_email" class="upf-input" data-form="auto" data-key="alerts_email"
                    value="${esc(val('auto', 'alerts_email'))}" placeholder="${esc(dflt)}" autocomplete="email"
                    ${dflt ? 'aria-describedby="upf-sf-alerts_email-help"' : ''} />
                ${dflt ? `<p class="upf-help" id="upf-sf-alerts_email-help">${esc(sprintf(
                    /* translators: %s: email address used when the field is left empty. */
                    __('Leave empty to send to %s.', 'order-sync-for-shipfusion-and-fluentcart'), dflt))}</p>` : ''}
            </div>` : '';
        const body = `
            ${locked ? lockedNote('upf-sf-locked-auto') : ''}
            ${switchRow({
                form: 'auto', key: 'send_orders', locked, lockedBy: 'upf-sf-locked-auto',
                label: __('Send paid orders to ShipFusion automatically', 'order-sync-for-shipfusion-and-fluentcart'),
                help: __('Paid orders go to the warehouse without anyone clicking Send.', 'order-sync-for-shipfusion-and-fluentcart'),
            })}
            ${switchRow({
                form: 'auto', key: 'check_tracking', locked, lockedBy: 'upf-sf-locked-auto',
                label: __('Check tracking automatically', 'order-sync-for-shipfusion-and-fluentcart'),
                extra: val('auto', 'check_tracking') ? intervalField('tracking_interval', locked) : '',
            })}
            ${switchRow({
                form: 'auto', key: 'sync_stock', locked, lockedBy: 'upf-sf-locked-auto',
                label: __('Update stock automatically', 'order-sync-for-shipfusion-and-fluentcart'),
                extra: val('auto', 'sync_stock') ? intervalField('stock_interval', locked) : '',
            })}
            ${switchRow({
                form: 'auto', key: 'complete_on_delivery',
                label: __('Mark orders completed when ShipFusion reports them delivered', 'order-sync-for-shipfusion-and-fluentcart'),
                help: __('Leave off to complete orders yourself.', 'order-sync-for-shipfusion-and-fluentcart'),
            })}
            ${switchRow({
                form: 'auto', key: 'alerts',
                label: __('Email me when something needs attention', 'order-sync-for-shipfusion-and-fluentcart'),
                help: __('For example when an order could not be sent or ShipFusion stops answering.', 'order-sync-for-shipfusion-and-fluentcart'),
                extra: alertsExtra,
            })}`;
        const foot = `
            <button type="button" class="button button-primary" id="upf-sf-save-auto" data-action="save-auto"${saving ? ' aria-disabled="true" aria-busy="true"' : ''}>${esc(saving ? __('Saving…', 'order-sync-for-shipfusion-and-fluentcart') : __('Save', 'order-sync-for-shipfusion-and-fluentcart'))}</button>
            <span class="upf-dirty" id="upf-sf-dirty-auto"${isDirty('auto') ? '' : ' hidden'}>${esc(__('You have unsaved changes.', 'order-sync-for-shipfusion-and-fluentcart'))}</span>
            ${liveSlot('auto')}`;
        return card('auto', __('Automatic sync', 'order-sync-for-shipfusion-and-fluentcart'), body, foot);
    }

    // ─── 7. Connection ───

    function connectionCard() {
        const s = settings();
        const b = busy.conn || '';
        const options = Array.isArray(s.warehouse_options) ? s.warehouse_options : [];
        const picked = Array.isArray(val('conn', 'warehouses')) ? val('conn', 'warehouses') : [];
        const pickedLabels = options.filter((o) => picked.includes(o.value)).map((o) => o.label);
        const tokenHelp = [];
        let saved = '';
        if (s.token_saved) {
            saved = s.token_hint
                /* translators: %s: last characters of the saved token, for example "…a1b2". */
                ? sprintf(__('A token is saved (ends in %s). Paste a new token only to replace it.', 'order-sync-for-shipfusion-and-fluentcart'), s.token_hint)
                : __('A token is saved. Paste a new token only to replace it.', 'order-sync-for-shipfusion-and-fluentcart');
            tokenHelp.push('upf-sf-token-saved');
        }
        tokenHelp.push('upf-sf-token-help');
        const body = `
            <div class="upf-form-grid">
                <div class="upf-field upf-field--wide">
                    <label class="upf-label" for="upf-sf-token">${esc(__('API token', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                    ${saved ? `<p class="upf-saved" id="upf-sf-token-saved"><span class="upf-badge is-success">${esc(__('Saved', 'order-sync-for-shipfusion-and-fluentcart'))}</span> ${esc(saved)}</p>` : ''}
                    <input type="password" id="upf-sf-token" class="upf-input" data-form="conn" data-key="token"
                        value="${esc(draft.conn.token || '')}" autocomplete="new-password" spellcheck="false"
                        aria-describedby="${tokenHelp.join(' ')}" />
                    <p class="upf-help" id="upf-sf-token-help">${esc(__('In Shipfusion 360, open Settings → Integrations → API Credentials and copy the token.', 'order-sync-for-shipfusion-and-fluentcart'))}
                        <a href="https://360.shipfusion.com/settings/integrations" target="_blank" rel="noopener noreferrer">${esc(__('Open Shipfusion 360 integrations', 'order-sync-for-shipfusion-and-fluentcart'))}<span class="upf-sr-only"> ${esc(__('(opens in a new tab)', 'order-sync-for-shipfusion-and-fluentcart'))}</span></a></p>
                </div>
                <div class="upf-field">
                    <span class="upf-label" id="upf-sf-warehouses-label">${esc(__('Warehouses', 'order-sync-for-shipfusion-and-fluentcart'))}</span>
                    <details class="upf-multi" id="upf-sf-warehouses"${warehousesOpen ? ' open' : ''}>
                        <summary class="upf-input upf-multi-summary" aria-labelledby="upf-sf-warehouses-label upf-sf-warehouses-picked" aria-describedby="upf-sf-warehouse-help"><span id="upf-sf-warehouses-picked">${esc(pickedLabels.join(', '))}</span></summary>
                        <fieldset class="upf-multi-list">
                            <legend class="upf-sr-only">${esc(__('ShipFusion warehouses you use', 'order-sync-for-shipfusion-and-fluentcart'))}</legend>
                            ${options.map((o) => `<label class="upf-multi-option"><input type="checkbox" data-warehouse="${esc(o.value)}"${picked.includes(o.value) ? ' checked' : ''} /> ${esc(o.label)}</label>`).join('')}
                        </fieldset>
                    </details>
                    <p class="upf-help" id="upf-sf-warehouse-help">${esc(__('Check every ShipFusion warehouse that stores your products. Each order ships from one: the first checked warehouse in the customer\'s country that has every item in stock. Stock levels add up the checked warehouses.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                </div>
                <div class="upf-field">
                    <label class="upf-label" for="upf-sf-fallback_phone">${esc(__('Phone number for orders without one', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                    <input type="tel" id="upf-sf-fallback_phone" class="upf-input" data-form="conn" data-key="fallback_phone"
                        value="${esc(val('conn', 'fallback_phone'))}" autocomplete="tel" aria-describedby="upf-sf-fallback_phone-help" />
                    <p class="upf-help" id="upf-sf-fallback_phone-help">${esc(__('ShipFusion needs a phone number for some carriers. Use your store or support number.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                </div>
            </div>`;
        const saveLabel = b === 'save' ? __('Saving…', 'order-sync-for-shipfusion-and-fluentcart')
            : (b === 'test' || b === 'save-test') ? __('Testing…', 'order-sync-for-shipfusion-and-fluentcart')
            : __('Save and test', 'order-sync-for-shipfusion-and-fluentcart');
        const testLabel = b === 'test-only' ? __('Testing…', 'order-sync-for-shipfusion-and-fluentcart') : __('Test connection', 'order-sync-for-shipfusion-and-fluentcart');
        const dis = b ? ' aria-disabled="true"' : '';
        const foot = `
            <button type="button" class="button button-primary" id="upf-sf-save-conn" data-action="save-conn"${dis}${b && b !== 'test-only' ? ' aria-busy="true"' : ''}>${esc(b === 'test-only' ? __('Save and test', 'order-sync-for-shipfusion-and-fluentcart') : saveLabel)}</button>
            <button type="button" class="button" id="upf-sf-test-conn" data-action="test-conn"${dis}${b === 'test-only' ? ' aria-busy="true"' : ''}>${esc(testLabel)}</button>
            <span class="upf-dirty" id="upf-sf-dirty-conn"${isDirty('conn') ? '' : ' hidden'}>${esc(__('You have unsaved changes.', 'order-sync-for-shipfusion-and-fluentcart'))}</span>
            ${liveSlot('conn')}`;
        return card('connection', __('Connection', 'order-sync-for-shipfusion-and-fluentcart'), body, foot);
    }

    // ─── 8. Tracking updates from ShipFusion ───

    function webhookCard() {
        const wh = state.webhook || {};
        const making = !!busy.secret;
        const makeLabel = making
            ? __('Making a secret…', 'order-sync-for-shipfusion-and-fluentcart')
            : (wh.secret_set || newSecret ? __('Make a new secret', 'order-sync-for-shipfusion-and-fluentcart') : __('Make a secret', 'order-sync-for-shipfusion-and-fluentcart'));
        const makeBtn = `<button type="button" class="button${wh.secret_set || newSecret ? '' : ' button-primary'}" id="upf-sf-make-secret" data-action="make-secret"${making ? ' aria-disabled="true" aria-busy="true"' : ''}>${esc(makeLabel)}</button>`;
        let secret;
        if (newSecret) {
            secret = `
                <label class="upf-label" for="upf-sf-secret">${esc(__('Secret', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                <div class="upf-copy-row">
                    <input type="text" id="upf-sf-secret" class="upf-input upf-input--code" value="${esc(newSecret)}" readonly spellcheck="false" aria-describedby="upf-sf-secret-help" />
                    <button type="button" class="button button-primary" id="upf-sf-copy-secret" data-action="copy" data-source="upf-sf-secret" data-what="secret">${esc(__('Copy secret', 'order-sync-for-shipfusion-and-fluentcart'))}</button>
                </div>
                <p class="upf-help upf-help--strong" id="upf-sf-secret-help">${esc(__("Copy it now and paste it into Shipfusion 360. It won't be shown again.", 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                <p class="upf-actions">${makeBtn}</p>`;
        } else if (wh.secret_set) {
            secret = `
                <p class="upf-label">${esc(__('Secret', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                <p class="upf-saved"><span class="upf-badge is-success">${esc(__('Set', 'order-sync-for-shipfusion-and-fluentcart'))}</span> ${esc(__('A secret is set.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                <p class="upf-actions">${makeBtn}</p>`;
        } else {
            secret = `
                <p class="upf-label">${esc(__('Secret', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                <p class="upf-help">${esc(__("No secret yet. Tracking won't reach your store until you make one and paste it into Shipfusion 360.", 'order-sync-for-shipfusion-and-fluentcart'))}</p>
                <p class="upf-actions">${makeBtn}</p>`;
        }
        const recent = Array.isArray(wh.recent) ? wh.recent : [];
        const recentHtml = recent.length
            ? `<ul class="upf-recent">${recent.map((r) => `<li>${timeTag(r.time)} <span>${esc(r.text)}</span></li>`).join('')}</ul>`
            : `<p class="upf-help">${esc(__('No updates received yet.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`;
        const events = [
            ['shipment_shipped', __('Adds the tracking number to the order as soon as it ships.', 'order-sync-for-shipfusion-and-fluentcart'), __('Add', 'order-sync-for-shipfusion-and-fluentcart')],
            ['shipment_onHold', __('Shows why the warehouse put an order on hold.', 'order-sync-for-shipfusion-and-fluentcart'), __('Add', 'order-sync-for-shipfusion-and-fluentcart')],
            ['shipment_backOrder', __('Shows that an order is waiting for stock.', 'order-sync-for-shipfusion-and-fluentcart'), __('Add', 'order-sync-for-shipfusion-and-fluentcart')],
            ['warehouseShipment_received', __('Updates stock as soon as new stock arrives at the warehouse.', 'order-sync-for-shipfusion-and-fluentcart'), __('Add if stock updates are on', 'order-sync-for-shipfusion-and-fluentcart')],
            ['lowInventory', __('Updates a product\'s stock as soon as it runs low.', 'order-sync-for-shipfusion-and-fluentcart'), __('Add if stock updates are on', 'order-sync-for-shipfusion-and-fluentcart')],
            ['return_received', __('Not used: this plugin doesn\'t handle returns.', 'order-sync-for-shipfusion-and-fluentcart'), __('Skip', 'order-sync-for-shipfusion-and-fluentcart')],
        ];
        const eventsHtml = `
            <h3 class="upf-subhead">${esc(__('Set it up in Shipfusion 360', 'order-sync-for-shipfusion-and-fluentcart'))}</h3>
            <ol class="upf-setup">
                <li>${esc(__('In Shipfusion 360, open Settings and go to Webhooks.', 'order-sync-for-shipfusion-and-fluentcart'))}</li>
                <li>${esc(__('Create one webhook for each event below. Use the same webhook address for every event.', 'order-sync-for-shipfusion-and-fluentcart'))}</li>
                <li>${esc(__('Paste the same secret into each webhook\'s authCode field.', 'order-sync-for-shipfusion-and-fluentcart'))}</li>
            </ol>
            <ul class="upf-events">${events.map(([code, what, add]) => `
                <li><code>${esc(code)}</code><span class="upf-badge ${add === __('Skip', 'order-sync-for-shipfusion-and-fluentcart') ? 'is-neutral' : 'is-info'}">${esc(add)}</span><span class="upf-event-what">${esc(what)}</span></li>`).join('')}
            </ul>
            <p class="upf-help">${esc(__('Without the webhooks, tracking and stock still update on their schedule, just not right away.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`;
        const body = `
            <p class="upf-help upf-intro">${esc(__('ShipFusion tells your store the moment an order ships, so customers get tracking right away. It needs the webhook address and secret below.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>
            <div class="upf-field">
                <label class="upf-label" for="upf-sf-webhook-url">${esc(__('Webhook address', 'order-sync-for-shipfusion-and-fluentcart'))}</label>
                <div class="upf-copy-row">
                    <input type="text" id="upf-sf-webhook-url" class="upf-input upf-input--code" value="${esc(wh.url)}" readonly spellcheck="false" />
                    <button type="button" class="button" id="upf-sf-copy-url" data-action="copy" data-source="upf-sf-webhook-url" data-what="address">${esc(__('Copy address', 'order-sync-for-shipfusion-and-fluentcart'))}</button>
                </div>
            </div>
            <div class="upf-field">${secret}</div>
            ${liveSlot('webhook')}
            ${eventsHtml}
            <h3 class="upf-subhead">${esc(__('Recent updates', 'order-sync-for-shipfusion-and-fluentcart'))}</h3>
            ${recentHtml}`;
        return card('webhook', __('Tracking updates from ShipFusion', 'order-sync-for-shipfusion-and-fluentcart'), body);
    }

    // ─── 9. Troubleshooting ───

    function levelBadge(level) {
        const map = {
            error: ['error', __('Error', 'order-sync-for-shipfusion-and-fluentcart')],
            warning: ['warning', __('Warning', 'order-sync-for-shipfusion-and-fluentcart')],
            info: ['neutral', __('Info', 'order-sync-for-shipfusion-and-fluentcart')],
        };
        const m = map[level] || map.info;
        return `<span class="upf-badge is-${m[0]}">${esc(m[1])}</span>`;
    }

    function logHtml() {
        if (log.loading && !log.loaded) return `<p class="upf-help upf-loading">${esc(__('Loading…', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`;
        if (log.error) return `<p class="upf-notice is-error">${esc(log.error)}</p>`;
        if (!log.loaded) return '';
        if (!log.entries.length) return `<p class="upf-help">${esc(__('Nothing logged yet.', 'order-sync-for-shipfusion-and-fluentcart'))}</p>`;
        return `
            <ol class="upf-log" tabindex="0" aria-label="${esc(__('Activity log, newest first', 'order-sync-for-shipfusion-and-fluentcart'))}">
                ${log.entries.map((e) => `
                    <li class="upf-log-entry">
                        <span class="upf-log-meta">${levelBadge(e.level)} ${timeTag(e.time)}</span>
                        <span class="upf-log-msg">${esc(e.message)}</span>
                    </li>`).join('')}
            </ol>`;
    }

    function troubleCard() {
        const s = settings();
        const body = `
            ${switchRow({
                key: 'debug', checked: !!s.debug, busy: !!busy.debug,
                dataAttr: 'data-debug',
                label: __('Detailed logging', 'order-sync-for-shipfusion-and-fluentcart'),
                help: __("Adds customer details to the log. Turn it off when you're done.", 'order-sync-for-shipfusion-and-fluentcart'),
            })}
            ${liveSlot('debug')}
            <div class="upf-log-head">
                <h3 class="upf-subhead">${esc(__('Activity log', 'order-sync-for-shipfusion-and-fluentcart'))}</h3>
                <button type="button" class="button button-small" id="upf-sf-reload-log" data-action="reload-log"${log.loading ? ' aria-disabled="true" aria-busy="true"' : ''}>${esc(log.loading ? __('Loading…', 'order-sync-for-shipfusion-and-fluentcart') : __('Refresh log', 'order-sync-for-shipfusion-and-fluentcart'))}</button>
            </div>
            ${logHtml()}`;
        return `
            <details class="upf-card upf-details" id="upf-sf-trouble"${troubleOpen ? ' open' : ''}>
                <summary class="upf-card-head" id="upf-sf-trouble-summary" data-focus-fallback><span class="upf-card-title">${esc(__('Troubleshooting', 'order-sync-for-shipfusion-and-fluentcart'))}</span></summary>
                <div class="upf-card-body">${body}</div>
            </details>`;
    }

    // ─── Actions ───

    async function refresh() {
        try {
            state = await request('GET', 'state');
            loadError = '';
        } catch (err) {
            if (state) {
                setLive('page', err.message, 'error');
            } else {
                loadError = err.message;
            }
        }
        render();
    }

    async function runNow(kind) {
        const key = 'run-' + kind;
        if (busy[key] || isLocked()) return;
        if (kind === 'orders') {
            const n = num((state.counts || {}).waiting);
            /* translators: %d: number of paid orders to send. */
            if (n > 0 && !window.confirm(sprintf(_n('Send %d paid order to ShipFusion now? The warehouse will pick and ship it.', 'Send %d paid orders to ShipFusion now? The warehouse will pick and ship them.', n, 'order-sync-for-shipfusion-and-fluentcart'), n))) return;
        }
        busy[key] = true;
        setLive(key, '', '');
        render();
        try {
            const r = await request('POST', 'run/' + kind);
            setLive(key, r.message || (r.ok ? __('Done.', 'order-sync-for-shipfusion-and-fluentcart') : __('Something went wrong. Check the activity log under Troubleshooting.', 'order-sync-for-shipfusion-and-fluentcart')), r.ok ? 'success' : 'error');
        } catch (err) {
            setLive(key, err.message, 'error');
        }
        busy[key] = false;
        await refresh();
    }

    async function rowAction(kind, id, label) {
        if (rowBusy[id]) return;
        if (kind === 'retry' && isLocked()) return;
        if (kind === 'dismiss' && !window.confirm(sprintf(
            /* translators: %s: order number, for example "#1042". */
            __("Mark order %s as handled? It won't be sent to ShipFusion or retried.", 'order-sync-for-shipfusion-and-fluentcart'), label))) return;
        rowBusy[id] = kind;
        delete rowNote[id];
        render();
        let note;
        try {
            const r = await request('POST', 'orders/' + encodeURIComponent(id) + '/' + kind);
            note = { ok: !!r.ok, message: r.message || (r.ok ? __('Done.', 'order-sync-for-shipfusion-and-fluentcart') : __('Something went wrong.', 'order-sync-for-shipfusion-and-fluentcart')) };
        } catch (err) {
            note = { ok: false, message: err.message };
        }
        rowNote[id] = note;
        /* translators: 1: order number, 2: result message. */
        setLive('attention', sprintf(__('%1$s: %2$s', 'order-sync-for-shipfusion-and-fluentcart'), label, note.message), note.ok ? 'success' : 'error');
        delete rowBusy[id];
        await refresh();
    }

    function collectAuto() {
        const body = {};
        AUTO_KEYS.forEach((k) => {
            const v = val('auto', k);
            body[k] = (typeof v === 'string') ? v.trim() : v;
        });
        return body;
    }

    async function saveAuto() {
        if (busy.auto) return;
        const body = collectAuto();
        if (body.alerts && body.alerts_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(body.alerts_email)) {
            setLive('auto', __('Enter a full email address, like name@example.com, or leave it empty.', 'order-sync-for-shipfusion-and-fluentcart'), 'error');
            const f = document.getElementById('upf-sf-alerts_email');
            if (f) f.focus();
            return;
        }
        busy.auto = true;
        setLive('auto', '', '');
        render();
        try {
            state = await request('POST', 'settings', body);
            draft.auto = {};
            setLive('auto', __('Saved.', 'order-sync-for-shipfusion-and-fluentcart'), 'success');
        } catch (err) {
            setLive('auto', err.message, 'error');
        }
        busy.auto = false;
        render();
    }

    const typedToken = () => norm(draft.conn.token).trim();

    function needToken() {
        setLive('conn', __('Paste your API token first.', 'order-sync-for-shipfusion-and-fluentcart'), 'error');
        const f = document.getElementById('upf-sf-token');
        if (f) f.focus();
    }

    async function saveConnection() {
        if (busy.conn) return;
        const token = typedToken();
        if (!token && !settings().token_saved) { needToken(); return; }
        const body = {
            warehouses: val('conn', 'warehouses'),
            fallback_phone: norm(val('conn', 'fallback_phone')).trim(),
        };
        if (token) body.token = token;
        busy.conn = 'save';
        setLive('conn', '', '');
        render();
        try {
            state = await request('POST', 'settings', body);
            draft.conn = {};
            const field = document.getElementById('upf-sf-token');
            if (field) field.value = '';
        } catch (err) {
            setLive('conn', err.message, 'error');
            busy.conn = '';
            render();
            return;
        }
        busy.conn = 'save-test';
        render();
        try {
            const r = await request('POST', 'connection/test', {});
            setLive('conn', r.message || (r.ok ? __('Saved. ShipFusion accepted the connection.', 'order-sync-for-shipfusion-and-fluentcart') : __("Saved, but ShipFusion didn't accept the connection.", 'order-sync-for-shipfusion-and-fluentcart')), r.ok ? 'success' : 'error');
        } catch (err) {
            setLive('conn', err.message, 'error');
        }
        busy.conn = '';
        await refresh();
    }

    async function testConnection() {
        if (busy.conn) return;
        const token = typedToken();
        if (!token && !settings().token_saved) { needToken(); return; }
        const body = {};
        if (token) body.token = token;
        if (Object.prototype.hasOwnProperty.call(draft.conn, 'warehouses')) body.warehouses = draft.conn.warehouses;
        busy.conn = 'test-only';
        setLive('conn', '', '');
        render();
        try {
            const r = await request('POST', 'connection/test', body);
            let msg = r.message || (r.ok ? __('ShipFusion accepted the connection.', 'order-sync-for-shipfusion-and-fluentcart') : __("ShipFusion didn't accept the connection.", 'order-sync-for-shipfusion-and-fluentcart'));
            if (r.ok && isDirty('conn')) msg += ' ' + __('Click Save and test to keep these values.', 'order-sync-for-shipfusion-and-fluentcart');
            setLive('conn', msg, r.ok ? 'success' : 'error');
        } catch (err) {
            setLive('conn', err.message, 'error');
        }
        busy.conn = '';
        await refresh();
    }

    async function makeSecret() {
        if (busy.secret) return;
        const wh = state.webhook || {};
        if ((wh.secret_set || newSecret) && !window.confirm(__('Make a new secret? ShipFusion will reject updates until you paste the new secret there.', 'order-sync-for-shipfusion-and-fluentcart'))) return;
        busy.secret = true;
        setLive('webhook', '', '');
        render();
        let made = false;
        try {
            const r = await request('POST', 'webhook/secret');
            newSecret = r.secret || '';
            made = !!newSecret;
            setLive('webhook', made
                ? __("New secret made. Copy it now and paste it into Shipfusion 360. It won't be shown again.", 'order-sync-for-shipfusion-and-fluentcart')
                : __("The secret wasn't made. Reload the page and try again.", 'order-sync-for-shipfusion-and-fluentcart'), made ? 'success' : 'error');
        } catch (err) {
            setLive('webhook', err.message, 'error');
        }
        busy.secret = false;
        await refresh();
        if (made) {
            const btn = document.getElementById('upf-sf-copy-secret');
            if (btn) btn.focus();
        }
    }

    async function copyFrom(sourceId, what) {
        const input = document.getElementById(sourceId);
        if (!input) return;
        let ok = false;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(input.value);
                ok = true;
            }
        } catch (e) { ok = false; }
        if (!ok) {
            input.focus();
            input.select();
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        }
        const done = what === 'secret'
            ? __('Secret copied.', 'order-sync-for-shipfusion-and-fluentcart')
            : __('Address copied.', 'order-sync-for-shipfusion-and-fluentcart');
        setLive('webhook', ok ? done : __("Couldn't copy. Select the text and copy it yourself.", 'order-sync-for-shipfusion-and-fluentcart'), ok ? 'success' : 'error');
    }

    async function saveDebug(on) {
        if (busy.debug) return;
        busy.debug = true;
        setLive('debug', '', '');
        render();
        try {
            state = await request('POST', 'settings', { debug: on });
            setLive('debug', on
                ? __('Detailed logging is on.', 'order-sync-for-shipfusion-and-fluentcart')
                : __('Detailed logging is off.', 'order-sync-for-shipfusion-and-fluentcart'), 'success');
        } catch (err) {
            setLive('debug', err.message, 'error');
        }
        busy.debug = false;
        render();
    }

    async function loadLog() {
        if (log.loading) return;
        log.loading = true;
        log.error = '';
        render();
        try {
            const r = await request('GET', 'log');
            log.entries = Array.isArray(r.entries) ? r.entries : [];
            log.loaded = true;
        } catch (err) {
            log.error = err.message;
        }
        log.loading = false;
        render();
    }

    function goTo(id) {
        const el = document.getElementById(id);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.focus({ preventScroll: true });
    }

    // ─── Events ───

    root.addEventListener('click', (e) => {
        const el = e.target.closest('[data-action], [aria-disabled="true"]');
        if (!el || !root.contains(el)) return;
        if (el.getAttribute('aria-disabled') === 'true') {
            e.preventDefault();
            return;
        }
        const action = el.getAttribute('data-action');
        switch (action) {
            case 'goto':
                e.preventDefault();
                goTo(el.getAttribute('data-target'));
                break;
            case 'reload':
                loadError = '';
                render();
                refresh();
                break;
            case 'run': runNow(el.getAttribute('data-run')); break;
            case 'retry': rowAction('retry', el.getAttribute('data-order'), el.getAttribute('data-label') || ('#' + el.getAttribute('data-order'))); break;
            case 'dismiss': rowAction('dismiss', el.getAttribute('data-order'), el.getAttribute('data-label') || ('#' + el.getAttribute('data-order'))); break;
            case 'save-auto': saveAuto(); break;
            case 'save-conn': saveConnection(); break;
            case 'test-conn': testConnection(); break;
            case 'make-secret': makeSecret(); break;
            case 'copy': copyFrom(el.getAttribute('data-source'), el.getAttribute('data-what')); break;
            case 'reload-log': loadLog(); break;
            default: break;
        }
    });

    function onField(e) {
        const el = e.target;
        if (!el || !root.contains(el)) return;
        const toggleLike = el.type === 'checkbox' || el.tagName === 'SELECT';
        if (toggleLike && e.type !== 'change') return;
        if (el.hasAttribute('data-debug')) {
            saveDebug(el.checked);
            return;
        }
        if (el.hasAttribute('data-warehouse')) {
            const codes = Array.from(root.querySelectorAll('[data-warehouse]')).filter((b) => b.checked).map((b) => b.getAttribute('data-warehouse'));
            if (!codes.length) {
                el.checked = true;
                setLive('conn', __('Keep at least one warehouse checked.', 'order-sync-for-shipfusion-and-fluentcart'), 'error');
                return;
            }
            draft.conn.warehouses = codes;
            render();
            const box = root.querySelector(`[data-warehouse="${el.getAttribute('data-warehouse')}"]`);
            if (box) box.focus();
            return;
        }
        const form = el.getAttribute('data-form');
        const key = el.getAttribute('data-key');
        if (!form || !key || !draft[form]) return;
        draft[form][key] = el.type === 'checkbox' ? el.checked : el.value;
        if (el.type === 'checkbox') {
            render();   // shows or hides the interval / email field
        } else {
            const dirty = document.getElementById('upf-sf-dirty-' + form);
            if (dirty) dirty.hidden = !isDirty(form);
        }
    }

    root.addEventListener('input', onField);
    root.addEventListener('change', onField);

    // <details> toggle does not bubble; listen in the capture phase.
    root.addEventListener('toggle', (e) => {
        const d = e.target;
        if (d && d.id === 'upf-sf-warehouses') { warehousesOpen = d.open; return; }
        if (!d || d.id !== 'upf-sf-trouble' || d.open === troubleOpen) return;
        troubleOpen = d.open;
        if (troubleOpen) loadLog(); else render();
    }, true);

    window.addEventListener('beforeunload', (e) => {
        if (isDirty('auto') || isDirty('conn')) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Keep relative times current without re-rendering forms.
    window.setInterval(() => {
        root.querySelectorAll('time[data-rel]').forEach((t) => {
            const label = relTime(t.getAttribute('datetime'));
            if (label && t.textContent !== label) t.textContent = label;
        });
    }, 60000);

    // ─── Start ───

    if (!cfg.canManage) {
        mountShell(card('no-access', __('ShipFusion settings', 'order-sync-for-shipfusion-and-fluentcart'),
            `<p class="upf-help">${esc(__("You can't change these settings. Ask a store administrator.", 'order-sync-for-shipfusion-and-fluentcart'))}</p>`));
        return;
    }

    mountShell();
    if (!cfg.restUrl) {
        loadError = __("This page didn't load completely. Reload the page and try again.", 'order-sync-for-shipfusion-and-fluentcart');
        render();
        return;
    }
    render();
    refresh();
})();
