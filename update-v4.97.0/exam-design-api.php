<?php
// File: exam-design-api.php
/**
 * JSON API for saving/loading exam live designs and question bank.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/exams_helper.php';
ensure_exams_schema();
header('Content-Type: application/json; charset=utf-8');

function json_out($ok, $data = []) { echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE); exit; }
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$designTokenOk = verify_exam_design_token($_GET['dt'] ?? $_POST['dt'] ?? '', $examId);
if (!$examId || (!$designTokenOk && !exam_can_design($examId))) json_out(false, ['error'=>'دسترسی غیرمجاز']);
$exam = DB::fetch("SELECT es.*, COALESCE(t.full_name, es.teacher_name) AS t_name FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?", [$examId]);
if (!$exam) json_out(false, ['error'=>'آزمون یافت نشد']);

/* v4.97.0: تطبیق موضوع درس — بانک فقط سوالات/آزمون‌های هم‌درس با آزمونِ در حال طراحی را نشان می‌دهد.
   نرمال‌سازی: ی/ک عربی→فارسی، حذف نیم‌فاصله و فاصله‌ها؛ تطبیق دوطرفه (شامل‌بودن) تا
   «قرآن» و «آموزش قرآن» یکی حساب شوند ولی «ریاضی» برای «علوم» نیاید. */
function exam_subject_norm($t) {
    $t = str_replace(["\u{064A}", "\u{0643}"], ['ی', 'ک'], (string)$t);
    $t = str_replace(["\u{200C}", ' ', "\t"], '', $t);
    return mb_strtolower(trim($t), 'UTF-8');
}
function exam_subject_matches($a, $b) {
    $a = exam_subject_norm($a); $b = exam_subject_norm($b);
    if ($a === '' || $b === '') return true;   // درس نامشخص → فیلتر نکن
    return $a === $b || mb_strpos($a, $b, 0, 'UTF-8') !== false || mb_strpos($b, $a, 0, 'UTF-8') !== false;
}

if ($action === 'load') {
    $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
    $curSubject = trim((string)($exam['subject_name'] ?? ''));
    $params = [];
    $where = ["1=1"];
    if (!empty($_GET['subject'])) { $where[] = "(subject_name LIKE ? OR question_html LIKE ?)"; $params[] = '%' . trim($_GET['subject']) . '%'; $params[] = '%' . trim($_GET['subject']) . '%'; }
    if (!empty($_GET['year'])) { $where[] = "academic_year=?"; $params[] = trim($_GET['year']); }
    if (!empty($_GET['month'])) { $where[] = "exam_month=?"; $params[] = trim($_GET['month']); }
    if (!empty($_GET['type'])) { $where[] = "question_type=?"; $params[] = trim($_GET['type']); }
    if (!empty($_GET['designer'])) { $where[] = "designer_name=?"; $params[] = trim($_GET['designer']); } /* v4.96.0: فیلتر انتخابی دقیق */
    $bank = DB::fetchAll("SELECT * FROM exam_question_bank WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1000", $params);
    /* v4.97.0: فقط سوالات هم‌درس با آزمون در حال طراحی */
    if ($curSubject !== '') {
        $bank = array_values(array_filter($bank, function ($b0) use ($curSubject) { return exam_subject_matches($b0['subject_name'] ?? '', $curSubject); }));
        if (count($bank) > 300) $bank = array_slice($bank, 0, 300);
    }
    /* v4.95.0: آزمون‌های طراحی‌شده (با فایل منبع تصویری) هم در بانک نمایش داده می‌شوند */
    $dWhere = ["1=1"]; $dParams = [];
    if (!empty($_GET['year']))     { $dWhere[] = "es.academic_year=?";  $dParams[] = trim($_GET['year']); }
    if (!empty($_GET['month']))    { $dWhere[] = "es.exam_month=?";     $dParams[] = trim($_GET['month']); }
    if (!empty($_GET['designer'])) { $dWhere[] = "ed.designer_name=?"; $dParams[] = trim($_GET['designer']); }
    if (!empty($_GET['subject']))  { $dWhere[] = "es.subject_name LIKE ?";  $dParams[] = '%' . trim($_GET['subject']) . '%'; }
    $designRows = DB::fetchAll("SELECT ed.exam_id, ed.design_json, ed.designer_name, ed.updated_at_jalali, es.subject_name, es.exam_month, es.academic_year, es.grade_level, es.class_name, es.question_file FROM exam_designs ed JOIN exam_schedules es ON es.id=ed.exam_id WHERE " . implode(' AND ', $dWhere) . " ORDER BY ed.id ASC LIMIT 400", $dParams);
    /* v4.96.0: فقط اولین طراحی هر منبع در بانک می‌ماند — نسخه‌های استفاده مجدد
       (درج از بانک، تکثیر پایه‌ای، کپی کلاسی) با نشانه origin.txt یا اثرانگشت
       یکسان محتوا رد می‌شوند تا هر آزمون فقط یک بار ذخیره شود. */
    $designBank = []; $seenSrc = [];
    foreach ($designRows as $dr) {
        $eid = (int)$dr['exam_id'];
        if ($eid === $examId) continue;              // خود آزمون جاری نمایش داده نشود
        if ($curSubject !== '' && !exam_subject_matches($dr['subject_name'] ?? '', $curSubject)) continue; // v4.97.0: فقط هم‌درس
        $cacheDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $eid;
        if (is_file($cacheDir . '/origin.txt')) continue;   // نسخه استفاده مجدد — منبع اصلی جای دیگر ثبت است
        $pages = [];
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $pg) $pages[] = str_replace(__DIR__ . '/', '', $pg);
        sort($pages);
        if (!$pages) {
            $qf = (string)($dr['question_file'] ?? '');
            $qext = $qf !== '' ? strtolower(pathinfo($qf, PATHINFO_EXTENSION)) : '';
            if (in_array($qext, ['jpg','jpeg','png','webp'], true) && is_file(__DIR__ . '/' . ltrim($qf, '/'))) $pages = [$qf];
        }
        if (!$pages) continue;                       // فقط آزمون‌های دارای منبع تصویری
        $sig = @md5_file(__DIR__ . '/' . $pages[0]) . '|' . count($pages);
        if (isset($seenSrc[$sig])) continue;         // همان منبع قبلاً (قدیمی‌تر) ثبت شده
        $seenSrc[$sig] = 1;
        $dj = json_decode((string)($dr['design_json'] ?? ''), true) ?: [];
        $designBank[] = [
            'exam_id' => $eid,
            'subject' => (string)$dr['subject_name'],
            'designer' => teacher_respectful_name((string)($dr['designer_name'] ?? '')),
            'month' => (string)($dr['exam_month'] ?? ''),
            'year' => (string)($dr['academic_year'] ?? ''),
            'grade' => (string)($dr['grade_level'] ?? ''),
            'class' => (string)($dr['class_name'] ?? ''),
            'saved_at' => (string)($dr['updated_at_jalali'] ?? ''),
            'pages' => $pages,
            // v4.96.0: تنظیمات چینش/برش/روشنایی/کنتراست/پس‌زمینه/اندازه صفحات
            'crops' => (isset($dj['sourceCrops']) && is_array($dj['sourceCrops']) && $dj['sourceCrops']) ? $dj['sourceCrops'] : new stdClass(),
            'order' => (isset($dj['sourceOrder']) && is_array($dj['sourceOrder'])) ? array_values($dj['sourceOrder']) : [],
        ];
    }
    $designBank = array_reverse($designBank);        // نمایش: جدیدترین اول
    /* v4.96.0: گزینه‌های فیلترهای انتخابی (سال/ماه/طراح) از هر دو منبع بانک */
    $fYears = []; $fMonths = []; $fDesigners = [];
    /* v4.97.0: گزینه‌های فیلتر هم فقط از داده‌های هم‌درس با آزمون جاری ساخته می‌شوند */
    foreach (DB::fetchAll("SELECT academic_year, exam_month, designer_name, subject_name FROM exam_question_bank UNION ALL SELECT es.academic_year, es.exam_month, ed.designer_name, es.subject_name FROM exam_designs ed JOIN exam_schedules es ON es.id=ed.exam_id") as $r0) {
        if ($curSubject !== '' && !exam_subject_matches($r0['subject_name'] ?? '', $curSubject)) continue;
        if (($r0['academic_year'] ?? '') !== '') $fYears[] = (string)$r0['academic_year'];
        if (($r0['exam_month'] ?? '') !== '')    $fMonths[] = (string)$r0['exam_month'];
        if (($r0['designer_name'] ?? '') !== '') $fDesigners[] = (string)$r0['designer_name'];
    }
    $fYears = array_values(array_unique($fYears)); $fMonths = array_values(array_unique($fMonths)); $fDesigners = array_values(array_unique($fDesigners));
    rsort($fYears);
    $mOrder = ['مهر'=>1,'آبان'=>2,'آذر'=>3,'دی'=>4,'بهمن'=>5,'اسفند'=>6,'فروردین'=>7,'اردیبهشت'=>8,'خرداد'=>9,'تیر'=>10,'مرداد'=>11,'شهریور'=>12];
    usort($fMonths, function ($a, $b) use ($mOrder) { return ($mOrder[$a] ?? 99) <=> ($mOrder[$b] ?? 99); });
    sort($fDesigners);
    $fDesigners = array_map(function ($d) { return ['v'=>$d, 'label'=>teacher_respectful_name($d)]; }, $fDesigners);
    json_out(true, ['design'=>$design ? json_decode($design['design_json'], true) : null, 'bank'=>$bank, 'designBank'=>$designBank,
                    'subject'=>$curSubject,
                    'filters'=>['years'=>array_values(array_unique($fYears)), 'months'=>array_values(array_unique($fMonths)), 'designers'=>$fDesigners]]);
}

/* v4.95.0: درج یک آزمون ذخیره‌شده از بانک در ویرایشگر زنده آزمون فعلی:
   تصاویر صفحات منبع به کش آزمون فعلی کپی و کل طراحی (سوالات، برش‌ها،
   ترتیب صفحات و تنظیمات) برگردانده می‌شود. */
if ($action === 'import_design') {
    $srcId = (int)($_POST['source_exam_id'] ?? 0);
    if ($srcId <= 0) json_out(false, ['error'=>'آزمون منبع نامعتبر است']);
    $srcDesign = DB::fetch("SELECT design_json FROM exam_designs WHERE exam_id=?", [$srcId]);
    $srcPages = [];
    foreach (glob(__DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId . '/page_*.jpg') ?: [] as $pg) $srcPages[] = $pg;
    sort($srcPages);
    if (!$srcPages) {
        $srcExam = DB::fetch("SELECT question_file FROM exam_schedules WHERE id=?", [$srcId]);
        $qf = (string)($srcExam['question_file'] ?? '');
        if ($qf !== '' && in_array(strtolower(pathinfo($qf, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'], true) && is_file(__DIR__ . '/' . ltrim($qf, '/'))) $srcPages = [__DIR__ . '/' . ltrim($qf, '/')];
    }
    // کپی تصاویر منبع به کش آزمون فعلی (جایگزین منبع قبلی این آزمون)
    $dstDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $examId;
    if (!is_dir($dstDir)) @mkdir($dstDir, 0755, true);
    foreach (glob($dstDir . '/page_*.jpg') ?: [] as $oldp) @unlink($oldp);
    $newPages = [];
    foreach ($srcPages as $i => $sp) {
        $dst = $dstDir . '/page_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . '.jpg';
        if (@copy($sp, $dst)) $newPages[] = str_replace(__DIR__ . '/', '', $dst);
    }
    if ($newPages) {
        // مرجع منبع: کش تصویری (question_file لازم نیست فایل موجود باشد — v4.94.0)
        DB::execute('UPDATE exam_schedules SET question_file=? WHERE id=?', ['uploads/exams/pdf-pages/exam_' . $examId . '/source.pdf', $examId]);
        /* v4.96.0: نشانه استفاده مجدد — این آزمون کپی منبع است و دوباره در بانک ثبت نمی‌شود */
        $srcDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId;
        $origin = is_file($srcDir . '/origin.txt') ? trim((string)@file_get_contents($srcDir . '/origin.txt')) : (string)$srcId;
        @file_put_contents($dstDir . '/origin.txt', $origin !== '' ? $origin : (string)$srcId);
    }
    json_out(true, [
        'design' => $srcDesign ? json_decode($srcDesign['design_json'], true) : null,
        'sourcePages' => $newPages,
        'message' => 'آزمون انتخابی از بانک در ویرایشگر درج شد.',
    ]);
}

/* v4.95.0: حذف آزمون ذخیره‌شده از بانک (فقط مدیر) — طراحی + تصاویر منبع آن آزمون */
if ($action === 'delete_design') {
    if (!is_admin_logged_in()) json_out(false, ['error'=>'فقط مدیر مجاز است']);
    $srcId = (int)($_POST['source_exam_id'] ?? 0);
    if ($srcId <= 0) json_out(false, ['error'=>'آزمون نامعتبر']);
    DB::execute("DELETE FROM exam_designs WHERE exam_id=?", [$srcId]);
    foreach (glob(__DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId . '/page_*.jpg') ?: [] as $pg) @unlink($pg);
    json_out(true, ['message'=>'آزمون از بانک حذف شد.']);
}

if ($action === 'save') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!$payload) json_out(false, ['error'=>'داده نامعتبر']);
    $design = $payload['design'] ?? [];
    $questions = $design['questions'] ?? [];
    $designerTeacherId = !empty($_SESSION['teacher_id']) ? (int)$_SESSION['teacher_id'] : ($exam['teacher_id'] ?: null);
    $designerName = $exam['t_name'] ?: ($_SESSION['teacher_name'] ?? '');
    if (!is_admin_logged_in() && !empty($_SESSION['teacher_name'])) $designerName = $_SESSION['teacher_name'];
    $json = json_encode($design, JSON_UNESCAPED_UNICODE);
    $exists = DB::fetch("SELECT id FROM exam_designs WHERE exam_id=?", [$examId]);
    if ($exists) DB::execute("UPDATE exam_designs SET design_json=?, designer_teacher_id=?, designer_name=?, saved_by_admin_id=?, updated_at_jalali=? WHERE exam_id=?", [$json,$designerTeacherId,$designerName,$_SESSION['admin_id']??null,jalali_now(),$examId]);
    else DB::execute("INSERT INTO exam_designs (exam_id, design_json, designer_teacher_id, designer_name, saved_by_admin_id, updated_at_jalali) VALUES (?,?,?,?,?,?)", [$examId,$json,$designerTeacherId,$designerName,$_SESSION['admin_id']??null,jalali_now()]);

    // Archive every designed question in the bank while preserving original designer.
    // Deduplication: exact/near-exact question bodies are stored only once per subject/type/score.
    $normalizeQuestion = function($html) {
        $html = preg_replace('/<div class="q-actions".*?<\/div>/is', '', (string)$html);
        $html = preg_replace('/\s+/', ' ', trim($html));
        return sha1(mb_strtolower($html, 'UTF-8'));
    };
    foreach ($questions as $q) {
        if (($q['type'] ?? '') !== 'q') continue;
        $qid = $q['bank_id'] ?? null;
        $qhtml = $q['html'] ?? '';
        if (trim(strip_tags($qhtml)) === '' && strpos($qhtml, '<img') === false) continue;
        if ($qid && is_admin_logged_in()) {
            DB::execute("UPDATE exam_question_bank SET question_html=?, score=?, question_type=?, meta_json=?, updated_at_jalali=? WHERE id=?", [$qhtml,$q['score']??'', $q['qtype']??'text', json_encode($q,JSON_UNESCAPED_UNICODE), jalali_now(), (int)$qid]);
        } elseif (!$qid) {
            $hash = $normalizeQuestion($qhtml);
            $candidates = DB::fetchAll("SELECT id, question_html FROM exam_question_bank WHERE subject_name=? AND question_type=? AND COALESCE(score,'')=? ORDER BY id DESC LIMIT 500", [$exam['subject_name'], $q['qtype']??'text', $q['score']??'']);
            $duplicateId = null;
            foreach ($candidates as $cand) {
                if ($normalizeQuestion($cand['question_html']) === $hash) { $duplicateId = (int)$cand['id']; break; }
            }
            if (!$duplicateId) {
                DB::execute("INSERT INTO exam_question_bank (source_exam_id, academic_year, exam_month, subject_name, teacher_id, designer_name, question_type, question_html, score, meta_json, created_at_jalali) VALUES (?,?,?,?,?,?,?,?,?,?,?)", [$examId,$exam['academic_year'],$exam['exam_month'],$exam['subject_name'],$designerTeacherId,$designerName,$q['qtype']??'text',$qhtml,$q['score']??'',json_encode($q,JSON_UNESCAPED_UNICODE),jalali_now()]);
            }
        }
    }
    /* v4.88.0: a grade-wide design saved on one class's exam must be ready
       for the sibling classes of the same grade too — replicate it now so
       the print tab and the bot PDF cover every class automatically. */
    $replicated = 0;
    try {
        if (function_exists('exam_replicate_design_to_grade_siblings')) $replicated = (int)exam_replicate_design_to_grade_siblings($examId);
    } catch (Exception $e) { error_log('grade design replicate failed: ' . $e->getMessage()); }
    /* v4.87.0: alert executive deputies on both bots (subject, term, date,
       designer, print note + «دریافت نسخه PDF» button). Debounced inside. */
    try {
        require_once __DIR__ . '/includes/bot_role_engine.php';
        bot_notify_exam_design_saved($examId);
    } catch (Exception $e) { error_log('exec design notify failed: ' . $e->getMessage()); }
    json_out(true, ['message'=>'طراحی آزمون و سوالات در بانک اطلاعاتی ذخیره شد.' . ($replicated > 0 ? ' (برای ' . tr_num($replicated, 'fa') . ' کلاس دیگر همین پایه هم اعمال شد)' : '')]);
}

if ($action === 'delete_bank') {
    if (!is_admin_logged_in()) json_out(false, ['error'=>'فقط مدیر مجاز است']);
    DB::execute("DELETE FROM exam_question_bank WHERE id=?", [(int)($_POST['question_id'] ?? 0)]);
    json_out(true);
}

json_out(false, ['error'=>'عملیات نامعتبر']);
