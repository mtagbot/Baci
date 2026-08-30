<?php
// File: sync-api.php  (v1.0.0 — companion endpoint for the SchoolDesk desktop app)
/**
 * REST endpoint used by the portable Windows client (SchoolDesk).
 * Actions (JSON POST):
 *   login : {action, username, password}            -> {ok, api_key, school_name}
 *   pull  : {action, api_key}                       -> {ok, students, classes, teachers, school_name, year}
 *   push  : {action, api_key, ops:[{op_id, entity, op, payload}]} -> {ok, results:[{op_id, ok, uuid, entity, server_id?, error?}]}
 *
 * Offline-first notes:
 *  - The client identifies rows by a UUID it generates (stored in *_desk_uuid columns
 *    added here via safe ALTERs). Server ids are mapped back after each push.
 *  - All writes are idempotent: re-sending the same create finds the row by uuid.
 */

/* Hard guards: many shared hosts print notices/warnings or BOMs that would
 * corrupt the JSON response — silence everything and flush buffers. */
@error_reporting(0);
@ini_set('display_errors', '0');
if (!headers_sent()) { @ob_start(); }

require_once __DIR__ . '/includes/auth.php';

while (ob_get_level() > 0) { @ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

function sd_out($arr) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function sd_fail($msg) { sd_out(['ok' => false, 'msg' => $msg]); }

/* GET ping: open https://site/sync-api.php in a browser to verify install */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sd_out(['ok' => true, 'ping' => 'sync-api ready', 'version' => '1.3.0',
            'php' => PHP_VERSION, 'time' => date('Y-m-d H:i:s')]);
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) sd_fail('درخواست نامعتبر (JSON دریافت نشد)');
$action = $in['action'] ?? '';

/* ---- ensure schema bits (safe / idempotent) ---- */
try { DB::execute("CREATE TABLE IF NOT EXISTS desk_api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(100) DEFAULT 'SchoolDesk',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}
foreach ([
    "ALTER TABLE students ADD COLUMN desk_uuid VARCHAR(40) NULL",
    "ALTER TABLE classes  ADD COLUMN desk_uuid VARCHAR(40) NULL",
    "ALTER TABLE teachers ADD COLUMN desk_uuid VARCHAR(40) NULL",
    "CREATE INDEX idx_students_desk_uuid ON students(desk_uuid)",
    "CREATE INDEX idx_classes_desk_uuid  ON classes(desk_uuid)",
    "CREATE INDEX idx_teachers_desk_uuid ON teachers(desk_uuid)",
    "CREATE TABLE IF NOT EXISTS student_attendance (
        id int(11) NOT NULL AUTO_INCREMENT,
        student_id int(11) NOT NULL,
        academic_year varchar(20) DEFAULT NULL,
        date_jalali varchar(10) NOT NULL,
        status enum('present','absent','late') NOT NULL DEFAULT 'present',
        minutes_late int(11) DEFAULT 0,
        scan_time varchar(10) DEFAULT NULL,
        source enum('manual','qr','auto') NOT NULL DEFAULT 'manual',
        created_at_jalali varchar(30) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_student_date (student_id, date_jalali)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE student_attendance ADD COLUMN scan_time varchar(10) DEFAULT NULL",
    "ALTER TABLE student_attendance ADD COLUMN source enum('manual','qr','auto') NOT NULL DEFAULT 'manual'",
] as $ddl) { try { DB::execute($ddl); } catch (Exception $e) {} }

/* ---------------- login ---------------- */
if ($action === 'login') {
    $user = trim($in['username'] ?? '');
    $pass = (string)($in['password'] ?? '');
    if ($user === '' || $pass === '') sd_fail('نام کاربری و رمز الزامی است');
    $adm = DB::fetch("SELECT * FROM admins WHERE username = ? AND status = 1", [$user]);
    if (!$adm || !(password_verify($pass, $adm['password']) || $pass === $adm['password'])) {
        sd_fail('نام کاربری یا رمز عبور نادرست است');
    }
    $key = bin2hex(random_bytes(24));
    DB::execute("INSERT INTO desk_api_keys (admin_id, api_key) VALUES (?, ?)", [$adm['id'], $key]);
    $school = function_exists('get_setting') ? get_setting('report_header_line2', get_setting('school_name', '')) : '';
    sd_out(['ok' => true, 'api_key' => $key, 'school_name' => $school]);
}

/* ---------------- auth for pull/push ---------------- */
$key = trim($in['api_key'] ?? '');
if ($key === '') sd_fail('کلید API ارسال نشده');
$keyRow = DB::fetch("SELECT * FROM desk_api_keys WHERE api_key = ?", [$key]);
if (!$keyRow) sd_fail('کلید API نامعتبر است — دوباره از تنظیمات متصل شوید');
DB::execute("UPDATE desk_api_keys SET last_used = NOW() WHERE id = ?", [$keyRow['id']]);

$year = function_exists('get_current_academic_year') ? get_current_academic_year() : '';

/* backfill uuids for rows created on the website itself */
function sd_backfill($table) {
    $rows = DB::fetchAll("SELECT id FROM {$table} WHERE desk_uuid IS NULL OR desk_uuid = '' LIMIT 500");
    foreach ($rows as $r) {
        DB::execute("UPDATE {$table} SET desk_uuid = ? WHERE id = ?", [bin2hex(random_bytes(16)), $r['id']]);
    }
}

/* ---------------- pull ---------------- */
if ($action === 'pull') {
    sd_backfill('students'); sd_backfill('classes'); sd_backfill('teachers');

    $students = DB::fetchAll(
        "SELECT id, desk_uuid AS uuid, national_id, first_name, last_name, father_name,
                grade_level, class_name, phone, father_phone, status
         FROM students WHERE status = 'active' ORDER BY class_name, last_name");
    $classes = $year !== ''
        ? DB::fetchAll("SELECT id, desk_uuid AS uuid, name, grade, academic_year
                        FROM classes WHERE academic_year = ? ORDER BY name", [$year])
        : [];
    if (empty($classes)) { // fallback: all years
        $classes = DB::fetchAll("SELECT id, desk_uuid AS uuid, name, grade, academic_year FROM classes ORDER BY name");
    }
    $teachers = DB::fetchAll(
        "SELECT id, desk_uuid AS uuid, full_name, national_id, personnel_code, mobile, status
         FROM teachers WHERE status = 1 ORDER BY full_name");

    // attendance: last records only (keeps payload small)
    $attendance = [];
    try {
        $attendance = DB::fetchAll(
            "SELECT CONCAT('srv-', a.id) AS uuid, s.desk_uuid AS student_uuid, a.date_jalali,
                    a.status, a.minutes_late, a.scan_time
             FROM student_attendance a JOIN students s ON s.id = a.student_id
             WHERE s.desk_uuid IS NOT NULL
             ORDER BY a.id DESC LIMIT 5000");
    } catch (Exception $e) {}

    // grades of current year (joined via reports)
    $grades = [];
    try {
        $grades = DB::fetchAll(
            "SELECT CONCAT('srv-', rg.id) AS uuid, s.desk_uuid AS student_uuid,
                    r.report_month, rg.subject_name, rg.score
             FROM report_grades rg
             JOIN reports r ON r.id = rg.report_id
             JOIN students s ON s.id = r.student_id
             WHERE s.desk_uuid IS NOT NULL" .
             ($year !== '' ? " AND r.academic_year = " . "'" . str_replace("'", "", $year) . "'" : "") .
            " ORDER BY rg.id DESC LIMIT 20000");
    } catch (Exception $e) {}

    // distinct subjects list
    $subjects = [];
    try {
        $subjects = DB::fetchAll("SELECT DISTINCT subject_name AS name FROM report_grades
                                  WHERE subject_name IS NOT NULL AND subject_name <> ''
                                  ORDER BY subject_name LIMIT 100");
    } catch (Exception $e) {}

    $school = function_exists('get_setting') ? get_setting('report_header_line2', get_setting('school_name', '')) : '';
    sd_out(['ok' => true, 'students' => $students, 'classes' => $classes,
            'teachers' => $teachers, 'attendance' => $attendance,
            'grades' => $grades, 'subjects' => $subjects,
            'school_name' => $school, 'year' => $year]);
}

/* ---------------- push ---------------- */
if ($action === 'push') {
    $ops = $in['ops'] ?? [];
    if (!is_array($ops)) sd_fail('ops نامعتبر');
    $results = [];
    foreach ($ops as $op) {
        $opId = (int)($op['op_id'] ?? 0);
        $entity = $op['entity'] ?? '';
        $kind = $op['op'] ?? '';
        $p = $op['payload'] ?? [];
        $uuid = trim($p['uuid'] ?? '');
        $res = ['op_id' => $opId, 'entity' => $entity, 'uuid' => $uuid, 'ok' => false];
        try {
            if ($uuid === '') throw new Exception('uuid ندارد');
            if ($entity === 'student') {
                $row = DB::fetch("SELECT id FROM students WHERE desk_uuid = ?", [$uuid]);
                if ($kind === 'delete') {
                    if ($row) DB::execute("UPDATE students SET status='inactive' WHERE id=?", [$row['id']]);
                    $res['ok'] = true; $res['server_id'] = $row['id'] ?? null;
                } else {
                    $vals = [
                        trim($p['national_id'] ?? ''), trim($p['first_name'] ?? ''), trim($p['last_name'] ?? ''),
                        trim($p['father_name'] ?? ''), trim($p['grade_level'] ?? ''), trim($p['class_name'] ?? ''),
                        trim($p['phone'] ?? ''), trim($p['father_phone'] ?? ''),
                    ];
                    if ($vals[1] === '' || $vals[2] === '') throw new Exception('نام/نام خانوادگی خالی است');
                    if ($row) {
                        DB::execute("UPDATE students SET national_id=?, first_name=?, last_name=?, father_name=?,
                                     grade_level=?, class_name=?, phone=?, father_phone=? WHERE id=?",
                                    array_merge($vals, [$row['id']]));
                        $res['server_id'] = (int)$row['id'];
                    } else {
                        // guard: same national_id already present? adopt it instead of duplicating
                        $dup = $vals[0] !== '' ? DB::fetch("SELECT id FROM students WHERE national_id=?", [$vals[0]]) : null;
                        if ($dup) {
                            DB::execute("UPDATE students SET desk_uuid=?, first_name=?, last_name=?, father_name=?,
                                         grade_level=?, class_name=?, phone=?, father_phone=? WHERE id=?",
                                        [$uuid, $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $vals[6], $vals[7], $dup['id']]);
                            $res['server_id'] = (int)$dup['id'];
                        } else {
                            DB::execute("INSERT INTO students (desk_uuid, national_id, first_name, last_name, father_name,
                                         grade_level, class_name, phone, father_phone, status, academic_year)
                                         VALUES (?,?,?,?,?,?,?,?,?, 'active', ?)",
                                        array_merge([$uuid], $vals, [$year]));
                            $res['server_id'] = (int)DB::lastInsertId();
                        }
                    }
                    $res['ok'] = true;
                }
            } elseif ($entity === 'class') {
                $row = DB::fetch("SELECT id FROM classes WHERE desk_uuid = ?", [$uuid]);
                if ($kind === 'delete') {
                    if ($row) DB::execute("DELETE FROM classes WHERE id=?", [$row['id']]);
                    $res['ok'] = true; $res['server_id'] = $row['id'] ?? null;
                } else {
                    $name = trim($p['name'] ?? '');
                    $grade = trim($p['grade'] ?? '');
                    if ($name === '') throw new Exception('نام کلاس خالی است');
                    if ($row) {
                        DB::execute("UPDATE classes SET name=?, grade=? WHERE id=?", [$name, $grade, $row['id']]);
                        $res['server_id'] = (int)$row['id'];
                    } else {
                        $dup = DB::fetch("SELECT id FROM classes WHERE name=? AND academic_year=?", [$name, $year]);
                        if ($dup) {
                            DB::execute("UPDATE classes SET desk_uuid=?, grade=? WHERE id=?", [$uuid, $grade, $dup['id']]);
                            $res['server_id'] = (int)$dup['id'];
                        } else {
                            DB::execute("INSERT INTO classes (desk_uuid, name, grade, academic_year) VALUES (?,?,?,?)",
                                        [$uuid, $name, $grade, $year]);
                            $res['server_id'] = (int)DB::lastInsertId();
                        }
                    }
                    $res['ok'] = true;
                }
            } elseif ($entity === 'teacher') {
                $row = DB::fetch("SELECT id FROM teachers WHERE desk_uuid = ?", [$uuid]);
                if ($kind === 'delete') {
                    if ($row) DB::execute("UPDATE teachers SET status=0 WHERE id=?", [$row['id']]);
                    $res['ok'] = true; $res['server_id'] = $row['id'] ?? null;
                } else {
                    $name = trim($p['full_name'] ?? '');
                    if ($name === '') throw new Exception('نام دبیر خالی است');
                    $vals = [trim($p['national_id'] ?? ''), trim($p['personnel_code'] ?? ''), trim($p['mobile'] ?? '')];
                    if ($row) {
                        DB::execute("UPDATE teachers SET full_name=?, national_id=?, personnel_code=?, mobile=? WHERE id=?",
                                    [$name, $vals[0], $vals[1], $vals[2], $row['id']]);
                        $res['server_id'] = (int)$row['id'];
                    } else {
                        $dup = $vals[0] !== '' ? DB::fetch("SELECT id FROM teachers WHERE national_id=?", [$vals[0]]) : null;
                        if ($dup) {
                            DB::execute("UPDATE teachers SET desk_uuid=?, full_name=?, personnel_code=?, mobile=? WHERE id=?",
                                        [$uuid, $name, $vals[1], $vals[2], $dup['id']]);
                            $res['server_id'] = (int)$dup['id'];
                        } else {
                            DB::execute("INSERT INTO teachers (desk_uuid, full_name, national_id, personnel_code, mobile,
                                         password, status) VALUES (?,?,?,?,?, '', 1)",
                                        [$uuid, $name, $vals[0], $vals[1], $vals[2]]);
                            $res['server_id'] = (int)DB::lastInsertId();
                        }
                    }
                    $res['ok'] = true;
                }
            } elseif ($entity === 'attendance') {
                $stuUuid = trim($p['student_uuid'] ?? '');
                $date = trim($p['date_jalali'] ?? '');
                $status = trim($p['status'] ?? '');
                if ($stuUuid === '' || $date === '') throw new Exception('اطلاعات حضورغیاب ناقص است');
                $stu = DB::fetch("SELECT id FROM students WHERE desk_uuid = ?", [$stuUuid]);
                if (!$stu) throw new Exception('دانش‌آموز هنوز روی سرور ساخته نشده (در تلاش بعدی ارسال می‌شود)');
                if ($status === 'clear' || $status === '') {
                    DB::execute("DELETE FROM student_attendance WHERE student_id = ? AND date_jalali = ?",
                                [$stu['id'], $date]);
                } else {
                    if (!in_array($status, ['present', 'late', 'absent'])) throw new Exception('وضعیت نامعتبر');
                    $ml = (int)($p['minutes_late'] ?? 0);
                    $stime = trim($p['scan_time'] ?? '');
                    $ex = DB::fetch("SELECT id FROM student_attendance WHERE student_id = ? AND date_jalali = ?",
                                    [$stu['id'], $date]);
                    if ($ex) {
                        DB::execute("UPDATE student_attendance SET status=?, minutes_late=?, scan_time=?, source='manual' WHERE id=?",
                                    [$status, $ml, $stime, $ex['id']]);
                    } else {
                        DB::execute("INSERT INTO student_attendance (student_id, academic_year, date_jalali, status, minutes_late, scan_time, source, created_at_jalali)
                                     VALUES (?, ?, ?, ?, ?, ?, 'manual', ?)",
                                    [$stu['id'], $year, $date, $status, $ml, $stime,
                                     function_exists('jdate') ? jdate('Y/m/d H:i') : date('Y/m/d H:i')]);
                    }
                }
                $res['ok'] = true;
                $res['server_id'] = (int)$stu['id'];
            } elseif ($entity === 'grade') {
                $stuUuid = trim($p['student_uuid'] ?? '');
                $rmonth = trim($p['report_month'] ?? '');
                $subj = trim($p['subject_name'] ?? '');
                $score = trim((string)($p['score'] ?? ''));
                if ($stuUuid === '' || $rmonth === '' || $subj === '') throw new Exception('اطلاعات نمره ناقص است');
                $stu = DB::fetch("SELECT id, class_name FROM students WHERE desk_uuid = ?", [$stuUuid]);
                if (!$stu) throw new Exception('دانش‌آموز هنوز روی سرور ساخته نشده (در تلاش بعدی ارسال می‌شود)');
                // find/create the report row for this student+month
                $rep = DB::fetch("SELECT id FROM reports WHERE student_id = ? AND report_month = ?" .
                                 ($year !== '' ? " AND academic_year = ?" : ""),
                                 $year !== '' ? [$stu['id'], $rmonth, $year] : [$stu['id'], $rmonth]);
                if (!$rep && $score !== '') {
                    DB::execute("INSERT INTO reports (student_id, class_name, academic_year, term, report_month, total_score, gpa) VALUES (?, ?, ?, 'نوبت اول', ?, 0, 0)",
                                [$stu['id'], $stu['class_name'], $year, $rmonth]);
                    $rep = ['id' => DB::lastInsertId()];
                }
                if ($rep) {
                    $ex = DB::fetch("SELECT id FROM report_grades WHERE report_id = ? AND subject_name = ?",
                                    [$rep['id'], $subj]);
                    if ($score === '') {
                        if ($ex) DB::execute("DELETE FROM report_grades WHERE id = ?", [$ex['id']]);
                    } elseif ($ex) {
                        DB::execute("UPDATE report_grades SET score = ? WHERE id = ?", [(float)$score, $ex['id']]);
                    } else {
                        DB::execute("INSERT INTO report_grades (report_id, subject_name, score, max_score, coefficient, status) VALUES (?, ?, ?, 20, 1, ?)",
                                    [$rep['id'], $subj, (float)$score, ((float)$score >= 10 ? 'قبول' : 'مردود')]);
                    }
                    // recompute gpa
                    $ag = DB::fetch("SELECT AVG(score) a, SUM(score) t, COUNT(*) c FROM report_grades WHERE report_id = ?", [$rep['id']]);
                    if ((int)($ag['c'] ?? 0) > 0) {
                        DB::execute("UPDATE reports SET gpa = ?, total_score = ? WHERE id = ?",
                                    [round((float)$ag['a'], 2), (float)$ag['t'], $rep['id']]);
                    }
                }
                $res['ok'] = true;
                $res['server_id'] = (int)$stu['id'];
            } else {
                throw new Exception('نوع موجودیت ناشناخته');
            }
        } catch (Exception $e) {
            $res['ok'] = false;
            $res['error'] = $e->getMessage();
        }
        $results[] = $res;
    }
    sd_out(['ok' => true, 'results' => $results]);
}

sd_fail('action ناشناخته');
