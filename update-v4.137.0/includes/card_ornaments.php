<?php
/**
 * includes/card_ornaments.php — v4.136.0
 *
 * نقش‌مایه‌های ایرانی–اسلامی برای کارت ورود دانش‌آموز.
 *
 * چرا SVG دست‌ساز و نه تصویر تولیدشده با مدل:
 *   کارت قرار است «چاپ» شود. تصویر رستری در چاپ ۳۰۰dpi محو و پیکسلی
 *   می‌شود، حجم بسته را چند صد کیلوبایت بالا می‌برد، و در نسخهٔ دسکتاپ
 *   که آفلاین کار می‌کند باید همراه بسته حمل شود. نقش برداری در هر
 *   ابعادی تیز است، چند کیلوبایت است، رنگش از تم مدرسه می‌آید و با
 *   currentColor هماهنگ می‌ماند.
 *
 *   ضمناً نقوش اسلامی ذاتاً هندسی‌اند (تکرار، تقارن، شبکهٔ گره‌چینی)؛
 *   این دقیقاً چیزی است که با مختصات دقیق بهتر از حدسِ مدل درمی‌آید.
 *
 * همهٔ نقش‌ها روی مختصات ۰..۱۰۰ کشیده شده‌اند تا با هر اندازهٔ کارت
 * مقیاس بگیرند، و هیچ رنگ ثابتی ندارند.
 */

/* ══════════════════════════════════════════════════════════════════
   v4.137.0 — فونت‌های ایرانی کارت
   چهار فونت همراه بسته‌اند و همگی محلی‌اند (بدون CDN)، چون نسخهٔ
   دسکتاپ آفلاین کار می‌کند. هر طرح کارت با متغیر --f-* فونتش را
   انتخاب می‌کند، پس افزودن طرح جدید نیازی به دست‌زدن به این بخش ندارد.
   ══════════════════════════════════════════════════════════════════ */

if (!function_exists('card_font_list')) {
    /** نام فونت => فهرست [مسیر پایه بدون پسوند, وزن] */
    function card_font_list() {
        return [
            'Vazirmatn' => [['Vazirmatn/Vazirmatn-Regular', 400], ['Vazirmatn/Vazirmatn-Bold', 700]],
            'Sahel'     => [['Sahel/Sahel', 400]],
            'Yekan'     => [['Yekan/Yekan', 400]],
            'B-Titr'    => [['B-Titr/B-Titr', 400]],
        ];
    }
}

if (!function_exists('card_font_exists')) {
    /** آیا دست‌کم یک فایل از این فونت روی دیسک هست؟ */
    function card_font_exists($name) {
        $all = card_font_list();
        if (!isset($all[$name])) return false;
        foreach ($all[$name] as $f) {
            $base = __DIR__ . '/../uploads/' . $f[0];
            if (is_file($base . '.woff2') || is_file($base . '.ttf')) return true;
        }
        return false;
    }
}

if (!function_exists('card_font_faces')) {
    /**
     * قواعد @font-face برای همهٔ فونت‌های موجود.
     *
     * فقط فایل‌هایی اعلام می‌شوند که واقعاً روی دیسک‌اند — یک src
     * شکسته کل قاعده را بی‌اثر می‌کند و فونت بی‌صدا به Tahoma برمی‌گردد.
     */
    function card_font_faces() {
        $out = '';
        foreach (card_font_list() as $name => $files) {
            foreach ($files as $f) {
                list($base, $weight) = $f;
                $srcs = [];
                if (is_file(__DIR__ . '/../uploads/' . $base . '.woff2')) $srcs[] = "url('uploads/{$base}.woff2') format('woff2')";
                if (is_file(__DIR__ . '/../uploads/' . $base . '.ttf'))   $srcs[] = "url('uploads/{$base}.ttf') format('truetype')";
                if ($srcs) {
                    $out .= "@font-face{font-family:'{$name}';src:" . implode(',', $srcs)
                          . ";font-weight:{$weight};font-display:block}\n";
                }
            }
        }
        return $out;
    }
}

if (!function_exists('card_font_vars')) {
    /** متغیرهای CSS فونت. اگر فونتی نبود، به وزیرمتن و بعد Tahoma می‌افتد. */
    function card_font_vars() {
        $pick = function ($name) {
            return card_font_exists($name) ? "'{$name}', " : '';
        };
        return
            '--f-vazir:' . $pick('Vazirmatn') . "Tahoma,sans-serif;" .
            '--f-sahel:' . $pick('Sahel')     . $pick('Vazirmatn') . "Tahoma,sans-serif;" .
            '--f-yekan:' . $pick('Yekan')     . $pick('Vazirmatn') . "Tahoma,sans-serif;" .
            '--f-titr:'  . $pick('B-Titr')    . $pick('Vazirmatn') . "Tahoma,sans-serif;";
    }
}

if (!function_exists('card_themes')) {
    /** فهرست طرح‌های کارت — یک منبع واحد برای فرم، پیش‌نمایش و اعتبارسنجی. */
    function card_themes() {
        return [
            'classic' => 'کلاسیک — طاق و شمسه (وزیرمتن)',
            'ribbon'  => 'نواری — سربرگ رنگی (ساحل)',
            'minimal' => 'مینیمال — خطوط ساده (یکان)',
            'titr'    => 'تیتر — سربرگ گرادیانی (بی‌تیتر)',
            'tile'    => 'کاشی — قاب گره‌چینی (ساحل)',
            'sarv'    => 'سرو — روشن و ملایم (وزیرمتن)',
        ];
    }
}

if (!function_exists('card_ornament_defs')) {
    /**
     * تعریف الگوهای تکرارشونده و نقش‌ها. یک بار در هر صفحه چاپ می‌شود.
     *
     * @param string $primary رنگ اصلی مدرسه
     * @param string $accent  رنگ دوم مدرسه
     */
    function card_ornament_defs($primary, $accent) {
        static $done = false;
        if ($done) return;
        $done = true;
        $p = htmlspecialchars($primary, ENT_QUOTES, 'UTF-8');
        $a = htmlspecialchars($accent, ENT_QUOTES, 'UTF-8');
        ?>
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true" focusable="false"
     xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs>

  <!-- گره هشت‌پر (شمسه) — پایه‌ای‌ترین نقش گره‌چینی ایرانی.
       دو مربع ۴۵ درجه چرخیده روی هم = ستارهٔ هشت‌پر. -->
  <symbol id="orn-shamse" viewBox="0 0 100 100">
    <path d="M50 4 L64 22 L86 14 L78 36 L96 50 L78 64 L86 86 L64 78 L50 96 L36 78 L14 86 L22 64 L4 50 L22 36 L14 14 L36 22 Z"/>
    <path d="M50 20 L60 33 L76 28 L71 44 L84 50 L71 56 L76 72 L60 67 L50 80 L40 67 L24 72 L29 56 L16 50 L29 44 L24 28 L40 33 Z"/>
    <circle cx="50" cy="50" r="10"/>
  </symbol>

  <!-- بته‌جقه — نماد سرو خمیده، شناخته‌شده‌ترین نقش ایرانی -->
  <symbol id="orn-boteh" viewBox="0 0 100 100">
    <path d="M50 92 C22 78 14 54 24 34 C32 18 50 10 60 18 C70 26 66 42 54 48
             C44 53 40 46 45 40 C48 36 54 37 55 41"/>
    <path d="M50 92 C60 80 70 66 74 52"/>
  </symbol>

  <!-- طاق ایرانی (قوس جناغی) — قاب بالای کارت -->
  <symbol id="orn-arch" viewBox="0 0 100 100">
    <path d="M10 96 L10 46 C10 20 30 6 50 4 C70 6 90 20 90 46 L90 96"/>
    <path d="M20 96 L20 48 C20 28 34 16 50 14 C66 16 80 28 80 48 L80 96"/>
  </symbol>

  <!-- گل شش‌پر اسلیمی -->
  <symbol id="orn-flower" viewBox="0 0 100 100">
    <circle cx="50" cy="50" r="12"/>
    <path d="M50 38 C50 20 62 10 62 10 C62 10 50 22 50 38 Z"/>
    <path d="M50 62 C50 80 38 90 38 90 C38 90 50 78 50 62 Z"/>
    <path d="M38 50 C20 50 10 38 10 38 C10 38 22 50 38 50 Z"/>
    <path d="M62 50 C80 50 90 62 90 62 C90 62 78 50 62 50 Z"/>
    <path d="M41 41 C29 29 30 14 30 14 C30 14 33 30 41 41 Z"/>
    <path d="M59 59 C71 71 70 86 70 86 C70 86 67 70 59 59 Z"/>
  </symbol>

  <!-- نوار گره‌چینی افقی — برای لبهٔ کارت -->
  <pattern id="orn-band" width="16" height="16" patternUnits="userSpaceOnUse">
    <path d="M0 8 L8 0 L16 8 L8 16 Z" fill="none" stroke="<?php echo $p; ?>" stroke-width="0.9" opacity="0.5"/>
    <path d="M8 5.5 L10.5 8 L8 10.5 L5.5 8 Z" fill="<?php echo $a; ?>" opacity="0.45"/>
  </pattern>

  <!-- شبکهٔ شمسه برای پس‌زمینهٔ بسیار کم‌رنگ کارت -->
  <pattern id="orn-mesh" width="34" height="34" patternUnits="userSpaceOnUse">
    <g fill="none" stroke="<?php echo $p; ?>" stroke-width="0.55" opacity="0.22">
      <path d="M17 2 L21 11 L30 7 L26 16 L32 17 L26 18 L30 27 L21 23 L17 32 L13 23 L4 27 L8 18 L2 17 L8 16 L4 7 L13 11 Z"/>
    </g>
  </pattern>

  <!-- گرادیان ملایم سربرگ کارت -->
  <linearGradient id="orn-head" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0%"   stop-color="<?php echo $p; ?>"/>
    <stop offset="100%" stop-color="<?php echo $a; ?>"/>
  </linearGradient>
</defs></svg>
        <?php
    }
}

if (!function_exists('card_ornament')) {
    /** چاپ یک نقش از مجموعه. */
    function card_ornament($name, $class = '') {
        $n = preg_replace('/[^a-z0-9_-]/', '', (string)$name);
        $c = htmlspecialchars($class, ENT_QUOTES, 'UTF-8');
        echo '<svg class="' . $c . '" viewBox="0 0 100 100" xmlns:xlink="http://www.w3.org/1999/xlink"'
           . ' aria-hidden="true" focusable="false"><use href="#orn-' . $n . '" xlink:href="#orn-' . $n . '"></use></svg>';
    }
}
