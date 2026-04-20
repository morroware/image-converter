/* =========================================================================
   utils.js — tiny cross-page helpers (toast, escapeHtml, fetch wrapper, CSRF)
   Exposes a single global window.Castle object.
   ========================================================================= */
(function () {
    'use strict';

    const Castle = window.Castle = window.Castle || {};

    /* ---------- CSRF -------------------------------------------------- */

    const meta = document.querySelector('meta[name="castle-csrf"]');
    Castle.csrf = meta ? meta.getAttribute('content') : '';

    Castle.capabilities = (function () {
        const body = document.body;
        const raw = body && body.dataset.capabilities;
        if (!raw) return {};
        try { return JSON.parse(raw); } catch (e) { return {}; }
    }());

    /* ---------- escapeHtml -------------------------------------------- */

    Castle.escapeHtml = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;',
                '"': '&quot;', "'": '&#39;'
            })[c];
        });
    };

    /* ---------- formatBytes ------------------------------------------- */

    Castle.formatBytes = function (bytes) {
        bytes = +bytes || 0;
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? bytes : bytes.toFixed(bytes >= 10 ? 1 : 2)) + ' ' + units[i];
    };

    /* ---------- Toasts ------------------------------------------------ */

    let toastStack = document.querySelector('.toast-stack');
    if (!toastStack) {
        toastStack = document.createElement('div');
        toastStack.className = 'toast-stack';
        document.body.appendChild(toastStack);
    }

    Castle.toast = function (message, type, ms) {
        type = type || 'info';
        ms = ms || 3500;
        const el = document.createElement('div');
        el.className = 'toast ' + type;
        el.textContent = message;
        toastStack.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity 200ms, transform 200ms';
            el.style.opacity = '0';
            el.style.transform = 'translateX(24px)';
            setTimeout(function () { el.remove(); }, 220);
        }, ms);
    };

    /* ---------- fetchJson -------------------------------------------- */

    Castle.api = async function (action, data, options) {
        options = options || {};
        const isForm = data instanceof FormData;
        const url = 'api.php?action=' + encodeURIComponent(action);
        const init = {
            method: options.method || 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': Castle.csrf,
            },
        };
        if (isForm) {
            data.append('_csrf', Castle.csrf);
            init.body = data;
        } else if (data) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(Object.assign({ _csrf: Castle.csrf }, data));
        }

        let res;
        try {
            res = await fetch(url, init);
        } catch (e) {
            throw new Error('Network error — check your connection.');
        }

        let body = null;
        try { body = await res.json(); } catch (e) { /* empty response */ }

        if (!res.ok) {
            const msg = (body && body.error) || ('Request failed (' + res.status + ')');
            if (res.status === 401) {
                window.location.href = 'login.php';
            }
            throw new Error(msg);
        }
        return body || {};
    };

    /* ---------- Downloading files ------------------------------------ */

    Castle.download = function (url, filename) {
        const a = document.createElement('a');
        a.href = url;
        if (filename) a.download = filename;
        a.rel = 'noopener';
        document.body.appendChild(a);
        a.click();
        a.remove();
    };

    /* ---------- copyToClipboard -------------------------------------- */

    Castle.copy = async function (text) {
        try {
            await navigator.clipboard.writeText(text);
            Castle.toast('Copied to clipboard', 'success', 2000);
        } catch (e) {
            Castle.toast('Copy failed — select and copy manually', 'warn');
        }
    };

    /* ---------- Format dropdown filtering based on caps ------------- */

    Castle.filterFormats = function (selectEl) {
        const allowed = (Castle.capabilities.outputFormats || []);
        Array.from(selectEl.options).forEach(function (o) {
            if (o.value && !allowed.includes(o.value)) o.remove();
        });
    };

}());
