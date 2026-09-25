<?php
// File: exam-design-api.php
/**
 * JSON API for saving/loading exam live designs and question bank.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/class_exam_groups.php';
ensure_exams_schema(); ceg_schema();

/* v4.100.0: ضد کش مرورگر — آدرس تصاویر صفحات با زمان تغییر فایل نسخه‌گذاری می‌شود
   تا بعد از تعویض فایل منبع، مرورگر صفحات قدیمی را از کش نشان ندهد. */
function exam_page_vurl($rel) {
    $full = __DIR__ . '/' . ltrim((string)$rel, '/');
    return is_file($full) ? ($rel . '?v=' . filemtime($full)) : $rel;
}

/* v4.160.1 — سرعت بارگذاری کاتالوگ بانک (آزمون‌های ذخیره‌شده + سوالات + فیلترها):
   ۱) کش ماندگار اثرانگشت: MD5 صفحهٔ اول هر منبع فقط یک‌بار برای هر نسخهٔ فایل
      (size+mtime) محاسبه می‌شود. خودِ اثرانگشت همان MD5 محتوای واقعی است، پس
      قاعدهٔ «فقط اولین طراحی هر منبع در بانک بماند» دقیقاً مثل قبل کار می‌کند.
   ۲) کش اسکن کاتالوگ: فهرست متادیتای بانک/طراحی‌ها + گزینه‌های فیلتر برای هر
      مجموعه فیلتر در یک فایل کوچک ذخیره می‌شود و با «نسخهٔ داده» (COUNT/MAX id/
      MAX تاریخ سه جدول) باطل می‌شود؛ هر ذخیره/حذف بلافاصله کش را تازه می‌کند و
      یک TTL کوتاه هم تغییرات فقط-فایلی را پوشش می‌دهد. نتیجه: ورق‌زدن صفحه‌های
      بانک دیگر کل دیسک/دیتابیس را جارو نمی‌کند.
   ۳) بندانگشتی سبک ۳۲۰px کنار صفحات ساخته و بازیابی می‌شود؛ بدون GD یا بدون
      مجوز نوشتن، همان تصویر اصلی برگردانده می‌شود (مکانیزم هیچ‌وقت نمی‌شکند). */
function exam_catalog_quiet(callable $fn) {
    /* I/O اختیاری کش هرگز نباید warning به خروجی نشت کند — حتی زیر
       error handler سخت‌گیرانه‌تر از خودِ @. */
    set_error_handler(function () { return true; });
    try { return $fn(); } finally { restore_error_handler(); }
}
function exam_catalog_sig_file() { return __DIR__ . '/uploads/exams/.catalog-signatures.json'; }
function &exam_catalog_sig_store() {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $file = exam_catalog_sig_file();
        $raw = exam_catalog_quiet(function () use ($file) { return is_file($file) ? file_get_contents($file) : ''; });
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $cache = $decoded;
        }
    }
    return $cache;
}
function exam_catalog_sig_commit() {
    $cache = &exam_catalog_sig_store();
    if (count($cache) > 5000) $cache = array_slice($cache, -2500, null, true);
    $json = json_encode($cache, JSON_UNESCAPED_UNICODE);
    if ($json === false) return;
    exam_catalog_quiet(function () use ($json) {
        $dir = __DIR__ . '/uploads/exams';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $file = exam_catalog_sig_file();
        $tmp = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) !== false) rename($tmp, $file);
        else unlink($tmp);
    });
}
function exam_page_signature_cached($rel) {
    $full = __DIR__ . '/' . ltrim((string)$rel, '/');
    [$size, $mtime] = exam_catalog_quiet(function () use ($full) {
        return is_file($full) ? [filesize($full), filemtime($full)] : [false, false];
    });
    $cache = &exam_catalog_sig_store();
    if ($size !== false && $mtime !== false) {
        $entry = $cache[$rel] ?? null;
        if (is_array($entry) && (int)($entry['s'] ?? -1) === (int)$size && (int)($entry['m'] ?? -1) === (int)$mtime && isset($entry['h'])) {
            return (string)$entry['h'];
        }
    }
    $hash = (string)exam_catalog_quiet(function () use ($full) { return is_file($full) ? md5_file($full) : ''; });
    if ($hash !== '' && $size !== false && $mtime !== false) {
        $cache[$rel] = ['s' => (int)$size, 'm' => (int)$mtime, 'h' => $hash];
        exam_catalog_sig_commit();
    }
    return $hash;
}
function exam_catalog_scan_file() { return __DIR__ . '/uploads/exams/.catalog-scan-cache.json'; }
function exam_catalog_data_version() {
    $parts = [];
    foreach ([['exam_question_bank','updated_at_jalali'], ['exam_designs','updated_at_jalali'], ['exam_design_archive','created_at_jalali']] as $t) {
        try {
            $row = DB::fetch('SELECT COUNT(*) AS c, MAX(id) AS i, MAX(' . $t[1] . ') AS u FROM ' . $t[0]);
            $parts[] = $t[0] . ':' . (int)($row['c'] ?? 0) . ':' . (int)($row['i'] ?? 0) . ':' . (string)($row['u'] ?? '');
        } catch (Throwable $e) { $parts[] = $t[0] . ':na'; }
    }
    $parts[] = 'grade:' . (exam_design_bank_has_grade_column() ? '1' : '0');
    return implode('|', $parts);
}
function exam_catalog_filter_key($curSubject, $curGrade) {
    return md5((string)json_encode([
        's' => trim((string)($_GET['subject'] ?? '')), 'y' => trim((string)($_GET['year'] ?? '')),
        'm' => trim((string)($_GET['month'] ?? '')), 't' => trim((string)($_GET['type'] ?? '')),
        'd' => trim((string)($_GET['designer'] ?? '')), 'cs' => $curSubject, 'cg' => $curGrade,
    ], JSON_UNESCAPED_UNICODE));
}
/* v4.163.0: the catalog scan is split into a cheap SQL half (bank questions +
   filter options) and the disk-heavy half (saved-design thumbnails). The page
   asks for each half separately ("part=q" / "part=d") so the question list can
   be on screen before the design thumbnails exist. Both halves share one cache
   file; entries are prefixed so they never collide. */
function exam_catalog_cache_read() {
    $file = exam_catalog_scan_file();
    $raw = exam_catalog_quiet(function () use ($file) { return is_file($file) ? file_get_contents($file) : ''; });
    $cache = (is_string($raw) && $raw !== '') ? (json_decode($raw, true) ?: []) : [];
    return is_array($cache) ? $cache : [];
}
function exam_catalog_cache_entry($cache, $version, $key) {
    if (($cache['version'] ?? '') !== $version) return null;
    $entry = $cache['entries'][$key] ?? null;
    if (!is_array($entry)) return null;
    if ((time() - (int)($entry['at'] ?? 0)) >= 80) return null;
    return $entry;
}
function exam_catalog_cache_put($cache, $version, $key, array $payload) {
    $entries = (($cache['version'] ?? '') === $version && isset($cache['entries']) && is_array($cache['entries'])) ? $cache['entries'] : [];
    foreach ($entries as $k => $e) { if ((time() - (int)($e['at'] ?? 0)) >= 80) unset($entries[$k]); }
    $entries[$key] = array_merge(['at' => time()], $payload);
    if (count($entries) > 24) $entries = array_slice($entries, -24, null, true);
    $file = exam_catalog_scan_file();
    $newCache = ['version' => $version, 'entries' => $entries];
    $encoded = json_encode($newCache, JSON_UNESCAPED_UNICODE);
    if ($encoded !== false) {
        exam_catalog_quiet(function () use ($encoded, $file) {
            $dir = __DIR__ . '/uploads/exams';
            if (!is_dir($dir)) mkdir($dir, 0775, true);
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (file_put_contents($tmp, $encoded, LOCK_EX) !== false) rename($tmp, $file);
            else unlink($tmp);
        });
    }
    return $newCache;
}
function exam_catalog_bank_scan_cached($curSubject, $curGrade) {
    $key = 'bank:' . exam_catalog_filter_key($curSubject, $curGrade);
    $version = exam_catalog_data_version();
    $cache = exam_catalog_cache_read();
    $entry = exam_catalog_cache_entry($cache, $version, $key);
    if ($entry !== null && isset($entry['bankMeta'], $entry['filters'])) {
        return ['bankMeta' => $entry['bankMeta'], 'filters' => $entry['filters']];
    }
    $scan = exam_catalog_bank_scan_compute($curSubject, $curGrade);
    exam_catalog_cache_put($cache, $version, $key, $scan);
    return $scan;
}
function exam_catalog_design_scan_cached($examId, $curSubject, $curGrade) {
    $key = 'design:' . $examId . ':' . exam_catalog_filter_key($curSubject, $curGrade);
    $version = exam_catalog_data_version();
    $cache = exam_catalog_cache_read();
    $entry = exam_catalog_cache_entry($cache, $version, $key);
    if ($entry !== null && isset($entry['designCandidates'])) {
        return ['designCandidates' => $entry['designCandidates']];
    }
    $scan = exam_catalog_design_scan_compute($examId, $curSubject, $curGrade);
    exam_catalog_cache_put($cache, $version, $key, $scan);
    return $scan;
}
function exam_catalog_scan_cached($examId, $curSubject, $curGrade) {
    $bank = exam_catalog_bank_scan_cached($curSubject, $curGrade);
    $design = exam_catalog_design_scan_cached($examId, $curSubject, $curGrade);
    return [
        'bankMeta' => $bank['bankMeta'],
        'designCandidates' => $design['designCandidates'],
        'filters' => $bank['filters'],
    ];
}
function exam_page_thumb_rel($rel) {
    /* بندانگشتی ۳۲۰px در مسیر مرکزی uploads/exams/page-thumbs — هیچ glob صفحه‌ای
       را تحت تأثیر قرار نمی‌دهد و با تغییر فایل منبع خودش تازه می‌شود. */
    if (!function_exists('imagejpeg') || !function_exists('imagecreatefromstring')) return '';
    $full = __DIR__ . '/' . ltrim((string)$rel, '/');
    if (!is_file($full)) return '';
    $ext = strtolower((string)pathinfo($full, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) return '';
    $thumbRel = 'uploads/exams/page-thumbs/' . md5((string)$rel) . '.jpg';
    return (string)exam_catalog_quiet(function () use ($rel, $full, $thumbRel) {
        $dir = __DIR__ . '/uploads/exams/page-thumbs';
        $thumbFull = __DIR__ . '/' . $thumbRel;
        $srcMtime = (int)filemtime($full);
        if (is_file($thumbFull) && (int)filemtime($thumbFull) >= $srcMtime) return $thumbRel;
        $data = file_get_contents($full);
        if (!is_string($data) || $data === '') return '';
        $src = imagecreatefromstring($data);
        if (!$src) return '';
        $w = imagesx($src); $h = imagesy($src);
        if ($w > 320 && $h > 0) {
            $nh = max(1, (int)round($h * 320 / $w));
            $dst = imagecreatetruecolor(320, $nh);
            if ($dst) { imagecopyresampled($dst, $src, 0, 0, 0, 0, 320, $nh, $w, $h); imagedestroy($src); $src = $dst; }
        }
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $tmp = $thumbFull . '.' . getmypid() . '.tmp';
        $ok = imagejpeg($src, $tmp, 72);
        imagedestroy($src);
        if (!$ok || !rename($tmp, $thumbFull)) { if (is_file($tmp)) unlink($tmp); return ''; }
        touch($thumbFull, max($srcMtime, time()));
        return $thumbRel;
    });
}
function exam_page_thumbs_for(array $rawPages) {
    $out = [];
    foreach ($rawPages as $rel) {
        $thumb = exam_page_thumb_rel((string)$rel);
        $out[] = ($thumb !== '' && is_file(__DIR__ . '/' . ltrim($thumb, '/'))) ? exam_page_vurl($thumb) : '';
    }
    return $out;
}
header('Content-Type: application/json; charset=utf-8');

function json_out($ok, $data = []) { ceg_write_end($ok); echo json_encode(array_merge(['ok'=>$ok], $data), JSON_UNESCAPED_UNICODE); exit; }
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$examId = (int)($_GET['exam_id'] ?? $_POST['exam_id'] ?? 0);
$designTokenOk = verify_exam_design_token($_GET['dt'] ?? $_POST['dt'] ?? '', $examId);
if (!$examId || (!$designTokenOk && !exam_can_design($examId))) json_out(false, ['error'=>'دسترسی غیرمجاز']);
$exam = DB::fetch("SELECT es.*, COALESCE(t.full_name, es.teacher_name) AS t_name FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?", [$examId]);
if (!$exam) json_out(false, ['error'=>'آزمون یافت نشد']);
if(($exam['exam_kind']??'')==='class_deleted')json_out(false,['error'=>'این آزمون کلاسی حذف شده است.']);
if(in_array($action,['save','import_design'],true))ceg_write_begin($exam);
$exam=DB::fetch('SELECT es.*,COALESCE(t.full_name,es.teacher_name) t_name FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?',[$examId]);
if(($exam['exam_kind']??'')==='class_deleted')json_out(false,['error'=>'این آزمون کلاسی حذف شده است.']);
$boundGroup=ceg_for_exam($exam);
if(ceg_pending_sync($exam,$boundGroup))json_out(false,['error'=>'اطلاعات گروه هنوز همگام نشده است.']);
if($boundGroup && !$boundGroup['excluded'] && (int)$boundGroup['design_exam_id']!==$examId) json_out(false,['error'=>'این کلاس اکنون عضو آزمون پایه است؛ صفحه را تازه‌سازی کنید.']);
if($boundGroup && $boundGroup['excluded'] && (int)$boundGroup['member_exam_id']===$examId && !empty($boundGroup['detached_exam_id']))json_out(false,['error'=>'این کلاس مستثنی شده است؛ صفحهٔ طراحی مستقل را باز کنید.']);
if($boundGroup && !$boundGroup['excluded'] && !empty($_GET['group_member'])) {
    if(!ceg_validate_member($boundGroup,(int)$_GET['group_member']))json_out(false,['error'=>'این کلاس دیگر عضو آزمون پایه نیست؛ صفحه را تازه‌سازی کنید.']);
}
if($action==='group_scope')json_out(true,['scope'=>$boundGroup&&!$boundGroup['excluded']?ceg_scope($boundGroup):'']);


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

/* v4.160.0: the live editor loads its design separately from the catalog.  The
   catalog path is deliberately paged: metadata is scanned without question
   bodies/design JSON, and only the five visible records are hydrated. */
function exam_design_bank_has_grade_column() {
    static $available = null;
    if ($available !== null) return $available;
    try {
        DB::fetch("SELECT grade_level FROM exam_question_bank LIMIT 1");
        $available = true;
    } catch (Throwable $e) {
        // Older installations are still valid; an absent optional column means
        // that all rows have the legacy "no grade" behaviour.
        $available = false;
    }
    return $available;
}
function exam_design_score_value($value) {
    $value = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], ['0','1','2','3','4','5','6','7','8','9'], (string)$value);
    $value = str_replace(['٫','/'], ['.','.'], trim($value));
    return is_numeric($value) ? (float)$value : INF;
}
function exam_design_catalog_page($key, $default = 1) {
    $value = (int)($_GET[$key] ?? $default);
    return max(1, min(10000, $value));
}

/* v4.163.0: نیمهٔ سبک (فقط SQL): متادیتای سوالات بانک + گزینه‌های فیلتر.
   هیچ دسترسی دیسکی ندارد تا فهرست سوال‌ها تقریباً آنی برسد. */
function exam_catalog_bank_scan_compute($curSubject, $curGrade) {
    $params = [];
    $where = ['1=1'];
    if (!empty($_GET['subject'])) {
        $where[] = '(subject_name LIKE ? OR question_html LIKE ?)';
        $params[] = '%' . trim($_GET['subject']) . '%';
        $params[] = '%' . trim($_GET['subject']) . '%';
    }
    if (!empty($_GET['year'])) { $where[] = 'academic_year=?'; $params[] = trim($_GET['year']); }
    if (!empty($_GET['month'])) { $where[] = 'exam_month=?'; $params[] = trim($_GET['month']); }
    if (!empty($_GET['type'])) { $where[] = 'question_type=?'; $params[] = trim($_GET['type']); }
    if (!empty($_GET['designer'])) { $where[] = 'designer_name=?'; $params[] = trim($_GET['designer']); }
    $gradeColumn = exam_design_bank_has_grade_column();
    $gradeSelect = $gradeColumn ? ', grade_level' : '';
    $bankMeta = DB::fetchAll(
        'SELECT id, source_exam_id, academic_year, exam_month, subject_name, teacher_id, designer_name, question_type, score, created_at_jalali, updated_at_jalali' . $gradeSelect .
        ' FROM exam_question_bank WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 1000',
        $params
    );
    if ($curSubject !== '') {
        $bankMeta = array_values(array_filter($bankMeta, function ($row) use ($curSubject) {
            return exam_subject_matches($row['subject_name'] ?? '', $curSubject);
        }));
    }
    if ($curGrade !== '' && $gradeColumn) {
        $bankMeta = array_values(array_filter($bankMeta, function ($row) use ($curGrade) {
            $grade = trim((string)($row['grade_level'] ?? ''));
            return $grade === '' || $grade === $curGrade;
        }));
    }
    usort($bankMeta, function ($a, $b) {
        $score = exam_design_score_value($a['score'] ?? '') <=> exam_design_score_value($b['score'] ?? '');
        return $score !== 0 ? $score : ((int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0));
    });
    $bankMeta = array_slice($bankMeta, 0, 300);


    $fYears = []; $fMonths = []; $fDesigners = [];
    foreach (DB::fetchAll(
        'SELECT academic_year, exam_month, designer_name, subject_name FROM exam_question_bank' .
        ' UNION ALL SELECT es.academic_year, es.exam_month, ed.designer_name, es.subject_name' .
        ' FROM exam_designs ed JOIN exam_schedules es ON es.id=ed.exam_id'
    ) as $filterRow) {
        if ($curSubject !== '' && !exam_subject_matches($filterRow['subject_name'] ?? '', $curSubject)) continue;
        if (($filterRow['academic_year'] ?? '') !== '') $fYears[] = (string)$filterRow['academic_year'];
        if (($filterRow['exam_month'] ?? '') !== '') $fMonths[] = (string)$filterRow['exam_month'];
        if (($filterRow['designer_name'] ?? '') !== '') $fDesigners[] = (string)$filterRow['designer_name'];
    }
    $fYears = array_values(array_unique($fYears)); $fMonths = array_values(array_unique($fMonths)); $fDesigners = array_values(array_unique($fDesigners));
    rsort($fYears);
    $monthOrder = ['مهر'=>1,'آبان'=>2,'آذر'=>3,'دی'=>4,'بهمن'=>5,'اسفند'=>6,'فروردین'=>7,'اردیبهشت'=>8,'خرداد'=>9,'تیر'=>10,'مرداد'=>11,'شهریور'=>12];
    usort($fMonths, function ($a, $b) use ($monthOrder) { return ($monthOrder[$a] ?? 99) <=> ($monthOrder[$b] ?? 99); });
    sort($fDesigners);
    $fDesigners = array_map(function ($designer) { return ['v'=>$designer, 'label'=>teacher_respectful_name($designer)]; }, $fDesigners);
    return [
        'bankMeta' => $bankMeta,
        'filters' => ['years'=>$fYears, 'months'=>$fMonths, 'designers'=>$fDesigners],
    ];
}
/* v4.163.0: نیمهٔ سنگین: ردیف‌های طراحی ذخیره‌شده + اثرانگشت صفحات (دیسک). */
function exam_catalog_design_scan_compute($examId, $curSubject, $curGrade) {
    /* Saved exams use the same source-deduplication rules as the legacy
       catalog. Only metadata is selected for the scan; page URLs stay raw in
       the cache and are versioned when a page is actually returned. */
    $dWhere = ["COALESCE(es.exam_kind,'official')<>'class_deleted'"];
    $dParams = [];
    if (!empty($_GET['year'])) { $dWhere[] = 'es.academic_year=?'; $dParams[] = trim($_GET['year']); }
    if (!empty($_GET['month'])) { $dWhere[] = 'es.exam_month=?'; $dParams[] = trim($_GET['month']); }
    if (!empty($_GET['designer'])) { $dWhere[] = 'ed.designer_name=?'; $dParams[] = trim($_GET['designer']); }
    if (!empty($_GET['subject'])) { $dWhere[] = 'es.subject_name LIKE ?'; $dParams[] = '%' . trim($_GET['subject']) . '%'; }
    $designRows = DB::fetchAll(
        'SELECT ed.id AS design_id, ed.exam_id, ed.designer_name, ed.updated_at_jalali, es.subject_name, es.exam_month, es.academic_year, es.grade_level, es.class_name, es.question_file' .
        ' FROM exam_designs ed JOIN exam_schedules es ON es.id=ed.exam_id WHERE ' . implode(' AND ', $dWhere) .
        ' ORDER BY ed.id ASC LIMIT 400',
        $dParams
    );
    $designCandidates = [];
    $seenSrc = [];
    foreach ($designRows as $row) {
        $eid = (int)$row['exam_id'];
        if ($eid === $examId) continue;
        if ($curSubject !== '' && !exam_subject_matches($row['subject_name'] ?? '', $curSubject)) continue;
        $cacheDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $eid;
        if (is_file($cacheDir . '/origin.txt')) continue;
        $pages = [];
        foreach (glob($cacheDir . '/page_*.jpg') ?: [] as $pageFile) $pages[] = str_replace(__DIR__ . '/', '', $pageFile);
        sort($pages);
        if (!$pages) {
            $questionFile = (string)($row['question_file'] ?? '');
            $extension = $questionFile !== '' ? strtolower(pathinfo($questionFile, PATHINFO_EXTENSION)) : '';
            if (in_array($extension, ['jpg','jpeg','png','webp'], true) && is_file(__DIR__ . '/' . ltrim($questionFile, '/'))) $pages = [$questionFile];
        }
        if (!$pages) continue;
        $signature = exam_page_signature_cached($pages[0]) . '|' . count($pages);
        if (isset($seenSrc[$signature])) continue;
        $seenSrc[$signature] = 1;
        $designCandidates[] = [
            '_source_id' => $eid, '_archived' => false,
            'ref' => 'e' . $eid, 'exam_id' => $eid,
            'subject' => (string)$row['subject_name'],
            'designer' => teacher_respectful_name((string)$row['designer_name'] ?? ''),
            'month' => (string)($row['exam_month'] ?? ''), 'year' => (string)($row['academic_year'] ?? ''),
            'grade' => (string)($row['grade_level'] ?? ''), 'class' => (string)($row['class_name'] ?? ''),
            'saved_at' => (string)($row['updated_at_jalali'] ?? ''),
            'pages' => $pages,
        ];
    }
    $archWhere = ['1=1']; $archParams = [];
    if (!empty($_GET['year'])) { $archWhere[] = 'academic_year=?'; $archParams[] = trim($_GET['year']); }
    if (!empty($_GET['month'])) { $archWhere[] = 'exam_month=?'; $archParams[] = trim($_GET['month']); }
    if (!empty($_GET['designer'])) { $archWhere[] = 'designer_name=?'; $archParams[] = trim($_GET['designer']); }
    foreach (DB::fetchAll(
        'SELECT id, exam_id, designer_name, subject_name, exam_month, academic_year, grade_level, class_name, created_at_jalali' .
        ' FROM exam_design_archive WHERE ' . implode(' AND ', $archWhere) . ' ORDER BY id ASC LIMIT 400',
        $archParams
    ) as $row) {
        if ($curSubject !== '' && !exam_subject_matches($row['subject_name'] ?? '', $curSubject)) continue;
        $pages = [];
        foreach (glob(__DIR__ . '/uploads/exams/bank-archive/' . (int)$row['id'] . '/page_*.jpg') ?: [] as $pageFile) $pages[] = str_replace(__DIR__ . '/', '', $pageFile);
        sort($pages);
        if (!$pages) continue;
        $signature = exam_page_signature_cached($pages[0]) . '|' . count($pages);
        if (isset($seenSrc[$signature])) continue;
        $seenSrc[$signature] = 1;
        $designCandidates[] = [
            '_source_id' => (int)$row['id'], '_archived' => true,
            'ref' => 'a' . (int)$row['id'], 'exam_id' => (int)$row['exam_id'], 'archived' => true,
            'subject' => (string)$row['subject_name'],
            'designer' => teacher_respectful_name((string)$row['designer_name'] ?? ''),
            'month' => (string)($row['exam_month'] ?? ''), 'year' => (string)($row['academic_year'] ?? ''),
            'grade' => (string)($row['grade_level'] ?? ''), 'class' => (string)($row['class_name'] ?? ''),
            'saved_at' => (string)($row['created_at_jalali'] ?? ''), 'pages' => $pages,
        ];
    }
    $designCandidates = array_reverse($designCandidates);

    return [
        'designCandidates' => $designCandidates,
    ];
}

if ($action === 'load') {
    $catalogMode = strtolower(trim((string)($_GET['catalog'] ?? '')));
    $curSubject = trim((string)($exam['subject_name'] ?? ''));
    /* v4.160.0: render the A4 editor as soon as its own design is available;
       filters, bank questions and saved-exam thumbnails are not on this path. */
    if ($catalogMode === 'design') {
        $design = DB::fetch("SELECT design_json FROM exam_designs WHERE exam_id=?", [$examId]);
        json_out(true, [
            'design' => $design ? (json_decode($design['design_json'], true) ?: null) : null,
            'subject' => $curSubject,
            'grade' => trim((string)($exam['grade_level'] ?? '')),
        ]);
    }
    if ($catalogMode === 'bank') {
        $curGrade = trim((string)($exam['grade_level'] ?? ''));
        $bankPage = exam_design_catalog_page('bank_page');
        $designBankPage = exam_design_catalog_page('design_page');
        $pageSize = 5;
        /* v4.160.1: جاروی پرهزینه (متادیتا + اثرانگشت منابع + گزینه‌های فیلتر)
           کش‌شده است؛ هر درخواست فقط ۵ ردیف نمایان را هیدریت می‌کند.
           v4.163.0: با part=q فقط نیمهٔ سریع (سوالات + فیلترها) و با part=d فقط
           نیمهٔ سنگین (طراحی‌های ذخیره‌شده) برمی‌گردد؛ بدون part همان پاسخ کامل
           قبلی ارسال می‌شود تا لینک‌های قدیمی سالم بمانند. */
        $part = strtolower(trim((string)($_GET['part'] ?? '')));
        if (!in_array($part, ['', 'q', 'd'], true)) {
            json_out(false, ['error' => 'پارامتر part نامعتبر است.']);
        }
        $response = ['subject' => $curSubject, 'grade' => $curGrade];
        if ($part === '' || $part === 'q') {
            $bankScan = exam_catalog_bank_scan_cached($curSubject, $curGrade);
            $bankMeta = $bankScan['bankMeta'];
            $bankTotal = count($bankMeta);
            $bankPageRows = array_slice($bankMeta, ($bankPage - 1) * $pageSize, $pageSize);
            $bank = [];
            if ($bankPageRows) {
                $ids = array_values(array_map(function ($row) { return (int)$row['id']; }, $bankPageRows));
                $marks = implode(',', array_fill(0, count($ids), '?')) ;
                $bodyRows = DB::fetchAll("SELECT id, question_html FROM exam_question_bank WHERE id IN ($marks)", $ids);
                $bodies = [];
                foreach ($bodyRows as $body) $bodies[(int)$body['id']] = (string)$body['question_html'];
                foreach ($bankPageRows as $row) {
                    $row['question_html'] = $bodies[(int)$row['id']] ?? '';
                    $bank[] = $row;
                }
            }
            $response['bank'] = $bank;
            $response['bankTotal'] = $bankTotal;
            $response['bankPage'] = $bankPage;
            $response['filters'] = $bankScan['filters'];
        }
        if ($part === '' || $part === 'd') {
            $designScan = exam_catalog_design_scan_cached($examId, $curSubject, $curGrade);
            $designCandidates = $designScan['designCandidates'];
            $designBankTotal = count($designCandidates);
            $designPageRows = array_slice($designCandidates, ($designBankPage - 1) * $pageSize, $pageSize);
            $designBank = [];
            foreach ($designPageRows as $candidate) {
                $design = $candidate['_archived']
                    ? DB::fetch('SELECT design_json FROM exam_design_archive WHERE id=?', [$candidate['_source_id']])
                    : DB::fetch('SELECT design_json FROM exam_designs WHERE exam_id=?', [$candidate['_source_id']]);
                $decoded = $design ? (json_decode((string)$design['design_json'], true) ?: []) : [];
                $candidate['crops'] = (isset($decoded['sourceCrops']) && is_array($decoded['sourceCrops']) && $decoded['sourceCrops']) ? $decoded['sourceCrops'] : new stdClass();
                $candidate['order'] = (isset($decoded['sourceOrder']) && is_array($decoded['sourceOrder'])) ? array_values($decoded['sourceOrder']) : [];
                /* آدرس نسخه‌دار در زمان خروجی ساخته می‌شود تا ورودی کش‌شده بعد از
                   تعویض فایل هم درست بماند؛ thumbs موازی pages برای شبکهٔ موبایل است. */
                $rawPages = array_values((array)($candidate['pages'] ?? []));
                $candidate['thumbs'] = exam_page_thumbs_for($rawPages);
                $candidate['pages'] = array_map('exam_page_vurl', $rawPages);
                unset($candidate['_source_id'], $candidate['_archived']);
                $designBank[] = $candidate;
            }
            $response['designBank'] = $designBank;
            $response['designBankTotal'] = $designBankTotal;
            $response['designBankPage'] = $designBankPage;
        }
        json_out(true, $response);
    }

    // Legacy load clients still receive the original complete response shape.
    $design = DB::fetch("SELECT design_json FROM exam_designs WHERE exam_id=?", [$examId]);
    $curSubject = trim((string)($exam['subject_name'] ?? ''));
    /* v4.111.0: grade_level is optional on pre-v4.111 databases. The bank
       page migrates it once; this read path must never run DDL per request. */
    $curGrade = trim((string)($exam['grade_level'] ?? ''));
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
    }
    /* v4.111.0: فیلتر پایه تحصیلی — سوال هم‌پایه یا بدون پایه ثبت‌شده */
    if ($curGrade !== '') {
        $bank = array_values(array_filter($bank, function ($b0) use ($curGrade) {
            $g = trim((string)($b0['grade_level'] ?? ''));
            return $g === '' || $g === $curGrade;
        }));
    }
    if (count($bank) > 300) $bank = array_slice($bank, 0, 300);
    /* v4.95.0: آزمون‌های طراحی‌شده (با فایل منبع تصویری) هم در بانک نمایش داده می‌شوند */
    $dWhere = ["COALESCE(es.exam_kind,'official')<>'class_deleted'"]; $dParams = [];
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
        $sig = exam_page_signature_cached($pages[0]) . '|' . count($pages);
        $pagesV = array_map('exam_page_vurl', $pages);
        if (isset($seenSrc[$sig])) continue;         // همان منبع قبلاً (قدیمی‌تر) ثبت شده
        $seenSrc[$sig] = 1;
        $dj = json_decode((string)($dr['design_json'] ?? ''), true) ?: [];
        $designBank[] = [
            'ref' => 'e' . $eid,                     /* v4.99.0: شناسه یکتا (زنده) */
            'exam_id' => $eid,
            'subject' => (string)$dr['subject_name'],
            'designer' => teacher_respectful_name((string)($dr['designer_name'] ?? '')),
            'month' => (string)($dr['exam_month'] ?? ''),
            'year' => (string)($dr['academic_year'] ?? ''),
            'grade' => (string)($dr['grade_level'] ?? ''),
            'class' => (string)($dr['class_name'] ?? ''),
            'saved_at' => (string)($dr['updated_at_jalali'] ?? ''),
            'pages' => $pagesV,
            // v4.96.0: تنظیمات چینش/برش/روشنایی/کنتراست/پس‌زمینه/اندازه صفحات
            'crops' => (isset($dj['sourceCrops']) && is_array($dj['sourceCrops']) && $dj['sourceCrops']) ? $dj['sourceCrops'] : new stdClass(),
            'order' => (isset($dj['sourceOrder']) && is_array($dj['sourceOrder'])) ? array_values($dj['sourceOrder']) : [],
        ];
    }
    /* v4.99.0: نسخه‌های بایگانی‌شده (طراحی‌های قبلی که منبع‌شان عوض شده) —
       مستقل و برای همیشه در بانک می‌مانند. */
    $archWhere = ["1=1"]; $archParams = [];
    if (!empty($_GET['year']))     { $archWhere[] = "academic_year=?"; $archParams[] = trim($_GET['year']); }
    if (!empty($_GET['month']))    { $archWhere[] = "exam_month=?";    $archParams[] = trim($_GET['month']); }
    if (!empty($_GET['designer'])) { $archWhere[] = "designer_name=?"; $archParams[] = trim($_GET['designer']); }
    foreach (DB::fetchAll("SELECT * FROM exam_design_archive WHERE " . implode(' AND ', $archWhere) . " ORDER BY id ASC LIMIT 400", $archParams) as $ar) {
        if ($curSubject !== '' && !exam_subject_matches($ar['subject_name'] ?? '', $curSubject)) continue;
        $aPages = [];
        foreach (glob(__DIR__ . '/uploads/exams/bank-archive/' . (int)$ar['id'] . '/page_*.jpg') ?: [] as $pg) $aPages[] = str_replace(__DIR__ . '/', '', $pg);
        sort($aPages);
        if (!$aPages) continue;
        $sig = exam_page_signature_cached($aPages[0]) . '|' . count($aPages);
        if (isset($seenSrc[$sig])) continue;
        $seenSrc[$sig] = 1;
        $adj = json_decode((string)($ar['design_json'] ?? ''), true) ?: [];
        $designBank[] = [
            'ref' => 'a' . (int)$ar['id'],
            'exam_id' => (int)$ar['exam_id'],
            'archived' => true,
            'subject' => (string)$ar['subject_name'],
            'designer' => teacher_respectful_name((string)($ar['designer_name'] ?? '')),
            'month' => (string)($ar['exam_month'] ?? ''),
            'year' => (string)($ar['academic_year'] ?? ''),
            'grade' => (string)($ar['grade_level'] ?? ''),
            'class' => (string)($ar['class_name'] ?? ''),
            'saved_at' => (string)($ar['created_at_jalali'] ?? ''),
            'pages' => array_map('exam_page_vurl', $aPages),
            'crops' => (isset($adj['sourceCrops']) && is_array($adj['sourceCrops']) && $adj['sourceCrops']) ? $adj['sourceCrops'] : new stdClass(),
            'order' => (isset($adj['sourceOrder']) && is_array($adj['sourceOrder'])) ? array_values($adj['sourceOrder']) : [],
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
                    'subject'=>$curSubject, 'grade'=>$curGrade,
                    'filters'=>['years'=>array_values(array_unique($fYears)), 'months'=>array_values(array_unique($fMonths)), 'designers'=>$fDesigners]]);
}

/* v4.95.0: درج یک آزمون ذخیره‌شده از بانک در ویرایشگر زنده آزمون فعلی:
   تصاویر صفحات منبع به کش آزمون فعلی کپی و کل طراحی (سوالات، برش‌ها،
   ترتیب صفحات و تنظیمات) برگردانده می‌شود. */
if ($action === 'import_design') {
    /* v4.99.0: منبع درج می‌تواند آزمون زنده (e123 یا عدد) یا نسخه بایگانی (a45) باشد */
    $srcRef = trim((string)($_POST['source_ref'] ?? $_POST['source_exam_id'] ?? ''));
    $isArch = $srcRef !== '' && $srcRef[0] === 'a';
    $srcId = (int)ltrim($srcRef, 'ae');
    if ($srcId <= 0) json_out(false, ['error'=>'آزمون منبع نامعتبر است']);
    if(!$isArch){$sourceExam=DB::fetch('SELECT exam_kind FROM exam_schedules WHERE id=?',[$srcId]);if($sourceExam && $sourceExam['exam_kind']==='class_deleted')json_out(false,['error'=>'آزمون منبع حذف شده است.']);}
    $srcPages = [];
    if ($isArch) {
        $arch = DB::fetch("SELECT * FROM exam_design_archive WHERE id=?", [$srcId]);
        if (!$arch) json_out(false, ['error'=>'نسخه بایگانی یافت نشد']);
        $srcDesign = ['design_json' => $arch['design_json']];
        foreach (glob(__DIR__ . '/uploads/exams/bank-archive/' . $srcId . '/page_*.jpg') ?: [] as $pg) $srcPages[] = $pg;
        sort($srcPages);
    } else {
        $srcDesign = DB::fetch("SELECT design_json FROM exam_designs WHERE exam_id=?", [$srcId]);
        foreach (glob(__DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId . '/page_*.jpg') ?: [] as $pg) $srcPages[] = $pg;
        sort($srcPages);
        if (!$srcPages) {
            $srcExam = DB::fetch("SELECT question_file FROM exam_schedules WHERE id=?", [$srcId]);
            $qf = (string)($srcExam['question_file'] ?? '');
            if ($qf !== '' && in_array(strtolower(pathinfo($qf, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp'], true) && is_file(__DIR__ . '/' . ltrim($qf, '/'))) $srcPages = [__DIR__ . '/' . ltrim($qf, '/')];
        }
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
        /* v4.96.0/v4.99.0: نشانه استفاده مجدد — این آزمون کپی منبع بانک است و دوباره ثبت/بایگانی نمی‌شود */
        if ($isArch) {
            @file_put_contents($dstDir . '/origin.txt', 'a' . $srcId);
        } else {
            $srcDir = __DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId;
            $origin = is_file($srcDir . '/origin.txt') ? trim((string)@file_get_contents($srcDir . '/origin.txt')) : (string)$srcId;
            @file_put_contents($dstDir . '/origin.txt', $origin !== '' ? $origin : (string)$srcId);
        }
    }
    json_out(true, [
        'design' => $srcDesign ? json_decode($srcDesign['design_json'], true) : null,
        'sourcePages' => array_map('exam_page_vurl', $newPages),
        'message' => 'آزمون انتخابی از بانک در ویرایشگر درج شد.',
    ]);
}

/* v4.95.0: حذف آزمون ذخیره‌شده از بانک (فقط مدیر) — طراحی + تصاویر منبع آن آزمون */
if ($action === 'delete_design') {
    if (!is_admin_logged_in()) json_out(false, ['error'=>'فقط مدیر مجاز است']);
    $srcRef = trim((string)($_POST['source_ref'] ?? $_POST['source_exam_id'] ?? ''));
    $isArch = $srcRef !== '' && $srcRef[0] === 'a';
    $srcId = (int)ltrim($srcRef, 'ae');
    if ($srcId <= 0) json_out(false, ['error'=>'آزمون نامعتبر']);
    if ($isArch) {
        /* v4.99.0: حذف نسخه بایگانی از بانک */
        DB::execute("DELETE FROM exam_design_archive WHERE id=?", [$srcId]);
        foreach (glob(__DIR__ . '/uploads/exams/bank-archive/' . $srcId . '/*') ?: [] as $pg) @unlink($pg);
        @rmdir(__DIR__ . '/uploads/exams/bank-archive/' . $srcId);
    } else {
        $deleting=DB::fetch('SELECT * FROM exam_schedules WHERE id=?',[$srcId]);
        ceg_write_begin($deleting);
        if($deleting && ceg_for_exam($deleting))json_out(false,['error'=>'برای حفاظت از طراحی گروه و نسخه‌های مستقل، حذف این آزمون از بانک مجاز نیست.']);
        DB::execute("DELETE FROM exam_designs WHERE exam_id=?", [$srcId]);
        foreach (glob(__DIR__ . '/uploads/exams/pdf-pages/exam_' . $srcId . '/page_*.jpg') ?: [] as $pg) @unlink($pg);
    }
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
    ceg_write_end(true); // Release before legacy replication/notification schema work.
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
