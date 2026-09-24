<?php
// File: includes/online_exam_helpers.php
/**
 * Online Exam System Helpers - Self-hosted Quiz Maker Pro equivalent (v21.8.4 features)
 * Covers: categories, question types, exam lifecycle, attempts, proctoring, webcam, anti-cheat
 */

require_once __DIR__ . '/functions.php';

if (!function_exists('ensure_online_exams_schema')) {
    function ensure_online_exams_schema() {
        // Exam categories
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            description text DEFAULT NULL,
            color varchar(20) DEFAULT '#3b82f6',
            sort_order int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Question categories
        DB::execute("CREATE TABLE IF NOT EXISTS online_question_categories (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            description text DEFAULT NULL,
            teacher_id int(11) DEFAULT NULL,
            academic_year varchar(20) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Online exams main
        DB::execute("CREATE TABLE IF NOT EXISTS online_exams (
            id int(11) NOT NULL AUTO_INCREMENT,
            title varchar(300) NOT NULL,
            description text DEFAULT NULL,
            category_id int(11) DEFAULT NULL,
            academic_year varchar(20) NOT NULL DEFAULT '1404/1405',
            grade_level varchar(100) DEFAULT '',
            class_name varchar(100) DEFAULT '',
            subject_name varchar(150) DEFAULT '',
            teacher_id int(11) DEFAULT NULL,
            created_by_admin_id int(11) DEFAULT NULL,
            exam_kind varchar(50) DEFAULT 'online',
            duration_minutes int(11) NOT NULL DEFAULT 60,
            max_attempts int(11) DEFAULT 1,
            passing_score float DEFAULT 0,
            randomize_questions tinyint(1) DEFAULT 0,
            randomize_answers tinyint(1) DEFAULT 0,
            show_results enum('after_submit','after_end','never','immediately') DEFAULT 'after_submit',
            start_datetime datetime DEFAULT NULL,
            end_datetime datetime DEFAULT NULL,
            status enum('draft','published','archived') DEFAULT 'draft',
            is_active tinyint(1) DEFAULT 1,
            allow_copy tinyint(1) DEFAULT 0,
            enable_webcam tinyint(1) DEFAULT 1,
            enable_location tinyint(1) DEFAULT 1,
            enable_proctoring tinyint(1) DEFAULT 1,
            enable_watermark tinyint(1) DEFAULT 1,
            watermark_text varchar(500) DEFAULT NULL,
            settings_json text DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_teacher (teacher_id),
            KEY idx_year_class (academic_year, class_name),
            KEY idx_status (status, is_active),
            KEY idx_dates (start_datetime, end_datetime)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Question bank (reusable)
        DB::execute("CREATE TABLE IF NOT EXISTS online_question_bank (
            id int(11) NOT NULL AUTO_INCREMENT,
            teacher_id int(11) DEFAULT NULL,
            category_id int(11) DEFAULT NULL,
            question_type varchar(50) NOT NULL DEFAULT 'radio',
            question_text text NOT NULL,
            question_data longtext DEFAULT NULL,
            points float DEFAULT 1,
            difficulty enum('easy','medium','hard') DEFAULT 'medium',
            is_public tinyint(1) DEFAULT 0,
            usage_count int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_teacher (teacher_id),
            KEY idx_category (category_id),
            KEY idx_type (question_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Questions per exam
        DB::execute("CREATE TABLE IF NOT EXISTS online_questions (
            id int(11) NOT NULL AUTO_INCREMENT,
            exam_id int(11) NOT NULL,
            bank_question_id int(11) DEFAULT NULL,
            category_id int(11) DEFAULT NULL,
            question_type varchar(50) NOT NULL DEFAULT 'radio',
            question_text text NOT NULL,
            question_data longtext DEFAULT NULL,
            points float DEFAULT 1,
            order_index int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_exam (exam_id),
            KEY idx_bank (bank_question_id),
            KEY idx_order (exam_id, order_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Attempts
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_attempts (
            id int(11) NOT NULL AUTO_INCREMENT,
            exam_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            attempt_number int(11) DEFAULT 1,
            start_time datetime NOT NULL,
            end_time datetime DEFAULT NULL,
            submitted_at datetime DEFAULT NULL,
            status enum('in_progress','submitted','auto_submitted','expired') DEFAULT 'in_progress',
            score float DEFAULT 0,
            max_score float DEFAULT 0,
            ip_address varchar(100) DEFAULT NULL,
            user_agent varchar(500) DEFAULT NULL,
            geo_lat double DEFAULT NULL,
            geo_lng double DEFAULT NULL,
            geo_accuracy float DEFAULT NULL,
            camera_ok tinyint(1) DEFAULT 0,
            mic_ok tinyint(1) DEFAULT 0,
            location_ok tinyint(1) DEFAULT 0,
            internet_quality varchar(20) DEFAULT NULL,
            tab_switch_count int(11) DEFAULT 0,
            exit_count int(11) DEFAULT 0,
            copy_attempts int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_exam_student (exam_id, student_id),
            KEY idx_status (status),
            KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Answers
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_answers (
            id int(11) NOT NULL AUTO_INCREMENT,
            attempt_id int(11) NOT NULL,
            question_id int(11) NOT NULL,
            answer_data longtext DEFAULT NULL,
            is_correct tinyint(1) DEFAULT NULL,
            points_earned float DEFAULT 0,
            needs_manual tinyint(1) DEFAULT 0,
            teacher_comment text DEFAULT NULL,
            graded_by_type enum('admin','teacher') DEFAULT NULL,
            graded_by_id int(11) DEFAULT NULL,
            graded_at datetime DEFAULT NULL,
            answered_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_attempt_question (attempt_id, question_id),
            KEY idx_attempt (attempt_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Proctoring logs
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_proctoring_logs (
            id int(11) NOT NULL AUTO_INCREMENT,
            attempt_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            exam_id int(11) NOT NULL,
            event_type varchar(80) NOT NULL,
            event_data text DEFAULT NULL,
            ip_address varchar(100) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempt (attempt_id),
            KEY idx_exam (exam_id),
            KEY idx_event (event_type),
            KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Live sessions (heartbeat)
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_live_sessions (
            id int(11) NOT NULL AUTO_INCREMENT,
            attempt_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            exam_id int(11) NOT NULL,
            last_heartbeat datetime NOT NULL,
            ip_address varchar(100) DEFAULT NULL,
            lat double DEFAULT NULL,
            lng double DEFAULT NULL,
            accuracy float DEFAULT NULL,
            status enum('active','idle','offline') DEFAULT 'active',
            camera_ok tinyint(1) DEFAULT 0,
            mic_ok tinyint(1) DEFAULT 0,
            location_ok tinyint(1) DEFAULT 0,
            exit_count int(11) DEFAULT 0,
            tab_switch_count int(11) DEFAULT 0,
            copy_attempts int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_attempt (attempt_id),
            KEY idx_exam (exam_id),
            KEY idx_last (last_heartbeat)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Webcam requests
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_webcam_requests (
            id int(11) NOT NULL AUTO_INCREMENT,
            attempt_id int(11) NOT NULL,
            exam_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            requested_by_type enum('admin','teacher') DEFAULT 'admin',
            requested_by_id int(11) DEFAULT NULL,
            requested_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status enum('pending','completed','failed','expired') DEFAULT 'pending',
            snapshot_path varchar(500) DEFAULT NULL,
            request_type enum('snapshot','video_10sec') DEFAULT 'snapshot',
            duration_seconds int(11) DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempt (attempt_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Webcam snapshots
        DB::execute("CREATE TABLE IF NOT EXISTS online_exam_webcam_snapshots (
            id int(11) NOT NULL AUTO_INCREMENT,
            attempt_id int(11) NOT NULL,
            exam_id int(11) NOT NULL,
            student_id int(11) NOT NULL,
            file_path varchar(500) NOT NULL,
            file_size int(11) DEFAULT 0,
            is_saved_by_teacher tinyint(1) DEFAULT 0,
            saved_by_type enum('admin','teacher') DEFAULT NULL,
            saved_by_id int(11) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_attempt (attempt_id),
            KEY idx_exam (exam_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Ensure columns if older
        try { DB::execute("ALTER TABLE online_exams ADD COLUMN enable_watermark tinyint(1) DEFAULT 1"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exams ADD COLUMN watermark_text varchar(500) DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exams ADD COLUMN settings_json text DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN geo_lat double DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN geo_lng double DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN is_timer_started tinyint(1) DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN first_started_at datetime DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN timer_started_at datetime DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_attempts ADD COLUMN is_timer_started_second tinyint(1) DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_answers ADD COLUMN needs_manual tinyint(1) DEFAULT 0"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_answers ADD COLUMN teacher_comment text DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_answers ADD COLUMN graded_by_type enum('admin','teacher') DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_answers ADD COLUMN graded_by_id int(11) DEFAULT NULL"); } catch (Exception $e) {}
        try { DB::execute("ALTER TABLE online_exam_answers ADD COLUMN graded_at datetime DEFAULT NULL"); } catch (Exception $e) {}

        // Create uploads dirs
        $base = dirname(__DIR__) . '/uploads/online-exams';
        $dirs = [$base, $base.'/questions', $base.'/answers', $base.'/webcam', $base.'/webcam/temp', $base.'/webcam/saved', $base.'/media'];
        foreach ($dirs as $d) if (!is_dir($d)) @mkdir($d, 0755, true);

        // Seed default category
        $cnt = DB::fetch("SELECT COUNT(*) c FROM online_exam_categories");
        if (!$cnt || (int)$cnt['c'] === 0) {
            DB::execute("INSERT INTO online_exam_categories (name, description, color) VALUES (?, ?, ?)", ['عمومی', 'دسته پیش‌فرض آزمون‌ها', '#3b82f6']);
        }
        $cnt2 = DB::fetch("SELECT COUNT(*) c FROM online_question_categories");
        if (!$cnt2 || (int)$cnt2['c'] === 0) {
            DB::execute("INSERT INTO online_question_categories (name, description) VALUES (?, ?)", ['عمومی', 'دسته پیش‌فرض سوالات']);
        }
    }
}

if (!function_exists('online_question_types')) {
    function online_question_types() {
        return [
            'radio' => ['label' => 'تک‌گزینه‌ای (Radio)', 'icon' => '🔘', 'has_options' => true],
            'checkbox' => ['label' => 'چندگزینه‌ای (Checkbox)', 'icon' => '☑️', 'has_options' => true],
            'dropdown' => ['label' => 'کشویی (Dropdown)', 'icon' => '🔽', 'has_options' => true],
            'short_text' => ['label' => 'متن کوتاه', 'icon' => '📝', 'has_options' => false],
            'text' => ['label' => 'متن بلند', 'icon' => '📄', 'has_options' => false],
            'number' => ['label' => 'عددی', 'icon' => '🔢', 'has_options' => false],
            'info' => ['label' => 'اطلاع‌رسانی / بنر (بدون نمره)', 'icon' => 'ℹ️', 'has_options' => false],
            'fill_blank' => ['label' => 'جای خالی (Fill in the blanks)', 'icon' => '✍️', 'has_options' => true],
            'matching' => ['label' => 'تطبیقی / اتصالی (Matching)', 'icon' => '🔗', 'has_options' => true],
            'file_upload' => ['label' => 'آپلود فایل', 'icon' => '📎', 'has_options' => false],
            'voice_upload' => ['label' => 'آپلود صدا / Voice', 'icon' => '🎤', 'has_options' => false],
            'whiteboard' => ['label' => 'تخته سفید / نقاشی', 'icon' => '🎨', 'has_options' => false],
        ];
    }
}

if (!function_exists('online_exam_status_label')) {
    function online_exam_status_label($status) {
        $map = ['draft'=>'پیش‌نویس','published'=>'منتشر شده','archived'=>'بایگانی'];
        return $map[$status] ?? $status;
    }
}

if (!function_exists('calc_online_question_score')) {
    function calc_online_question_score($qType, $qData, $answerData) {
        // $qData decoded array, $answerData decoded
        $points = (float)($qData['points'] ?? $qData['question_points'] ?? 1);
        $max = (float)($qData['points'] ?? 1);
        // Normalize
        $qData = is_string($qData) ? json_decode($qData, true) : $qData;
        $answerData = is_string($answerData) ? json_decode($answerData, true) : $answerData;
        if (!$qData) $qData = [];
        if (!$answerData) return ['correct'=>false,'points'=>0,'max'=>$max];

        switch ($qType) {
            case 'radio':
            case 'dropdown':
                $correctOptions = array_filter($qData['options'] ?? [], fn($o)=>!empty($o['is_correct']));
                $correctId = $correctOptions ? array_values($correctOptions)[0]['id'] ?? null : null;
                $given = $answerData['selected'] ?? $answerData['value'] ?? null;
                $isCorrect = ($given !== null && (string)$given === (string)$correctId);
                return ['correct'=>$isCorrect,'points'=>$isCorrect ? $points : 0,'max'=>$max];
            case 'checkbox':
                $options = $qData['options'] ?? [];
                $correctIds = array_map(fn($o)=> (string)$o['id'], array_filter($options, fn($o)=>!empty($o['is_correct'])));
                $givenIds = array_map('strval', $answerData['selected'] ?? []);
                sort($correctIds); sort($givenIds);
                $isCorrect = ($correctIds === $givenIds);
                // partial scoring if enabled
                if ($isCorrect) return ['correct'=>true,'points'=>$points,'max'=>$max];
                // partial: count correct minus incorrect
                $correctCount = count(array_intersect($givenIds, $correctIds));
                $incorrectCount = count(array_diff($givenIds, $correctIds));
                $partial = max(0, ($correctCount - $incorrectCount*0.5) / max(1,count($correctIds))) * $points;
                $partial = round($partial,2);
                return ['correct'=>$isCorrect,'points'=>$partial,'max'=>$max];
            case 'short_text':
            case 'text':
                $correct = trim($qData['correct_answer'] ?? '');
                $given = trim($answerData['value'] ?? '');
                if ($correct === '') return ['correct'=>false,'points'=>0,'max'=>$max];
                $caseSensitive = !empty($qData['case_sensitive']);
                $isCorrect = $caseSensitive ? ($given === $correct) : (mb_strtolower($given) === mb_strtolower($correct));
                return ['correct'=>$isCorrect,'points'=>$isCorrect ? $points : 0,'max'=>$max];
            case 'number':
                $correct = (float)($qData['correct_answer'] ?? 0);
                $given = (float)($answerData['value'] ?? 0);
                $tolerance = (float)($qData['tolerance'] ?? 0);
                $isCorrect = abs($correct - $given) <= $tolerance;
                return ['correct'=>$isCorrect,'points'=>$isCorrect ? $points : 0,'max'=>$max];
            case 'fill_blank':
                $blanks = $qData['blanks'] ?? [];
                $givenBlanks = $answerData['blanks'] ?? [];
                $total = count($blanks);
                if ($total === 0) return ['correct'=>false,'points'=>0,'max'=>$max];
                $correctCnt = 0;
                foreach ($blanks as $idx=>$b) {
                    $corr = trim($b['answer'] ?? '');
                    $giv = trim($givenBlanks[$idx] ?? '');
                    if (mb_strtolower($giv) === mb_strtolower($corr)) $correctCnt++;
                }
                $score = $total>0 ? round($correctCnt / $total * $points,2) : 0;
                return ['correct'=> $correctCnt===$total,'points'=>$score,'max'=>$max];
            case 'matching':
                $pairs = $qData['pairs'] ?? [];
                $given = $answerData['matches'] ?? [];
                $total = count($pairs);
                $correctCnt = 0;
                foreach ($pairs as $i=>$p) {
                    $correctRight = $p['right'] ?? '';
                    $givenRight = $given[$i] ?? '';
                    if (trim($givenRight) === trim($correctRight)) $correctCnt++;
                }
                $score = $total>0 ? round($correctCnt / $total * $points,2) : 0;
                return ['correct'=> $correctCnt===$total,'points'=>$score,'max'=>$max];
            case 'file_upload':
            case 'voice_upload':
            case 'whiteboard':
                // manual grading, give full if file exists, or pending
                $hasFile = !empty($answerData['file_path']) || !empty($answerData['value']);
                return ['correct'=>null,'points'=> $hasFile ? $points : 0,'max'=>$max, 'needs_manual'=>true];
            case 'info':
                return ['correct'=>true,'points'=>0,'max'=>0];
            default:
                return ['correct'=>false,'points'=>0,'max'=>$max];
        }
    }
}

if (!function_exists('haversine_distance')) {
    function haversine_distance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371000; // meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $earth_radius * $c;
    }
}

if (!function_exists('online_exam_can_student_take')) {
    function online_exam_can_student_take($exam, $student) {
        if (!$exam || !$student) return [false,'آزمون یافت نشد'];
        if ((int)$exam['is_active'] !== 1) return [false,'آزمون غیرفعال است'];
        if ($exam['status'] !== 'published') return [false,'آزمون هنوز منتشر نشده'];
        $now = time();
        if (!empty($exam['start_datetime'])) {
            if (strtotime($exam['start_datetime']) > $now) return [false,'زمان شروع آزمون هنوز فرا نرسیده: '.tr_num(jdate('Y/m/d H:i', strtotime($exam['start_datetime'])),'fa')];
        }
        if (!empty($exam['end_datetime'])) {
            if (strtotime($exam['end_datetime']) < $now) return [false,'مهلت شرکت در آزمون به پایان رسیده'];
        }
        // class filter
        if (!empty($exam['class_name']) && trim($exam['class_name']) !== '' && $student['class_name'] !== $exam['class_name']) {
            // allow if grade matches? but enforce class if set
            // For flexibility, if class_name set, student must match
            // If you want broader, comment this
            // return [false,'این آزمون برای کلاس دیگری تنظیم شده'];
        }
        // check attempts
        $attempts = DB::fetch("SELECT COUNT(*) c FROM online_exam_attempts WHERE exam_id=? AND student_id=? AND status IN ('submitted','auto_submitted','in_progress')", [$exam['id'],$student['id']]);
        $cnt = (int)($attempts['c'] ?? 0);
        if ($cnt >= (int)$exam['max_attempts']) {
            return [false,'شما حداکثر دفعات مجاز شرکت ('.tr_num($exam['max_attempts'],'fa').') را استفاده کرده‌اید'];
        }
        return [true,''];
    }
}

if (!function_exists('online_exam_finalize_attempt')) {
    /* v4.119.0: نهایی‌سازی attempt — محاسبه نمره از پاسخ‌های ذخیره‌شده و بستن آزمون.
       برای submit عادی، انقضای سرور و ثبت خودکار استفاده می‌شود (يک مسیر واحد). */
    function online_exam_finalize_attempt($attemptId, $status = 'submitted') {
        $attempt = DB::fetch("SELECT * FROM online_exam_attempts WHERE id=?", [$attemptId]);
        if (!$attempt) return false;
        if (in_array($attempt['status'], ['submitted','auto_submitted'])) return true; /* قبلاً بسته شده */
        $total = 0; $max = 0;
        try {
            $answers = DB::fetchAll("SELECT * FROM online_exam_answers WHERE attempt_id=?", [$attemptId]);
            foreach ($answers as $a) $total += (float)($a['points_earned'] ?? 0);
        } catch (Exception $e) {}
        try {
            $questions = DB::fetchAll("SELECT * FROM online_questions WHERE exam_id=?", [$attempt['exam_id']]);
            foreach ($questions as $q) { if (($q['question_type']??'')==='info') continue; $max += (float)($q['points'] ?? 0); }
        } catch (Exception $e) { $max = $total; }
        $now = date('Y-m-d H:i:s');
        try {
            DB::execute("UPDATE online_exam_attempts SET status=?, end_time=?, submitted_at=?, score=?, max_score=? WHERE id=?", [$status, $now, $now, $total, $max, $attemptId]);
        } catch (Exception $e) {
            try { DB::execute("UPDATE online_exam_attempts SET status=?, end_time=?, submitted_at=?, score=? WHERE id=?", [$status, $now, $now, $total, $attemptId]); } catch (Exception $ee) {}
        }
        try { DB::execute("UPDATE online_exam_live_sessions SET status='offline' WHERE attempt_id=?", [$attemptId]); } catch (Exception $e) {}
        return true;
    }
}

if (!function_exists('online_exam_attempt_time_left')) {
    /* v4.119.0: باقیمانده زمان واقعی attempt بر اساس first_started_at؛ null = تایمر هنوز شروع نشده */
    function online_exam_attempt_time_left($attempt, $durationMinutes) {
        if (empty($attempt['is_timer_started']) || empty($attempt['first_started_at'])) return null;
        $elapsed = time() - strtotime($attempt['first_started_at']);
        return max(0, (int)$durationMinutes * 60 - $elapsed);
    }
}

if (!function_exists('normalize_academic_year')) {
    function normalize_academic_year($year) {
        if (function_exists('unify_academic_year')) {
            $u = unify_academic_year($year);
            if ($u !== '') return $u;
        }
        if (!$year) return '';
        $year = trim($year);
        $year = str_replace(['-', '–', '—', ' '], ['/', '/', '/', ''], $year);
        $year = preg_replace('#/+#', '/', $year);
        return trim($year, '/');
    }
}

if (!function_exists('get_current_academic_year')) {
    function get_current_academic_year() {
        $year = get_setting('current_academic_year', '1404/1405');
        return normalize_academic_year($year);
    }
}

if (!function_exists('online_proctoring_event_label')) {
    function online_proctoring_event_label($eventType) {
        $map = [
            'tab_hidden' => 'تب مرورگر مخفی شد / جابجایی تب',
            'tab_visible' => 'بازگشت به تب آزمون',
            'window_blurred' => 'پنجره کوچک شد / خارج شدن از فوکوس',
            'window_focused' => 'بازگشت فوکوس به پنجره',
            'fullscreen_exit' => 'خروج از حالت تمام صفحه',
            'fullscreen_enter' => 'ورود به حالت تمام صفحه',
            'copy_attempt' => 'تلاش برای کپی متن',
            'paste_attempt' => 'تلاش برای پیست',
            'right_click' => 'کلیک راست (مسدود)',
            'printscreen' => 'کلید PrintScreen (اسکرین‌شات)',
            'permission_revoked_camera' => 'دوربین قطع شد',
            'permission_revoked_mic' => 'میکروفون قطع شد',
            'permission_revoked_location' => 'موقعیت مکانی قطع شد / Timeout',
            'permission_granted_camera' => 'دوربین مجددا وصل شد',
            'permission_granted_mic' => 'میکروفون وصل شد',
            'permission_granted_location' => 'موقعیت مکانی وصل شد',
            'ip_changed' => 'تغییر آدرس IP',
            'location_changed' => 'تغییر موقعیت',
            'page_unload' => 'خروج / بستن صفحه آزمون',
            'minimize' => 'حداقل کردن پنجره',
            'contextmenu_blocked' => 'منوی کلیک راست مسدود شد',
            'location_ip_fallback' => 'موقعیت از طریق IP (GPS ناموفق - سازگار ایران)',
            'location_ip_manual_override' => 'ادامه با IP دستی (GPS در دسترس نبود - سازگار ایران)',
            'mic_not_found_continue' => 'ادامه بدون میکروفون (دسکتاپ بدون میکروفون)',
            'devtools_attempt' => 'تلاش برای باز کردن ابزار توسعه (F12)',
            'camera_snapshot_requested' => 'درخواست تصویر وب‌کم توسط مراقب',
        ];
        return $map[$eventType] ?? $eventType;
    }
}

if (!function_exists('online_exam_cleanup_old_snapshots')) {
    function online_exam_cleanup_old_snapshots($minutes = 10) {
        try {
            $threshold = date('Y-m-d H:i:s', time() - $minutes*60);
            $old = DB::fetchAll("SELECT * FROM online_exam_webcam_snapshots WHERE is_saved_by_teacher=0 AND created_at < ?", [$threshold]);
            foreach ($old as $s) {
                $p = $s['file_path'];
                $full = dirname(__DIR__) . '/../' . $p; // relative
                $full2 = __DIR__.'/../'.$p;
                if (file_exists($full2)) @unlink($full2);
                DB::execute("DELETE FROM online_exam_webcam_snapshots WHERE id=?", [$s['id']]);
            }
            // Also temp folder files older than threshold
            $tempDir = __DIR__.'/../uploads/online-exams/webcam/temp';
            if (is_dir($tempDir)) {
                foreach (glob($tempDir.'/*') as $f) {
                    if (is_file($f) && filemtime($f) < time() - $minutes*60) @unlink($f);
                }
            }
        } catch (Exception $e) {}
    }
}
?>
