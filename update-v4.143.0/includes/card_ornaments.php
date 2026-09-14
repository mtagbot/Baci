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

/* ══════════════════════════════════════════════════════════════════
   v4.141.0 — کاغذ چاپ و مقیاس کارت
   یک منبع واحد برای اندازهٔ کاغذها و ابعاد پایهٔ هر شکل کارت، تا
   پیش‌نمایش زنده و خروجی چاپ هرگز از هم جدا نشوند.
   ══════════════════════════════════════════════════════════════════ */

if (!function_exists('card_paper_sizes')) {
    /** اندازهٔ کاغذها به میلی‌متر (عمودی). */
    function card_paper_sizes() {
        return [
            'A5' => ['w' => 148, 'h' => 210, 'label' => 'A5 — ۱۴۸×۲۱۰'],
            'A4' => ['w' => 210, 'h' => 297, 'label' => 'A4 — ۲۱۰×۲۹۷'],
            'A3' => ['w' => 297, 'h' => 420, 'label' => 'A3 — ۲۹۷×۴۲۰'],
            'A2' => ['w' => 420, 'h' => 594, 'label' => 'A2 — ۴۲۰×۵۹۴'],
        ];
    }
}

if (!function_exists('card_paper')) {
    /**
     * ابعاد کاغذ با در نظر گرفتن جهت.
     * @return array ['w'=>عرض, 'h'=>ارتفاع]  (میلی‌متر)
     */
    function card_paper($name, $orient = 'portrait') {
        $all = card_paper_sizes();
        $p = $all[$name] ?? $all['A4'];
        return ($orient === 'landscape')
            ? ['w' => $p['h'], 'h' => $p['w']]
            : ['w' => $p['w'], 'h' => $p['h']];
    }
}

if (!function_exists('card_base_size')) {
    /**
     * ابعاد پایهٔ کارت (پیش از اعمال مقیاس) برای هر شکل.
     * همان اعدادی که در card_styles.php هم هستند؛ اینجا تکرار شده‌اند
     * چون PHP باید بتواند چیدمان صفحه را حساب کند.
     */
    function card_base_size($layout) {
        return ($layout === 'sq')
            ? ['w' => 54.0, 'h' => 54.0]
            : ['w' => 85.6, 'h' => 54.0];
    }
}

if (!function_exists('card_grid_info')) {
    /**
     * چند کارت در هر صفحه جا می‌شود.
     *
     * نکتهٔ مهم: فاصلهٔ بین کارت‌ها (gap) فقط *بین* آن‌ها شمرده
     * می‌شود، نه بعد از آخرین کارت. فرمول (فضا + gap) / (کارت + gap)
     * دقیقاً همین را می‌دهد.
     */
    function card_grid_info($paperName, $orient, $layout, $scale, $margin, $gap) {
        $pp = card_paper($paperName, $orient);
        $cb = card_base_size($layout);
        $cw = $cb['w'] * $scale;
        $ch = $cb['h'] * $scale;
        $uw = max(0, $pp['w'] - 2 * $margin);
        $uh = max(0, $pp['h'] - 2 * $margin);
        $cols = ($cw > 0) ? (int) floor(($uw + $gap) / ($cw + $gap)) : 0;
        $rows = ($ch > 0) ? (int) floor(($uh + $gap) / ($ch + $gap)) : 0;
        $cols = max(0, $cols); $rows = max(0, $rows);
        return [
            'paper_w' => $pp['w'], 'paper_h' => $pp['h'],
            'card_w'  => $cw,      'card_h'  => $ch,
            'cols'    => $cols,    'rows'    => $rows,
            'per_page' => $cols * $rows,
        ];
    }
}

if (!function_exists('card_preview_cap')) {
    /**
     * بیشترین تعداد کارتی که در بدترین (یعنی پرظرفیت‌ترین) حالت ممکن
     * روی یک صفحه جا می‌شود.
     *
     * v4.142.0 — پیش‌تر عدد ثابت ۷۰ بود که سقف A2 در حالت مربع با
     * مقیاس ۱۰۰٪ و حاشیهٔ ۸ را نشان می‌داد. ولی کاربر می‌تواند مقیاس
     * را تا ۶۰٪ و حاشیه را تا صفر ببرد، و آن‌وقت ۲۱۶ کارت جا می‌شود —
     * یعنی پیش‌نمایش ۱۴۶ کارت کم می‌آورد و صفحه ناقص دیده می‌شد.
     *
     * حالا به‌جای عدد ثابت، همهٔ ترکیب‌های ممکن پیمایش می‌شوند و
     * بیشینه برگردانده می‌شود. اگر روزی کاغذ بزرگ‌تری اضافه شود یا
     * بازهٔ مقیاس عوض شود، این خودکار به‌روز می‌شود.
     */
    function card_preview_cap() {
        static $cap = null;
        if ($cap !== null) return $cap;

        $minScale  = card_scale_min();
        $minMargin = 0.0;
        $minGap    = 0.0;
        $best = 0;
        foreach (array_keys(card_paper_sizes()) as $paper) {
            foreach (['portrait', 'landscape'] as $orient) {
                foreach (['full', 'qrmax', 'sq'] as $layout) {
                    $g = card_grid_info($paper, $orient, $layout, $minScale, $minMargin, $minGap);
                    if ($g['per_page'] > $best) $best = $g['per_page'];
                }
            }
        }
        $cap = max(1, $best);
        return $cap;
    }
}

if (!function_exists('card_scale_min')) {
    /** کف مقیاس کارت. زیر این مقدار ماژول‌های QR برای دوربین ریز می‌شوند. */
    function card_scale_min() { return 0.6; }
}

if (!function_exists('card_scale_max')) {
    /** سقف مقیاس کارت. */
    function card_scale_max() { return 1.6; }
}

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
            'classic' => 'کلاسیک — محراب و شمسه (وزیرمتن)',
            'ribbon'  => 'گنبد — سربرگ گرادیانی، بافت موج (ساحل)',
            'minimal' => 'فروهر — مینیمال و بدون بافت (یکان)',
            'titr'    => 'تخت‌جمشید — ستون و آجرکاری (بی‌تیتر)',
            'tile'    => 'کاشی — ستارهٔ دوازده‌پر، شش‌ضلعی (ساحل)',
            'sarv'    => 'سرو — نقش قالی، روشن (وزیرمتن)',
        ];
    }
}

if (!function_exists('card_theme_art')) {
    /**
     * تصویر شاخص و نقش سربرگِ هر طرح.
     *
     * جدا نگه داشته شد تا افزودن طرح تازه فقط یک ردیف اینجا و چند خط
     * CSS باشد — نه دست‌زدن به قالب کارت.
     *
     * @return array [hero, headOrn, backOrn]
     */
    function card_theme_art($theme) {
        $map = [
            'classic' => ['mehrab',   'shamse',   'flower'],
            'ribbon'  => ['dome',     'star12',   'eslimi'],
            'minimal' => ['farvahar', 'shamse',   'lotus'],
            'titr'    => ['column',   'star12',   'taq'],
            'tile'    => ['star12',   'shamse',   'hex-none'],
            'sarv'    => ['sarv',     'boteh',    'boteh'],
        ];
        $a = $map[$theme] ?? $map['classic'];
        if ($a[2] === 'hex-none') $a[2] = 'star12';
        return $a;
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

  <!-- ── v4.138.0: نقش‌های تازه، برای تنوع واقعی بین طرح‌ها ──────── -->

  <!-- سرو ایرانی — نماد پایداری، از تخت‌جمشید تا نقش قالی -->
  <symbol id="orn-sarv" viewBox="0 0 100 100">
    <path d="M50 96 L50 62"/>
    <path d="M50 66 C34 60 28 44 34 28 C38 16 46 8 50 4 C54 8 62 16 66 28 C72 44 66 60 50 66 Z"/>
    <path d="M50 12 L50 60"/>
    <path d="M50 26 L40 34 M50 26 L60 34 M50 38 L38 46 M50 38 L62 46 M50 50 L42 56 M50 50 L58 56"/>
  </symbol>

  <!-- محراب / قاب مقرنس — قوس پنج‌ودو تند ایرانی -->
  <symbol id="orn-mehrab" viewBox="0 0 100 100">
    <path d="M14 96 L14 44 C14 26 30 12 50 6 C70 12 86 26 86 44 L86 96"/>
    <path d="M24 96 L24 46 C24 32 36 22 50 17 C64 22 76 32 76 46 L76 96"/>
    <path d="M50 6 L50 17"/>
    <path d="M34 40 L50 28 L66 40"/>
  </symbol>

  <!-- گنبد فیروزه‌ای با ترک‌های عمودی -->
  <symbol id="orn-dome" viewBox="0 0 100 100">
    <path d="M18 74 C18 46 32 22 50 8 C68 22 82 46 82 74 Z"/>
    <path d="M50 8 L50 74 M34 16 C30 34 28 54 28 74 M66 16 C70 34 72 54 72 74"/>
    <path d="M14 74 L86 74 M20 82 L80 82"/>
    <path d="M50 8 L50 2"/>
  </symbol>

  <!-- ستون تخت‌جمشید (سرستون شیاردار) -->
  <symbol id="orn-column" viewBox="0 0 100 100">
    <path d="M30 96 L30 30 M42 96 L42 30 M58 96 L58 30 M70 96 L70 30"/>
    <path d="M24 30 L76 30 M22 22 L78 22"/>
    <path d="M30 22 C30 12 40 6 50 6 C60 6 70 12 70 22"/>
    <path d="M22 96 L78 96 M26 88 L74 88"/>
  </symbol>

  <!-- بادگیر یزدی -->
  <symbol id="orn-badgir" viewBox="0 0 100 100">
    <path d="M26 96 L26 34 L74 34 L74 96"/>
    <path d="M20 34 L80 34 M22 28 L78 28"/>
    <path d="M38 28 L38 10 M50 28 L50 6 M62 28 L62 10"/>
    <path d="M34 10 L42 10 M46 6 L54 6 M58 10 L66 10"/>
    <path d="M38 48 L62 48 M38 60 L62 60 M38 72 L62 72"/>
  </symbol>

  <!-- طاق کسری / قوس بیضی -->
  <symbol id="orn-taq" viewBox="0 0 100 100">
    <path d="M8 96 L8 52 C8 28 26 10 50 10 C74 10 92 28 92 52 L92 96"/>
    <path d="M22 96 L22 54 C22 36 34 24 50 24 C66 24 78 36 78 54 L78 96"/>
    <path d="M36 96 L36 58 C36 48 42 40 50 40 C58 40 64 48 64 58 L64 96"/>
  </symbol>

  <!-- شمسهٔ دوازده‌پر (ستارهٔ کاشی‌کاری) -->
  <symbol id="orn-star12" viewBox="0 0 100 100">
    <path d="M50 4 L59 20 L77 15 L72 33 L90 38 L77 50 L90 62 L72 67 L77 85 L59 80 L50 96 L41 80 L23 85 L28 67 L10 62 L23 50 L10 38 L28 33 L23 15 L41 20 Z"/>
    <circle cx="50" cy="50" r="14"/>
    <circle cx="50" cy="50" r="6"/>
  </symbol>

  <!-- گل نیلوفر آبی (لوتوس هخامنشی) -->
  <symbol id="orn-lotus" viewBox="0 0 100 100">
    <path d="M50 88 C50 66 50 54 50 44"/>
    <path d="M50 44 C40 26 44 10 50 4 C56 10 60 26 50 44 Z"/>
    <path d="M50 48 C34 38 20 40 14 46 C22 62 40 60 50 48 Z"/>
    <path d="M50 48 C66 38 80 40 86 46 C78 62 60 60 50 48 Z"/>
    <path d="M30 82 C40 74 60 74 70 82"/>
  </symbol>

  <!-- اسلیمی پیچان (نوار گیاهی) -->
  <symbol id="orn-eslimi" viewBox="0 0 100 100">
    <path d="M4 50 C18 26 34 74 50 50 C66 26 82 74 96 50"/>
    <path d="M22 42 C24 34 32 32 34 38"/>
    <path d="M54 58 C56 66 64 68 66 62"/>
    <path d="M78 42 C80 34 88 32 90 38"/>
  </symbol>

  <!-- فروهر (نماد ساده‌شده و مینیمال) -->
  <symbol id="orn-farvahar" viewBox="0 0 100 100">
    <circle cx="50" cy="40" r="9"/>
    <path d="M50 49 L50 66"/>
    <path d="M41 56 L59 56"/>
    <path d="M41 49 C24 49 10 43 2 38 C12 40 26 42 41 42"/>
    <path d="M59 49 C76 49 90 43 98 38 C88 40 74 42 59 42"/>
    <path d="M44 66 C36 76 34 86 38 94 M56 66 C64 76 66 86 62 94"/>
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

  <!-- v4.138.0: بافت‌های متنوع، هر طرح یکی -->

  <!-- گره‌چینی شش‌ضلعی (کاشی‌کاری اصفهان) -->
  <pattern id="orn-hex" width="30" height="26" patternUnits="userSpaceOnUse">
    <g fill="none" stroke="<?php echo $p; ?>" stroke-width="0.6" opacity="0.24">
      <path d="M7.5 0 L22.5 0 L30 13 L22.5 26 L7.5 26 L0 13 Z"/>
      <path d="M15 6 L21 13 L15 20 L9 13 Z"/>
    </g>
  </pattern>

  <!-- آجرکاری سنتی -->
  <pattern id="orn-brick" width="24" height="12" patternUnits="userSpaceOnUse">
    <g fill="none" stroke="<?php echo $p; ?>" stroke-width="0.5" opacity="0.2">
      <path d="M0 0 H24 M0 6 H24 M0 12 H24"/>
      <path d="M0 0 V6 M12 0 V6 M6 6 V12 M18 6 V12"/>
    </g>
  </pattern>

  <!-- موج اسلیمی -->
  <pattern id="orn-wave" width="32" height="16" patternUnits="userSpaceOnUse">
    <path d="M0 8 C8 0 8 16 16 8 C24 0 24 16 32 8" fill="none"
          stroke="<?php echo $a; ?>" stroke-width="0.7" opacity="0.22"/>
  </pattern>

  <!-- نقطه‌چین ظریف -->
  <pattern id="orn-dots" width="14" height="14" patternUnits="userSpaceOnUse">
    <circle cx="7" cy="7" r="1.1" fill="<?php echo $p; ?>" opacity="0.18"/>
  </pattern>

  <!-- لوزی قالی -->
  <pattern id="orn-rug" width="26" height="26" patternUnits="userSpaceOnUse">
    <g fill="none" stroke="<?php echo $a; ?>" stroke-width="0.6" opacity="0.22">
      <path d="M13 2 L24 13 L13 24 L2 13 Z"/>
      <path d="M13 8 L18 13 L13 18 L8 13 Z"/>
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
