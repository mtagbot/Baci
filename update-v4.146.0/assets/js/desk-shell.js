/**
 * desk-shell.js — v4.134.0
 *
 * «همه چیز در همان پنجرهٔ برنامه باز شود.»
 *
 * فقط در نسخهٔ دسکتاپ لود می‌شود (footer.php با PHP_SAPI === 'cli-server'
 * تصمیم می‌گیرد). روی سایت اصلاً فرستاده نمی‌شود، چون آنجا باز شدن در تب
 * جدید رفتار درست و مورد انتظار مرورگر است.
 *
 * سه کاری که می‌کند:
 *   ۱) هر لینک target="_blank" را به پیمایش در همین پنجره تبدیل می‌کند.
 *   ۲) هر فرم target="_blank" را هم همین‌طور.
 *   ۳) window.open را بازتعریف می‌کند تا به‌جای پنجرهٔ جدید، همین پنجره
 *      را ببرد به آن آدرس.
 *
 * ── استثنای مهم: صفحات چاپ ──
 * صفحاتی مثل bulk-print.php و exam-print.php به‌محض باز شدن
 * window.print() صدا می‌زنند و انتظار دارند در یک پنجرهٔ جداگانه باشند.
 * اگر آن‌ها را هم داخل همین پنجره باز کنیم، کاربر بعد از چاپ داخل صفحهٔ
 * چاپ گیر می‌افتد و باید دستی برگردد — و بدتر، محتوای صفحهٔ اصلی از بین
 * می‌رود. پس این‌ها عمداً مستثنا شده‌اند.
 *
 * این تصمیم آگاهانه است: خواستهٔ کاربر «پنجرهٔ جدید باز نشود» بود تا
 * تجربهٔ برنامه یکپارچه بماند؛ ولی پنجرهٔ چاپ بخشی از جریان چاپ است، نه
 * یک صفحهٔ برنامه. شکستن آن، یک نقص واقعی می‌ساخت.
 */
(function () {
    'use strict';

    /* آدرس‌هایی که باید در پنجرهٔ خودشان باز شوند */
    var PRINT_PAGES = [
        'bulk-print.php',
        'exam-print.php',
        'report-print.php',
        'student-bulk-report.php',
        'attendance-tags.php',
        'entry-cards.php',        /* v4.136.0: کارت ورود هم صفحهٔ چاپ است */
        'reports-lists.php',      /* v4.146.0: نمای PDF لیست کلاسی */
        'discipline-bulk-report.php',
        'export-pdf.php',
        'export-excel.php',
        'export-students.php',
        'download-sample-7reporte.php'
    ];

    function isPrintUrl(url) {
        if (!url) return false;
        var u = String(url).split('?')[0].split('#')[0];
        for (var i = 0; i < PRINT_PAGES.length; i++) {
            if (u.indexOf(PRINT_PAGES[i]) !== -1) return true;
        }
        return false;
    }

    /* لینک به بیرون از برنامه (مثلاً سایت مدرسه یا تلگرام) باید در
       مرورگر واقعی باز شود؛ داخل پنجرهٔ برنامه جای گشت‌وگذار نیست. */
    function isExternal(href) {
        if (!href) return false;
        if (/^(mailto:|tel:|javascript:|#)/i.test(href)) return false;
        if (!/^https?:\/\//i.test(href)) return false;
        try {
            return new URL(href, location.href).host !== location.host;
        } catch (e) {
            return false;
        }
    }

    /* ۱) لینک‌ها — در فاز capture گرفته می‌شود تا قبل از هندلرهای صفحه
       اجرا شود، ولی preventDefault فقط وقتی که واقعاً خودمان پیمایش
       می‌کنیم. */
    document.addEventListener('click', function (ev) {
        /* کلیک وسط یا Ctrl+کلیک را دست نمی‌زنیم: کاربر عمداً تب جدید
           خواسته و در --app mode هم بی‌اثر است. */
        if (ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey) return;

        var a = ev.target && ev.target.closest ? ev.target.closest('a[target="_blank"]') : null;
        if (!a) return;

        var href = a.getAttribute('href');
        if (!href || /^(javascript:|#)/i.test(href)) return;
        if (isPrintUrl(href) || isExternal(href)) return;   /* بگذار جدا باز شود */

        ev.preventDefault();
        window.location.href = a.href;
    }, true);

    /* ۲) فرم‌ها */
    document.addEventListener('submit', function (ev) {
        var f = ev.target;
        if (!f || f.target !== '_blank') return;
        var action = f.getAttribute('action') || location.href;
        if (isPrintUrl(action)) return;
        f.target = '_self';
    }, true);

    /* ۳) window.open */
    var nativeOpen = window.open;
    window.open = function (url, name, features) {
        if (!url) return nativeOpen.apply(window, arguments);
        if (isPrintUrl(url) || isExternal(url)) {
            return nativeOpen.apply(window, arguments);
        }
        window.location.href = url;
        /* شیئی شبیه window برمی‌گردانیم تا کدی که فرض می‌کند خروجی
           معتبر است (مثلاً w.focus()) با خطا نشکند. */
        return {
            closed: false,
            focus: function () {},
            close: function () {},
            blur: function () {},
            postMessage: function () {},
            document: { write: function () {}, close: function () {} }
        };
    };

    /* ۴) لینک‌هایی که بعداً با جاوااسکریپت ساخته می‌شوند هم پوشش داده
       می‌شوند، چون هندلر روی document است و در زمان کلیک ارزیابی
       می‌شود — نه اینکه یک‌بار موقع لود، target ها را پاک کنیم. */
})();
