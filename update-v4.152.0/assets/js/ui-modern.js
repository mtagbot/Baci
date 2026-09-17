/* ============================================================
 * ui-modern.js — v4.30.0 UX enhancement layer
 * Loaded AFTER main.js. Pure progressive enhancement:
 * nothing here is required for any feature to work.
 * ============================================================ */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1) Global top loading bar ---------- */
    var bar = null;
    function ensureBar() {
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'ui-loading-bar';
            bar.setAttribute('aria-hidden', 'true');
            document.body.appendChild(bar);
        }
        return bar;
    }
    var loadingCount = 0;
    function loadingOn() { loadingCount++; ensureBar().classList.add('on'); }
    function loadingOff() { loadingCount = Math.max(0, loadingCount - 1); if (!loadingCount && bar) bar.classList.remove('on'); }
    window.uiLoadingOn = loadingOn;
    window.uiLoadingOff = loadingOff;

    // Show bar during AJAX (fetch) — covers live filters, modals, hover cards
    if (window.fetch) {
        var origFetch = window.fetch;
        window.fetch = function () {
            var url=String(arguments[0]&&arguments[0].url||arguments[0]||'');
            if(/(?:desk-connection-status|session-watch|login-captcha-state)\.php/.test(url))return origFetch.apply(this,arguments);
            loadingOn();
            return origFetch.apply(this, arguments).then(function (r) { loadingOff(); return r; }, function (e) { loadingOff(); throw e; });
        };
    }

    // Show bar on full page navigations (links + form submits)
    window.addEventListener('beforeunload', function (e) {
        loadingOn();
        // A cancelled close (e.g. unsent desktop changes) must not leave a busy stripe behind.
        setTimeout(function(){if(e.defaultPrevented)loadingOff();},0);
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) { loadingCount = 0; if (bar) bar.classList.remove('on'); } });

    /* ---------- 2) Busy state on submit buttons (double-click guard) ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.noBusy === '1') return;
        // Skip AJAX GET filter forms (main.js intercepts those)
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        if (method !== 'POST') return;
        var btn = form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
        if (btn && !btn.classList.contains('ui-busy')) {
            setTimeout(function () {
                if (btn.classList) btn.classList.add('ui-busy');
                // Safety: release after 20s in case of download responses
                setTimeout(function () { btn.classList.remove('ui-busy'); }, 20000);
            }, 10);
        }
    }, true);

    /* ---------- 3) Back-to-top inside the scrolling <main> pane ---------- */
    function initBackTop() {
        var main = document.querySelector('main.flex-1');
        if (!main) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'ui-backtop';
        btn.title = 'بازگشت به بالا';
        btn.setAttribute('aria-label', 'بازگشت به بالا');
        btn.textContent = '↑';
        document.body.appendChild(btn);
        var scroller = main; // desktop-app shell scrolls inside <main>
        function onScroll() { btn.classList.toggle('show', scroller.scrollTop > 350); }
        scroller.addEventListener('scroll', onScroll, { passive: true });
        btn.addEventListener('click', function () {
            scroller.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    /* ---------- 4) Keyboard shortcut: "/" focuses first search input ---------- */
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var a = document.activeElement;
        if (a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT' || a.isContentEditable)) return;
        var search = document.querySelector('main input[type="search"], main input[name*="search"], main input[placeholder*="جستجو"]');
        if (search) { e.preventDefault(); search.focus(); try { search.select(); } catch (err) {} }
    });

    /* ---------- 5) Escape closes any open modal ---------- */
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-backdrop').forEach(function (m) {
            var disp = m.style.display || getComputedStyle(m).display;
            if (disp === 'flex' || disp === 'block') m.style.display = 'none';
        });
        document.body.classList.remove('sidebar-open', 'user-menu-open');
    });

    /* ---------- 6) Click on modal backdrop (outside card) closes it ---------- */
    document.addEventListener('mousedown', function (e) {
        var m = e.target;
        if (m.classList && m.classList.contains('modal-backdrop') && m.dataset.staticBackdrop !== '1') {
            m.style.display = 'none';
        }
    });

    /* ---------- 7) Auto-close mobile drawer after choosing a menu item ---------- */
    document.addEventListener('click', function (e) {
        if (window.innerWidth > 900) return;
        var item = e.target.closest ? e.target.closest('.sidebar-item') : null;
        if (item) document.body.classList.remove('sidebar-open');
    });

    /* ---------- 8) Numeric inputs: convert Persian/Arabic digits on blur ---------- */
    document.addEventListener('blur', function (e) {
        var el = e.target;
        if (!el || !el.matches) return;
        if (!el.matches('input[type="number"], input[inputmode="numeric"], input[data-digits="en"]')) return;
        if (typeof el.value !== 'string' || !el.value) return;
        var v = el.value
            .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
            .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
        if (v !== el.value) el.value = v;
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        ensureBar();
        initBackTop();
    });
})();
