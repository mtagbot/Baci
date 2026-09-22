<?php
// File: includes/bot_role_engine.php
/**
 * v4.87.0 — Multi-role bot accounts (Bale + Telegram).
 *
 * The bots previously served students/parents only; staff had just a hidden
 * login that handed out a web link. This engine turns the bots into real
 * role-aware assistants:
 *
 *   • national id detection: when a 10-digit id belongs to a TEACHER, the flow
 *     switches automatically — the bot asks for the personnel code instead of
 *     the student serial, then activates the proper role panel:
 *       - teacher            → weekly teaching schedule, own exam schedule,
 *                              grade-objection alerts with inline reply/edit
 *       - deputy (ناظم/معاون) → daily absents list on demand + the 08:02 push
 *       - executive deputy   → exam-design alerts + "دریافت نسخه PDF" button
 *     A teacher may hold several roles at once; every active role adds its own
 *     glass (reply-keyboard) buttons to the panel.
 *
 *   • notifications are always sent to BOTH platforms (bale + telegram) for
 *     every active staff session of the target teacher.
 */

require_once __DIR__ . '/bot_helpers.php';
require_once __DIR__ . '/school_roles.php';

/* ─────────────────────────── role model ─────────────────────────── */

if (!function_exists('bot_role_labels')) {
    function bot_role_labels() {
        return [
            'teacher'   => 'دبیر',
            'deputy'    => 'معاون/ناظم',
            'executive' => 'معاون اجرایی',
            'counselor' => 'مشاور',
        ];
    }
}

if (!function_exists('bot_teacher_roles')) {
    /** All roles a teachers-table row holds. Every teacher gets 'teacher'. */
    function bot_teacher_roles($teacher) {
        $roles = ['teacher'];
        if (!empty($teacher['is_deputy']))    $roles[] = 'deputy';
        if (!empty($teacher['is_executive'])) $roles[] = 'executive';
        if (!empty($teacher['is_counselor'])) $roles[] = 'counselor';
        return $roles;
    }
}

if (!function_exists('bot_staff_session')) {
    /** Latest active staff session (with teacher row) for this chat, or null. */
    function bot_staff_session($platform, $chatId) {
        ensure_school_roles_schema();
        $s = DB::fetch("SELECT bs.*, t.full_name, t.first_name, t.last_name, t.national_id, t.personnel_code, t.is_deputy, t.is_executive, t.is_counselor FROM bot_admin_sessions bs JOIN teachers t ON t.id = bs.teacher_id WHERE bs.platform=? AND bs.chat_id=? AND bs.role_type='teacher' AND bs.is_active=1 ORDER BY bs.id DESC LIMIT 1", [bot_valid_platform($platform), (string)$chatId]);
        return $s ?: null;
    }
}

if (!function_exists('bot_staff_keyboard')) {
    /** Glass reply-keyboard for a staff chat: buttons per active role. */
    function bot_staff_keyboard($teacher) {
        $rows = [];
        // teacher row(s)
        $rows[] = [['text' => '🗓 برنامه هفتگی تدریس من'], ['text' => '📝 برنامه امتحانی من']];
        $rows[] = [['text' => '💬 اعتراضات نمرات']];
        if (!empty($teacher['is_deputy']))    $rows[] = [['text' => '🚫 غایبین امروز']];
        if (!empty($teacher['is_executive'])) $rows[] = [['text' => '🖨 آزمون‌های طراحی‌شده']];
        $rows[] = [['text' => '🚪 خروج از حساب کارکنان']];
        return ['keyboard' => $rows, 'resize_keyboard' => true];
    }
}

if (!function_exists('bot_staff_send')) {
    /** Safe send that never breaks the webhook flow. */
    function bot_staff_send($platform, $chatId, $text, $keyboard = null) {
        try { return bot_send_message($platform, $chatId, $text, $keyboard); }
        catch (Exception $e) { error_log('bot_staff_send failed: ' . $e->getMessage()); return null; }
    }
}

if (!function_exists('bot_notify_teacher_chats')) {
    /**
     * Deliver a message (+ optional inline keyboard) to every active staff chat
     * of a teacher on BOTH platforms. Optional $roleFlag filters targets by an
     * extra role column (e.g. 'is_executive'). Returns delivered count.
     */
    function bot_notify_teacher_chats($teacherId, $text, $inlineKeyboard = null, $roleFlag = '') {
        $sent = 0;
        foreach (['bale', 'telegram'] as $pf) {
            try {
                ensure_bot_schema($pf);
                ensure_school_roles_schema();
                $rows = DB::fetchAll("SELECT DISTINCT bs.chat_id FROM bot_admin_sessions bs JOIN teachers t ON t.id=bs.teacher_id WHERE bs.platform=? AND bs.teacher_id=? AND bs.role_type='teacher' AND bs.is_active=1" . ($roleFlag !== '' ? " AND t.`" . preg_replace('/[^a-z_]/', '', $roleFlag) . "`=1" : ''), [$pf, (int)$teacherId]);
                foreach ($rows as $r) {
                    try {
                        bot_send_message($pf, $r['chat_id'], $text, $inlineKeyboard);
                        $sent++;
                    } catch (Exception $e) { error_log("notify teacher chat failed ($pf): " . $e->getMessage()); }
                }
            } catch (Exception $e) { error_log("notify teacher chats failed ($pf): " . $e->getMessage()); }
        }
        return $sent;
    }
}

if (!function_exists('bot_notify_role_chats')) {
    /** Same as above but to ALL teachers holding a role flag (deputy/executive). */
    function bot_notify_role_chats($roleFlag, $text, $inlineKeyboard = null) {
        $flag = preg_replace('/[^a-z_]/', '', $roleFlag);
        $sent = 0;
        foreach (['bale', 'telegram'] as $pf) {
            try {
                ensure_bot_schema($pf);
                ensure_school_roles_schema();
                $rows = DB::fetchAll("SELECT DISTINCT bs.chat_id FROM bot_admin_sessions bs JOIN teachers t ON t.id=bs.teacher_id WHERE bs.platform=? AND bs.role_type='teacher' AND bs.is_active=1 AND t.`$flag`=1", [$pf]);
                foreach ($rows as $r) {
                    try {
                        bot_send_message($pf, $r['chat_id'], $text, $inlineKeyboard);
                        $sent++;
                    } catch (Exception $e) { error_log("notify role chat failed ($pf): " . $e->getMessage()); }
                }
            } catch (Exception $e) { error_log("notify role chats failed ($pf): " . $e->getMessage()); }
        }
        return $sent;
    }
}

/* ─────────────────── teacher content: schedule / exams ─────────────────── */

if (!function_exists('bot_teacher_weekly_text')) {
    function bot_teacher_weekly_text($teacherId, $teacherName = '') {
        $year = get_setting('current_academic_year', '1404/1405');
        $rows = DB::fetchAll("SELECT day_of_week, period_num, subject_name, class_name FROM class_schedules WHERE academic_year=? AND (teacher_id=? OR (teacher_id IS NULL AND teacher_name<>'' AND teacher_name=?)) ORDER BY id", [$year, (int)$teacherId, (string)$teacherName]);
        if (!$rows) return '🗓 برنامه هفتگی تدریس شما در سال ' . tr_num($year, 'fa') . " ثبت نشده است.\nدر صورت مغایرت با دفتر مدرسه تماس بگیرید.";
        $days = ['شنبه','یکشنبه','دوشنبه','سه‌شنبه','چهارشنبه','پنجشنبه','جمعه'];
        $byDay = [];
        foreach ($rows as $r) $byDay[trim($r['day_of_week'])][] = $r;
        $msg = '🗓 برنامه هفتگی تدریس شما — سال ' . tr_num($year, 'fa') . "\n";
        foreach ($days as $d) {
            $variants = [$d, str_replace('‌', '', $d), str_replace('‌', ' ', $d)];
            $list = [];
            foreach ($variants as $v) if (isset($byDay[$v])) { $list = array_merge($list, $byDay[$v]); unset($byDay[$v]); }
            if (!$list) continue;
            usort($list, fn($a, $b) => strnatcmp(tr_num($a['period_num'], 'en'), tr_num($b['period_num'], 'en')));
            $msg .= "\n📌 " . $d . ':';
            foreach ($list as $it) $msg .= "\n   ▪️ " . tr_num($it['period_num'], 'fa') . ' — ' . $it['subject_name'] . ' (' . $it['class_name'] . ')';
            $msg .= "\n";
        }
        foreach ($byDay as $d => $list) {   // days written differently than the 7 defaults
            $msg .= "\n📌 " . $d . ':';
            foreach ($list as $it) $msg .= "\n   ▪️ " . tr_num($it['period_num'], 'fa') . ' — ' . $it['subject_name'] . ' (' . $it['class_name'] . ')';
            $msg .= "\n";
        }
        return rtrim($msg);
    }
}

if (!function_exists('bot_teacher_exams_text')) {
    function bot_teacher_exams_text($teacherId, $teacherName = '') {
        if (function_exists('ensure_exams_schema')) ensure_exams_schema();
        $year = get_setting('current_academic_year', '1404/1405');
        $rows = DB::fetchAll("SELECT * FROM exam_schedules WHERE academic_year=? AND is_active=1 AND (teacher_id=? OR (COALESCE(teacher_id,0)=0 AND teacher_name<>'' AND teacher_name=?)) ORDER BY exam_date_jalali, start_time", [$year, (int)$teacherId, (string)$teacherName]);
        if (!$rows) return '📝 در سال ' . tr_num($year, 'fa') . ' برنامه امتحانی برای دروس شما ثبت نشده است.';
        $msg = '📝 برنامه امتحانی دروس شما — سال ' . tr_num($year, 'fa') . "\n";
        foreach ($rows as $e) {
            $where = trim(($e['grade_level'] ?: '') . ' ' . ($e['class_name'] ?: ''));
            $msg .= "\n▪️ " . $e['subject_name']
                 . ($where !== '' ? ' (' . $where . ')' : '')
                 . ' — ' . tr_num($e['exam_date_jalali'] ?: 'بدون تاریخ', 'fa')
                 . ($e['exam_day_name'] ? ' ' . $e['exam_day_name'] : '')
                 . ($e['start_time'] ? ' ساعت ' . tr_num($e['start_time'], 'fa') : '')
                 . ' | مدت: ' . tr_num($e['duration_minutes'], 'fa') . ' دقیقه';
        }
        return $msg;
    }
}

/* ───────────────────────── deputy: absents list ───────────────────────── */

if (!function_exists('bot_deputy_absents_text')) {
    function bot_deputy_absents_text() {
        require_once __DIR__ . '/attendance_helpers.php';
        ensure_attendance_schema_v2();
        $today = att_today();
        $df = att_day_forms($today);
        $ph = implode(',', array_fill(0, count($df), '?'));
        $rows = DB::fetchAll("SELECT s.first_name, s.last_name, s.class_name FROM student_attendance a JOIN students s ON s.id=a.student_id WHERE a.date_jalali IN ($ph) AND a.status='absent' ORDER BY s.class_name, s.last_name", $df);
        if (function_exists('persian_usort_by')) persian_usort_by($rows, ['class_name', 'last_name', 'first_name']);
        $head = '🚫 غایبین امروز ' . tr_num($today, 'fa');
        if (!$rows) return $head . "\n\nتا این لحظه غیبتی ثبت نشده است. ✅";
        $msg = $head . ' — ' . tr_num(count($rows), 'fa') . " نفر\n";
        $lastClass = null;
        foreach ($rows as $r) {
            if ($r['class_name'] !== $lastClass) { $msg .= "\n🏫 " . ($r['class_name'] ?: 'بدون کلاس') . ':'; $lastClass = $r['class_name']; }
            $msg .= "\n   ▪️ " . trim($r['first_name'] . ' ' . $r['last_name']);
        }
        return $msg;
    }
}

if (!function_exists('bot_deputy_absents_push')) {
    /**
     * 08:02 absents push to every deputy chat (both platforms). Runs from the
     * regular attendance flow / cron; guarded to fire once per day, only after
     * the configured push time, and only after the auto-absent finalizer has
     * already produced today's list.
     */
    function bot_deputy_absents_push() {
        require_once __DIR__ . '/attendance_helpers.php';
        if (get_setting('att_deputy_absents_push', '1') !== '1') return 0;
        ensure_attendance_schema_v2();
        $today = att_today();
        if (get_setting('att_deputy_pushed_day', '') === $today) return 0;
        $pushAt = get_setting('att_deputy_push_at', '08:02');
        if (att_time_to_minutes(att_now_time()) < att_time_to_minutes($pushAt)) return 0;
        $offDays = array_map('trim', explode(',', get_setting('att_off_days', 'جمعه')));
        if (in_array(jdate('l'), $offDays, true)) { set_setting('att_deputy_pushed_day', $today); return 0; }
        // the absents list is meaningful only after the auto-absent finalizer ran
        if (get_setting('att_finalized_day', '') !== $today) return 0;
        $sent = bot_notify_role_chats('is_deputy', bot_deputy_absents_text());
        set_setting('att_deputy_pushed_day', $today);   // once per day even if nobody is connected yet
        return $sent;
    }
}

/* ──────────────── executive: designed-exams list + PDF ──────────────── */

if (!function_exists('bot_exec_designs_text_and_kb')) {
    function bot_exec_designs_text_and_kb() {
        if (function_exists('ensure_exams_schema')) ensure_exams_schema();
        $year = get_setting('current_academic_year', '1404/1405');
        $rows = DB::fetchAll("SELECT d.exam_id, d.designer_name, d.updated_at_jalali, e.subject_name, e.exam_month, e.exam_date_jalali, e.grade_level, e.class_name, COALESCE(e.exam_kind,'official') AS exam_kind FROM exam_designs d JOIN exam_schedules e ON e.id=d.exam_id WHERE e.academic_year=? AND e.is_active=1 ORDER BY d.updated_at_jalali DESC LIMIT 40", [$year]);
        if (!$rows) return ['🖨 هنوز آزمونی در سال ' . tr_num($year, 'fa') . ' طراحی نشده است.', null];
        $msg = '🖨 آخرین آزمون‌های طراحی‌شده — سال ' . tr_num($year, 'fa') . "\nبرای دریافت نسخه چاپی هر آزمون روی دکمه آن بزنید (آزمون پایه‌ای، همه کلاس‌های پایه را با هم می‌گیرد):";
        $inline = []; $seen = [];
        foreach ($rows as $r) {
            // v4.88.0: replicated grade-wide designs are ONE logical exam — show a
            // single button per (month|grade|subject) group instead of a row per class.
            $grade = trim((string)($r['grade_level'] ?: ''));
            $isGrade = $r['exam_kind'] !== 'class' && $grade !== '';
            $key = $isGrade ? ($r['exam_month'] . '|' . $grade . '|' . $r['subject_name']) : ('x' . $r['exam_id']);
            if (isset($seen[$key])) continue;
            $seen[$key] = 1;
            if (count($inline) >= 15) break;
            $where = $isGrade ? ('پایه ' . $grade) : trim(($r['grade_level'] ?: '') . ' ' . ($r['class_name'] ?: ''));
            $label = $r['subject_name'] . ($where !== '' ? ' ' . $where : '') . ' — ' . tr_num($r['exam_date_jalali'] ?: ($r['exam_month'] ?: ''), 'fa');
            $inline[] = [['text' => '📄 ' . mb_substr($label, 0, 56, 'UTF-8'), 'callback_data' => 'exampdf_' . (int)$r['exam_id']]];
        }
        return [$msg, ['inline_keyboard' => $inline]];
    }
}

if (!function_exists('bot_notify_exam_design_saved')) {
    /**
     * Called right after a designer saves an exam design. Sends the alert
     * (subject, term/month, date, designer, print note) with the
     * «دریافت نسخه PDF» button to every executive-deputy chat on both bots.
     * Debounced: repeated autosaves of the same exam within 10 minutes do not
     * spam the executive again.
     */
    function bot_notify_exam_design_saved($examId) {
        $examId = (int)$examId;
        try {
            $exam = DB::fetch("SELECT * FROM exam_schedules WHERE id=?", [$examId]);
            $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
            if (!$exam || !$design) return 0;
            $guardKey = 'exec_design_notified_' . $examId;
            $last = (int)get_setting($guardKey, '0');
            if (time() - $last < 600) return 0;   // debounce autosave storms
            set_setting($guardKey, (string)time());
            $note = '';
            $dj = json_decode($design['design_json'] ?? '', true);
            if (is_array($dj) && trim((string)($dj['printNote'] ?? '')) !== '') $note = trim((string)$dj['printNote']);
            $designer = $design['designer_name'] ?: ($exam['teacher_name'] ?: 'نامشخص');
            if (function_exists('teacher_respectful_name')) $designer = teacher_respectful_name($designer);
            $where = trim(($exam['grade_level'] ?: '') . ' ' . ($exam['class_name'] ?: ''));
            // v4.88.0: a grade-wide design speaks for all sibling classes of the grade
            $gW = trim((string)($exam['grade_level'] ?? ''));
            if ($gW !== '' && (string)($exam['exam_kind'] ?? 'official') !== 'class') {
                $sibC = DB::fetch("SELECT COUNT(*) c FROM exam_schedules WHERE academic_year=? AND exam_month=? AND subject_name=? AND is_active=1 AND COALESCE(exam_kind,'official')<>'class' AND (grade_level=? OR class_name LIKE ?)", [$exam['academic_year'], $exam['exam_month'], $exam['subject_name'], $gW, $gW . '%']);
                if ((int)($sibC['c'] ?? 0) > 1) $where = 'پایه ' . $gW . ' — همه کلاس‌ها (' . tr_num((int)$sibC['c'], 'fa') . ' کلاس)';
            }
            $msg = "🖨 آزمون جدید طراحی و ذخیره شد\n"
                 . "📚 درس: " . $exam['subject_name'] . ($where !== '' ? ' (' . $where . ')' : '') . "\n"
                 . "🗓 نوبت: " . ($exam['exam_month'] ?: '—') . " | تاریخ آزمون: " . tr_num($exam['exam_date_jalali'] ?: '—', 'fa') . "\n"
                 . "👨‍🏫 دبیر طراح: " . $designer . "\n"
                 . "⏱ آخرین ذخیره: " . tr_num($design['updated_at_jalali'], 'fa');
            if ($note !== '') $msg .= "\n\n📝 یادداشت چاپ:\n" . mb_substr($note, 0, 700, 'UTF-8');
            $kb = ['inline_keyboard' => [[['text' => '📄 دریافت نسخه PDF', 'callback_data' => 'exampdf_' . $examId]]]];
            return bot_notify_role_chats('is_executive', $msg, $kb);
        } catch (Exception $e) {
            error_log('bot_notify_exam_design_saved failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* ─────────── executive PDF: faithful print-page replica for ALL students ───────────
 *
 * v4.88.0 — the bot PDF is now a server-side rendering of the site's exam
 * print page (exam-print.php?type=questions) with per-student headers:
 *   • the saved design is reproduced as-is: uploaded PDF/image source pages
 *     with the designer's own crops, shift, zoom, contrast/brightness,
 *     typed-question table, handwriting overlays and page-style sliders;
 *   • every student gets his own sheet set — personal header (photo, name,
 *     class, seat, subject, teacher, date, stamp…) on odd pages, exactly like
 *     «تکثیر برای همه و چاپ» on the site;
 *   • grade exams (same subject+month across the classes of a grade) cover
 *     the students of ALL those classes — like the site's «چاپ پایه‌ای»;
 *   • optimised output: the shared exam body of each design page is embedded
 *     ONCE and referenced by every sheet; only the slim header strip is
 *     per-student, so even 90+ students stay a few megabytes.
 */

if (!function_exists('bot_exam_css_mm')) {
    function bot_exam_css_mm($v, $default) {
        $v = trim((string)$v);
        if ($v === '') return (float)$default;
        if (substr($v, -2) === 'mm') return (float)$v;
        if (substr($v, -2) === 'px') return (float)$v * 25.4 / 96.0;
        $f = (float)$v;
        return $f > 0 ? $f : (float)$default;
    }
}

if (!function_exists('bot_exam_source_page_files')) {
    /** Absolute paths of the design's source page images (same cache the print page uses). */
    function bot_exam_source_page_files($exam) {
        $f = (string)($exam['question_file'] ?? '');
        if ($f === '') return [];
        $root = dirname(__DIR__);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, ['png','jpg','jpeg','webp'], true)) {
            $p = $root . '/' . ltrim($f, '/');
            return is_file($p) ? [$p] : [];
        }
        if ($ext !== 'pdf') return [];
        $cacheDir = $root . '/uploads/exams/pdf-pages/exam_' . (int)$exam['id'];
        $files = glob($cacheDir . '/page_*.jpg') ?: [];
        sort($files);
        if ($files) return $files;
        $full = $root . '/' . ltrim($f, '/');
        if (!is_file($full)) return [];
        @mkdir($cacheDir, 0775, true);
        $declared = 1;
        if (class_exists('Imagick')) {
            try { $probe = new Imagick(); $probe->pingImage($full); $declared = max(1, (int)$probe->getNumberImages()); $probe->clear(); } catch (Exception $e) {}
            for ($i = 0; $i < $declared; $i++) {
                try {
                    $pg = new Imagick(); $pg->setResolution(150, 150); /* v4.98.0: 150dpi استاندارد چاپ اداری */ $pg->readImage($full . '[' . $i . ']');
                    $pg->setIteratorIndex(0); $pg->setImageBackgroundColor('white');
                    if (defined('Imagick::ALPHACHANNEL_REMOVE')) $pg->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                    $pg->setImageFormat('jpeg'); $pg->setImageCompressionQuality(90);
                    $pg->writeImage($cacheDir . '/page_' . str_pad((string)($i + 1), 3, '0', STR_PAD_LEFT) . '.jpg');
                    $pg->clear();
                } catch (Exception $e) { break; }
            }
        }
        $files = glob($cacheDir . '/page_*.jpg') ?: [];
        sort($files);
        if (!$files && function_exists('exec')) {
            $pcFile = $cacheDir . '/pages.txt';
            $dp = is_file($pcFile) ? max(1, (int)@file_get_contents($pcFile)) : $declared;
            for ($i = 1; $i <= $dp; $i++) {
                $file = $cacheDir . '/page_' . str_pad((string)$i, 3, '0', STR_PAD_LEFT) . '.jpg';
                $cmd = 'gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=jpeg -r180 -dJPEGQ=90 -dFirstPage=' . (int)$i . ' -dLastPage=' . (int)$i . ' -sOutputFile=' . escapeshellarg($file) . ' ' . escapeshellarg($full) . ' 2>&1';
                @exec($cmd, $o, $code);
                if ($code !== 0 || !is_file($file)) break;
            }
            $files = glob($cacheDir . '/page_*.jpg') ?: [];
            sort($files);
        }
        return $files;
    }
}

if (!function_exists('bot_exam_pdf_students')) {
    /**
     * Students who must receive a headed sheet. Grade exams (a design shared
     * by the sibling classes of the grade — the «پایه‌ای» batch) cover the
     * whole grade, exactly like exam-print.php?grade_all=1.
     * Returns [students, gradeWide(bool), gradeLabel].
     */
    function bot_exam_pdf_students($exam) {
        if (is_file(__DIR__ . '/class_schedule_sync.php')) require_once __DIR__ . '/class_schedule_sync.php';
        $grade = trim((string)($exam['grade_level'] ?? ''));
        if ($grade === '' && function_exists('infer_grade_from_class_name')) $grade = (string)infer_grade_from_class_name($exam['class_name'] ?? '');
        list($ySql, $yParams) = exam_year_students_sql($exam['academic_year'] ?? '');
        $kind = (string)($exam['exam_kind'] ?? 'official');
        $gradeWide = false;
        if ($kind !== 'class' && $grade !== '') {
            if (trim((string)($exam['class_name'] ?? '')) === '') $gradeWide = true;
            else {
                $sib = DB::fetch("SELECT COUNT(*) c FROM exam_schedules WHERE academic_year=? AND exam_month=? AND subject_name=? AND is_active=1 AND COALESCE(exam_kind,'official')<>'class' AND (grade_level=? OR class_name LIKE ?)",
                    [$exam['academic_year'], $exam['exam_month'], $exam['subject_name'], $grade, $grade . '%']);
                $gradeWide = ((int)($sib['c'] ?? 0)) > 1;
            }
        }
        if ($gradeWide) {
            $students = DB::fetchAll("SELECT s.* FROM students s WHERE s.status='active' AND (s.grade_level=? OR s.class_name LIKE ?) AND $ySql", array_merge([$grade, $grade . '%'], $yParams));
        } else {
            $students = DB::fetchAll("SELECT s.* FROM students s WHERE s.status='active' AND (?='' OR s.class_name=?) AND (?='' OR s.grade_level=?) AND $ySql",
                array_merge([$exam['class_name'], $exam['class_name'], $exam['grade_level'], $exam['grade_level']], $yParams));
        }
        if (function_exists('persian_usort_by')) persian_usort_by($students, ['class_name', 'last_name', 'first_name']);
        if (!$students) $students = [['id'=>0,'first_name'=>'نمونه','last_name'=>'دانش‌آموز','class_name'=>$exam['class_name'] ?? '','grade_level'=>$exam['grade_level'] ?? '','photo_url'=>'']];
        return [$students, $gradeWide, $grade];
    }
}

if (!function_exists('bot_exam_page_jpeg')) {
    /**
     * v4.98.0: بهینه‌ساز خروجی JPEG صفحات PDF ربات — با حفظ کیفیت استاندارد امتحان.
     *  الف-۱) اگر صفحه عملاً بی‌رنگ باشد (متن/اسکن سیاه‌سفید) فیلتر خاکستری اعمال
     *         می‌شود؛ کانال‌های رنگی خالی در JPEG بسیار بهتر فشرده می‌شوند (۳۰-۴۵٪ کمتر).
     *  الف-۲) کیفیت تطبیقی: صفحات «فقط متن و خطوط» کیفیت ۷۴ می‌گیرند (برای چاپ متن
     *         فارسی کاملاً کافی) و صفحاتی که عکس/تصویر دارند کیفیت ۸۶ تا وضوح عکس
     *         دانش‌آموز و تصاویر سوال حفظ شود. تشخیص با نمونه‌برداری شبکه‌ای سریع.
     */
    function bot_exam_page_jpeg($img) {
        $w = imagesx($img); $h = imagesy($img);
        $stepX = max(1, (int)($w / 220)); $stepY = max(1, (int)($h / 220));
        $total = 0; $colored = 0; $midtone = 0;
        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                $total++;
                if (max($r, $g, $b) - min($r, $g, $b) > 18) $colored++;      // پیکسل واقعاً رنگی
                $lum = (int)(($r * 299 + $g * 587 + $b * 114) / 1000);
                if ($lum > 64 && $lum < 208) $midtone++;                     // نیم‌سایه = عکس/اسکن
            }
        }
        $isGray = $total > 0 && ($colored / $total) < 0.004;
        $isTextOnly = $total > 0 && ($midtone / $total) < 0.035;
        if ($isGray) @imagefilter($img, IMG_FILTER_GRAYSCALE);
        ob_start(); imagejpeg($img, null, $isTextOnly ? 74 : 86);
        return ob_get_clean();
    }
}

if (!function_exists('bot_exam_class_page_ranges')) {
    /** v4.98.0 (ب-۴): محدوده صفحات هر کلاس در PDF نهایی — دانش‌آموزان مرتب بر اساس کلاس‌اند. */
    function bot_exam_class_page_ranges($students, $pagesPer) {
        $ranges = []; $page = 1; $pagesPer = max(1, (int)$pagesPer);
        foreach ($students as $st) {
            $cls = trim((string)($st['class_name'] ?? ''));
            if ($cls === '') $cls = 'بدون کلاس';
            if (!isset($ranges[$cls])) $ranges[$cls] = ['from' => $page, 'to' => $page + $pagesPer - 1];
            else $ranges[$cls]['to'] = $page + $pagesPer - 1;
            $page += $pagesPer;
        }
        return $ranges;
    }
}

if (!function_exists('bot_exam_wrap_text')) {
    /** Word-wraps $text so each line fits in $maxPx (measured with the real TTF). */
    function bot_exam_wrap_text($text, $pt, $maxPx, $bold = false) {
        $font = report_image_font_path($bold);
        $measure = function ($s) use ($font, $pt) {
            if (!$font) return mb_strlen($s, 'UTF-8') * $pt * 0.62;
            $t = preg_match('/[آاأإبپتثجچحخدذرزژسشصضطظعغفقکگلمنوهیئؤة]/u', $s) ? rtl_shape_persian_for_gd($s) : $s;
            $b = @imagettfbbox($pt, 0, $font, $t);
            return $b ? abs($b[2] - $b[0]) : mb_strlen($s, 'UTF-8') * $pt * 0.62;
        };
        $out = [];
        foreach (preg_split('/\n/u', (string)$text) as $para) {
            $words = preg_split('/\s+/u', trim($para), -1, PREG_SPLIT_NO_EMPTY);
            if (!$words) { continue; }
            $ln = '';
            foreach ($words as $wd) {
                $try = $ln === '' ? $wd : $ln . ' ' . $wd;
                if ($ln !== '' && $measure($try) > $maxPx) { $out[] = $ln; $ln = $wd; }
                else $ln = $try;
            }
            if ($ln !== '') $out[] = $ln;
        }
        return $out;
    }
}

if (!function_exists('bot_exam_q_plain')) {
    /** HTML question body → [text lines, embedded data-URI images with x/y/w css-px]. */
    function bot_exam_q_plain($html) {
        $html = preg_replace('/<div class="q-actions".*?<\/div>/is', '', (string)$html);
        $imgs = [];
        $html = preg_replace_callback('/<img[^>]*>/i', function ($m) use (&$imgs) {
            $tag = $m[0];
            if (preg_match('/src="(data:image\/[^";]+;base64,[^"]+)"/i', $tag, $sm)) {
                $left = 0.0; $top = 0.0; $wpx = 220.0;
                if (preg_match('/left:\s*(-?[\d.]+)px/i', $tag, $m2)) $left = (float)$m2[1];
                if (preg_match('/top:\s*(-?[\d.]+)px/i', $tag, $m2)) $top = (float)$m2[1];
                if (preg_match('/width:\s*(-?[\d.]+)px/i', $tag, $m2)) $wpx = (float)$m2[1];
                $bin = base64_decode(substr($sm[1], strpos($sm[1], ',') + 1));
                if ($bin !== false && $bin !== '') $imgs[] = ['bin' => $bin, 'x' => $left, 'y' => $top, 'w' => $wpx];
            }
            return '';
        }, $html);
        $html = preg_replace('/<span class="blank-line"[^>]*>\s*<\/span>/i', ' .................... ', $html);
        $html = preg_replace('/<span class="tf-square"[^>]*>\s*<\/span>/i', ' [  ] ', $html);
        $html = preg_replace('/<div class="q-options"[^>]*>/i', "\n", $html);
        $html = str_ireplace('<span>', "\n", $html);
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li|\/h[1-6])[^>]*>/i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (explode("\n", $text) as $l) {
            $l = trim(preg_replace('/\s+/u', ' ', $l));
            if ($l !== '') $lines[] = $l;
        }
        return [$lines, $imgs];
    }
}

if (!function_exists('bot_exam_gd_from_bin')) {
    function bot_exam_gd_from_bin($bin) {
        $im = @imagecreatefromstring($bin);
        return $im ?: null;
    }
}

if (!function_exists('bot_exam_design_pdf_path')) {
    /**
     * Renders the saved design into the final headed PDF for every target
     * student. Returns ['path'=>…, 'students'=>n, 'pages_per_student'=>n,
     * 'total_pages'=>n, 'grade_wide'=>bool, 'grade'=>label].
     */
    function bot_exam_design_pdf_path($examId) {
        require_once __DIR__ . '/report_image.php';
        require_once __DIR__ . '/exams_helper.php';
        if (function_exists('ensure_exams_schema')) ensure_exams_schema();
        $examId = (int)$examId;
        $exam = DB::fetch("SELECT es.*, COALESCE(t.full_name, es.teacher_name) AS t_name, t.last_name AS t_last FROM exam_schedules es LEFT JOIN teachers t ON t.id=es.teacher_id WHERE es.id=?", [$examId]);
        $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
        if (!$exam || !$design) throw new RuntimeException('طراحی این آزمون یافت نشد.');
        $dj = json_decode($design['design_json'] ?? '', true) ?: [];

        /* ---------- design payload ---------- */
        $style     = is_array($dj['style'] ?? null) ? $dj['style'] : [];
        $crops     = is_array($dj['sourceCrops'] ?? null) ? $dj['sourceCrops'] : [];
        $orderRaw  = is_array($dj['sourceOrder'] ?? null) ? array_values($dj['sourceOrder']) : [];
        $drawings  = is_array($dj['drawings'] ?? null) ? $dj['drawings'] : [];
        $questions = is_array($dj['questions'] ?? null) ? $dj['questions'] : [];
        $headerFields = is_array($dj['headerFields'] ?? null) && $dj['headerFields'] ? $dj['headerFields'] : [
            ['key'=>'studentName','label'=>'نام','source'=>'name','on'=>true],
            ['key'=>'class','label'=>'کلاس','source'=>'class_name','on'=>true],
            ['key'=>'teacher','label'=>'دبیر','value'=>'','on'=>true],
            ['key'=>'subject','label'=>'درس','value'=>'','on'=>true],
            ['key'=>'seat','label'=>'صندلی','source'=>'seat','on'=>true],
            ['key'=>'date','label'=>'تاریخ','value'=>'','on'=>true],
            ['key'=>'time','label'=>'ساعت','value'=>'','on'=>true],
            ['key'=>'score','label'=>'نمره','value'=>'','on'=>true],
        ];
        /* v4.92.0: در طرح‌های ذخیره‌شده قدیمی هم جای «دبیر» و «صندلی» عوض می‌شود */
        $hfSeat = $hfTeacher = -1;
        foreach ($headerFields as $hfI => $hf) { $hk = $hf['key'] ?? ''; if ($hk === 'seat' && $hfSeat < 0) $hfSeat = $hfI; if ($hk === 'teacher' && $hfTeacher < 0) $hfTeacher = $hfI; }
        if ($hfSeat > -1 && $hfTeacher > -1 && $hfSeat < $hfTeacher) { $tmp = $headerFields[$hfSeat]; $headerFields[$hfSeat] = $headerFields[$hfTeacher]; $headerFields[$hfTeacher] = $tmp; }

        /* ---------- geometry (same defaults as the print page CSS vars) ---------- */
        $S = 6.0;                                     // render scale: px per mm (~152 dpi — الف-۳: سقف DPI خروجی نهایی)
        $PX = 25.4 / 96.0 * $S;                       // css-px → render-px
        $W = (int)round(210 * $S); $H = (int)round(297 * $S);
        $headerH = 39; /* v4.101.0: ارتفاع سربرگ ثابت ۳۹mm — تنظیم‌گر حذف شد، مقادیر قدیمی نادیده گرفته می‌شود */
        /* v4.91.0: اندازه عکس (۳×۴) و مهر ثابت است — تنظیم ذخیره‌شده قدیمی نادیده گرفته می‌شود */
        $photoMm = 20; $stampMm = 18; /* v4.95.0: مهر ۱۸ + کادر شماره صندلی زیرش = هم‌ارتفاع عکس */
        $qWmm    = bot_exam_css_mm($style['--qW'] ?? '', 203);
        $qHmm    = bot_exam_css_mm($style['--qH'] ?? '', 260);
        $qTopMm  = bot_exam_css_mm($style['--qTop'] ?? '', 1);
        $qBorderCss = (float)str_replace('px', '', (string)($style['--qBorder'] ?? '1'));
        $bpx = max(0, (int)round($qBorderCss * $PX));

        $srcFiles = bot_exam_source_page_files($exam);
        $order = count($orderRaw) ? array_map('intval', $orderRaw) : range(1, count($srcFiles));
        $srcCount = max(1, count($order));
        if (!$srcFiles && !count($orderRaw)) $srcCount = 1;

        $qwrapW = function () use ($qWmm, $S) { return (int)round($qWmm * $S); };
        $qwrapH = function ($pageNo) use ($qHmm, $S) { return (int)round((($pageNo % 2) === 1 ? $qHmm : 286) * $S); };
        $qwrapX = (int)round((210 - $qWmm) / 2 * $S);
        $qwrapY = function ($pageNo) use ($headerH, $qTopMm, $S) { return (int)round((($pageNo % 2) === 1 ? ($headerH + $qTopMm) : 5) * $S); };

        $cropOf = function ($pageNo) use ($crops) {
            $c = $crops[(string)$pageNo] ?? ($crops[$pageNo] ?? []);
            return [
                't' => (float)($c['t'] ?? 0), 'r' => (float)($c['r'] ?? 0),
                'b' => (float)($c['b'] ?? 0), 'l' => (float)($c['l'] ?? 0),
                'w' => max(1.0, (float)($c['w'] ?? 100)),
                'x' => (float)($c['x'] ?? 0), 'y' => (float)($c['y'] ?? 0),
                'contrast' => (float)($c['contrast'] ?? 100), 'brightness' => (float)($c['brightness'] ?? 100),
            ];
        };
        $srcFileOf = function ($pageNo) use ($order, $srcFiles) {
            $idx = $order[$pageNo - 1] ?? 0;
            return ($idx >= 1 && isset($srcFiles[$idx - 1])) ? $srcFiles[$idx - 1] : '';
        };
        $natSize = [];
        $natOf = function ($file) use (&$natSize) {
            if (!isset($natSize[$file])) { $sz = @getimagesize($file); $natSize[$file] = $sz ? [$sz[0], $sz[1]] : [0, 0]; }
            return $natSize[$file];
        };
        /* v4.101.0: ارتفاع layout بلوک منبع = فقط بخش «دیده‌شده» (برش بالا/پایین کم
           می‌شود) — جدول سوالات دقیقا چسبیده به زیر قسمت برش‌خورده شروع می‌شود. */
        $srcLayoutH = function ($pageNo) use ($srcFileOf, $cropOf, $natOf, $qwrapW, $bpx) {
            $file = $srcFileOf($pageNo);
            if ($file === '') return 0;
            list($nw, $nh) = $natOf($file);
            if ($nw < 1) return 0;
            $c = $cropOf($pageNo);
            $dispW = $c['w'] / 100.0 * ($qwrapW() - 2 * $bpx);
            $vis = max(0.0, (100.0 - $c['t'] - $c['b']) / 100.0);
            return (int)round($dispW / $nw * $nh * $vis);
        };

        /* ---------- flow the typed questions across pages (same rules as the JS) ---------- */
        $noColW = (int)round(13 * $S); $scoreColW = (int)round(18 * $S);
        $tblBorder = max(1, (int)round(1 * $PX));
        $cellPad = (int)round(4 * $PX);
        $theadH = (int)round((11 * 1.45 + 8 + 2) * $PX);
        $contentColW = $qwrapW() - 2 * $bpx - $noColW - $scoreColW - 4 * $tblBorder;

        $rows = [];        // each: pageNo, y(rel qwrap), h, lines[], pt, no, score, imgs, lineH
        $qNo = 0;
        /* v4.102.0: سوالات تایپی فرم فقط «بعد از» صفحات فایل منبع چیده می‌شوند (مطابق ویرایشگر) */
        $qStartPage = (count($srcFiles) > 0 && count($order) > 0) ? count($order) + 1 : 1;
        $pageNo = $qStartPage;
        $tblYs = [];       // current table Y per page (rel qwrap)
        $rowCount = [];
        $startTableY = function ($p) use (&$tblYs, $srcLayoutH, $theadH) {
            if (!isset($tblYs[$p])) $tblYs[$p] = $srcLayoutH($p) + $theadH;
            return $tblYs[$p];
        };
        foreach ($questions as $q) {
            $type = $q['type'] ?? '';
            if ($type === 'page') { $pageNo++; continue; }
            if ($type !== 'q') continue;
            $qNo++;
            /* v4.101.0: شماره دستی سوال (اگر طراح وارد کرده) بر شماره خودکار مقدم است */
            $manualNo = trim((string)($q['no'] ?? ''));
            $rowNo = ($manualNo !== '') ? $manualNo : (string)$qNo;
            /* v4.94.0: فونت انتخابی سوال (یکان/ساحل/تیتر) در PDF ربات هم اعمال می‌شود */
            $qFontMap = ['Yekan' => '/uploads/Yekan/Yekan.ttf', 'Sahel' => '/uploads/Sahel/Sahel.ttf', 'BTitr' => '/uploads/B-Titr/B-Titr.ttf'];
            $qFontKey = (string)($q['font'] ?? '');
            $qFontFile = isset($qFontMap[$qFontKey]) && is_file(dirname(__DIR__) . $qFontMap[$qFontKey]) ? dirname(__DIR__) . $qFontMap[$qFontKey] : '';
            $fs = max(7.0, (float)($q['fontSize'] ?? 11));
            $pt = $fs * $PX * 0.75;
            $lineH = (int)round($fs * 1.45 * $PX);
            list($plainLines, $imgs) = bot_exam_q_plain((string)($q['html'] ?? ''));
            $lines = [];
            $prevOvW = $GLOBALS['gd_font_override'] ?? ''; if ($qFontFile !== '') $GLOBALS['gd_font_override'] = $qFontFile;
            foreach ($plainLines as $pl) foreach (bot_exam_wrap_text($pl, $pt, $contentColW - 2 * $cellPad) as $wl) $lines[] = $wl;
            $GLOBALS['gd_font_override'] = $prevOvW;
            $imgBottom = 0;
            foreach ($imgs as &$igRef) {
                $g = bot_exam_gd_from_bin($igRef['bin']);
                if ($g) { $igRef['nw'] = imagesx($g); $igRef['nh'] = imagesy($g); imagedestroy($g); }
                else { $igRef['nw'] = 0; $igRef['nh'] = 0; }
                if ($igRef['nw'] > 0) $imgBottom = max($imgBottom, ($igRef['y'] + $igRef['w'] * $igRef['nh'] / $igRef['nw']) * $PX);
            }
            unset($igRef);
            $minH = max(12.0, (float)($q['height'] ?? 18)) * $S;
            $contentH = max($minH, count($lines) * $lineH, (int)ceil($imgBottom));
            $rowH = (int)round($contentH + 2 * $cellPad + $tblBorder);
            $y = $startTableY($pageNo);
            $limit = $qwrapH($pageNo) - 2 * $bpx;
            if (($rowCount[$pageNo] ?? 0) > 0 && $y + $rowH > $limit + (int)round(4 * $PX)) {
                $pageNo++;
                $y = $startTableY($pageNo);
            }
            $rows[] = ['page' => $pageNo, 'y' => $y, 'h' => $rowH, 'lines' => $lines, 'pt' => $pt,
                       'lineH' => $lineH, 'no' => $rowNo, 'score' => trim((string)($q['score'] ?? '')), 'imgs' => $imgs, 'font' => $qFontFile];
            $tblYs[$pageNo] = $y + $rowH;
            $rowCount[$pageNo] = ($rowCount[$pageNo] ?? 0) + 1;
        }
        /* v4.102.0: اگر سوال تایپی وجود نداشته باشد، صفحه اضافه بعد از منبع ساخته نمی‌شود */
        $totalDesignPages = (!count($rows) && $pageNo === $qStartPage) ? max($srcCount, 1) : max($srcCount, $pageNo, 1);

        /* ---------- render the shared body of each design page ---------- */
        $bodyJpegs = [];
        for ($p = 1; $p <= $totalDesignPages; $p++) {
            $page = imagecreatetruecolor($W, $H);
            $white = imagecolorallocate($page, 255, 255, 255);
            imagefilledrectangle($page, 0, 0, $W, $H, $white);
            $qw = $qwrapW(); $qh = $qwrapH($p); $qx = $qwrapX; $qy = $qwrapY($p);

            // qwrap content canvas: automatic clipping like overflow:hidden
            $qc = imagecreatetruecolor($qw - 2 * $bpx, $qh - 2 * $bpx);
            $qcW = imagesx($qc); $qcH = imagesy($qc);
            imagefilledrectangle($qc, 0, 0, $qcW, $qcH, imagecolorallocate($qc, 255, 255, 255));

            // 1) source page with the designer's crop/shift/zoom/filters
            $file = $srcFileOf($p);
            if ($file !== '') {
                $src = bot_exam_gd_from_bin(@file_get_contents($file));
                if ($src) {
                    $c = $cropOf($p);
                    if (abs($c['contrast'] - 100) > 0.5) @imagefilter($src, IMG_FILTER_CONTRAST, (int)round(100 - $c['contrast']));
                    if (abs($c['brightness'] - 100) > 0.5) @imagefilter($src, IMG_FILTER_BRIGHTNESS, (int)round(($c['brightness'] - 100) * 2.55));
                    $nw = imagesx($src); $nh = imagesy($src);
                    $dispW = $c['w'] / 100.0 * $qcW;
                    $scale = $dispW / max(1, $nw);
                    // clip-path inset(t r b l) on the image box
                    $sx = (int)round($nw * $c['l'] / 100); $sy = (int)round($nh * $c['t'] / 100);
                    $sw = (int)round($nw * (100 - $c['l'] - $c['r']) / 100); $sh = (int)round($nh * (100 - $c['t'] - $c['b']) / 100);
                    if ($sw > 0 && $sh > 0) {
                        $dx = (int)round(($qcW - $dispW) / 2 + $c['x'] * $S + $sx * $scale);
                        /* v4.101.0: برش بالا از layout حذف شده — قسمت دیده‌شده از بالای بلوک شروع می‌شود */
                        $dy = (int)round($c['y'] * $S);
                        imagecopyresampled($qc, $src, $dx, $dy, $sx, $sy, (int)round($sw * $scale), (int)round($sh * $scale), $sw, $sh);
                    }
                    imagedestroy($src);
                }
            }

            // 2) typed questions table (only when this design actually has questions)
            $pageRows = array_values(array_filter($rows, function ($r) use ($p) { return $r['page'] === $p; }));
            if ($pageRows) {
                $ink = imagecolorallocate($qc, 17, 24, 39);
                $tblTop = $pageRows[0]['y'] - $theadH;
                $tw = $qcW;
                // thead
                $thBg = imagecolorallocate($qc, 241, 245, 249);
                imagefilledrectangle($qc, 0, $tblTop, $tw - 1, $tblTop + $theadH, $thBg);
                imagerectangle($qc, 0, $tblTop, $tw - 1, $tblTop + $theadH, $ink);
                imageline($qc, $tw - $noColW, $tblTop, $tw - $noColW, $tblTop + $theadH, $ink);
                imageline($qc, $scoreColW, $tblTop, $scoreColW, $tblTop + $theadH, $ink);
                $thPt = 11 * $PX * 0.75; $thBase = $tblTop + (int)round($theadH / 2 + $thPt * 0.5);
                gd_text($qc, 'شماره', $tw - (int)($noColW / 2), $thBase, $thPt, $ink, true, 'center');
                gd_text($qc, 'سوال', $scoreColW + (int)(($tw - $noColW - $scoreColW) / 2), $thBase, $thPt, $ink, true, 'center');
                gd_text($qc, 'بارم', (int)($scoreColW / 2), $thBase, $thPt, $ink, true, 'center');
                foreach ($pageRows as $r) {
                    $ry = $r['y']; $rh = $r['h'];
                    imagerectangle($qc, 0, $ry, $tw - 1, $ry + $rh, $ink);
                    imageline($qc, $tw - $noColW, $ry, $tw - $noColW, $ry + $rh, $ink);
                    imageline($qc, $scoreColW, $ry, $scoreColW, $ry + $rh, $ink);
                    $base = $ry + $cellPad + (int)round($r['lineH'] * 0.78);
                    gd_text($qc, tr_num($r['no'], 'fa'), $tw - (int)($noColW / 2), $base, $r['pt'], $ink, true, 'center');
                    if ($r['score'] !== '') gd_text($qc, tr_num($r['score'], 'fa'), (int)($scoreColW / 2), $base, $r['pt'], $ink, false, 'center');
                    $tx = $tw - $noColW - $tblBorder - $cellPad;
                    $ty = $base;
                    $prevOv = $GLOBALS['gd_font_override'] ?? ''; if (!empty($r['font'])) $GLOBALS['gd_font_override'] = $r['font'];
                    foreach ($r['lines'] as $ltxt) { gd_text($qc, $ltxt, $tx, $ty, $r['pt'], $ink, false, 'right'); $ty += $r['lineH']; }
                    $GLOBALS['gd_font_override'] = $prevOv;
                    // embedded images of the question (absolute inside the cell, like the editor)
                    foreach ($r['imgs'] as $ig) {
                        if ($ig['nw'] < 1) continue;
                        $g = bot_exam_gd_from_bin($ig['bin']);
                        if (!$g) continue;
                        $dw = (int)round($ig['w'] * $PX); $dh = (int)round($dw * $ig['nh'] / $ig['nw']);
                        $cellX = $scoreColW + $tblBorder; // LTR-left edge of content cell
                        imagecopyresampled($qc, $g, $cellX + (int)round($ig['x'] * $PX), $ry + $cellPad + (int)round($ig['y'] * $PX), 0, 0, $dw, $dh, $ig['nw'], $ig['nh']);
                        imagedestroy($g);
                    }
                }
            }

            // 3) handwriting overlay (stretched over the whole qwrap, like the print page)
            $dData = $drawings[(string)$p] ?? ($drawings[$p] ?? '');
            if (is_string($dData) && strpos($dData, 'base64,') !== false) {
                $bin = base64_decode(substr($dData, strpos($dData, 'base64,') + 7));
                $ov = $bin ? bot_exam_gd_from_bin($bin) : null;
                if ($ov) {
                    imagealphablending($qc, true);
                    imagecopyresampled($qc, $ov, 0, 0, 0, 0, $qcW, $qcH, imagesx($ov), imagesy($ov));
                    imagedestroy($ov);
                }
            }

            // note when a PDF source was never converted to images (rare, offline desktop)
            if ($file === '' && (string)($exam['question_file'] ?? '') !== '' && !$srcFiles && !$pageRows) {
                $mut = imagecolorallocate($qc, 100, 116, 139);
                gd_text($qc, 'فایل منبع این آزمون هنوز به تصویر تبدیل نشده است؛ یک‌بار صفحه چاپ آزمون را در سایت باز کنید و دوباره PDF بگیرید.', $qcW - 30, 60, 11 * $PX * 0.75, $mut, false, 'right');
            }

            imagecopy($page, $qc, $qx + $bpx, $qy + $bpx, 0, 0, $qcW, $qcH);
            imagedestroy($qc);
            if ($bpx > 0) {
                $bcol = imagecolorallocate($page, 0, 0, 0);
                for ($t = 0; $t < $bpx; $t++) imagerectangle($page, $qx + $t, $qy + $t, $qx + $qw - 1 - $t, $qy + $qh - 1 - $t, $bcol);
            }
            $bodyJpegs[$p] = bot_exam_page_jpeg($page); /* v4.98.0: خاکستری خودکار + کیفیت تطبیقی */
            imagedestroy($page);
        }

        /* ---------- per-student header strips ---------- */
        list($students, $gradeWide, $gradeLabel) = bot_exam_pdf_students($exam);
        if (count($students) > 600) throw new RuntimeException('تعداد دانش‌آموزان این آزمون بیش از حد مجاز ربات است؛ از چاپ سایت استفاده کنید.');
        $seatMap = [];
        foreach (DB::fetchAll("SELECT student_id, seat_number FROM exam_student_seating WHERE academic_year=? ORDER BY id", [$exam['academic_year']]) as $sm) {
            $seatMap[(int)$sm['student_id']] = (string)$sm['seat_number'];
        }
        $school = get_setting('school_name', 'آموزشگاه');
        $exTitle = get_setting('exam_header_title', $school . ' - سربرگ رسمی آزمون');
        $exSub = get_setting('exam_header_subtitle', '');
        $stampFile = '';
        $stampSet = (string)get_setting('exam_stamp_url', '');
        if ($stampSet !== '' && strpos($stampSet, 'http') !== 0) {
            $sp = dirname(__DIR__) . '/' . ltrim($stampSet, '/');
            if (is_file($sp)) $stampFile = $sp;
        }
        $fixedVals = [
            'subject' => (string)($exam['subject_name'] ?? ''),
            'teacher' => teacher_respectful_name(['last_name' => $exam['t_last'] ?? '', 'full_name' => $exam['t_name'] ?? '']), /* v4.94.0: فقط آقای + نام خانوادگی */
            'date' => tr_num((string)($exam['exam_date_jalali'] ?? ''), 'fa'),
            'time' => tr_num((string)($exam['start_time'] ?? ''), 'fa'),
        ];
        $hH = (int)round($headerH * $S);
        $mm4 = (int)round(4 * $S); $mm3 = (int)round(3 * $S); $mm2 = (int)round(2 * $S);
        $photoW = (int)round($photoMm * $S); $stampW = (int)round($stampMm * $S);
        $stampImg = $stampFile !== '' ? bot_exam_gd_from_bin(@file_get_contents($stampFile)) : null;

        /* v4.93.0: فونت سربرگ = B-Titr (بدون Bold)؛ اگر فونت روی هاست نبود، وزیرمتن */
        $btitr = dirname(__DIR__) . '/uploads/B-Titr/B-Titr.ttf';
        $GLOBALS['gd_font_override'] = is_file($btitr) ? $btitr : '';
        $headerJpegs = [];
        foreach ($students as $si => $st) {
            $strip = imagecreatetruecolor($W, $hH);
            $white = imagecolorallocate($strip, 255, 255, 255);
            $ink = imagecolorallocate($strip, 17, 24, 39);
            $box = imagecolorallocate($strip, 51, 51, 51);
            imagefilledrectangle($strip, 0, 0, $W, $hH, $white);
            imagerectangle($strip, 0, 0, $W - 1, $hH - 1, $ink);
            // photo box (right, RTL first grid column) — v4.91.0: نسبت ۳×۴
            $photoH = (int)round($photoW * 4 / 3);
            $px1 = $W - $mm4 - $photoW; $py1 = (int)(($hH - $photoH) / 2); if ($py1 < 2) $py1 = 2;
            imagerectangle($strip, $px1, $py1, $px1 + $photoW, $py1 + $photoH, $ink);
            $photoPath = '';
            $pu = (string)($st['photo_url'] ?? '');
            if ($pu !== '' && strpos($pu, 'http') !== 0) { $pp = dirname(__DIR__) . '/' . ltrim($pu, '/'); if (is_file($pp)) $photoPath = $pp; }
            if ($photoPath !== '') {
                $ph = bot_exam_gd_from_bin(@file_get_contents($photoPath));
                if ($ph) {   // object-fit: cover در کادر ۳×۴
                    $pw0 = imagesx($ph); $ph0 = imagesy($ph);
                    $sc = max($photoW / max(1, $pw0), $photoH / max(1, $ph0));
                    $cw = (int)round($photoW / $sc); $ch = (int)round($photoH / $sc);
                    $ox = (int)(($pw0 - $cw) / 2); $oy = (int)(($ph0 - $ch) / 2);
                    imagecopyresampled($strip, $ph, $px1 + 1, $py1 + 1, $ox, $oy, $photoW - 1, $photoH - 1, $cw, $ch);
                    imagedestroy($ph);
                }
            } else {
                gd_text($strip, 'عکس', $px1 + (int)($photoW / 2), $py1 + (int)($photoH / 2) + 6, 9 * $PX * 0.75, $box, false, 'center');
            }
            // stamp column (left) — v4.95.0: مهر + کادر شماره صندلی زیر آن، مجموع = ارتفاع عکس ۳×۴
            $colH = $photoH;                       // کل ستون هم‌ارتفاع عکس
            $sx1 = $mm4; $sy1 = $py1;              // بالای ستون = بالای عکس (تراز دو سمت)
            $seatBoxH = $colH - $stampW;           // ارتفاع کادر شماره
            imagerectangle($strip, $sx1, $sy1, $sx1 + $stampW, $sy1 + $stampW, $ink);
            if ($stampImg) {   // object-fit: contain
                $sw0 = imagesx($stampImg); $sh0 = imagesy($stampImg);
                $sc = min(($stampW - 2) / max(1, $sw0), ($stampW - 2) / max(1, $sh0));
                $dw = (int)round($sw0 * $sc); $dh = (int)round($sh0 * $sc);
                imagecopyresampled($strip, $stampImg, $sx1 + (int)(($stampW - $dw) / 2), $sy1 + (int)(($stampW - $dh) / 2), 0, 0, $dw, $dh, $sw0, $sh0);
            } else {
                gd_text($strip, 'مهر', $sx1 + (int)($stampW / 2), $sy1 + (int)($stampW / 2) + 6, 9 * $PX * 0.75, $box, false, 'center');
            }
            // v4.95.0: کادر شماره صندلی چسبیده به خط پایین مهر (فقط عدد)
            if ($seatBoxH > 4) {
                imagerectangle($strip, $sx1, $sy1 + $stampW, $sx1 + $stampW, $sy1 + $colH, $ink);
                $seatVal = tr_num($seatMap[(int)($st['id'] ?? 0)] ?? '', 'fa');
                if ($seatVal !== '') gd_text($strip, $seatVal, $sx1 + (int)($stampW / 2), $sy1 + $stampW + (int)($seatBoxH / 2) + (int)round(13 * $PX * 0.75 * 0.45), 13 * $PX * 0.75, $ink, false, 'center');
            }
            // center column
            $cx1 = $sx1 + $stampW + $mm3; $cx2 = $px1 - $mm3;
            $cw = $cx2 - $cx1;
            $stVals = [
                'name' => trim(($st['first_name'] ?? '') . ' ' . ($st['last_name'] ?? '')),
                'class_name' => (string)($st['class_name'] ?? ''),
                'seat' => tr_num($seatMap[(int)($st['id'] ?? 0)] ?? '---', 'fa'),
            ];
            $fieldsOn = array_values(array_filter($headerFields, function ($f) { return !empty($f['on']); }));
            $rowsN = (int)ceil(count($fieldsOn) / 4);
            $boxH = (int)round((13 * 1.45) * $PX + 2 * $S);
            $gap = (int)round(1.2 * $S);
            $titlePt = 15 * $PX * 0.75; $subPt = 12 * $PX * 0.75; $fieldPt = 13 * $PX * 0.75;
            $blockH = (int)round($titlePt / 0.75) + ($exSub !== '' ? (int)round($subPt / 0.75 * 1.3) : 0) + (int)round(1.5 * $S) + $rowsN * ($boxH + $gap);
            $yCur = max($mm2, (int)(($hH - $blockH) / 2));
            $yCur += (int)round($titlePt / 0.75 * 0.9);
            gd_text($strip, $exTitle, $cx1 + (int)($cw / 2), $yCur, $titlePt, $ink, false, 'center');
            if ($exSub !== '') { $yCur += (int)round($subPt / 0.75 * 1.35); gd_text($strip, $exSub, $cx1 + (int)($cw / 2), $yCur, $subPt, $ink, false, 'center'); }
            $yCur += (int)round(1.5 * $S) + 2;
            $boxW = (int)(($cw - 3 * $gap) / 4);
            foreach ($fieldsOn as $fi => $f) {
                $col = $fi % 4; $rowI = (int)($fi / 4);
                $bx2 = $cx2 - $col * ($boxW + $gap);           // RTL: first box at right
                $bx1 = $bx2 - $boxW;
                $by1 = $yCur + $rowI * ($boxH + $gap);
                imagerectangle($strip, $bx1, $by1, $bx2, $by1 + $boxH, $box);
                $val = (isset($f['value']) && $f['value'] !== '') ? (string)$f['value'] : (string)($stVals[$f['source'] ?? ''] ?? ($fixedVals[$f['key'] ?? ''] ?? ''));
                if (($f['key'] ?? '') === 'subject' && $val === '') $val = $fixedVals['subject'];
                /* v4.95.0: نام دبیر همیشه از سرور (آقای + نام خانوادگی) — مقدار ذخیره‌شده قدیمی نادیده گرفته می‌شود */
                if (($f['key'] ?? '') === 'teacher' && $fixedVals['teacher'] !== '') $val = $fixedVals['teacher'];
                gd_text($strip, ($f['label'] ?? '') . ': ' . $val, $bx2 - (int)round(1 * $S), $by1 + (int)round($boxH * 0.72), $fieldPt, $ink, false, 'right');
            }
            $headerJpegs[$si] = bot_exam_page_jpeg($strip); /* v4.98.0: سربرگ با عکس رنگی خودکار کیفیت بالا می‌گیرد */
            imagedestroy($strip);
        }
        $GLOBALS['gd_font_override'] = ''; /* v4.93.0: بقیه صفحه با فونت پیش‌فرض */
        if ($stampImg) imagedestroy($stampImg);

        /* ---------- assemble the PDF: shared body XObjects + per-student headers ---------- */
        $pw = 595.28; $phh = 841.89;
        $hhPt = $headerH * 72.0 / 25.4;
        /* v4.98.0 (ب-۱): حاشیه امن چاپ ۵ میلی‌متر — هیچ محتوایی داخل ناحیه غیرقابل چاپ
           پرینترهای لیزری معمولی نمی‌افتد؛ کل صفحه با مقیاس یکنواخت (~۹۵٪) وسط‌چین می‌شود
           تا نسبت ابعاد و کیفیت حفظ شود و کادر سربرگ دیگر لب کاغذ بریده نشود. */
        $safeM = 5 * 72.0 / 25.4;
        $sclSafe = ($pw - 2 * $safeM) / $pw;
        $bwSafe = $pw * $sclSafe; $bhSafe = $phh * $sclSafe;
        $bxSafe = ($pw - $bwSafe) / 2; $bySafe = ($phh - $bhSafe) / 2;
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $n = 3;
        $bodyRef = [];
        foreach ($bodyJpegs as $p => $jpeg) {
            $bodyRef[$p] = $n;
            $objs[$n] = "<< /Type /XObject /Subtype /Image /Width {$W} /Height {$H} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
            $n++;
        }
        $hW = $W; $hHpx = $hH;
        $headRef = [];
        foreach ($headerJpegs as $si => $jpeg) {
            $headRef[$si] = $n;
            $objs[$n] = "<< /Type /XObject /Subtype /Image /Width {$hW} /Height {$hHpx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
            $n++;
        }
        $kids = [];
        /* v4.101.0: چاپ دورو — اگر تعداد صفحات هر دانش‌آموز فرد است، بعد از آخرین
           صفحه او یک صفحه خالی می‌آید تا برگه دانش‌آموز بعدی پشت برگه او چاپ نشود.
           v4.103.0: آزمون تک‌صفحه‌ای استثناست — یک‌رو چاپ می‌شود و صفحه خالی لازم ندارد. */
        $padBlank = ($totalDesignPages % 2) === 1 && $totalDesignPages > 1;
        $blankContentNum = 0;
        if ($padBlank) {
            $blankContent = 'q Q';
            $blankContentNum = $n; $n++;
            $objs[$blankContentNum] = "<< /Length " . strlen($blankContent) . " >>\nstream\n" . $blankContent . "\nendstream";
        }
        foreach ($students as $si => $st) {
            for ($p = 1; $p <= $totalDesignPages; $p++) {
                $isOdd = ($p % 2) === 1;
                $content = sprintf("q\n%.2F 0 0 %.2F %.2F %.2F cm\n/B Do\nQ", $bwSafe, $bhSafe, $bxSafe, $bySafe);
                $res = "/B " . $bodyRef[$p] . " 0 R";
                if ($isOdd) {
                    $hhScaled = $hhPt * $sclSafe;
                    $content .= sprintf("\nq\n%.2F 0 0 %.2F %.2F %.2F cm\n/H Do\nQ", $bwSafe, $hhScaled, $bxSafe, $bySafe + $bhSafe - $hhScaled);
                    $res .= " /H " . $headRef[$si] . " 0 R";
                }
                $pageNumO = $n; $contNum = $n + 1; $n += 2;
                $kids[] = $pageNumO . ' 0 R';
                $objs[$pageNumO] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$phh}] /Resources << /XObject << {$res} >> /ProcSet [/PDF /ImageC] >> /Contents {$contNum} 0 R >>";
                $objs[$contNum] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            }
            if ($padBlank) { /* v4.101.0: صفحه خالی جداکننده بین دانش‌آموزان (چاپ دورو) */
                $blankPageNum = $n; $n++;
                $kids[] = $blankPageNum . ' 0 R';
                $objs[$blankPageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$phh}] /Resources << /ProcSet [/PDF] >> /Contents {$blankContentNum} 0 R >>";
            }
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $maxObj = max(array_keys($objs));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($i2 = 1; $i2 <= $maxObj; $i2++) {
            $pdf .= isset($offsets[$i2]) ? sprintf("%010d 00000 n \n", $offsets[$i2]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";
        $dir = dirname(__DIR__) . '/uploads/exams/bot-pdf';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (glob($dir . '/*.pdf') ?: [] as $old) { if (filemtime($old) < time() - 3600) @unlink($old); }
        $path = $dir . '/exam-design-' . $examId . '.pdf';
        file_put_contents($path, $pdf);
        return ['path' => $path, 'students' => count($students), 'pages_per_student' => $totalDesignPages,
                'total_pages' => count($kids), 'grade_wide' => $gradeWide, 'grade' => $gradeLabel,
                'blank_pad' => $padBlank, /* v4.101.0 */
                'class_ranges' => bot_exam_class_page_ranges($students, $totalDesignPages + ($padBlank ? 1 : 0))]; /* v4.98.0/v4.101.0: با احتساب صفحه خالی */
    }
}

if (!function_exists('bot_send_exam_design_pdf')) {
    function bot_send_exam_design_pdf($platform, $chatId, $examId) {
        $exam = DB::fetch("SELECT * FROM exam_schedules WHERE id=?", [(int)$examId]);
        if (!$exam) throw new RuntimeException('آزمون یافت نشد.');
        $info = bot_exam_design_pdf_path($examId);
        $where = $info['grade_wide']
            ? ('پایه ' . $info['grade'] . ' — همه کلاس‌ها')
            : trim(($exam['grade_level'] ?: '') . ' ' . ($exam['class_name'] ?: ''));
        $caption = '📄 آزمون ' . $exam['subject_name'] . ($where !== '' ? ' (' . $where . ')' : '') . ' — ' . tr_num($exam['exam_date_jalali'] ?: ($exam['exam_month'] ?: ''), 'fa')
                 . "\n👥 " . tr_num($info['students'], 'fa') . ' دانش‌آموز × ' . tr_num($info['pages_per_student'], 'fa') . ' صفحه، همگی سربرگ‌خورده ('
                 . tr_num($info['total_pages'], 'fa') . ' صفحه)';
        /* v4.98.0 (ب-۴): راهنمای چاپ برای اپراتور — بدون بازکردن فایل تنظیمات را بزند */
        $caption .= "\n🖨 چاپ: A4 عمودی — سیاه‌وسفید کافی است";
        if (!empty($info['blank_pad'])) $caption .= "\n📃 بعد از هر دانش‌آموز یک صفحه خالی است (مخصوص چاپ دورو — برگه نفر بعد پشت برگه قبلی نمی‌افتد)";
        $ranges = $info['class_ranges'] ?? [];
        if (count($ranges) > 1) {
            $parts = [];
            foreach ($ranges as $cls => $rg) $parts[] = $cls . ': ص ' . tr_num($rg['from'], 'fa') . '–' . tr_num($rg['to'], 'fa');
            $line = '📑 ' . implode(' | ', $parts);
            if (mb_strlen($caption, 'UTF-8') + mb_strlen($line, 'UTF-8') > 950) $line = mb_substr($line, 0, 940 - mb_strlen($caption, 'UTF-8'), 'UTF-8') . '…';
            $caption .= "\n" . $line;
        }
        $res = bot_send_document($platform, $chatId, $info['path'], $caption, null, 'application/pdf', 'Azmoon-' . (int)$examId . '.pdf');
        /* v4.101.0: ارسال موفق بود (خطا exception می‌داد) → فایل PDF از هاست حذف
           می‌شود؛ نسخه روی سرورهای پیام‌رسان است و فضای هاست آزاد می‌ماند. */
        @unlink($info['path']);
        return $res;
    }
}

/* ───────────── grade-objection: alert + inline reply / edit ───────────── */

if (!function_exists('bot_notify_grade_objection')) {
    /**
     * Called when a student files a grade objection (grade_messages row).
     * Sends the alert WITH the student's own text and a «پاسخ به اعتراض»
     * glass button to every active bot session of the subject teacher.
     */
    function bot_notify_grade_objection($msgId) {
        try {
            $gm = DB::fetch("SELECT gm.*, s.first_name, s.last_name, s.class_name, r.report_month, r.academic_year FROM grade_messages gm JOIN students s ON s.id=gm.student_id JOIN reports r ON r.id=gm.report_id WHERE gm.id=?", [(int)$msgId]);
            if (!$gm || empty($gm['teacher_id'])) return 0;
            $g = DB::fetch("SELECT score FROM report_grades WHERE report_id=? AND subject_name=?", [$gm['report_id'], $gm['subject_name']]);
            $msg = "💬 اعتراض جدید به نمره\n"
                 . "👤 دانش‌آموز: " . trim($gm['first_name'] . ' ' . $gm['last_name']) . ' (' . ($gm['class_name'] ?: '—') . ")\n"
                 . "📚 درس: " . $gm['subject_name'] . " | ماه: " . ($gm['report_month'] ?: '—') . "\n"
                 . "🔢 نمره فعلی: " . tr_num($g['score'] ?? '—', 'fa') . "\n\n"
                 . "📝 متن اعتراض دانش‌آموز:\n" . mb_substr((string)$gm['message'], 0, 800, 'UTF-8');
            $kb = ['inline_keyboard' => [[['text' => '✍️ پاسخ به اعتراض', 'callback_data' => 'objreply_' . (int)$msgId]]]];
            return bot_notify_teacher_chats((int)$gm['teacher_id'], $msg, $kb);
        } catch (Exception $e) {
            error_log('bot_notify_grade_objection failed: ' . $e->getMessage());
            return 0;
        }
    }
}

/* ─────────────────────── webhook plumbing helpers ─────────────────────── */

if (!function_exists('bot_role_handle_callback')) {
    /**
     * Handles role-specific callback buttons. Returns true when consumed.
     *   exampdf_<examId>   executive deputy asks for the design PDF
     *   objreply_<msgId>   teacher starts the objection-reply flow
     */
    function bot_role_handle_callback($platform, $chatId, $data, $stateTable, $chatCol) {
        $staff = bot_staff_session($platform, $chatId);

        if (strpos($data, 'exampdf_') === 0) {
            $examId = (int)substr($data, 8);
            if (!$staff || empty($staff['is_executive'])) {
                bot_staff_send($platform, $chatId, 'این گزینه مخصوص معاون اجرایی است. ابتدا با نقش معاون اجرایی وارد شوید.');
                return true;
            }
            bot_staff_send($platform, $chatId, '⏳ در حال آماده‌سازی نسخه PDF آزمون... چند لحظه صبر کنید.');
            try {
                bot_send_exam_design_pdf($platform, $chatId, $examId);
            } catch (Exception $e) {
                bot_staff_send($platform, $chatId, 'ساخت PDF آزمون با خطا روبه‌رو شد: ' . $e->getMessage());
            }
            return true;
        }

        if (strpos($data, 'objreply_') === 0) {
            $msgId = (int)substr($data, 9);
            if (!$staff) {
                bot_staff_send($platform, $chatId, 'برای پاسخ به اعتراض ابتدا با حساب دبیر وارد ربات شوید (کد ملی سپس کد پرسنلی).');
                return true;
            }
            $gm = DB::fetch("SELECT gm.*, s.first_name, s.last_name FROM grade_messages gm JOIN students s ON s.id=gm.student_id WHERE gm.id=?", [$msgId]);
            if (!$gm) { bot_staff_send($platform, $chatId, 'این اعتراض یافت نشد.'); return true; }
            if ((int)$gm['teacher_id'] !== (int)$staff['teacher_id']) {
                bot_staff_send($platform, $chatId, 'این اعتراض مربوط به درس شما نیست.');
                return true;
            }
            DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'objection_reply_text', ?, NULL)", [$chatId, (string)$msgId]);
            bot_staff_send($platform, $chatId, '✍️ پاسخ به اعتراض ' . trim($gm['first_name'] . ' ' . $gm['last_name']) . " — درس {$gm['subject_name']}\n\nمتن پاسخ خود به دانش‌آموز را بنویسید و ارسال کنید:");
            return true;
        }

        if (strpos($data, 'objgrade_') === 0) {   // keep-grade / change-grade decision
            $parts = explode('_', $data);          // objgrade_keep_<id> | objgrade_edit_<id>
            $mode = $parts[1] ?? '';
            $msgId = (int)($parts[2] ?? 0);
            if (!$staff) { bot_staff_send($platform, $chatId, 'ابتدا با حساب دبیر وارد شوید.'); return true; }
            $gm = DB::fetch("SELECT * FROM grade_messages WHERE id=?", [$msgId]);
            if (!$gm || (int)$gm['teacher_id'] !== (int)$staff['teacher_id']) {
                bot_staff_send($platform, $chatId, 'این اعتراض مربوط به درس شما نیست.');
                return true;
            }
            if ($mode === 'keep') {
                DB::execute("DELETE FROM `$stateTable` WHERE `$chatCol`=?", [$chatId]);
                bot_role_finish_objection($platform, $chatId, $msgId, null, $staff);
                return true;
            }
            if ($mode === 'edit') {
                DB::execute("REPLACE INTO `$stateTable` (`$chatCol`, step, temp_nid, temp_payload) VALUES (?, 'objection_new_grade', ?, NULL)", [$chatId, (string)$msgId]);
                bot_staff_send($platform, $chatId, '🔢 نمره جدید این درس را ارسال کنید (مثال: 17.5):');
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('bot_role_finish_objection')) {
    /**
     * Saves the teacher's reply (and optionally the new grade), notifies the
     * student's linked parent chats on both platforms, and confirms.
     * $newScore = null → grade unchanged.
     */
    function bot_role_finish_objection($platform, $chatId, $msgId, $newScore, $staff) {
        $gm = DB::fetch("SELECT gm.*, s.first_name, s.last_name FROM grade_messages gm JOIN students s ON s.id=gm.student_id WHERE gm.id=?", [(int)$msgId]);
        if (!$gm) { bot_staff_send($platform, $chatId, 'اعتراض یافت نشد.'); return; }
        $reply = trim((string)($gm['reply'] ?? ''));
        $changed = '';
        if ($newScore !== null) {
            $rg = DB::fetch("SELECT id, score FROM report_grades WHERE report_id=? AND subject_name=?", [$gm['report_id'], $gm['subject_name']]);
            if ($rg) {
                $status = (float)$newScore == 21.0 ? 'none' : ((float)$newScore >= 10 ? 'passed' : 'failed');
                DB::execute("UPDATE report_grades SET score=?, status=? WHERE id=?", [(float)$newScore, $status, $rg['id']]);
                // recompute GPA like teacher-panel does (via shared extractor on next view);
                // update the aggregate right away for accuracy:
                try {
                    $all = DB::fetchAll("SELECT * FROM report_grades WHERE report_id=?", [$gm['report_id']]);
                    $parsed = extract_clean_grades_and_discipline($all, null);
                    if (($parsed['count'] ?? 0) > 0) {
                        DB::execute("UPDATE reports SET gpa=?, total_score=? WHERE id=?", [$parsed['calculated_gpa'], $parsed['calculated_gpa'] * $parsed['count'], $gm['report_id']]);
                    }
                } catch (Exception $e) {}
                $changed = "\n🔢 نمره درس از " . tr_num($rg['score'], 'fa') . ' به ' . tr_num($newScore, 'fa') . ' تغییر کرد.';
                log_activity(null, 'تغییر نمره از ربات (پاسخ به اعتراض)', "دبیر {$staff['full_name']} | دانش‌آموز {$gm['first_name']} {$gm['last_name']} | درس {$gm['subject_name']} | نمره جدید {$newScore}");
            } else {
                $changed = "\n⚠️ رکورد نمره این درس یافت نشد؛ فقط پاسخ متنی ثبت شد.";
            }
        }
        DB::execute("UPDATE grade_messages SET status='replied', replied_at=NOW() WHERE id=?", [(int)$msgId]);
        // notify the student's linked chats on BOTH platforms
        $tFam = function_exists('teacher_respectful_name') ? teacher_respectful_name($staff) : $staff['full_name'];
        $stMsg = "📣 پاسخ دبیر به اعتراض شما\n"
               . "📚 درس: " . $gm['subject_name'] . "\n"
               . "👨‍🏫 دبیر: " . $tFam . "\n\n"
               . "💬 پاسخ:\n" . ($reply !== '' ? $reply : '—')
               . ($changed !== '' ? "\n" . trim($changed) : '');
        $notified = 0;
        foreach (['bale', 'telegram'] as $pf) {
            try {
                ensure_bot_schema($pf);
                $tbl = bot_user_table($pf);
                $col = $pf === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
                $chats = DB::fetchAll("SELECT DISTINCT `$col` AS cid FROM `$tbl` WHERE student_id=?", [(int)$gm['student_id']]);
                foreach ($chats as $c) {
                    try { bot_send_message($pf, $c['cid'], $stMsg); $notified++; }
                    catch (Exception $e) {}
                }
            } catch (Exception $e) {}
        }
        bot_staff_send($platform, $chatId, "✅ پاسخ اعتراض ثبت شد." . ($changed !== '' ? trim($changed) : '') . ($notified ? "\n📨 به " . tr_num($notified, 'fa') . " چت متصل دانش‌آموز اطلاع داده شد." : "\nℹ️ دانش‌آموز هنوز به ربات متصل نیست؛ پاسخ در کارنامه سایت ثبت شد."), bot_staff_keyboard($staff));
    }
}
