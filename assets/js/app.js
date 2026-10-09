/*
 * Shared behaviour for every page that uses the app shell.
 * Kept dependency-free on purpose; Bootstrap's own JS is loaded
 * separately in footer.php for modals/dropdowns in later phases.
 *
 * Exposes window.AutoWay = { toast(), confirm(), statusClass(), escapeHtml(),
 *   formatDate(), formatMoney() } for every later module
 * (vehicles, bookings, ...) to reuse instead of each rolling its own.
 */

(function () {
    'use strict';

    var root = document.documentElement;
    var STORAGE_KEY = 'autoway-theme';

    function systemPrefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function currentTheme() {
        return root.getAttribute('data-theme') || (systemPrefersDark() ? 'dark' : 'light');
    }

    function syncToggleIcon() {
        var icon = document.querySelector('#theme-toggle .bi');
        if (!icon) return;
        // Show the icon for the mode you'd switch TO.
        icon.className = 'bi ' + (currentTheme() === 'dark' ? 'bi-sun' : 'bi-moon-stars');
    }

    function setTheme(theme) {
        root.setAttribute('data-theme', theme);
        root.setAttribute('data-bs-theme', theme); // keeps Bootstrap modals/dropdowns in step
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (e) { /* private mode etc. */ }
        syncToggleIcon();
        // Charts read colors when they're built, so let them know.
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: theme } }));
    }

    // Color palettes (Bay is the default). Saved per browser, like the theme.
    var PALETTE_KEY = 'autoway-palette';
    var PALETTES = ['bay', 'pine', 'jeepney', 'orchid'];

    function currentPalette() {
        return root.getAttribute('data-palette') || 'bay';
    }

    function syncPaletteMenu() {
        var active = currentPalette();
        document.querySelectorAll('.palette-option').forEach(function (opt) {
            var on = opt.getAttribute('data-palette') === active;
            opt.classList.toggle('active', on);
            opt.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function setPalette(name) {
        if (PALETTES.indexOf(name) === -1) return;
        if (name === 'bay') root.removeAttribute('data-palette'); else root.setAttribute('data-palette', name);
        try { localStorage.setItem(PALETTE_KEY, name); } catch (e) { /* private mode etc. */ }
        syncPaletteMenu();
        document.dispatchEvent(new CustomEvent('themechange', { detail: { theme: currentTheme(), palette: name } }));
    }

    // ---------------------------------------------------------------
    // Toasts. One stacking container, created on first use.
    // ---------------------------------------------------------------
    var TOAST_ICONS = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', info: 'bi-info-circle-fill' };

    function toastContainer() {
        var el = document.getElementById('toast-stack');
        if (!el) {
            el = document.createElement('div');
            el.id = 'toast-stack';
            el.className = 'toast-stack';
            el.setAttribute('aria-live', 'polite');
            el.setAttribute('aria-atomic', 'true');
            document.body.appendChild(el);
        }
        return el;
    }

    function toast(message, type) {
        type = type === 'error' || type === 'info' ? type : 'success';

        var el = document.createElement('div');
        el.className = 'app-toast app-toast-' + type;
        el.setAttribute('role', 'status');
        el.innerHTML = '<i class="bi ' + TOAST_ICONS[type] + '"></i><span></span>'
            + '<button type="button" class="toast-close" aria-label="Dismiss">&times;</button>';
        el.querySelector('span').textContent = message; // textContent, not innerHTML — message may be untrusted

        var stack = toastContainer();
        stack.appendChild(el);

        var timer = setTimeout(function () { dismiss(); }, 4500);
        function dismiss() {
            clearTimeout(timer);
            el.classList.add('leaving');
            setTimeout(function () { el.remove(); }, 200);
        }
        el.querySelector('.toast-close').addEventListener('click', dismiss);
    }

    // ---------------------------------------------------------------
    // Confirm dialog. A Bootstrap modal built once and reused, so
    // "are you sure?" moments don't block the thread like confirm().
    // Usage: AutoWay.confirm('Archive this vehicle?').then(ok => ...)
    // ---------------------------------------------------------------
    function confirmDialog(message, opts) {
        opts = opts || {};
        var modalEl = document.getElementById('confirm-modal');
        if (!modalEl) {
            modalEl = document.createElement('div');
            modalEl.id = 'confirm-modal';
            modalEl.className = 'modal fade';
            modalEl.tabIndex = -1;
            modalEl.innerHTML =
                '<div class="modal-dialog modal-dialog-centered">' +
                  '<div class="modal-content">' +
                    '<div class="modal-body py-4"><p class="mb-0" id="confirm-modal-message"></p></div>' +
                    '<div class="modal-footer border-0 pt-0">' +
                      '<button type="button" class="btn btn-outline-secondary" data-choice="cancel">Cancel</button>' +
                      '<button type="button" class="btn" id="confirm-modal-confirm" data-choice="confirm">Confirm</button>' +
                    '</div>' +
                  '</div>' +
                '</div>';
            document.body.appendChild(modalEl);
        }

        modalEl.querySelector('#confirm-modal-message').textContent = message;
        var confirmBtn = modalEl.querySelector('#confirm-modal-confirm');
        confirmBtn.textContent = opts.confirmText || 'Confirm';
        confirmBtn.className = 'btn ' + (opts.confirmClass || 'btn-brand');

        var bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var settled = false;
            function onClick(e) {
                var choice = e.target.getAttribute('data-choice');
                if (!choice) return;
                settled = true;
                bsModal.hide();
                resolve(choice === 'confirm');
            }
            function onHidden() {
                modalEl.removeEventListener('click', onClick);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                if (!settled) resolve(false); // dismissed via backdrop/Esc — treat as "no"
            }
            modalEl.addEventListener('click', onClick);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            bsModal.show();
        });
    }

    // ---------------------------------------------------------------
    // Small shared formatters. statusClass() mirrors PHP's
    // status_badge_class() in includes/functions.php exactly, including
    // the neutral fallback — keep the two lists in sync.
    // ---------------------------------------------------------------
    var STATUS_GROUPS = {
        'status-success': ['available', 'active', 'completed', 'paid', 'verified', 'approved'],
        'status-warning': ['pending', 'unverified', 'scheduled', 'partial', 'reserved', 'unpaid'],
        'status-info':    ['confirmed', 'in_progress', 'rented'],
        'status-neutral': ['maintenance', 'refunded', 'deactivated'],
        'status-danger':  ['cancelled', 'unavailable', 'blocked', 'rejected', 'failed', 'no_show', 'suspended', 'void']
    };

    function statusClass(status) {
        for (var cls in STATUS_GROUPS) {
            if (STATUS_GROUPS[cls].indexOf(status) !== -1) return cls;
        }
        return 'status-neutral';
    }

    // Safe in text and in quoted attributes (Phase 14: quotes too).
    function escapeHtml(s) {
        return (s == null ? '' : String(s))
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatDate(s) {
        if (!s) return '';
        var d = new Date(String(s).replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatMoney(n) {
        return '\u20B1' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    /** This role's URL for a shared page ('bookings', 'customers', ...), with an optional query. */
    function link(page, query) {
        var base = (window.APP_LINKS || {})[page];
        if (!base) return null;
        var qs = query ? Object.keys(query).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(query[k]);
        }).join('&') : '';
        return base + (qs ? '?' + qs : '');
    }

    window.AutoWay = Object.assign(window.AutoWay || {}, {
        link: link,
        toast: toast,
        confirm: confirmDialog,
        statusClass: statusClass,
        escapeHtml: escapeHtml,
        formatDate: formatDate,
        formatMoney: formatMoney
    });

    // A submit button with data-confirm="..." asks first (plain form posts).
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('button[type="submit"][data-confirm]');
        if (!btn || btn.dataset.confirmed === '1') return;
        e.preventDefault();
        confirmDialog(btn.getAttribute('data-confirm'), { confirmText: 'Continue' }).then(function (yes) {
            if (!yes) return;
            btn.dataset.confirmed = '1';
            if (btn.form.requestSubmit) btn.form.requestSubmit(btn); else btn.click();
        });
    });

    document.addEventListener('DOMContentLoaded', function () {
        syncToggleIcon();
        syncPaletteMenu();
        document.querySelectorAll('.palette-option').forEach(function (opt) {
            opt.addEventListener('click', function () { setPalette(opt.getAttribute('data-palette')); });
        });

        var themeBtn = document.getElementById('theme-toggle');
        if (themeBtn) {
            themeBtn.addEventListener('click', function () {
                setTheme(currentTheme() === 'dark' ? 'light' : 'dark');
            });
        }

        var sidebar  = document.getElementById('sidebar');
        var backdrop = document.getElementById('sidebar-backdrop');
        var openBtn  = document.getElementById('sidebar-toggle');

        function closeSidebar() {
            if (sidebar) sidebar.classList.remove('open');
            if (backdrop) backdrop.classList.remove('open');
        }

        if (openBtn && sidebar) {
            openBtn.addEventListener('click', function () {
                sidebar.classList.add('open');
                if (backdrop) backdrop.classList.add('open');
            });
        }
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    });
})();
