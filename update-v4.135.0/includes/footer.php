<?php /* v4.60.0 fix: teachers were missing from this condition, so for the
   teacher panel the wrapping <div class="flex flex-1"> was never closed and
   the footer rendered INSIDE the flex row (stuck to the left on desktop).
   Teachers get a sidebar + main exactly like admin/student, so they must
   close both tags too. */
if (is_admin_logged_in() || is_student_logged_in() || (function_exists('is_teacher_logged_in') && is_teacher_logged_in())): ?>
    </main>
</div>
<?php else: ?>
    </main>
<?php endif; ?>

<footer class="py-4 px-6 border-t border-color bg-card text-center text-xs text-muted mt-auto">
    <span>طراحی و توسعه : معاونت فناوری متوسطه اول</span>
</footer>

<script src="assets/vendor/chart.umd.min.js"></script>
<script src="assets/js/main.js"></script>
<script src="assets/js/ui-modern.js"></script><!-- v4.30.0: UX enhancements -->
<script src="assets/js/searchable-select.js"></script><!-- v4.135.0: جستجو در لیست‌های بلند -->
<?php /* v4.134.0: در نسخهٔ دسکتاپ همه چیز باید در همان پنجرهٔ برنامه باز
      شود. روی سایت لود نمی‌شود، چون آنجا تبِ جدید رفتار درست است.
      PHP_SAPI === 'cli-server' همان شرطی است که desk-prepend.php هم
      برای تشخیص دسکتاپ استفاده می‌کند. */ ?>
<?php if (PHP_SAPI === 'cli-server'): ?>
<script src="assets/js/desk-shell.js"></script>
<?php endif; ?>

<script>
/* SchoolDesk Pro: real-time auto-sync.
   - a light "tick" runs every 5 seconds: local edits are pushed to the site
     immediately and site-side changes are pulled within seconds;
   - offline: the server-side engine retries on a 10s ×5 → 20s ×5 → 60s ladder
     while every change stays safe in the local database; after 15 failed
     attempts a banner asks the user to check the internet connection;
   - closing the app with unsent changes triggers a warning; they are sent
     automatically the next time the app starts. */
(function () {
    if (!document.querySelector('a[href="desk-sync.php"]')) return; /* admin pages only */
    var pending = 0, busy = false;

    function banner(show, text) {
        var el = document.getElementById('sdpSyncBanner');
        if (!show) { if (el) el.remove(); return; }
        if (!el) {
            el = document.createElement('div');
            el.id = 'sdpSyncBanner';
            el.style.cssText = 'position:fixed;bottom:14px;right:14px;left:14px;z-index:9999;background:#fef3c7;border:2px solid #f59e0b;color:#92400e;border-radius:12px;padding:12px 18px;font-size:13px;line-height:1.9;box-shadow:0 6px 18px rgba(0,0,0,.15);display:flex;justify-content:space-between;align-items:center;gap:12px';
            var span = document.createElement('span'); span.id = 'sdpSyncBannerText';
            var btn = document.createElement('button');
            btn.textContent = 'باشه';
            btn.style.cssText = 'background:#f59e0b;color:#fff;border:0;border-radius:8px;padding:6px 18px;cursor:pointer;font-family:inherit';
            btn.onclick = function () { el.remove(); banner.muted = Date.now(); };
            el.appendChild(span); el.appendChild(btn);
            document.body.appendChild(el);
        }
        document.getElementById('sdpSyncBannerText').textContent = text;
    }

    function tick() {
        if (busy) return;
        busy = true;
        fetch('desk-sync.php?ajax=tick')
            .then(function (r) { return r.json(); })
            .then(function (j) {
                busy = false;
                pending = j.pending || 0;
                if (j.alert && !(banner.muted && Date.now() - banner.muted < 300000)) {
                    banner(true, '⚠ اتصال به سایت برقرار نمی‌شود — لطفاً اتصال اینترنت دستگاه را بررسی کنید. ' +
                        (pending ? 'تغییرات (' + pending + ' مورد) در برنامه محفوظ است و پس از برقراری اتصال خودکار ارسال می‌شود.'
                                 : 'تلاش مجدد هر یک دقیقه ادامه دارد.'));
                } else if (!j.alert && !j.fails) {
                    banner(false);
                }
            })
            .catch(function () { busy = false; });
    }
    setTimeout(tick, 3000);      /* run right after startup: send anything left from last session */
    setInterval(tick, 5000);     /* every edit reaches the server within seconds */

    /* exit warning when unsent changes exist.
       Moving between the app's own pages must NOT warn — only a real close
       (window X / Alt+F4) does. In-app navigation always starts with a link
       click or form submit, so flag those right before unload. */
    var innerNav = false;
    document.addEventListener('click', function (ev) {
        var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
        if (a && a.host === location.host) { innerNav = true; setTimeout(function () { innerNav = false; }, 4000); }
    }, true);
    document.addEventListener('submit', function () {
        innerNav = true; setTimeout(function () { innerNav = false; }, 4000);
    }, true);
    window.addEventListener('beforeunload', function (e) {
        if (pending > 0 && !innerNav) {
            /* v2.12.0: exact message per spec — data exists that has not
               reached the server because the internet was unavailable.
               (It is safe: everything is stored in the local database and
               will be uploaded automatically on the next run.) */
            var msg = 'داده‌های جدید به دلیل عدم ارتباط با اینترنت هنوز روی سرور بارگذاری نشده است. ' +
                      'این داده‌ها (' + pending + ' مورد) در بانک اطلاعاتی برنامه محفوظ می‌ماند و در اجرای بعدی، به محض اتصال اینترنت، خودکار به سرور ارسال می‌شود.';
            e.preventDefault();
            e.returnValue = msg;
            return msg;
        }
    });
})();
</script>
</body>
</html>
