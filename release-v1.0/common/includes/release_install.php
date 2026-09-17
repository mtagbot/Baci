<?php
/** First-install implementation; never loads historical seed accounts or credentials. */
function release_distribution(): string {
    return (require dirname(__DIR__).'/config/release.php')['distribution'];
}
function release_prerequisites(): array {
    $root = dirname(__DIR__);
    $checks = ['PHP 8.1+' => PHP_VERSION_ID >= 80100];
    foreach (['pdo', release_distribution()==='desktop'?'pdo_sqlite':'pdo_mysql', 'mbstring', 'gd', 'curl', 'openssl', 'fileinfo', 'zip', 'zlib', 'dom'] as $ext) {
        $checks['PHP: '.$ext] = extension_loaded($ext);
    }
    foreach (['config', 'uploads', 'backups'] as $dir) $checks['write: '.$dir] = is_dir($root.'/'.$dir) && is_writable($root.'/'.$dir);
    if (release_distribution() === 'desktop') $checks['write: data'] = is_dir(dirname($root).'/data') && is_writable(dirname($root).'/data');
    $checks['sessions'] = session_status() === PHP_SESSION_ACTIVE;
    $checks['TCPDF + Persian font'] = is_file($root.'/vendor/tcpdf/tcpdf.php') && is_file($root.'/vendor/tcpdf/fonts/dejavusans.z');
    $checks['Chart.js'] = is_file($root.'/assets/vendor/chart.umd.min.js');
    return $checks;
}
function release_owner_allowed(string $submitted): bool {
    if (release_distribution() === 'desktop') return true;
    $path = dirname(__DIR__).'/config/install-access.php';
    if (!is_file($path)) return false;
    $expected = require $path;
    return is_string($expected) && strlen($expected) >= 32 && hash_equals($expected, $submitted);
}
function release_write_new(string $path, string $bytes): void {
    // Exclusive creation: never replace existing configuration, even if a lock was removed.
    $handle = @fopen($path, 'xb');
    if (!$handle) throw new RuntimeException('امکان ایجاد فایل تنظیمات نیست؛ فایل موجود بازنویسی نمی‌شود.');
    try {
        if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) throw new RuntimeException('نوشتن تنظیمات کامل نشد.');
        @chmod($path, 0600);
    } finally { fclose($handle); }
}
function release_validate(array $input): array {
    $out = [];
    foreach (['school_name','admin_name','admin_username','academic_year','db_host','db_port','db_name','db_user'] as $key) $out[$key] = trim((string)($input[$key] ?? ''));
    if ($out['school_name']==='' || mb_strlen($out['school_name'])>150 || $out['admin_name']==='' || mb_strlen($out['admin_name'])>100) throw new RuntimeException('نام مدرسه و نام مدیر را وارد کنید (حداکثر ۱۵۰ و ۱۰۰ نویسه).');
    if (!preg_match('/\A[A-Za-z0-9_.-]{3,50}\z/D', $out['admin_username'])) throw new RuntimeException('نام کاربری: ۳ تا ۵۰ حرف انگلیسی، عدد، نقطه، خط تیره یا زیرخط.');
    $out['admin_password'] = (string)($input['admin_password'] ?? '');
    if (!mb_check_encoding($out['admin_password'],'UTF-8') || mb_strlen($out['admin_password'],'UTF-8')<12 || strpos($out['admin_password'], "\0")!==false || strlen($out['admin_password'])>72 || !hash_equals($out['admin_password'], (string)($input['admin_password_confirm'] ?? ''))) throw new RuntimeException('رمز مدیر و تکرار آن یکسان، با طول ۱۲ تا ۷۲ بایت باشند.');
    $year = strtr($out['academic_year'], array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹',-1,PREG_SPLIT_NO_EMPTY),str_split('0123456789')));
    if (!preg_match('~\A(1[34][0-9]{2})/(1[34][0-9]{2})\z~D',$year,$m) || (int)$m[2] !== (int)$m[1]+1) throw new RuntimeException('سال تحصیلی را به صورت دو سال پیاپی با / وارد کنید.');
    $out['academic_year'] = $year;
    if (release_distribution()==='site') {
        if (!preg_match('/\A[a-zA-Z0-9_.-]{1,253}\z/D',$out['db_host']) || !ctype_digit($out['db_port']) || (int)$out['db_port']<1 || (int)$out['db_port']>65535 || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D',$out['db_name']) || $out['db_user']==='') throw new RuntimeException('مشخصات میزبان، درگاه، نام دیتابیس و کاربر MySQL معتبر نیست.');
        $out['db_pass'] = (string)($input['db_pass'] ?? '');
    }
    return $out;
}
function release_migrate(): void {
    $root = dirname(__DIR__);
    require_once $root.'/includes/functions.php';
    foreach (['exams_helper','online_exam_helpers','school_roles','attendance_helpers','grade_permissions','academic_year_helpers','class_exam_groups','session_tracker','header_tiles'] as $helper) require_once $root.'/includes/'.$helper.'.php';
    ensure_exams_schema();
    ensure_online_exams_schema();
    ensure_school_roles_schema();
    ensure_bot_schema('bale');
    ensure_bot_schema('telegram');
    ensure_attendance_schema_v2();
    ensure_grade_permissions_schema();
    ensure_academic_years_unified_schema();
    ceg_schema();
    ensure_user_sessions_schema();
    login_guard_ensure_schema();
    // Verify key current-version features after legacy idempotent migrations.
    foreach ([
        'admins'=>['username','password','role','status'],
        'students'=>['academic_year','national_id','class_name'],
        'exam_schedules'=>['exam_kind','teacher_id','subject_name'],
        'class_exam_groups'=>['identity_key','design_exam_id'],
        'class_exam_group_members'=>['excluded','detached_exam_id'],
        'student_qr_tags'=>['student_id','token'],
        'student_attendance'=>['scan_time','source'],
        'online_exams'=>['id'], 'user_sessions'=>['session_id'],
    ] as $table=>$columns) DB::fetchAll('SELECT '.implode(',', $columns).' FROM '.$table.' WHERE 1=0');
}
function release_install(array $raw): void {
    $root = dirname(__DIR__);
    $guard = @fopen($root.'/config/.installing', 'c');
    if (!$guard || !flock($guard, LOCK_EX|LOCK_NB)) throw new RuntimeException('نصب دیگری در حال اجراست؛ کمی صبر کنید.');
    try {
        if (is_file($root.'/config/installed.lock') || is_file($root.'/config/database.php') || is_file($root.'/config/desk-sync-key.php')) throw new RuntimeException('تنظیمات یا قفل نصب موجود است. نصب دوباره مجاز نیست.');
        if (!release_owner_allowed((string)($raw['install_access']??''))) throw new RuntimeException('کلید اجازهٔ نصب معتبر نیست. فایل config/install-access.php را طبق راهنما بسازید.');
        if (in_array(false, release_prerequisites(), true)) throw new RuntimeException('پیش‌نیازهای قرمز را قبل از نصب برطرف کنید.');
        $input = release_validate($raw);
        $desktop = release_distribution()==='desktop';
        $opts = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        if ($desktop) {
            $path = dirname($root).'/data/school.sqlite';
            if (file_exists($path)) throw new RuntimeException('فایل دیتابیس موجود است؛ روی اطلاعات قبلی نصب نمی‌شود. از پوشهٔ خالی جدید استفاده کنید.');
            $pdo = new PDO('sqlite:'.$path, null, null, $opts);
            $config = ['driver'=>'sqlite','database'=>$path];
            $pdo->exec('PRAGMA foreign_keys=OFF');
        } else {
            $config = ['driver'=>'mysql','host'=>$input['db_host'],'port'=>(int)$input['db_port'],'database'=>$input['db_name'],'username'=>$input['db_user'],'password'=>$input['db_pass'],'charset'=>'utf8mb4'];
            $pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'].';charset=utf8mb4', $config['username'], $config['password'], $opts);
            if ($pdo->query('SHOW TABLES')->fetch()) throw new RuntimeException('دیتابیس باید کاملاً خالی باشد؛ هیچ جدول موجودی حذف نمی‌شود.');
        }
        $sql = json_decode(file_get_contents($root.'/sql/install-'.($desktop?'sqlite':'mysql').'.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($sql as $statement) {
            try { $pdo->exec($statement); }
            catch (PDOException $e) {
                // Old base schema repeats a small number of indexes/columns. Nothing else is ignored.
                if (!$desktop && in_array((int)($e->errorInfo[1]??0), [1060,1061], true) && preg_match('/^ALTER\s+TABLE\b/i',ltrim($statement))) continue;
                throw $e;
            }
        }
        // Keep credentials in process memory during migrations; disk configuration is published LAST.
        $GLOBALS['dbConfig'] = $config;
        release_migrate();
        $db = DB::getInstance()->getPdo();
        $db->beginTransaction();
        try {
            DB::execute('INSERT INTO admins (username,password,name,role,permissions,status) VALUES (?,?,?,?,?,1)', [$input['admin_username'], password_hash($input['admin_password'], PASSWORD_DEFAULT), $input['admin_name'], 'super_admin', null]);
            foreach (['school_name'=>$input['school_name'], 'school_phone'=>'', 'school_address'=>'', 'school_logo'=>'', 'current_academic_year'=>$input['academic_year'], 'desk_sync_key'=>'', 'desk_sync_url'=>'', 'release_version'=>'Release_V1.0'] as $key=>$value) set_setting($key,$value);
            DB::execute('INSERT INTO academic_years (year_name,is_default,status) VALUES (?,1,1)',[$input['academic_year']]);
            $db->commit();
        } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        // Portable desktop paths must be evaluated on the user's machine, not frozen at install time.
        $phpConfig = $desktop ? "<?php\nreturn ['driver'=>'sqlite','database'=>dirname(__DIR__,2).'/data/school.sqlite'];\n" : "<?php\nreturn ".var_export($config,true).";\n";
        release_write_new($root.'/config/database.php', $phpConfig);
        release_write_new($root.'/config/desk-sync-key.php', "<?php\nreturn '".bin2hex(random_bytes(32))."';\n");
        release_write_new($root.'/config/installed.lock', "Release_V1.0\n".gmdate('c')."\n");
        unset($_SESSION['release_csrf']);
        session_regenerate_id(true);
    } finally { flock($guard, LOCK_UN); fclose($guard); }
}
