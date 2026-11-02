<?php
// File: report-broadcast.php — v4.213.0
/**
 * «اعلام سراسری پایه‌ای و کلاسی»
 *
 * چه چیزی در این نسخه عوض شد؟
 *
 *  ۱) گسترهٔ ارسال. مدیر می‌تواند کارنامه‌های یک ماه را برای «همهٔ پایه‌ها و
 *     کلاس‌ها»، «فقط یک پایه» یا «فقط یک کلاس» بفرستد. پیش از ارسال،
 *     پیش‌نمایشِ همان گستره نشان می‌دهد چند کارنامه هست و چند تا قفل است.
 *
 *  ۲) قفل کارنامه — و این مهم‌ترین تغییر است. تا پیش از این، شرطِ کوئری
 *     فقط `reports.is_locked = 0` بود؛ یعنی قفلِ گروهی (جدول report_locks)
 *     که ستون را دست نمی‌زند، می‌توانست از این مسیر دور بزند و کارنامهٔ
 *     مسدود برای دانش‌آموز ارسال شود. حالا برای «هر» کارنامه همان تابعی
 *     صدا زده می‌شود که پنل دانش‌آموز، صفحهٔ کارنامه و ربات استفاده می‌کنند
 *     (is_report_locked_for_student) — پس قفل فردی و گروهی هر دو مانع
 *     ارسال‌اند و تصمیمِ قفل فقط در یک جا گرفته می‌شود.
 *
 *  ۳) سه پیام‌رسانِ همین سامانه: «بله»، «تلگرام» و «روبیکا». هر مقداری
 *     جز این سه در POST همان‌جا دور ریخته می‌شود. ستون چت هر پیام‌رسان از
 *     bot_chat_column() خوانده می‌شود (bale_chat_id / telegram_chat_id /
 *     rubika_chat_id) — پیش از این، ستون روبیکا به‌اشتباه بله فرض می‌شد.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/academic_year_helpers.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_permission('manage_reports');
foreach (bot_platforms() as $rbPf) {
    try { ensure_bot_schema($rbPf); } catch (Throwable $e) { /* نبودِ ربات نباید کل صفحه را بترکاند */ }
}

if (!function_exists('rb_platforms')) {
    /**
     * پلتفرم‌های مجاز این صفحه: بله، تلگرام و روبیکا — همان‌هایی که
     * bot_platforms() می‌شناسد و اتصالشان در «اکانت‌های ربات» ثبت می‌شود.
     */
    function rb_platforms() {
        $labels = function_exists('bot_platform_labels') ? bot_platform_labels() : ['bale' => 'بله', 'telegram' => 'تلگرام', 'rubika' => 'روبیکا'];
        $out = [];
        foreach (function_exists('bot_platforms') ? bot_platforms() : ['bale', 'telegram', 'rubika'] as $pf) {
            if (isset($labels[$pf])) $out[$pf] = $labels[$pf];
        }
        return $out;
    }
}

if (!function_exists('rb_scope_options')) {
    function rb_scope_options() {
        return [
            'all'   => 'همهٔ پایه‌ها و کلاس‌ها',
            'grade' => 'فقط یک پایه',
            'class' => 'فقط یک کلاس',
        ];
    }
}

if (!function_exists('rb_norm_class_sql')) {
    /** مقایسهٔ نام کلاس بدون حساسیت به فاصله و خط تیره — همان قاعدهٔ بقیهٔ صفحه‌ها. */
    function rb_norm_class_sql($column) {
        return "REPLACE(REPLACE(REPLACE(REPLACE($column,' ',''),'-',''),'/',''),'_','') = ?";
    }
}

if (!function_exists('rb_collect_reports')) {
    /**
     * کارنامه‌های یک ماه در گسترهٔ انتخاب‌شده + وضعیت قفل هر کدام.
     *
     * @return array{rows:array<int,array>,sendable:int,locked:int}
     */
    function rb_collect_reports($year, $month, $scope, $grade, $class) {
        $where  = ['r.academic_year = ?', 'r.report_month = ?'];
        $params = [$year, $month];
        if ($scope === 'grade' && $grade !== '') {
            $where[] = 's.grade_level = ?';
            $params[] = $grade;
        } elseif ($scope === 'class' && $class !== '') {
            $where[] = rb_norm_class_sql('s.class_name');
            $params[] = norm_class_str($class);
        }
        $rows = DB::fetchAll(
            "SELECT r.*, s.first_name, s.last_name, s.grade_level, s.class_name AS student_class, s.photo_url
               FROM reports r JOIN students s ON s.id = r.student_id
              WHERE " . implode(' AND ', $where) . "
              ORDER BY s.grade_level, s.class_name, s.last_name, s.first_name, r.id",
            $params
        ) ?: [];
        $sendable = 0;
        $locked = 0;
        foreach ($rows as $i => $row) {
            $reason = '';
            $isLocked = is_report_locked_for_student($row, $reason);
            if ($isLocked && trim((string)$reason) === '') $reason = report_lock_generic_reason();
            $rows[$i]['broadcast_locked'] = $isLocked;
            $rows[$i]['broadcast_lock_reason'] = $isLocked ? trim((string)$reason) : '';
            if ($isLocked) { $locked++; } else { $sendable++; }
        }
        return ['rows' => $rows, 'sendable' => $sendable, 'locked' => $locked];
    }
}

if (!function_exists('broadcast_report_text')) {
    function broadcast_report_text($reportId) {
        $report = DB::fetch("SELECT r.*, s.first_name, s.last_name FROM reports r JOIN students s ON s.id=r.student_id WHERE r.id=?", [$reportId]);
        if (!$report) return '';
        $grades = DB::fetchAll("SELECT subject_name, score FROM report_grades WHERE report_id=? ORDER BY id", [$reportId]);
        $txt = "🎓 کارنامه " . trim($report['first_name'].' '.$report['last_name']) . "\n";
        $txt .= "📅 " . $report['term'] . ' - ' . $report['report_month'] . "\n⭐ معدل: " . format_score($report['gpa']) . "\n";
        if ($report['discipline_score'] !== null && $report['discipline_score'] !== '') $txt .= "✨ انضباط: " . format_score($report['discipline_score']) . "\n";
        foreach ($grades as $g) $txt .= "\n▪️ " . $g['subject_name'] . ': ' . display_score($g['score']);
        return $txt;
    }
}

/* ─────────────── ورودی‌های مشترک فرم و ارسال ─────────────── */
$yearOptions = get_academic_years_for_filter();
$monthRows   = DB::fetchAll("SELECT DISTINCT report_month FROM reports WHERE report_month<>'' ORDER BY report_month DESC") ?: [];

$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$selYear  = unify_academic_year(trim((string)($request['academic_year'] ?? '')));
if ($selYear === '' && $yearOptions) {
    $firstYear = $yearOptions[0];
    $selYear = unify_academic_year(is_array($firstYear) ? (string)($firstYear['academic_year'] ?? '') : (string)$firstYear);
}
$selMonth = trim((string)($request['report_month'] ?? ($monthRows[0]['report_month'] ?? '')));
$selScope = (string)($request['scope'] ?? 'all');
if (!array_key_exists($selScope, rb_scope_options())) $selScope = 'all';
$selGrade = trim((string)($request['grade_level'] ?? ''));
$selClass = trim((string)($request['class_name'] ?? ''));
if ($selScope === 'grade' && $selGrade === '' && $selClass !== '') $selScope = 'class';
if ($selScope === 'class' && $selClass === '' && $selGrade !== '') $selScope = 'grade';

/* پایه/کلاس‌های موجود برای همین سال — فقط برای پر کردن فهرست‌ها. */
$gradeOptions = [];
$classOptions = [];
try {
    if (function_exists('get_unified_grade_options')) {
        foreach (get_unified_grade_options($selYear) as $g) {
            $name = is_array($g) ? (string)($g['grade_level'] ?? '') : (string)$g;
            if ($name !== '') $gradeOptions[$name] = $name;
        }
    }
} catch (Throwable $e) { /* فهرست خالی، فیلتر دستی نمی‌شود */ }
try {
    if (function_exists('get_unified_class_options')) {
        foreach (get_unified_class_options($selYear) as $c) {
            $name = is_array($c) ? (string)($c['class_name'] ?? '') : (string)$c;
            if ($name !== '') $classOptions[$name] = $name;
        }
    }
} catch (Throwable $e) { /* همان بالا */ }
if (!$gradeOptions) {
    foreach (DB::fetchAll("SELECT DISTINCT grade_level FROM students WHERE grade_level<>'' ORDER BY grade_level") ?: [] as $g) {
        if (trim((string)$g['grade_level']) !== '') $gradeOptions[trim((string)$g['grade_level'])] = trim((string)$g['grade_level']);
    }
}
if (!$classOptions) {
    foreach (DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE class_name<>'' ORDER BY class_name") ?: [] as $c) {
        if (trim((string)$c['class_name']) !== '') $classOptions[trim((string)$c['class_name'])] = trim((string)$c['class_name']);
    }
}

$bundle = rb_collect_reports($selYear, $selMonth, $selScope, $selGrade, $selClass);
$reportRows  = $bundle['rows'];
$sendableCnt = $bundle['sendable'];
$lockedCnt   = $bundle['locked'];
$scopeLabel  = rb_scope_options()[$selScope];
if ($selScope === 'grade' && $selGrade !== '') $scopeLabel .= ' — پایهٔ ' . $selGrade;
if ($selScope === 'class' && $selClass !== '') $scopeLabel .= ' — کلاس ' . $selClass;

/* ─────────────── ارسال ─────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'اعتبار فرم منقضی شده است؛ صفحه را تازه کنید.');
        redirect('report-broadcast.php');
    }
    $requested = $_POST['platforms'] ?? [];
    if (!is_array($requested)) $requested = [];
    $platforms = [];
    foreach ($requested as $pf) {
        $pf = trim((string)$pf);
        if (isset(rb_platforms()[$pf]) && !in_array($pf, $platforms, true)) $platforms[] = $pf;
    }
    if (!$platforms) {
        set_flash_message('error', 'حداقل یک ربات (بله، تلگرام یا روبیکا) را انتخاب کنید.');
        redirect('report-broadcast.php?' . http_build_query(['academic_year' => $selYear, 'report_month' => $selMonth, 'scope' => $selScope, 'grade_level' => $selGrade, 'class_name' => $selClass]));
    }

    $sent = 0; $failed = 0; $noLink = 0; $attempts = 0; $skipped = 0;
    foreach ($reportRows as $r) {
        /* حتی اگر بین پیش‌نمایش و کلیک، قفل تازه‌ای ثبت شده باشد، دوباره
           سنجیده می‌شود — تصمیم قفل در همین لحظه گرفته می‌شود. */
        $reason = '';
        if (is_report_locked_for_student($r, $reason)) { $skipped++; continue; }
        foreach ($platforms as $pf) {
            $table = bot_user_table($pf);
            /* V4.214.0 — ستون چت از خودِ پیام‌رسان گرفته می‌شود؛ روبیکا دیگر
               به‌اشتباه از ستون بله خوانده نمی‌شود. */
            $chatCol = bot_chat_column($pf);
            $links = DB::fetchAll("SELECT DISTINCT `$chatCol` chat_id FROM `$table` WHERE student_id=?", [$r['student_id']]) ?: [];
            if (!$links) { $noLink++; continue; }
            foreach ($links as $ln) {
                $attempts++;
                try {
                    $txt = broadcast_report_text($r['id']);
                    if ($txt) bot_send_message($pf, $ln['chat_id'], $txt);
                    bot_send_report_image_to_chat($pf, $ln['chat_id'], $r['id']);
                    $sent++;
                } catch (Exception $e) {
                    $failed++;
                    bot_log_send($pf, $ln['chat_id'] ?? '', $r['student_id'], 'report_broadcast', 'report_id='.$r['id'], 'failed', $e->getMessage());
                }
            }
        }
    }
    log_activity($_SESSION['admin_id'] ?? null, 'اعلام سراسری کارنامه',
        "{$selYear} / {$selMonth} — گستره: {$scopeLabel} — قفل‌شده: {$skipped}، موفق: {$sent}، ناموفق: {$failed}");
    set_flash_message($failed ? 'warning' : 'success',
        'اعلام سراسری انجام شد. گستره: ' . $scopeLabel
        . ' — کارنامه‌ها: ' . count($reportRows)
        . '، قفل‌شده (ارسال نشد): ' . $skipped
        . '، تلاش ارسال: ' . $attempts
        . '، موفق: ' . $sent
        . '، ناموفق: ' . $failed
        . '، بدون اتصال ربات: ' . $noLink
    );
    redirect('report-broadcast.php?' . http_build_query(['academic_year' => $selYear, 'report_month' => $selMonth, 'scope' => $selScope, 'grade_level' => $selGrade, 'class_name' => $selClass]));
}

require_once __DIR__ . '/includes/header.php';
$previewLimit = 25;
?>
<div class="space-y-4 rb-wrap">
    <div class="card">
        <h2 class="font-bold text-lg">اعلام سراسری پایه‌ای و کلاسی</h2>
        <p class="text-xs text-muted" style="line-height:2;margin-top:6px">
            گسترهٔ ارسال را انتخاب کنید؛ پیش از ارسال، همین صفحه نشان می‌دهد چند کارنامه در آن گستره هست و
            چند کارنامه <b>قفل</b> است. کارنامه‌های قفل‌شده — چه قفل فردی و چه قفل گروهی — هرگز ارسال نمی‌شوند.
            برای هر کارنامه ابتدا متن نمرات و سپس تصویر کارنامه همراه دکمه «بررسی شد» فرستاده می‌شود.
        </p>
        <p class="text-xs text-muted" style="line-height:2;margin-top:2px">
            ارسال از سه پیام‌رسان انجام می‌شود: <b>بله</b>، <b>تلگرام</b> و <b>روبیکا</b>.
            تصویر کارنامهٔ ارسالی، وضعیت هر نمره را با طیف رنگ (از بحرانی تا عالی) نشان می‌دهد و
            دکمهٔ «دریافت نسخهٔ PDF» همان تصویر را به‌صورت PDF می‌فرستد.
        </p>
    </div>

    <form method="GET" class="card rb-filters" data-no-ajax="1">
        <div><label for="rbYear">سال تحصیلی</label>
            <select id="rbYear" name="academic_year" class="form-select" onchange="this.form.submit()">
                <?php if (!$yearOptions): ?><option value="<?php echo clean($selYear); ?>"><?php echo clean($selYear ?: '—'); ?></option><?php endif; ?>
                <?php foreach ($yearOptions as $y):
                    $yv = is_array($y) ? (string)($y['academic_year'] ?? '') : (string)$y;
                    if ($yv === '') continue; ?>
                    <option value="<?php echo clean($yv); ?>" <?php echo $yv === $selYear ? 'selected' : ''; ?>><?php echo clean(tr_num($yv, 'fa')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="rbMonth">ماه</label>
            <select id="rbMonth" name="report_month" class="form-select" onchange="this.form.submit()">
                <?php foreach ($monthRows as $m): ?>
                    <option value="<?php echo clean($m['report_month']); ?>" <?php echo $m['report_month'] === $selMonth ? 'selected' : ''; ?>><?php echo clean($m['report_month']); ?></option>
                <?php endforeach; ?>
                <?php if (!$monthRows): ?><option value="">ماهی با کارنامه ثبت نشده</option><?php endif; ?>
            </select>
        </div>
        <div><label for="rbScope">گسترهٔ اعلام</label>
            <select id="rbScope" name="scope" class="form-select" onchange="this.form.submit()">
                <?php foreach (rb_scope_options() as $key => $label): ?>
                    <option value="<?php echo clean($key); ?>" <?php echo $key === $selScope ? 'selected' : ''; ?>><?php echo clean($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="rbGrade">پایه<?php echo $selScope === 'grade' ? '' : ' (فقط در گسترهٔ پایه)'; ?></label>
            <select id="rbGrade" name="grade_level" class="form-select" onchange="this.form.submit()" <?php echo $selScope === 'grade' ? '' : 'disabled'; ?>>
                <option value="">همهٔ پایه‌ها</option>
                <?php foreach ($gradeOptions as $g): ?>
                    <option value="<?php echo clean($g); ?>" <?php echo $g === $selGrade ? 'selected' : ''; ?>><?php echo clean($g); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><label for="rbClass">کلاس<?php echo $selScope === 'class' ? '' : ' (فقط در گسترهٔ کلاس)'; ?></label>
            <select id="rbClass" name="class_name" class="form-select" onchange="this.form.submit()" <?php echo $selScope === 'class' ? '' : 'disabled'; ?>>
                <option value="">همهٔ کلاس‌ها</option>
                <?php foreach ($classOptions as $c): ?>
                    <option value="<?php echo clean($c); ?>" <?php echo $c === $selClass ? 'selected' : ''; ?>><?php echo clean($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="rb-apply"><button class="btn btn-primary" type="submit">نمایش گستره</button></div>
    </form>

    <div class="card rb-summary">
        <div class="rb-stat"><span class="rb-stat-label">گستره</span><b><?php echo clean($scopeLabel); ?></b></div>
        <div class="rb-stat"><span class="rb-stat-label">کارنامه‌های این ماه</span><b><?php echo tr_num(count($reportRows), 'fa'); ?></b></div>
        <div class="rb-stat rb-stat-ok"><span class="rb-stat-label">قابل ارسال</span><b><?php echo tr_num($sendableCnt, 'fa'); ?></b></div>
        <div class="rb-stat rb-stat-lock"><span class="rb-stat-label">قفل (ارسال نمی‌شود)</span><b><?php echo tr_num($lockedCnt, 'fa'); ?></b></div>
        <div class="rb-stat"><span class="rb-stat-label">ماه</span><b><?php echo clean($selMonth ?: '—'); ?></b></div>
        <div class="rb-stat"><span class="rb-stat-label">سال</span><b><?php echo clean(tr_num($selYear, 'fa')); ?></b></div>
    </div>

    <form method="POST" class="card" data-no-ajax="1">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="academic_year" value="<?php echo clean($selYear); ?>">
        <input type="hidden" name="report_month" value="<?php echo clean($selMonth); ?>">
        <input type="hidden" name="scope" value="<?php echo clean($selScope); ?>">
        <input type="hidden" name="grade_level" value="<?php echo clean($selScope === 'grade' ? $selGrade : ''); ?>">
        <input type="hidden" name="class_name" value="<?php echo clean($selScope === 'class' ? $selClass : ''); ?>">
        <div class="rb-platforms">
            <span class="rb-platforms-title">ارسال با:</span>
            <?php foreach (rb_platforms() as $rbPfKey => $rbPfLabel): ?>
                <label class="rb-platform"><input type="checkbox" name="platforms[]" value="<?php echo clean($rbPfKey); ?>" checked> <?php echo clean($rbPfLabel); ?></label>
            <?php endforeach; ?>
        </div>
        <div class="rb-actions">
            <button class="btn btn-success" type="submit" <?php echo $sendableCnt ? '' : 'disabled'; ?>>ارسال کارنامه‌های ماه انتخابی</button>
            <span class="text-xs text-muted">
                <?php if (!$sendableCnt): ?>
                    برای این گستره کارنامهٔ آزادی وجود ندارد.
                <?php else: ?>
                    <?php echo tr_num($sendableCnt, 'fa'); ?> کارنامه ارسال می‌شود<?php echo $lockedCnt ? ' و ' . tr_num($lockedCnt, 'fa') . ' کارنامهٔ قفل‌شده کنار گذاشته می‌شود' : ''; ?>.
                <?php endif; ?>
            </span>
        </div>
    </form>

    <div class="card table-container">
        <table>
            <thead>
                <tr>
                    <th>دانش‌آموز</th>
                    <th>پایه / کلاس</th>
                    <th>نوبت / ماه</th>
                    <th>معدل</th>
                    <th>وضعیت ارسال</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($reportRows, 0, $previewLimit) as $r): ?>
                    <tr>
                        <td><b><?php echo clean(trim((string)$r['first_name'] . ' ' . (string)$r['last_name'])); ?></b></td>
                        <td class="text-xs"><?php echo clean(trim((string)($r['grade_level'] ?? '')) ?: '—'); ?> / <?php echo clean(trim((string)($r['student_class'] ?? '')) ?: '—'); ?></td>
                        <td class="text-xs"><?php echo clean($r['term'] . ' / ' . $r['report_month']); ?></td>
                        <td class="font-mono text-xs"><?php echo format_score($r['gpa']); ?></td>
                        <td>
                            <?php if (!empty($r['broadcast_locked'])): ?>
                                <span class="badge badge-danger" title="<?php echo clean($r['broadcast_lock_reason']); ?>">قفل — ارسال نمی‌شود</span>
                                <?php if (trim((string)$r['broadcast_lock_reason']) !== ''): ?>
                                    <div class="text-[11px] text-muted" style="margin-top:4px">علت: <?php echo clean(mb_strlen((string)$r['broadcast_lock_reason']) > 60 ? mb_substr((string)$r['broadcast_lock_reason'], 0, 60) . '…' : (string)$r['broadcast_lock_reason']); ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-success">آزاد — ارسال می‌شود</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$reportRows): ?>
                    <tr><td colspan="5" class="text-center text-muted">برای این سال و ماه، کارنامه‌ای در گسترهٔ انتخابی ثبت نشده است.</td></tr>
                <?php elseif (count($reportRows) > $previewLimit): ?>
                    <tr><td colspan="5" class="text-center text-muted">و <?php echo tr_num(count($reportRows) - $previewLimit, 'fa'); ?> ردیف دیگر…</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<style>
.rb-wrap .rb-filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;align-items:end}
.rb-wrap .rb-filters label{display:block;font-size:11px;font-weight:800;color:#475569;margin-bottom:4px}
.rb-wrap .rb-apply .btn{width:100%}
.rb-wrap .rb-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.rb-wrap .rb-stat{border:1px solid #e2e8f0;border-radius:10px;padding:8px 12px;background:#f8fafc;display:flex;flex-direction:column;gap:2px}
.rb-wrap .rb-stat-label{font-size:11px;color:#64748b;font-weight:700}
.rb-wrap .rb-stat b{font-size:14px}
.rb-wrap .rb-stat-ok{border-color:#bbf7d0;background:#f0fdf4}
.rb-wrap .rb-stat-lock{border-color:#fecaca;background:#fef2f2}
.rb-wrap .rb-platforms{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:12px}
.rb-wrap .rb-platforms-title{font-size:12px;font-weight:800;color:#475569}
.rb-wrap .rb-platform{display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700}
.rb-wrap .rb-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px}
@media (max-width:640px){.rb-wrap .rb-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
