/**
 * Checkout fields: conditional visibility and pre-submit validation.
 *
 * FluentCart's checkout button dispatches a synthetic submit event, so native
 * constraint validation never runs. This script validates the visible custom
 * fields on the button click (capture phase, ahead of FluentCart's handler)
 * and stops the order when something is missing. The server validates again
 * with the same rules, so this is a courtesy, not the gate.
 *
 * Hidden fields are disabled so the browser leaves them out of the posted
 * FormData; the server evaluates the same conditions against what it receives.
 */
(function () {
    'use strict';

    const cfg  = window.fctCmfCheckout || {};
    const I18N = cfg.i18n || {};
    const msg  = (key, title, fallback) => String(I18N[key] || fallback).replace('%s', title);

    function init() {
        const form = document.querySelector('form[data-fluent-cart-checkout-page-checkout-form]')
            || document.querySelector('form.fct_checkout');
        if (!form) return;

        const fields = () => Array.from(form.querySelectorAll('[data-cmf-field]'));
        if (!fields().length) return;

        // "Buy now" / instant checkout links keep their cart in the URL, not a
        // cookie; FluentCart only finds that cart when the hash is sent along.
        function appendCartHash(body) {
            const hash = new URLSearchParams(window.location.search).get('fct_cart_hash');
            if (hash) body.append('fct_cart_hash', hash);
        }

        // ── Reading values by input name (FluentCart's own inputs or ours) ──

        function valueOf(name) {
            let els;
            try { els = form.querySelectorAll('[name="' + CSS.escape(name) + '"]'); } catch (e) { return ''; }
            if (!els.length) return '';
            const el = els[0];
            if (el.type === 'radio') {
                const checked = Array.from(els).find(r => r.checked && !r.disabled);
                return checked ? checked.value : '';
            }
            if (el.type === 'checkbox') return el.checked && !el.disabled ? el.value : '';
            if (el.disabled) return '';
            return el.value || '';
        }

        // ── Conditions (same semantics as CheckoutFields::isVisible) ──

        function ruleMatches(rule) {
            const actual   = String(valueOf(rule.field) || '').trim().toLowerCase();
            const expected = String(rule.value || '').trim().toLowerCase();
            switch (rule.operator) {
                case 'not_equals':   return actual !== expected;
                case 'contains':     return expected !== '' && actual.indexOf(expected) !== -1;
                case 'not_contains': return expected === '' || actual.indexOf(expected) === -1;
                case 'empty':        return actual === '';
                case 'not_empty':    return actual !== '';
                default:             return actual === expected;
            }
        }

        function shouldShow(wrap) {
            let c = {};
            try { c = JSON.parse(wrap.dataset.cmfConditions || '{}') || {}; } catch (e) { c = {}; }
            if (!c.enabled || !Array.isArray(c.rules) || !c.rules.length) return true;
            const results = c.rules.map(ruleMatches);
            return c.relation === 'any' ? results.indexOf(true) !== -1 : results.indexOf(false) === -1;
        }

        function setVisible(wrap, show) {
            wrap.classList.toggle('cmf-hidden', !show);
            const required = wrap.dataset.cmfRequired === '1';
            wrap.querySelectorAll('input, select, textarea').forEach(el => {
                el.disabled = !show;
                if (!required) return;
                if (show) {
                    el.required = true;
                    el.setAttribute('aria-required', 'true');
                } else {
                    el.required = false;
                    el.removeAttribute('aria-required');
                }
            });
            if (!show) clearError(wrap);
        }

        // Fields can depend on each other, so settle in a few passes.
        function apply() {
            for (let pass = 0; pass < 6; pass++) {
                let changed = false;
                fields().forEach(wrap => {
                    const show = shouldShow(wrap);
                    const was  = !wrap.classList.contains('cmf-hidden');
                    if (show === was) return;
                    changed = true;
                    setVisible(wrap, show);
                });
                if (!changed) break;
            }
        }

        let timer = null;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(apply, 30);
        };

        // ── Inline errors (FluentCart's has-error / show_error classes) ──

        function showError(wrap, text) {
            wrap.classList.add('has-error');
            const box = wrap.querySelector('.cmf-field-error');
            if (box) {
                box.textContent = text;
                box.classList.add('show_error');
            }
        }

        function clearError(wrap) {
            wrap.classList.remove('has-error');
            const box = wrap.querySelector('.cmf-field-error');
            if (box) {
                box.textContent = '';
                box.classList.remove('show_error');
            }
        }

        function validateVisible() {
            let firstBad = null;
            fields().forEach(wrap => {
                clearError(wrap);
                if (wrap.classList.contains('cmf-hidden')) return;

                const type     = wrap.dataset.cmfType || 'text';
                const title    = wrap.dataset.cmfTitle || '';
                const required = wrap.dataset.cmfRequired === '1';

                if (type === 'file') {
                    const box   = wrap.querySelector('.cmf-file');
                    const token = wrap.querySelector('.cmf-file-token');
                    const input = wrap.querySelector('.cmf-file-input');
                    let text = '';
                    if (box && box.classList.contains('is-uploading')) {
                        text = msg('stillUploading', title, '%s is still uploading. Please wait.');
                    } else if (required && !(token && token.value)) {
                        text = msg('required', title, '%s is required.');
                    }
                    if (text) {
                        showError(wrap, text);
                        if (!firstBad) firstBad = input || token;
                    }
                    return;
                }

                const el = wrap.querySelector('input, select, textarea');
                if (!el) return;
                const value    = type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || '').trim();

                let text = '';
                if (required && value === '') {
                    text = msg('required', title, '%s is required.');
                } else if (value !== '') {
                    if (type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                        text = msg('invalidEmail', title, '%s must be a valid email address.');
                    } else if (type === 'url' && !/^[a-z][a-z0-9+.-]*:\/\/\S+$/i.test(value)) {
                        text = msg('invalidUrl', title, '%s must be a valid URL.');
                    } else if (type === 'number' && !isFinite(Number(value))) {
                        text = msg('invalidNumber', title, '%s must be a number.');
                    } else if (type === 'date' && isNaN(new Date(value).getTime())) {
                        text = msg('invalidDate', title, '%s must be a valid date.');
                    }
                }

                if (text) {
                    showError(wrap, text);
                    if (!firstBad) firstBad = el;
                }
            });
            return firstBad;
        }

        function block(e, el) {
            e.preventDefault();
            e.stopImmediatePropagation();
            try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (err) { /* older browsers */ }
            el.focus({ preventScroll: true });
        }

        // ── File uploads: sent to FluentCart's storage the moment a file is chosen ──

        function setFileStatus(box, html) {
            const status = box.querySelector('.cmf-file-status');
            if (status) status.innerHTML = html;
        }

        function escapeHtml(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function resetFile(box) {
            const token = box.querySelector('.cmf-file-token');
            const input = box.querySelector('.cmf-file-input');
            if (token) token.value = '';
            if (input) { input.value = ''; input.hidden = false; }
            box.classList.remove('is-uploading', 'has-file');
            setFileStatus(box, '');
        }

        function uploadFile(box, file) {
            const wrap  = box.closest('[data-cmf-field]');
            const input = box.querySelector('.cmf-file-input');
            const token = box.querySelector('.cmf-file-token');
            const title = wrap ? (wrap.dataset.cmfTitle || '') : '';
            const fieldId = wrap ? String(wrap.dataset.cmfField || '').replace(/^cmf_/, '') : '';
            const allowed = (box.dataset.cmfAccept || '').split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
            const maxBytes = parseInt(box.dataset.cmfMax, 10) || 0;
            const ext = (file.name.split('.').pop() || '').toLowerCase();

            if (wrap) clearError(wrap);
            if (allowed.length && !allowed.includes(ext)) {
                if (wrap) showError(wrap, String(I18N.badType || 'Allowed file types: %s').replace('%s', allowed.join(', ')));
                input.value = '';
                return;
            }
            if (maxBytes && file.size > maxBytes) {
                if (wrap) showError(wrap, String(I18N.tooLarge || 'The file must be smaller than %s.').replace('%s', Math.round(maxBytes / 1048576) + ' MB'));
                input.value = '';
                return;
            }

            const body = new FormData();
            body.append('action', 'fct_cmf_upload');
            body.append('_nonce', cfg.uploadNonce || '');
            body.append('field_id', fieldId);
            body.append('file', file);
            appendCartHash(body);

            box.classList.add('is-uploading');
            box.classList.remove('has-file');
            if (token) token.value = '';
            setFileStatus(box, '<span class="cmf-file-progress">' + escapeHtml(I18N.uploading || 'Uploading…') + '</span>');

            fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body })
                .then(async (r) => {
                    let data = {};
                    try { data = await r.json(); } catch (e) { /* not JSON */ }
                    if (!r.ok || !data.token) throw new Error(data.message || I18N.uploadFailed || 'The upload failed. Please try again.');
                    return data;
                })
                .then(data => {
                    box.classList.remove('is-uploading');
                    box.classList.add('has-file');
                    if (token) token.value = data.token;
                    input.hidden = true;
                    setFileStatus(box,
                        '<span class="cmf-file-name">' + escapeHtml(data.name) + '</span>'
                        + (data.size ? ' <span class="cmf-file-size">(' + escapeHtml(data.size) + ')</span>' : '')
                        + ' <button type="button" class="cmf-file-remove">' + escapeHtml(I18N.remove || 'Remove') + '</button>');
                    schedule();
                })
                .catch(err => {
                    resetFile(box);
                    if (wrap) showError(wrap, err.message || I18N.uploadFailed || 'The upload failed. Please try again.');
                });
        }

        form.addEventListener('change', (e) => {
            const input = e.target.closest && e.target.closest('.cmf-file-input');
            if (!input) return;
            const box = input.closest('.cmf-file');
            const file = input.files && input.files[0];
            if (!box || !file) return;
            uploadFile(box, file);
        });

        form.addEventListener('click', (e) => {
            const btn = e.target.closest && e.target.closest('.cmf-file-remove');
            if (!btn) return;
            e.preventDefault();
            const box = btn.closest('.cmf-file');
            const token = box.querySelector('.cmf-file-token');
            const value = token ? token.value : '';
            resetFile(box);
            schedule();
            if (!value) return;
            const body = new FormData();
            body.append('action', 'fct_cmf_upload_remove');
            body.append('_nonce', cfg.uploadNonce || '');
            body.append('token', value);
            appendCartHash(body);
            fetch(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body }).catch(() => {});
        });

        // ── Wiring ──

        // Ahead of FluentCart's click handler on the place-order button.
        document.addEventListener('click', (e) => {
            const btn = e.target.closest && e.target.closest('[data-fluent-cart-checkout-page-checkout-button]');
            if (!btn || !form.contains(btn)) return;
            apply();
            const bad = validateVisible();
            if (bad) block(e, bad);
        }, true);

        // Implicit submission (Enter in a text field) arrives as a real submit.
        form.addEventListener('submit', (e) => {
            apply();
            const bad = validateVisible();
            if (bad) block(e, bad);
        }, true);

        const onEdit = (e) => {
            const wrap = e.target.closest && e.target.closest('[data-cmf-field]');
            if (wrap) clearError(wrap);
            schedule();
        };
        form.addEventListener('input', onEdit);
        form.addEventListener('change', onEdit);

        // FluentCart swaps parts of the form (shipping methods, payment methods,
        // address blocks) over AJAX; dependencies may have been replaced.
        new MutationObserver(schedule).observe(form, { childList: true, subtree: true });

        apply();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
