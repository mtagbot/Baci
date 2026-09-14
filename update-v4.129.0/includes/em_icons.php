<?php
/**
 * includes/em_icons.php — v4.129.0
 *
 * مجموعهٔ آیکون خطی (stroke) به‌صورت یک <svg> sprite.
 * یک‌بار در صفحه چاپ می‌شود؛ بعد با <svg class="ic"><use href="#i-check"></use></svg>
 * استفاده می‌شود. چون درون همان سند است، هیچ درخواست شبکهٔ اضافه‌ای ندارد
 * و رنگش از currentColor می‌آید — بر خلاف ایموجی، هم‌اندازه و هم‌تراز است.
 *
 * کاربرد:
 *   require_once __DIR__ . '/includes/em_icons.php';
 *   em_icon('check');            // چاپ آیکون
 *   em_icon('camera', 'ic-lg');  // با کلاس اندازه
 */

if (!function_exists('em_icon')) {
    /** چاپ یک آیکون از sprite. اگر sprite هنوز چاپ نشده باشد، اول چاپش می‌کند. */
    function em_icon($name, $class = 'ic') {
        static $printed = false;
        if (!$printed) { em_icon_sprite(); $printed = true; }
        $n = preg_replace('/[^a-z0-9_-]/', '', (string)$name);
        $c = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        echo '<svg class="' . $c . '" aria-hidden="true" focusable="false"><use href="#i-' . $n . '"></use></svg>';
    }
}

if (!function_exists('em_icon_sprite')) {
    /** خودِ sprite. دو بار چاپ نشود. */
    function em_icon_sprite() {
        static $done = false;
        if ($done) return;
        $done = true;
        ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
  <defs>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
    <symbol id="i-camera" viewBox="0 0 24 24"><path d="M14.5 4h-5L8 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-4l-1.5-2Z"/><circle cx="12" cy="13" r="3.5"/></symbol>
    <symbol id="i-camera-off" viewBox="0 0 24 24"><path d="m2 2 20 20"/><path d="M14.5 4h-5L8 6H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-7"/><path d="M10.5 10.6a3.5 3.5 0 0 0 4.9 4.9"/></symbol>
    <symbol id="i-mic" viewBox="0 0 24 24"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4"/></symbol>
    <symbol id="i-mic-off" viewBox="0 0 24 24"><path d="m2 2 20 20"/><path d="M9 5a3 3 0 0 1 6 0v5"/><path d="M15 11a3 3 0 0 1-6 0"/><path d="M5 11a7 7 0 0 0 10.9 5.8M19 11v.5A7 7 0 0 1 17 16M12 18v4"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="2.8"/></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 3 5 6v6c0 4.2 2.9 8 7 9 4.1-1 7-4.8 7-9V6l-7-3Z"/><path d="m9.2 12 2 2 3.6-3.8"/></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><path d="M12 3.5 1.8 20.5h20.4L12 3.5Z"/><path d="M12 10v4.2M12 17.6v.1"/></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7.2V12l3.2 2"/></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2.2 12S5.8 5.5 12 5.5 21.8 12 21.8 12 18.2 18.5 12 18.5 2.2 12 2.2 12Z"/><circle cx="12" cy="12" r="3"/></symbol>
    <symbol id="i-save" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></symbol>
    <symbol id="i-doc" viewBox="0 0 24 24"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5M9 13h6M9 17h6"/></symbol>
    <symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3.5V9h-5.5"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M21 3 10.5 13.5M21 3l-6.8 18-3.7-7.5L3 9.8 21 3Z"/></symbol>
    <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/></symbol>
    <symbol id="i-copy" viewBox="0 0 24 24"><rect x="8.5" y="8.5" width="12" height="12" rx="2"/><path d="M15.5 5.5h-9a2 2 0 0 0-2 2v9"/></symbol>
    <symbol id="i-wifi" viewBox="0 0 24 24"><path d="M2.5 8.5a15 15 0 0 1 19 0M5.8 12.4a10 10 0 0 1 12.4 0M9 16.2a5 5 0 0 1 6 0"/><path d="M12 20h.01"/></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.1"/></symbol>
  </defs>
</svg>
<?php
    }
}
