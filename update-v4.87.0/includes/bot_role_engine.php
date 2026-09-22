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
        $s = DB::fetch("SELECT bs.*, t.full_name, t.national_id, t.personnel_code, t.is_deputy, t.is_executive, t.is_counselor FROM bot_admin_sessions bs JOIN teachers t ON t.id = bs.teacher_id WHERE bs.platform=? AND bs.chat_id=? AND bs.role_type='teacher' AND bs.is_active=1 ORDER BY bs.id DESC LIMIT 1", [bot_valid_platform($platform), (string)$chatId]);
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
        $rows = DB::fetchAll("SELECT d.exam_id, d.designer_name, d.updated_at_jalali, e.subject_name, e.exam_month, e.exam_date_jalali, e.grade_level, e.class_name FROM exam_designs d JOIN exam_schedules e ON e.id=d.exam_id WHERE e.academic_year=? AND e.is_active=1 ORDER BY d.updated_at_jalali DESC LIMIT 15", [$year]);
        if (!$rows) return ['🖨 هنوز آزمونی در سال ' . tr_num($year, 'fa') . ' طراحی نشده است.', null];
        $msg = '🖨 آخرین آزمون‌های طراحی‌شده — سال ' . tr_num($year, 'fa') . "\nبرای دریافت نسخه چاپی هر آزمون روی دکمه آن بزنید:";
        $inline = [];
        foreach ($rows as $r) {
            $where = trim(($r['grade_level'] ?: '') . ' ' . ($r['class_name'] ?: ''));
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
            if (function_exists('teacher_family_name')) $designer = teacher_family_name($designer);
            $where = trim(($exam['grade_level'] ?: '') . ' ' . ($exam['class_name'] ?: ''));
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

if (!function_exists('bot_exam_design_pdf_path')) {
    /**
     * Renders the saved design of an exam into a single PDF containing one
     * copy of the exam questions (A4). It reuses the pure-PHP PDF writer
     * approach of bot_generate_report_pdf: GD raster page → JPEG → PDF.
     * Returns the generated file path.
     */
    function bot_exam_design_pdf_path($examId) {
        require_once __DIR__ . '/report_image.php';
        if (function_exists('ensure_exams_schema')) ensure_exams_schema();
        $examId = (int)$examId;
        $exam = DB::fetch("SELECT * FROM exam_schedules WHERE id=?", [$examId]);
        $design = DB::fetch("SELECT * FROM exam_designs WHERE exam_id=?", [$examId]);
        if (!$exam || !$design) throw new RuntimeException('طراحی این آزمون یافت نشد.');
        $dj = json_decode($design['design_json'] ?? '', true) ?: [];
        $questions = is_array($dj['questions'] ?? null) ? $dj['questions'] : [];

        // A4 canvas at ~110 dpi
        $w = 910; $h = 1287; $margin = 55;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $ink   = imagecolorallocate($im, 17, 24, 39);
        $mut   = imagecolorallocate($im, 71, 85, 105);
        $line  = imagecolorallocate($im, 148, 163, 184);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        $y = $margin + 8;
        $school = get_setting('school_name', 'آموزشگاه');
        gd_text($im, $school, $w / 2, $y, 17, $ink, true, 'center'); $y += 34;
        $where = trim(($exam['grade_level'] ?: '') . ' ' . ($exam['class_name'] ?: ''));
        gd_text($im, 'آزمون ' . $exam['subject_name'] . ($where !== '' ? ' — ' . $where : ''), $w / 2, $y, 15, $ink, true, 'center'); $y += 30;
        $designer = $design['designer_name'] ?: ($exam['teacher_name'] ?: '');
        if (function_exists('teacher_family_name') && $designer !== '') $designer = teacher_family_name($designer);
        gd_text($im, 'نوبت: ' . ($exam['exam_month'] ?: '—') . '    تاریخ: ' . tr_num($exam['exam_date_jalali'] ?: '—', 'fa') . '    مدت: ' . tr_num($exam['duration_minutes'], 'fa') . ' دقیقه' . ($designer !== '' ? '    طراح: ' . $designer : ''), $w / 2, $y, 11, $mut, false, 'center'); $y += 20;
        imageline($im, $margin, $y, $w - $margin, $y, $line); $y += 22;

        $pages = [];   // extra GD pages if questions overflow
        $qNo = 0;
        $newPage = function () use ($w, $h, $white) {
            $p = imagecreatetruecolor($w, $h);
            imagefilledrectangle($p, 0, 0, $w, $h, imagecolorallocate($p, 255, 255, 255));
            return $p;
        };
        $cur = $im;
        foreach ($questions as $q) {
            if (($q['type'] ?? '') !== 'q') continue;
            $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($q['html'] ?? ''))));
            if ($plain === '') $plain = '— (سوال تصویری/ترسیمی — در نسخه کامل چاپ سایت ببینید)';
            $qNo++;
            $score = trim((string)($q['score'] ?? ''));
            $head = tr_num($qNo, 'fa') . ') ' . ($score !== '' ? '(' . tr_num($score, 'fa') . ' نمره) ' : '');
            // wrap text to page width (~55 chars per line for fa at size 12)
            $words = preg_split('/\s+/u', $head . $plain);
            $lines = []; $ln = '';
            foreach ($words as $word) {
                $try = $ln === '' ? $word : $ln . ' ' . $word;
                if (mb_strlen($try, 'UTF-8') > 72) { $lines[] = $ln; $ln = $word; }
                else $ln = $try;
            }
            if ($ln !== '') $lines[] = $ln;
            $need = count($lines) * 24 + 14;
            if ($y + $need > $h - $margin) { $pages[] = $cur; $cur = $newPage(); $y = $margin; }
            foreach ($lines as $i => $lt) {
                gd_text($cur, $lt, $w - $margin, $y, 12, imagecolorallocate($cur, 17, 24, 39), $i === 0, 'right');
                $y += 24;
            }
            $y += 14;
        }
        if ($qNo === 0) {
            gd_text($cur, 'این طراحی از فایل منبع (PDF/تصویر) استفاده می‌کند؛ متن سوال تایپی ندارد.', $w - $margin, $y, 12, $mut, false, 'right'); $y += 26;
            gd_text($cur, 'نسخه کامل گرافیکی را از صفحه چاپ آزمون در سایت دریافت کنید.', $w - $margin, $y, 12, $mut, false, 'right');
        }
        $pages[] = $cur;

        // pages → JPEGs → single PDF (same writer style as bot_generate_report_pdf)
        $jpegs = [];
        foreach ($pages as $p) {
            ob_start(); imagejpeg($p, null, 88); $jpegs[] = ob_get_clean(); imagedestroy($p);
        }
        $pw = 595.28; $phh = 841.89;
        $objs = [];
        $objs[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $kids = []; $n = 3;
        $pageObjs = [];
        foreach ($jpegs as $i => $jpeg) {
            $pageNum = $n; $contentNum = $n + 1; $imgNum = $n + 2; $n += 3;
            $kids[] = "$pageNum 0 R";
            $content = "q\n{$pw} 0 0 {$phh} 0 0 cm\n/Im{$i} Do\nQ";
            $pageObjs[$pageNum] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pw} {$phh}] /Resources << /XObject << /Im{$i} {$imgNum} 0 R >> /ProcSet [/PDF /ImageC] >> /Contents {$contentNum} 0 R >>";
            $pageObjs[$contentNum] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $pageObjs[$imgNum] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
        }
        $objs[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";
        foreach ($pageObjs as $num => $body) $objs[$num] = $body;
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
        $dir = __DIR__ . '/../uploads/exams/bot-pdf';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $path = $dir . '/exam-design-' . $examId . '.pdf';
        file_put_contents($path, $pdf);
        return $path;
    }
}

if (!function_exists('bot_send_exam_design_pdf')) {
    function bot_send_exam_design_pdf($platform, $chatId, $examId) {
        $exam = DB::fetch("SELECT * FROM exam_schedules WHERE id=?", [(int)$examId]);
        if (!$exam) throw new RuntimeException('آزمون یافت نشد.');
        $pdf = bot_exam_design_pdf_path($examId);
        $where = trim(($exam['grade_level'] ?: '') . ' ' . ($exam['class_name'] ?: ''));
        $caption = '📄 نسخه PDF آزمون ' . $exam['subject_name'] . ($where !== '' ? ' (' . $where . ')' : '') . ' — ' . tr_num($exam['exam_date_jalali'] ?: ($exam['exam_month'] ?: ''), 'fa');
        return bot_send_document($platform, $chatId, $pdf, $caption, null, 'application/pdf', 'Azmoon-' . (int)$examId . '.pdf');
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
        $tFam = function_exists('teacher_family_name') ? teacher_family_name($staff['full_name']) : $staff['full_name'];
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
