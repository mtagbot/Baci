<?php
/**
 * includes/tile_icons.php — v4.133.0
 *
 * آیکون‌های خطی (stroke) مخصوص کاشی‌های هدر، جایگزین ایموجی.
 *
 * چرا ایموجی کنار گذاشته شد:
 *   · هر سیستم‌عامل ایموجی را جور دیگری می‌کشد؛ ویندوز ۷/۸/۱۰/۱۱ و
 *     وب‌ویوهای مختلف خروجی متفاوتی می‌دهند. یعنی ظاهر برنامه روی
 *     کامپیوتر مدرسه قابل پیش‌بینی نیست.
 *   · ایموجی رنگ ثابت دارد و با رنگ تم (--primary) هماهنگ نمی‌شود.
 *   · اندازه و خط پایه (baseline) بین ایموجی‌ها یکسان نیست، پس کاشی‌ها
 *     هم‌تراز نمی‌شوند.
 *   · بعضی ایموجی‌ها (♻️ ✈️ ⚙️) روی ویندوز قدیمی اصلاً فونت ندارند و
 *     به‌صورت مربع خالی دیده می‌شوند.
 *
 * چرا SVG sprite و نه فایل تصویر:
 *   این همان قراردادی است که پروژه از v4.129.0 در includes/em_icons.php
 *   دارد. sprite یک‌بار درون همان صفحه چاپ می‌شود، پس هیچ درخواست شبکهٔ
 *   اضافه‌ای ندارد (مهم برای نسخهٔ دسکتاپ که آفلاین است)، رنگش از
 *   currentColor می‌آید و با تم عوض می‌شود، و در هر بزرگ‌نمایی تیز است.
 *   بیست فایل PNG هیچ‌کدام از این‌ها را نداشت.
 *
 * سازگاری با مرورگر قدیمی:
 *   <use href> در مرورگرهای قدیمی‌تر پشتیبانی نمی‌شود و فقط
 *   xlink:href کار می‌کند. هر دو صفت چاپ می‌شوند تا روی IE11/وب‌ویوهای
 *   قدیمی هم آیکون دیده شود. SVG 1.1 پایه است — نه ماسک، نه فیلتر،
 *   نه گرادیان.
 */

if (!function_exists('tile_icon_sprite')) {
    /**
     * sprite آیکون‌های کاشی. فقط یک بار در هر صفحه چاپ می‌شود.
     *
     * همهٔ مسیرها روی شبکهٔ ۲۴×۲۴ کشیده شده‌اند، با ضخامت خط یکسان و
     * گردی یکنواخت، تا کنار هم یک خانواده به‌نظر برسند.
     */
    function tile_icon_sprite() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<svg width="0" height="0" style="position:absolute;overflow:hidden" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true" focusable="false"><defs>
  <symbol id="t-dashboard" viewBox="0 0 24 24"><path d="M3.5 11.2 12 4l8.5 7.2"/><path d="M5.6 9.8V19a1 1 0 0 0 1 1h3.2v-5h4.4v5h3.2a1 1 0 0 0 1-1V9.8"/></symbol>
  <symbol id="t-students" viewBox="0 0 24 24"><path d="M12 3.6 22 8l-10 4.4L2 8l10-4.4Z"/><path d="M6.4 10.2V15c0 1.6 2.5 2.9 5.6 2.9s5.6-1.3 5.6-2.9v-4.8"/><path d="M20.4 8.8v5"/></symbol>
  <symbol id="t-attendance" viewBox="0 0 24 24"><rect x="4" y="4.5" width="16" height="16" rx="2.2"/><path d="M8.5 2.8v3.4M15.5 2.8v3.4M4 9.4h16"/><path d="m9 14.4 2 2 4-4"/></symbol>
  <symbol id="t-courses" viewBox="0 0 24 24"><path d="M4 5.2A1.6 1.6 0 0 1 5.6 3.6H11a2 2 0 0 1 2 2v14a1.6 1.6 0 0 0-1.6-1.6H5.6A1.6 1.6 0 0 1 4 16.4V5.2Z"/><path d="M20 5.2a1.6 1.6 0 0 0-1.6-1.6H15a2 2 0 0 0-2 2v14a1.6 1.6 0 0 1 1.6-1.6h3.8A1.6 1.6 0 0 0 20 16.4V5.2Z"/></symbol>
  <symbol id="t-reports" viewBox="0 0 24 24"><path d="M14.5 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7.5L14.5 3Z"/><path d="M14.5 3v4.5H19"/><path d="M9 17v-3M12 17v-5.5M15 17v-2"/></symbol>
  <symbol id="t-exams" viewBox="0 0 24 24"><path d="M19 13.5V19a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h6"/><path d="M9 8.5h4M9 12h3"/><path d="m14.8 14.6 5-5a1.7 1.7 0 0 0-2.4-2.4l-5 5V15h2.4Z"/></symbol>
  <symbol id="t-online" viewBox="0 0 24 24"><rect x="2.8" y="4.2" width="18.4" height="12.4" rx="2"/><path d="M8.4 20.2h7.2M12 16.6v3.6"/><path d="m10 8.6 2.4 2.2-2.4 2.2"/></symbol>
  <symbol id="t-analytics" viewBox="0 0 24 24"><path d="M4 20V4"/><path d="M4 20h16"/><path d="m7.5 15.5 3.5-4 3 2.6 4.5-6"/><circle cx="11" cy="11.5" r="1.1"/><circle cx="14" cy="14.1" r="1.1"/></symbol>
  <symbol id="t-recovery" viewBox="0 0 24 24"><path d="M20.2 12a8.2 8.2 0 1 1-2.4-5.8"/><path d="M20.4 3.8v4.9h-4.9"/><path d="M12 8.4v4l2.6 1.6"/></symbol>
  <symbol id="t-messages" viewBox="0 0 24 24"><rect x="2.8" y="5" width="18.4" height="14" rx="2.2"/><path d="m3.6 6.6 8.4 6 8.4-6"/></symbol>
  <symbol id="t-bale" viewBox="0 0 24 24"><rect x="4" y="8" width="16" height="11.5" rx="2.6"/><path d="M12 8V4.8M12 3.2v.2"/><circle cx="9.2" cy="13.2" r="1.1"/><circle cx="14.8" cy="13.2" r="1.1"/><path d="M9.6 16.4h4.8"/><path d="M4 12H2.4M20 12h1.6"/></symbol>
  <symbol id="t-telegram" viewBox="0 0 24 24"><path d="M21.2 4.3 2.9 11.2l5.1 1.8 1.9 5.6 2.7-3.3 4.6 3.4 4-14.4Z"/><path d="m8 13 9.6-7.4L9.9 18.6"/></symbol>
  <symbol id="t-botaccounts" viewBox="0 0 24 24"><path d="M10.2 13.8a3.6 3.6 0 0 0 5.4.4l2.6-2.6a3.6 3.6 0 0 0-5.1-5.1l-1.5 1.5"/><path d="M13.8 10.2a3.6 3.6 0 0 0-5.4-.4l-2.6 2.6a3.6 3.6 0 0 0 5.1 5.1l1.5-1.5"/></symbol>
  <symbol id="t-backups" viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="7.5" ry="2.9"/><path d="M4.5 6v5.6c0 1.6 3.4 2.9 7.5 2.9s7.5-1.3 7.5-2.9V6"/><path d="M4.5 11.6v5.6c0 1.6 3.4 2.9 7.5 2.9s7.5-1.3 7.5-2.9v-5.6"/></symbol>
  <symbol id="t-logs" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.6"/><path d="M12 6.8V12l3.4 2.1"/></symbol>
  <symbol id="t-othersets" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3.1"/><path d="M19.2 14.5a1.5 1.5 0 0 0 .3 1.7l.1.1a1.9 1.9 0 1 1-2.6 2.6l-.1-.1a1.5 1.5 0 0 0-2.5 1v.3a1.9 1.9 0 1 1-3.7 0v-.2a1.5 1.5 0 0 0-2.6-1l-.1.1a1.9 1.9 0 1 1-2.6-2.6l.1-.1a1.5 1.5 0 0 0-1-2.5H4a1.9 1.9 0 1 1 0-3.7h.2a1.5 1.5 0 0 0 1-2.6l-.1-.1A1.9 1.9 0 1 1 7.7 4.8l.1.1a1.5 1.5 0 0 0 1.7.3h.1a1.5 1.5 0 0 0 .9-1.4V3.6a1.9 1.9 0 1 1 3.7 0v.2a1.5 1.5 0 0 0 2.5 1l.1-.1a1.9 1.9 0 1 1 2.6 2.6l-.1.1a1.5 1.5 0 0 0-.3 1.7v.1a1.5 1.5 0 0 0 1.4.9h.2a1.9 1.9 0 1 1 0 3.7h-.2a1.5 1.5 0 0 0-1.4.9Z"/></symbol>
  <symbol id="t-sync" viewBox="0 0 24 24"><path d="M3.8 9.4a8.4 8.4 0 0 1 14-3.4l2.4 2.2"/><path d="M20.2 14.6a8.4 8.4 0 0 1-14 3.4l-2.4-2.2"/><path d="M20.6 3.6v4.7h-4.7M3.4 20.4v-4.7h4.7"/></symbol>
  <symbol id="t-dbhealth" viewBox="0 0 24 24"><ellipse cx="12" cy="5.6" rx="7.2" ry="2.8"/><path d="M4.8 5.6v12.8c0 1.5 3.2 2.8 7.2 2.8s7.2-1.3 7.2-2.8V5.6"/><path d="M4.8 12c0 1.5 3.2 2.8 7.2 2.8s7.2-1.3 7.2-2.8"/></symbol>
  <symbol id="t-settings" viewBox="0 0 24 24"><path d="M12 3.2c-4.9 0-8.8 3.9-8.8 8.8 0 4.9 3.9 8.8 8.8 8.8 1.5 0 2.6-1.1 2.6-2.4 0-.6-.2-1.1-.6-1.5-.4-.4-.6-.9-.6-1.5 0-1.2 1.1-2.2 2.4-2.2h1.5c2 0 3.5-1.5 3.5-3.5 0-3.7-3.7-6.5-8.8-6.5Z"/><circle cx="7.6" cy="11.4" r="1.15"/><circle cx="11.4" cy="7.4" r="1.15"/><circle cx="16.2" cy="9.4" r="1.15"/></symbol>
  <symbol id="t-admins" viewBox="0 0 24 24"><circle cx="9.2" cy="8.4" r="3.4"/><path d="M2.9 19.4a6.3 6.3 0 0 1 12.6 0"/><path d="M16.6 5.4a3.4 3.4 0 0 1 0 6.4"/><path d="M17.8 14.2a6.3 6.3 0 0 1 3.3 5.2"/></symbol>
</defs></svg>
        <?php
    }
}

if (!function_exists('tile_icon')) {
    /**
     * چاپ یک آیکون کاشی.
     *
     * هم href و هم xlink:href چاپ می‌شود: مرورگرهای جدید اولی را
     * می‌خوانند و قدیمی‌ها دومی را. اگر فقط href باشد، روی IE11 و
     * وب‌ویوهای قدیمیِ ویندوز آیکون خالی می‌ماند.
     */
    function tile_icon($key, $class = 'hdr-tile-ic') {
        tile_icon_sprite();
        $k = preg_replace('/[^a-z0-9_-]/', '', (string)$key);
        $c = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        /* اعلام فضای‌نام xlink روی خودِ svg لازم است، وگرنه مرورگر
           قدیمی صفت xlink:href را نامعتبر می‌بیند و نادیده می‌گیرد. */
        echo '<svg class="' . $c . '" viewBox="0 0 24 24" xmlns:xlink="http://www.w3.org/1999/xlink" aria-hidden="true" focusable="false">'
           . '<use href="#t-' . $k . '" xlink:href="#t-' . $k . '"></use></svg>';
    }
}
