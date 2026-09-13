/**
 * searchable-select.js — v4.135.0
 *
 * هر لیست باز‌شوندهٔ بلند (به‌ویژه انتخاب دانش‌آموز) را قابل جستجو می‌کند.
 *
 * چرا این روش و نه ویرایش تک‌تک صفحات:
 * لیست دانش‌آموز در چند صفحهٔ مختلف است و ساختارشان یکی نیست — بعضی در
 * PHP پر می‌شوند و بعضی (مثل reports.php) بعداً با جاوااسکریپت. اگر هر
 * کدام را جدا دست می‌زدیم، هم تکرار می‌شد و هم موردی که بعداً اضافه شود
 * جا می‌ماند. اینجا یک بار روی همهٔ <select>های واجد شرایط اعمال می‌شود
 * و لیست‌هایی که بعداً با JS پر شوند هم پوشش داده می‌شوند.
 *
 * تصمیم‌های عمدی:
 *  · خودِ <select> اصلی در DOM می‌ماند و همان name/value را دارد، فقط
 *    پنهان می‌شود. یعنی ارسال فرم، required، و هر کد قدیمی که
 *    document.getElementById('studentSelect').value را می‌خواند، همگی
 *    بدون تغییر کار می‌کنند.
 *  · رویداد change روی select اصلی دستی شلیک می‌شود، چون چند صفحه به
 *    onchange آن وابسته‌اند (مثلاً reports.php که صفحه را عوض می‌کند).
 *  · فقط وقتی فعال می‌شود که تعداد گزینه‌ها از یک حد بیشتر باشد؛ برای
 *    لیست کوتاه، جستجو فقط مزاحم است.
 *  · بدون وابستگی بیرونی، بدون CDN. با کیبورد کامل کار می‌کند.
 */
(function () {
    'use strict';

    var MIN_OPTIONS = 8;      /* زیر این تعداد، لیست عادی می‌ماند */
    var seq = 0;

    function norm(s) {
        /* یکسان‌سازی فارسی/عربی + ارقام، تا جستجو با هر صفحه‌کلیدی کار کند */
        return String(s || '')
            .replace(/[\u064A\u0649]/g, '\u06CC')   /* ي ى -> ی */
            .replace(/\u0643/g, '\u06A9')           /* ك -> ک */
            .replace(/[\u0660-\u0669]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x0660 + 48); })
            .replace(/[\u06F0-\u06F9]/g, function (d) { return String.fromCharCode(d.charCodeAt(0) - 0x06F0 + 48); })
            .replace(/[\u200c\u200f\u200e]/g, ' ')  /* نیم‌فاصله */
            .replace(/\s+/g, ' ')
            .trim()
            .toLowerCase();
    }

    function build(sel) {
        if (sel.dataset.ssDone === '1') return;
        sel.dataset.ssDone = '1';

        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.classList.add('ss-hidden-select');

        var btn = document.createElement('button');
        btn.type = 'button';                 /* حیاتی: وگرنه فرم را submit می‌کند */
        btn.className = 'ss-btn form-select';
        btn.id = 'ssBtn' + (++seq);

        var panel = document.createElement('div');
        panel.className = 'ss-panel';
        panel.hidden = true;

        var search = document.createElement('input');
        search.type = 'text';
        search.className = 'ss-search';
        search.placeholder = 'جستجو…';
        search.autocomplete = 'off';

        var list = document.createElement('div');
        list.className = 'ss-list';
        list.setAttribute('role', 'listbox');

        var empty = document.createElement('div');
        empty.className = 'ss-empty';
        empty.textContent = 'موردی پیدا نشد';
        empty.hidden = true;

        panel.appendChild(search);
        panel.appendChild(list);
        panel.appendChild(empty);
        wrap.appendChild(btn);
        wrap.appendChild(panel);

        var rows = [];

        function syncLabel() {
            var o = sel.options[sel.selectedIndex];
            btn.textContent = o ? o.text : '—';
            btn.classList.toggle('ss-placeholder', !!o && o.value === '');
        }

        function rebuild() {
            list.innerHTML = '';
            rows = [];
            for (var i = 0; i < sel.options.length; i++) {
                var o = sel.options[i];
                var row = document.createElement('div');
                row.className = 'ss-opt';
                row.setAttribute('role', 'option');
                row.textContent = o.text;
                row.dataset.idx = String(i);
                row.dataset.key = norm(o.text);
                if (o.disabled) row.classList.add('is-disabled');
                if (i === sel.selectedIndex) row.classList.add('is-sel');
                list.appendChild(row);
                rows.push(row);
            }
            syncLabel();
        }

        function filter() {
            var q = norm(search.value);
            var shown = 0;
            for (var i = 0; i < rows.length; i++) {
                var hit = q === '' || rows[i].dataset.key.indexOf(q) !== -1;
                rows[i].hidden = !hit;
                if (hit) shown++;
            }
            empty.hidden = shown !== 0;
        }

        function open() {
            if (!panel.hidden) return;
            rebuild();
            panel.hidden = false;
            search.value = '';
            filter();
            /* اگر پایین صفحه جا نبود، پنل رو به بالا باز شود */
            var r = wrap.getBoundingClientRect();
            panel.classList.toggle('ss-up', (window.innerHeight - r.bottom) < 240 && r.top > 260);
            search.focus();
        }

        function close() { panel.hidden = true; }

        function pick(idx) {
            if (sel.options[idx] && sel.options[idx].disabled) return;
            sel.selectedIndex = idx;
            syncLabel();
            close();
            /* چند صفحه به onchange وابسته‌اند */
            sel.dispatchEvent(new Event('change', { bubbles: true }));
        }

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            panel.hidden ? open() : close();
        });
        search.addEventListener('input', filter);

        list.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.ss-opt') : null;
            if (row) pick(parseInt(row.dataset.idx, 10));
        });

        search.addEventListener('keydown', function (e) {
            var vis = rows.filter(function (r) { return !r.hidden && !r.classList.contains('is-disabled'); });
            var cur = vis.indexOf(list.querySelector('.ss-opt.is-active'));
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!vis.length) return;
                if (cur !== -1) vis[cur].classList.remove('is-active');
                var nxt = e.key === 'ArrowDown'
                    ? (cur + 1 >= vis.length ? 0 : cur + 1)
                    : (cur - 1 < 0 ? vis.length - 1 : cur - 1);
                vis[nxt].classList.add('is-active');
                vis[nxt].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var target = list.querySelector('.ss-opt.is-active') || vis[0];
                if (target) pick(parseInt(target.dataset.idx, 10));
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
                btn.focus();
            }
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) close();
        });

        /* اگر کد صفحه، گزینه‌ها یا مقدار را عوض کرد، برچسب هم‌گام بماند */
        sel.addEventListener('change', syncLabel);
        if (window.MutationObserver) {
            new MutationObserver(function () {
                if (panel.hidden) syncLabel();
            }).observe(sel, { childList: true });
        }

        rebuild();
    }

    function eligible(sel) {
        if (sel.multiple || sel.disabled) return false;
        if (sel.dataset.noSearch === '1') return false;
        if (sel.closest('.no-ajax')) { /* اجازه دارد */ }
        var forced = sel.dataset.searchable === '1' ||
                     /student/i.test(sel.name || '') ||
                     /student/i.test(sel.id || '');
        return forced || sel.options.length >= MIN_OPTIONS;
    }

    function scan(root) {
        var list = (root || document).querySelectorAll('select.form-select, select[data-searchable="1"]');
        for (var i = 0; i < list.length; i++) {
            try { if (eligible(list[i])) build(list[i]); } catch (e) { /* یک لیست نباید صفحه را بشکند */ }
        }
    }

    function init() {
        scan(document);
        /* لیست‌هایی که بعداً با JS ساخته/پر می‌شوند */
        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                for (var i = 0; i < muts.length; i++) {
                    for (var j = 0; j < muts[i].addedNodes.length; j++) {
                        var n = muts[i].addedNodes[j];
                        if (n.nodeType !== 1) continue;
                        if (n.tagName === 'SELECT') { try { if (eligible(n)) build(n); } catch (e) {} }
                        else if (n.querySelectorAll) scan(n);
                    }
                }
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
