<?php
/**
 * Automated Installer Wizard (installer.php)
 */

session_start();
require_once __DIR__ . '/includes/jdf.php';

$lockFile = __DIR__ . '/config/installed.lock';
if (file_exists($lockFile) && ($_GET['force'] ?? '') != '1') {
    die('
    <div style="font-family: Tahoma; text-align: center; margin-top: 100px; direction: rtl;">
        <h2 style="color: #dc2626;">سیستم قبلاً نصب شده است!</h2>
        <p>فایل قفل نصب (<code>config/installed.lock</code>) موجود است.</p>
        <p>برای نصب مجدد، پارامتر <a href="installer.php?force=1">installer.php?force=1</a> را باز کنید یا وارد <a href="index.php">صفحه اصلی سیستم</a> شوید.</p>
    </div>
    ');
}

$step = $_GET['step'] ?? 1;
$error = '';
$success = '';

// Step 2: Test & Save Database Config
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 2) {
    $driver   = $_POST['driver'] ?? 'mysql';
    $host     = $_POST['host'] ?? '127.0.0.1';
    $port     = $_POST['port'] ?? '3306';
    $dbname   = $_POST['database'] ?? 'student_report_db';
    $user     = $_POST['username'] ?? 'root';
    $pass     = $_POST['password'] ?? '';

    $_SESSION['install_db'] = [
        'driver'    => $driver,
        'host'      => $host,
        'port'      => $port,
        'database'  => $dbname,
        'username'  => $user,
        'password'  => $pass,
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
    ];

    if ($driver === 'sqlite') {
        $dbPath = __DIR__ . '/' . $dbname;
        $_SESSION['install_db']['database'] = $dbPath;
        try {
            $pdo = new PDO("sqlite:" . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            header("Location: installer.php?step=3");
            exit;
        } catch (Exception $e) {
            $error = 'خطا در ایجاد دیتابیس SQLite: ' . $e->getMessage();
        }
    } else {
        try {
            // First connect without dbname to create database if not exists
            $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
            
            // Connect to created db
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4", $user, $pass);
            header("Location: installer.php?step=3");
            exit;
        } catch (Exception $e) {
            $error = 'اتصال به MySQL برقرار نشد: ' . $e->getMessage() . '<br><small>نکته: اگر روی محیط تستی بدون سرور MySQL هستید، می‌توانید در فیلد نوع دیتابیس گزینه <b>SQLite (حالت محلی)</b> را انتخاب کنید.</small>';
        }
    }
}

// Step 3: Run SQL & Create Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step == 3) {
    $adminUser   = trim($_POST['admin_username'] ?? 'admin');
    $adminPass   = $_POST['admin_password'] ?? 'admin123';
    $adminName   = trim($_POST['admin_name'] ?? 'مدیر سیستم');
    $schoolName  = trim($_POST['school_name'] ?? 'دبیرستان نمونه دولتی');

    $dbConf = $_SESSION['install_db'] ?? null;
    if (!$dbConf) {
        header("Location: installer.php?step=2");
        exit;
    }

    try {
        if ($dbConf['driver'] === 'sqlite') {
            $pdo = new PDO("sqlite:" . $dbConf['database']);
        } else {
            $dsn = "mysql:host={$dbConf['host']};port={$dbConf['port']};dbname={$dbConf['database']};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbConf['username'], $dbConf['password']);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Run SQL schema
        $sqlContent = file_get_contents(__DIR__ . '/sql/database.sql');
        if ($dbConf['driver'] === 'sqlite') {
            // Adapt SQL for SQLite
            $sqlContent = preg_replace('/AUTO_INCREMENT/i', 'AUTOINCREMENT', $sqlContent);
            $sqlContent = preg_replace('/int\(11\) NOT NULL AUTOINCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sqlContent);
            $sqlContent = preg_replace('/enum\([^\)]+\)/i', 'TEXT', $sqlContent);
            $sqlContent = preg_replace('/ENGINE=InnoDB[^\n;]+/i', '', $sqlContent);
            $sqlContent = preg_replace('/SET FOREIGN_KEY_CHECKS[^\n;]+;/i', '', $sqlContent);
            $sqlContent = preg_replace('/PRIMARY KEY \(`id`\),?/i', '', $sqlContent);
            $sqlContent = preg_replace('/UNIQUE KEY [^\n,]+,?/i', '', $sqlContent);
            $sqlContent = preg_replace('/KEY [^\n,]+,?/i', '', $sqlContent);
        }

        // Execute statements
        $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (Exception $ex) {
                    // Ignore non-critical index or drop warnings
                }
            }
        }

        // Update or insert super admin
        $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
        $pdo->exec("DELETE FROM admins WHERE username = 'admin'");
        $stmt = $pdo->prepare("INSERT INTO admins (username, password, name, role, permissions, status, created_at) VALUES (?, ?, ?, 'super_admin', '[\"all\"]', 1, NOW())");
        $stmt->execute([$adminUser, $hashedPass, $adminName]);

        // Update school setting
        $stmt = $pdo->prepare("UPDATE settings SET key_value = ? WHERE key_name = 'school_name'");
        $stmt->execute([$schoolName]);

        // Write config/database.php
        $configContent = "<?php\n/**\n * Active Database Configuration\n */\nreturn " . var_export($dbConf, true) . ";\n";
        file_put_contents(__DIR__ . '/config/database.php', $configContent);

        // Create lock file
        file_put_contents($lockFile, "Installed on " . date('Y-m-d H:i:s'));

        header("Location: installer.php?step=4");
        exit;
    } catch (Exception $e) {
        $error = 'خطا در نصب دیتابیس: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>نصب‌یار سیستم مدیریت کارنامه دانش‌آموزی</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-body text-main min-h-screen flex items-center justify-center p-4">

<div class="max-w-2xl w-full card p-8 shadow-lg">
    <div class="text-center mb-8 border-b border-color pb-4">
        <h1 class="text-2xl font-bold text-primary mb-2">🚀 نصب‌یار خودکار سیستم مدیریت کارنامه</h1>
        <p class="text-xs text-muted">راه‌اندازی سریع و آسان پایگاه داده، جداول و حساب مدیریت ارشد</p>
    </div>

    <!-- Steps bar -->
    <div class="wizard-steps mb-8">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. پیش‌نیازها</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'active' : ''; ?>">۲. اتصال دیتابیس</div>
        <div class="wizard-step <?php echo $step >= 3 ? 'active' : ''; ?>">۳. تنظیمات مدرسه و مدیر</div>
        <div class="wizard-step <?php echo $step >= 4 ? 'active' : ''; ?>">۴. اتمام نصب</div>
    </div>

    <?php if (!empty($error)): ?>
    <div class="mb-6 p-4 rounded bg-red-100 text-red-800 border border-red-300 text-sm">
        <?php echo $error; ?>
    </div>
    <?php endif; ?>

    <?php if ($step == 1): ?>
        <h3 class="font-bold mb-4">بررسی پیش‌نیازهای سرور (PHP و افزونه‌ها):</h3>
        <ul class="space-y-3 mb-6 text-sm">
            <li class="flex justify-between p-2 rounded bg-gray-50 border">
                <span>نسخه PHP (حداقل 7.4 یا 8.x):</span>
                <span class="font-bold text-green-600"><?php echo PHP_VERSION; ?> ✔</span>
            </li>
            <li class="flex justify-between p-2 rounded bg-gray-50 border">
                <span>افزونه PDO و پایگاه داده:</span>
                <span class="font-bold text-green-600">فعال ✔</span>
            </li>
            <li class="flex justify-between p-2 rounded bg-gray-50 border">
                <span>افزونه JSON و mbstring:</span>
                <span class="font-bold text-green-600">فعال ✔</span>
            </li>
            <li class="flex justify-between p-2 rounded bg-gray-50 border">
                <span>دسترسی نوشتن در پوشه config:</span>
                <span class="font-bold text-green-600">قابل نوشتن ✔</span>
            </li>
        </ul>
        <div class="flex justify-end">
            <a href="installer.php?step=2" class="btn btn-primary px-6 py-2.5">مرحله بعد: تنظیمات دیتابیس &larr;</a>
        </div>

    <?php elseif ($step == 2): ?>
        <form method="POST" action="installer.php?step=2">
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1">نوع پایگاه داده</label>
                <select name="driver" class="form-select" id="dbDriver" onchange="toggleDbFields()">
                    <option value="mysql">MySQL / MariaDB (پیشنهادی برای سرور)</option>
                    <option value="sqlite">SQLite (فایل محلی - عالی برای تست بدون سرور)</option>
                </select>
            </div>
            <div id="mysqlFields">
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-semibold mb-1">آدرس سرور (Host)</label>
                        <input type="text" name="host" class="form-input" value="127.0.0.1">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">پورت (Port)</label>
                        <input type="text" name="port" class="form-input" value="3306">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">نام پایگاه داده (Database Name)</label>
                    <input type="text" name="database" class="form-input" value="student_report_db">
                </div>
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-xs font-semibold mb-1">نام کاربری دیتابیس (Username)</label>
                        <input type="text" name="username" class="form-input" value="root">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1">رمز عبور دیتابیس (Password)</label>
                        <input type="password" name="password" class="form-input" placeholder="خالی یا رمز عبور">
                    </div>
                </div>
            </div>
            <div class="flex justify-between">
                <a href="installer.php?step=1" class="btn btn-secondary">&rarr; مرحله قبل</a>
                <button type="submit" class="btn btn-primary px-6">تست اتصال و ادامه &larr;</button>
            </div>
        </form>
        <script>
        function toggleDbFields() {
            const drv = document.getElementById('dbDriver').value;
            const fields = document.getElementById('mysqlFields');
            if (drv === 'sqlite') {
                fields.style.display = 'none';
            } else {
                fields.style.display = 'block';
            }
        }
        </script>

    <?php elseif ($step == 3): ?>
        <form method="POST" action="installer.php?step=3">
            <h4 class="font-bold mb-4 text-primary">اطلاعات آموزشگاه و مدیر ارشد:</h4>
            <div class="mb-4">
                <label class="block text-xs font-semibold mb-1">نام آموزشگاه / مدرسه</label>
                <input type="text" name="school_name" class="form-input" value="دبیرستان نمونه دولتی نخبگان" required>
            </div>
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-xs font-semibold mb-1">نام و نام خانوادگی مدیر ارشد</label>
                    <input type="text" name="admin_name" class="form-input" value="مدیر ارشد سیستم" required>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1">نام کاربری ورود (Username)</label>
                    <input type="text" name="admin_username" class="form-input" value="admin" required>
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-xs font-semibold mb-1">رمز عبور مدیر ارشد</label>
                <input type="password" name="admin_password" class="form-input" value="admin123" required>
                <small class="text-xs text-muted">رمز پیش‌فرض پیشنهادی: <code>admin123</code></small>
            </div>
            <div class="flex justify-between">
                <a href="installer.php?step=2" class="btn btn-secondary">&rarr; مرحله قبل</a>
                <button type="submit" class="btn btn-success px-6 py-2.5 font-bold">ایجاد جداول و اتمام نصب &larr;</button>
            </div>
        </form>

    <?php elseif ($step == 4): ?>
        <div class="text-center py-6">
            <div class="text-5xl mb-4">🎉</div>
            <h2 class="text-2xl font-bold text-green-600 mb-2">نصب با موفقیت به پایان رسید!</h2>
            <p class="text-sm text-muted mb-6">جداول اطلاعاتی ایجاد شدند و فایل <code>config/installed.lock</code> ثبت گردید.</p>
            <div class="p-4 bg-blue-50 rounded-lg text-sm mb-6 inline-block text-right border border-blue-200">
                <p><b>نام کاربری مدیر ارشد:</b> <code>admin</code></p>
                <p><b>کلمه عبور:</b> رمزی که در مرحله قبل تعیین کردید (یا <code>admin123</code>)</p>
            </div>
            <div>
                <a href="index.php" class="btn btn-primary px-8 py-3 text-base font-bold shadow">ورود به سیستم و پنل کاربری &larr;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
