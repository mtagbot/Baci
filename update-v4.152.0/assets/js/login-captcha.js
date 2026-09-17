/**
 * login-captcha.js — v4.132.0
 *
 * «بار اول بدون کد امنیتی؛ از اولین اشتباه به بعد، همیشه با کد امنیتی.»
 *
 * کادر کپچا در HTML مخفی و ورودی‌اش disabled است. این اسکریپت وقتی
 * کاربر شناسه (نام کاربری/کد ملی) را وارد می‌کند از سرور می‌پرسد که
 * آیا همین نشست و نقش، تلاش ناموفق داشته است یا نه.
 *
 * تصمیم‌های عمدی:
 *  - ورودی وقتی مخفی است disabled می‌ماند تا مرورگر required را روی
 *    فیلد نامرئی اعمال نکند (وگرنه فرم بی‌هیچ پیام قابل‌دیدنی گیر می‌کند
 *    — همان کلاس باگی که کاربر با فریزشدن مرحلهٔ مجوزها دیده بود).
 *  - در خطای شبکه، وضعیت رندرشدهٔ سرور حفظ می‌شود؛ تصمیم امنیتی
 *    همچنان فقط بر عهدهٔ سرور است.
 *  - سرور تصمیم نهایی را می‌گیرد؛ این فقط UI است. پنهان‌کردن کادر با
 *    DevTools چیزی را دور نمی‌زند چون login_captcha_gate مستقل بررسی
 *    می‌کند.
 */
(function () {
    'use strict';

    var CACHE = Object.create(null);   // 'role:id' → true/false

    function setVisible(wrap, show) {
        var input = wrap.querySelector('.js-captcha-input');
        wrap.hidden = !show;
        if (!input) return;
        input.disabled = !show;
        input.required = show;
        if (!show) input.value = '';
    }

    function ask(role, id, cb) {
        var key = role + ':' + id;
        if (key in CACHE) { cb(CACHE[key]); return; }

        var url = 'login-captcha-state.php?role=' + encodeURIComponent(role) +
                  '&id=' + encodeURIComponent(id);
        var done = false;
        function finish(need) {
            if (done) return;
            done = true;
            if (need !== null) CACHE[key] = need;
            cb(need);
        }

        try {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', url, true);
            xhr.timeout = 6000;
            xhr.onload = function () {
                try {
                    var r = JSON.parse(xhr.responseText);
                    finish(!!r.need);
                } catch (e) { finish(null); }
            };
            xhr.onerror   = function () { finish(null); };
            xhr.ontimeout = function () { finish(null); };
            xhr.send();
        } catch (e) {
            finish(null);
        }
    }

    function wire(wrap) {
        var form = wrap.closest ? wrap.closest('form') : null;
        if (!form) return;
        var role  = wrap.getAttribute('data-cap-role') || '';
        var field = form.querySelector('.js-cap-identity');
        if (!field) { setVisible(wrap, true); return; }   // شناسه پیدا نشد → سخت‌گیرانه

        var timer = null;
        function check() {
            var id = (field.value || '').trim();

            ask(role, id, function (need) {
                /* اگر کاربر در این فاصله متن را عوض کرده، نتیجه را دور بریز */
                if ((field.value || '').trim() !== id) return;
                if (need !== null) setVisible(wrap, need);
            });
        }

        field.addEventListener('blur', check);
        field.addEventListener('change', check);
        field.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(check, 450);
        });

        /* اگر مرورگر مقدار را از قبل پر کرده باشد */
        check();

        /* شبکهٔ ایمنی: اگر سرور کپچا می‌خواست ولی ما نشان نداده بودیم،
           ارسال رد می‌شود و کاربر با پیام خطا برمی‌گردد؛ در آن حالت
           صفحه با فلش خطا لود شده و همان لحظه دوباره check اجرا می‌شود. */
        form.addEventListener('submit', function () {
            var input = wrap.querySelector('.js-captcha-input');
            if (wrap.hidden && input) input.disabled = true;
        });
    }

    function init() {
        var wraps = document.querySelectorAll('.js-captcha-wrap');
        for (var i = 0; i < wraps.length; i++) wire(wraps[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
