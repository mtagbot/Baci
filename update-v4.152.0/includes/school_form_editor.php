<?php
/**
 * ویرایشگر زندهٔ فرم‌های نامه‌ای — v4.181.0
 *
 * چرا؟ متن دعوتنامهٔ انجمن و رضایت‌نامهٔ اردو ثابت بود: عنوان اردو، تاریخ،
 * ساعت، مبدأ، مقصد، مدت، مبلغ، توضیحات و فهرست برنامه‌ها روی برگه خط‌چین
 * می‌ماند و مدرسه باید دستی پرش می‌کرد؛ در برخی جلسه‌ها هم بعضی فیلدها لازم
 * نبود. این ویرایشگر:
 *
 *   • هر فیلد را با «مقدار» و «کلید نمایش» می‌دهد؛
 *   • تغییرها را بلافاصله روی پیش‌نمایش همان برگهٔ چاپی اعمال می‌کند (JS)؛
 *   • با دکمهٔ «ذخیرهٔ پیش‌فرض» مقادیر را برای بار بعد در تنظیمات نگه می‌دارد؛
 *   • «چاپ / ذخیرهٔ PDF» فقط خودِ برگه‌ها را چاپ می‌کند (پنل ویرایش چاپ نمی‌شود).
 */

if (!function_exists('sfe_fields')) {
    /** تعریف فیلدهای هر فرم: کلید، برچسب، نوع (text|area|lines) و راهنما. */
    function sfe_fields($form) {
        if ($form === 'consent') {
            return [
                ['year_label', 'سال تحصیلی (سرصفحه)', 'text', 'مثلاً ۱۴۰۴/۱۴۰۵'],
                ['trip', 'عنوان اردو', 'text', 'مثلاً بازدید از موزهٔ علوم'],
                ['date', 'تاریخ اردو', 'text', 'مثلاً ۱۴۰۵/۰۸/۱۲'],
                ['time', 'راس ساعت', 'text', 'مثلاً ۷:۳۰'],
                ['from', 'از محل', 'text', 'مثلاً مدرسه'],
                ['to', 'به مقصد', 'text', 'مثلاً موزهٔ علوم تهران'],
                ['days', 'مدت (روز)', 'text', 'مثلاً ۱'],
                ['amount', 'مبلغ (تومان)', 'text', 'مثلاً ۲۵۰۰۰۰'],
                ['notes', 'توضیحات', 'area', 'این متن داخل کادر توضیحات برگه می‌آید.'],
            ];
        }
        return [
            ['date', 'تاریخ نامه', 'text', 'پیش‌فرض: تاریخ و ساعت امروز'],
            ['number', 'شمارهٔ نامه', 'text', 'خالی بماند، خط‌چین چاپ می‌شود'],
            ['place', 'زمان و مکان جلسه', 'text', 'مثلاً پنج‌شنبه ۸ آبان، ساعت ۱۶ — سالن اجتماعات'],
            ['intro', 'متن نامه', 'area', 'هر خط یک پاراگراف'],
            ['agenda', 'فهرست برنامه‌ها', 'lines', 'هر خط یک بند (شماره‌گذاری خودکار است)'],
        ];
    }
}
if (!function_exists('sfe_toggle_keys')) {
    /** کلیدهای نمایش برای هر فرم. */
    function sfe_toggle_keys($form) {
        if ($form === 'consent') {
            return ['year_label' => 'سال تحصیلی', 'trip' => 'عنوان اردو', 'date' => 'تاریخ اردو', 'time' => 'ساعت',
                    'from' => 'از محل', 'to' => 'به مقصد', 'days' => 'مدت', 'amount' => 'مبلغ', 'notes' => 'کادر توضیحات'];
        }
        return ['date' => 'تاریخ نامه', 'number' => 'شمارهٔ نامه', 'place' => 'زمان و مکان جلسه',
                'intro' => 'متن نامه', 'agenda' => 'فهرست برنامه‌ها', 'member_name' => 'نام و نام خانوادگی',
                'member_phone' => 'شمارهٔ تماس', 'member_education' => 'رشته و تحصیلات', 'parent_sign' => 'امضای ولی دانش‌آموز'];
    }
}
if (!function_exists('sfe_post_back')) {
    /** آدرس بازگشت به ویرایشگر با همان پارامترها. */
    function sfe_self_url($form, array $params) {
        $query = array_merge($params, ['action' => $form === 'consent' ? 'camp_editor' : 'anjoman_editor']);
        return 'reports-lists.php?' . http_build_query($query);
    }
}
if (!function_exists('sfe_render')) {
    /**
     * پنل ویرایش + پیش‌نمایش زندهٔ برگه‌ها.
     *
     * $params: class / grade / scope / mode / embedded (همان پارامترهای چاپ)
     */
    function sfe_render($form, array $params, array $students, $grade, $className, $ctx) {
        $form = $form === 'consent' ? 'consent' : 'anjoman';
        $blank = (($params['mode'] ?? '') === 'blank');
        $opts  = sf_form_defaults($form);
        $saved = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sfe_save'])) {
            if (!is_string($_POST['csrf_token'] ?? null) || !verify_csrf($_POST['csrf_token'])) {
                set_flash_message('error', 'اعتبار فرم منقضی شده است؛ صفحه را تازه کنید.');
            } else {
                $opts = sf_form_options_from_post($form, $_POST);
                sf_form_options_save($form, $opts);
                $saved = true;
            }
        }

        $blocks = $form === 'consent'
            ? sf_camp_blocks($students, $ctx, $blank, $opts)
            : sf_anjoman_blocks($students, $ctx, $blank, $opts);
        $perPage = $form === 'consent' ? 4 : 2;
        $title = $form === 'consent' ? 'رضایت‌نامهٔ اردو' : 'دعوتنامهٔ انجمن اولیاء و مربیان';

        $esc = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

        ob_start();
        ?>
<style>
.sfe-layout{max-width:1500px;margin:10px auto;padding:0 10px;display:grid;grid-template-columns:340px 1fr;gap:14px;align-items:start;font-family:Tahoma,sans-serif}
.sfe-panel{background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:12px;position:sticky;top:10px;max-height:92vh;overflow:auto}
.sfe-panel h2{font-size:15px;margin:0 0 8px}
.sfe-panel .row{margin-bottom:9px}
.sfe-panel label{display:block;font-size:12px;font-weight:700;margin-bottom:3px}
.sfe-panel input[type=text],.sfe-panel textarea{width:100%;border:1px solid #cbd5e1;border-radius:7px;padding:6px;font:inherit;font-size:12.5px}
.sfe-panel textarea{min-height:64px;resize:vertical}
.sfe-panel .hint{font-size:11px;color:#64748b;margin-top:2px}
.sfe-show{display:flex;align-items:center;gap:6px;font-size:12px;color:#0f172a;margin-top:4px}
.sfe-panel .actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.sfe-panel button,.sfe-panel .btn-sfe{border:0;border-radius:8px;padding:8px 14px;font:inherit;font-size:13px;cursor:pointer}
.sfe-save{background:#059669;color:#fff}
.sfe-print{background:#2563eb;color:#fff}
.sfe-toggle-all{background:#e2e8f0;color:#0f172a}
.sfe-note{font-size:11.5px;color:#065f46;background:#d1fae5;border-radius:6px;padding:6px 8px;margin-top:8px}
.sfe-preview{min-width:0}
.sfe-preview .tag{font-size:12px;color:#334155;margin:0 0 6px;font-family:Tahoma,sans-serif}
@media (max-width:1000px){.sfe-layout{grid-template-columns:1fr}.sfe-panel{position:static;max-height:none}}
@media print{.sfe-panel,.sfe-preview .tag{display:none!important}}
</style>
<div class="sfe-layout">
  <form class="sfe-panel" method="POST" action="<?php echo $esc(sfe_self_url($form, $params)); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo $esc(csrf_token()); ?>">
    <input type="hidden" name="sfe_save" value="1">
    <h2><?php echo $form === 'consent' ? 'رضایت‌نامهٔ اردو — مقادیر این اردو' : 'دعوتنامهٔ انجمن — مقادیر این جلسه'; ?></h2>
    <p class="hint" style="margin:0 0 10px">
      هر فیلد را پر کنید و اگر در این جلسه لازم نیست، تیک «نمایش» را بردارید.
      پیش‌نمایش همان لحظه عوض می‌شود. با «ذخیرهٔ پیش‌فرض» مقادیر برای بار بعد می‌ماند.
    </p>
    <?php foreach (sfe_fields($form) as $f):
        [$key, $label, $type, $hint] = $f;
        $value = (string)($opts[$key] ?? '');
        $shown = sf_show($opts, $key);
    ?>
    <div class="row">
      <label for="sfe-<?php echo $esc($key); ?>"><?php echo $esc($label); ?></label>
      <?php if ($type === 'area' || $type === 'lines'): ?>
        <textarea id="sfe-<?php echo $esc($key); ?>" name="f_<?php echo $esc($key); ?>" data-sfe-input="<?php echo $esc($key); ?>" rows="<?php echo $type === 'lines' ? 4 : 4; ?>"><?php echo $esc($value); ?></textarea>
      <?php else: ?>
        <input type="text" id="sfe-<?php echo $esc($key); ?>" name="f_<?php echo $esc($key); ?>" data-sfe-input="<?php echo $esc($key); ?>" value="<?php echo $esc($value); ?>">
      <?php endif; ?>
      <?php if ($hint !== ''): ?><div class="hint"><?php echo $esc($hint); ?></div><?php endif; ?>
      <label class="sfe-show"><input type="checkbox" name="show_<?php echo $esc($key); ?>" value="1" data-sfe-toggle="<?php echo $esc($key); ?>" <?php echo $shown ? 'checked' : ''; ?>> نمایش در برگه</label>
    </div>
    <?php endforeach; ?>

    <?php $toggles = sfe_toggle_keys($form); $extra = array_diff_key($toggles, array_flip(array_column(sfe_fields($form), 0))); ?>
    <?php if ($extra): ?>
    <div class="row" style="border-top:1px dashed #cbd5e1;padding-top:9px">
      <label>فیلدهای تکمیلی برگه</label>
      <?php foreach ($extra as $key => $label): ?>
        <label class="sfe-show"><input type="checkbox" name="show_<?php echo $esc($key); ?>" value="1" data-sfe-toggle="<?php echo $esc($key); ?>" <?php echo sf_show($opts, $key) ? 'checked' : ''; ?>> <?php echo $esc($label); ?></label>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="sfe-save">ذخیرهٔ پیش‌فرض</button>
      <button type="button" class="sfe-print" onclick="printForms()">چاپ / ذخیرهٔ PDF</button>
      <button type="button" class="sfe-toggle-all" onclick="sfeAll(true)">همه روشن</button>
      <button type="button" class="sfe-toggle-all" onclick="sfeAll(false)">همه خاموش</button>
    </div>
    <?php if ($saved): ?><div class="sfe-note">مقادیر به‌عنوان پیش‌فرض این فرم ذخیره شد.</div><?php endif; ?>
  </form>

  <div class="sfe-preview">
    <p class="tag">
      پیش‌نمایش زندهٔ برگه‌ها — <?php echo $esc(tr_num((string)count($blocks), 'fa')); ?> برگه،
      <?php echo $form === 'consent' ? 'چهار تا در هر صفحه' : 'دو تا در هر صفحه'; ?>.
      نقطه‌چین‌ها یعنی فیلد خالی مانده و با دست پر می‌شود.
    </p>
    <?php foreach (array_chunk($blocks, $perPage) as $page): ?>
      <div class="sheet"><?php echo implode('', $page); ?></div>
    <?php endforeach; ?>
  </div>
</div>
<script>
(function () {
  var inputs = document.querySelectorAll('[data-sfe-input]');
  var toggles = document.querySelectorAll('[data-sfe-toggle]');
  function nodes(key) { return document.querySelectorAll('[data-sfe="' + key + '"]'); }
  function applyInput(el) {
    var key = el.dataset.sfeInput, value = el.value;
    var list = nodes(key);
    for (var i = 0; i < list.length; i++) {
      if (list[i].tagName === 'OL') continue;
      list[i].textContent = value;
    }
    if (key === 'agenda') {
      var lists = document.querySelectorAll('ol.sfe-agenda');
      var lines = value.split(/\r?\n/).map(function (l) { return l.replace(/^\s*[-•*\d]+[).\-\s]*/, '').trim(); }).filter(Boolean);
      for (var j = 0; j < lists.length; j++) {
        lists[j].textContent = '';
        lines.forEach(function (line) {
          var li = document.createElement('li');
          li.textContent = line;
          lists[j].appendChild(li);
        });
      }
    }
    if (key === 'notes') {
      var boxes = document.querySelectorAll('[data-sfe-opt="notes"]');
      for (var k = 0; k < boxes.length; k++) boxes[k].style.display = value.trim() === '' ? 'none' : '';
    }
  }
  function applyToggle(el) {
    var key = el.dataset.sfeToggle, on = el.checked;
    var list = document.querySelectorAll('[data-sfe-opt="' + key + '"]');
    for (var i = 0; i < list.length; i++) list[i].style.display = on ? '' : 'none';
    /* جدول مشخصات: اگر هیچ خانه‌ای روشن نباشد، خودِ جدول هم برود */
    document.querySelectorAll('table.sfe-member').forEach(function (tbl) {
      var cells = tbl.querySelectorAll('[data-sfe-opt]');
      var any = false;
      for (var c = 0; c < cells.length; c++) if (cells[c].style.display !== 'none') any = true;
      tbl.style.display = any ? '' : 'none';
    });
  }
  window.sfeAll = function (on) {
    for (var i = 0; i < toggles.length; i++) { toggles[i].checked = on; applyToggle(toggles[i]); }
  };
  for (var i = 0; i < inputs.length; i++) { inputs[i].addEventListener('input', function () { applyInput(this); }); applyInput(inputs[i]); }
  for (var j = 0; j < toggles.length; j++) { toggles[j].addEventListener('change', function () { applyToggle(this); }); applyToggle(toggles[j]); }
})();
</script>
        <?php
        $panel = ob_get_clean();
        return sf_head($title . ' — ویرایشگر زنده') . $panel . sf_tail(false);
    }
}
