/* Shopify to FluentCart Migrator — admin screen. No dependencies. */
(function () {
    'use strict';

    var data = window.s2fcData || {};
    var i18n = data.i18n || {};

    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function sprintf(str) {
        var args = Array.prototype.slice.call(arguments, 1), i = 0;
        return String(str).replace(/%(\d+\$)?[sd]/g, function (m, pos) {
            var idx = pos ? parseInt(pos, 10) - 1 : i++;
            return args[idx] !== undefined ? args[idx] : '';
        });
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ── Upload: show the chosen file name, support drag and drop ── */
    var upload = $('[data-s2fc-upload]');
    if (upload) {
        var input = $('input[type=file]', upload);
        var drop  = $('.s2fc-drop', upload);
        var name  = $('[data-s2fc-filename]', upload);
        var btn   = $('[data-s2fc-upload-btn]', upload);

        input.addEventListener('change', function () {
            name.textContent = input.files && input.files[0] ? input.files[0].name : '';
        });
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
        upload.addEventListener('submit', function () {
            btn.disabled = true;
            btn.textContent = i18n.uploading || 'Uploading…';
        });
    }

    /* ── Review table ── */
    var table = $('[data-s2fc-table]');
    if (!table) { return; }

    var rows       = $$('[data-s2fc-row]', table);
    var selectAll  = $('[data-s2fc-select-all]');
    var filter     = $('[data-s2fc-filter]');
    var countEl    = $('[data-s2fc-selected-count]');
    var importBtn  = $('[data-s2fc-import]');
    var stopBtn    = $('[data-s2fc-stop]');
    var progress   = $('[data-s2fc-progress]');
    var bar        = $('[data-s2fc-progress-bar]');
    var progText   = $('[data-s2fc-progress-text]');
    var optionsBox = $('[data-s2fc-options]');
    var running    = false;
    var stopAsked  = false;

    function visibleRows() { return rows.filter(function (r) { return !r.hidden; }); }
    /* Every ticked row, filtered or not: what you ticked is what gets imported. */
    function pickedRows() {
        return rows.filter(function (r) { return $('[data-s2fc-pick]', r).checked; });
    }
    function updateCount() {
        var n = pickedRows().length;
        countEl.textContent = n ? sprintf(i18n.selected || '%d selected', n) : '';
        var vis = visibleRows();
        selectAll.checked = vis.length > 0 && vis.every(function (r) { return $('[data-s2fc-pick]', r).checked; });
    }

    selectAll.addEventListener('change', function () {
        visibleRows().forEach(function (r) { $('[data-s2fc-pick]', r).checked = selectAll.checked; });
        updateCount();
    });
    rows.forEach(function (r) {
        $('[data-s2fc-pick]', r).addEventListener('change', updateCount);
    });
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase();
        rows.forEach(function (r) {
            r.hidden = q !== '' && (r.getAttribute('data-search') || '').indexOf(q) === -1;
        });
        updateCount();
    });
    updateCount();

    /* Category column follows the "categories from" select. */
    var catSelect = $('[data-s2fc-category-source]');
    if (catSelect) {
        catSelect.addEventListener('change', function () {
            var source = catSelect.value;
            rows.forEach(function (r) {
                var cell = $('[data-s2fc-cats]', r);
                if (!cell) { return; }
                var map;
                try { map = JSON.parse(cell.getAttribute('data-s2fc-cats') || '{}'); } catch (e) { map = {}; }
                var names = map[source] || [];
                cell.textContent = names.length ? names.join(', ') : '\u2014';
            });
        });
    }

    function options() {
        var o = {};
        $$('select, input', optionsBox).forEach(function (el) {
            if (el.type === 'checkbox') { o[el.name] = el.checked ? 1 : 0; }
            else { o[el.name] = el.value; }
        });
        return o;
    }

    /* Resolves with {status, json} for any HTTP answer; rejects only when the request itself failed. */
    function post(body) {
        var form = new FormData();
        Object.keys(body).forEach(function (k) {
            if (typeof body[k] === 'object') {
                Object.keys(body[k]).forEach(function (kk) { form.append(k + '[' + kk + ']', body[k][kk]); });
            } else {
                form.append(k, body[k]);
            }
        });
        return fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: form })
            .then(function (res) {
                return res.text().then(function (text) {
                    var json = null;
                    try { json = JSON.parse(text); } catch (e) { json = null; }
                    return { status: res.status, json: json, text: text };
                });
            });
    }

    function renderResult(row, r, ok) {
        var cell = $('[data-s2fc-result]', row);
        var status = ok ? (r.status || 'imported') : 'failed';
        var cls = { imported: 'good', skipped: '', failed: 'bad' }[status] || 'bad';
        var label = { imported: i18n.imported, skipped: i18n.skipped, failed: i18n.failed }[status] || status;
        var html = '<span class="s2fc-pill s2fc-pill--' + cls + '">' + escapeHtml(label) + '</span>';
        if (r.edit_url) {
            html += ' <a href="' + escapeHtml(r.edit_url) + '" target="_blank" rel="noopener">' + escapeHtml(i18n.edit || 'Edit') + '</a>';
        }
        var notes = [];
        if (r.message) { notes.push(r.message); }
        if (r.warnings && r.warnings.length) { notes = notes.concat(r.warnings); }
        if (r.gtin && r.gtin.saved) { notes.push('GTIN ×' + r.gtin.saved); }
        if (r.variant_images) { notes.push('Variant images ×' + r.variant_images); }
        if (notes.length) {
            html += '<div class="s2fc-small s2fc-muted">' + escapeHtml(notes.join(' ')) + '</div>';
        }
        cell.innerHTML = html;
        row.classList.remove('is-working');
        row.classList.add(ok && status !== 'failed' ? 'is-done' : 'is-failed');
        if (ok && status === 'imported') {
            $('[data-s2fc-pick]', row).checked = false;
        }
    }

    function setRunning(on) {
        running = on;
        importBtn.disabled = on;
        stopBtn.hidden = !on;
        progress.hidden = false;
        $$('select, input', optionsBox).forEach(function (el) { el.disabled = on; });
    }

    function importQueue(queue, opts) {
        var total = queue.length, done = 0, imported = 0, skipped = 0, failed = 0, expired = '';

        function tick() {
            if (!queue.length || stopAsked) {
                finish();
                return;
            }
            var row = queue.shift();
            row.classList.add('is-working');
            $('[data-s2fc-result]', row).innerHTML = '<span class="s2fc-muted">' + escapeHtml(i18n.importing || 'Importing…') + '</span>';
            row.scrollIntoView({ block: 'nearest' });

            var attempt = 0;
            function send() {
                attempt++;
                post({ action: 's2fc_import_one', nonce: data.nonce, index: row.getAttribute('data-index'), options: opts })
                    .then(function (r) {
                        var res = r.json;
                        /* Expired nonce or lost permission: stop everything, do not retry. */
                        if (r.status === 403 || r.text === '-1' || r.text === '0') {
                            var msg = (res && res.data && res.data.message) || i18n.expired || 'Session expired. Reload the page.';
                            renderResult(row, { message: msg }, false);
                            failed++;
                            stopAsked = true;
                            expired = msg;
                            step();
                            return;
                        }
                        if (!res || typeof res.success === 'undefined') {
                            /* HTML error page or empty body: the server may still have created the product, so never re-send. */
                            failed++;
                            renderResult(row, { message: sprintf(i18n.serverError || 'Server error (HTTP %d).', r.status) }, false);
                            step();
                            return;
                        }
                        var payload = res.data || {};
                        if (res.success) {
                            if (payload.status === 'skipped') { skipped++; } else { imported++; }
                            renderResult(row, payload, true);
                        } else {
                            failed++;
                            renderResult(row, payload, false);
                        }
                        step();
                    })
                    .catch(function () {
                        /* The request never reached the server (offline, DNS): safe to retry. */
                        if (attempt < 3) {
                            progText.textContent = i18n.network || 'Network error; retrying…';
                            setTimeout(send, 1500 * attempt);
                            return;
                        }
                        failed++;
                        renderResult(row, { message: 'Request failed' }, false);
                        step();
                    });
            }
            function step() {
                done++;
                bar.style.width = Math.round(done / total * 100) + '%';
                progText.textContent = sprintf(i18n.progress || '%1$d of %2$d', done, total);
                tick();
            }
            send();
        }

        function finish() {
            if (!expired) { post({ action: 's2fc_finish', nonce: data.nonce }).catch(function () {}); }
            setRunning(false);
            var msg = expired ? expired + ' ' : (stopAsked ? (i18n.stopped || 'Stopped.') + ' ' : '');
            msg += sprintf(i18n.done || 'Done. %1$d imported, %2$d skipped, %3$d failed.', imported, skipped, failed);
            progText.innerHTML = escapeHtml(msg) + ' <a href="' + escapeHtml(data.productsUrl || '#') + '">' + escapeHtml(i18n.openProducts || 'Open FluentCart products') + '</a>';
            stopAsked = false;
            updateCount();
        }

        setRunning(true);
        bar.style.width = '0%';
        tick();
    }

    importBtn.addEventListener('click', function () {
        if (running) { return; }
        var picked = pickedRows();
        if (!picked.length) {
            window.alert(i18n.none || 'Tick at least one product.');
            return;
        }
        if (!window.confirm(sprintf(i18n.confirm || 'Import %d products?', picked.length))) {
            return;
        }
        importQueue(picked.slice(), options());
    });

    stopBtn.addEventListener('click', function () {
        stopAsked = true;
        stopBtn.disabled = true;
        setTimeout(function () { stopBtn.disabled = false; }, 2000);
    });

    window.addEventListener('beforeunload', function (e) {
        if (running) {
            e.preventDefault();
            e.returnValue = i18n.leave || '';
        }
    });

    var reset = $('[data-s2fc-reset]');
    if (reset) {
        reset.addEventListener('submit', function (e) {
            if (running && !window.confirm(i18n.leave || 'Leave?')) { e.preventDefault(); }
        });
    }
})();
