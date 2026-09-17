<?php
/**
 * Database Migration & Updater (migration-updater.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';

require_permission('system_settings');

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $message = 'خطای امنیتی CSRF رخ داد.';
        $messageType = 'error';
    } else {
        try {
            $pdo = DB::getInstance()->getPdo();
            if ($pdo) {
                // Ensure required new tables exist
                $pdo->exec("CREATE TABLE IF NOT EXISTS `report_locks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `academic_year` varchar(20) DEFAULT NULL,
                  `term` varchar(50) DEFAULT NULL,
                  `report_month` varchar(50) DEFAULT NULL,
                  `class_name` varchar(100) DEFAULT NULL,
                  `student_id` int(11) DEFAULT NULL,
                  `is_locked` tinyint(1) NOT NULL DEFAULT 1,
                  `reason` varchar(255) DEFAULT 'عدم تسویه بدهی مالی یا تکمیل مدارک',
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `import_sessions` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `admin_id` int(11) NOT NULL,
                  `filename` varchar(255) NOT NULL,
                  `file_type` enum('csv','xlsx') NOT NULL DEFAULT 'csv',
                  `status` enum('pending','preview','resolving','completed','failed') NOT NULL DEFAULT 'pending',
                  `total_rows` int(11) DEFAULT 0,
                  `processed_rows` int(11) DEFAULT 0,
                  `preview_data` longtext DEFAULT NULL,
                  `ambiguities_data` longtext DEFAULT NULL,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `import_queue` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `session_id` int(11) NOT NULL,
                  `file_path` varchar(255) NOT NULL,
                  `status` enum('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
                  `progress` int(11) DEFAULT 0,
                  `error_log` longtext DEFAULT NULL,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `processed_at` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `sms_logs` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `provider` varchar(50) NOT NULL,
                  `sender` varchar(50) DEFAULT NULL,
                  `recipient` varchar(50) NOT NULL,
                  `message` text NOT NULL,
                  `status` varchar(50) DEFAULT 'sent',
                  `response` text DEFAULT NULL,
                  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                // Ensure columns exist in students table
                try { $pdo->exec("ALTER TABLE `students` ADD COLUMN `serial_number` varchar(50) DEFAULT NULL AFTER `student_code`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `students` ADD COLUMN `photo_url` varchar(255) DEFAULT NULL AFTER `class_name`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `students` ADD COLUMN `father_phone` varchar(20) DEFAULT NULL AFTER `phone`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `students` ADD COLUMN `mother_phone` varchar(20) DEFAULT NULL AFTER `father_phone`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `reports` ADD COLUMN `discipline_score` decimal(5,2) DEFAULT NULL AFTER `gpa`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `teacher_name` varchar(150) DEFAULT NULL AFTER `grade_level`"); } catch(Exception $ex) {}
                try { $pdo->exec("ALTER TABLE `subjects` ADD COLUMN `teacher_id` int(11) DEFAULT NULL AFTER `teacher_name`"); } catch(Exception $ex) {}

                $pdo->exec("CREATE TABLE IF NOT EXISTS `teachers` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `national_id` varchar(50) NOT NULL,
                  `personnel_code` varchar(50) DEFAULT NULL,
                  `full_name` varchar(150) NOT NULL,
                  `mobile` varchar(20) DEFAULT NULL,
                  `academic_year` varchar(20) NOT NULL DEFAULT '1404/1405',
                  `password` varchar(255) NOT NULL,
                  `status` tinyint(1) NOT NULL DEFAULT 1,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `national_id` (`national_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `class_schedules` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `academic_year` varchar(20) NOT NULL DEFAULT '1404/1405',
                  `class_name` varchar(100) NOT NULL,
                  `day_of_week` varchar(50) NOT NULL,
                  `period_num` varchar(50) NOT NULL,
                  `subject_name` varchar(100) NOT NULL,
                  `teacher_name` varchar(150) DEFAULT NULL,
                  `teacher_id` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `class_name` (`class_name`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `grade_messages` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `student_id` int(11) NOT NULL,
                  `teacher_id` int(11) DEFAULT NULL,
                  `report_id` int(11) NOT NULL,
                  `subject_name` varchar(100) NOT NULL,
                  `message` text NOT NULL,
                  `reply` text DEFAULT NULL,
                  `status` enum('pending','replied') NOT NULL DEFAULT 'pending',
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  `replied_at` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                $pdo->exec("CREATE TABLE IF NOT EXISTS `bale_bot_users` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `bale_chat_id` varchar(50) NOT NULL,
                  `bale_username` varchar(100) DEFAULT NULL,
                  `student_id` int(11) NOT NULL,
                  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `bale_chat_id` (`bale_chat_id`),
                  KEY `student_id` (`student_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

                // Update system version setting
                set_setting('system_version', '2.5.0');
                log_activity($_SESSION['admin_id'], 'بروزرسانی دیتابیس', 'مایگریشن و ارتقای دیتابیس به نگارش 2.5.0 انجام شد.');
                $message = 'پایگاه داده با موفقیت به نگارش نهایی (2.5.0) ارتقا یافت.';
                $messageType = 'success';
            }
        } catch (Exception $e) {
            $message = 'خطا در اجرای مایگریشن: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$currentDbVer = get_setting('system_version', '1.0.0');
?>
<div class="max-w-3xl mx-auto space-y-6">
    <div class="card p-6">
        <h2 class="text-xl font-bold text-amber-600 mb-2 flex items-center gap-2">
            <span><svg data-ui-icon="settings" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6m-8 0v6"/></svg> ارتقا و مایگریشن پایگاه داده (Migration Updater)</span>
        </h2>
        <p class="text-xs text-muted mb-6">در صورتی که فایل‌های پروژه را از نسخه‌های قدیمی بروزرسانی کرده‌اید، با اجرای این ابزار، ساختار جداول پایگاه داده و ستون‌های جدید به‌صورت خودکار ایجاد و همگام‌سازی می‌شوند.</p>

        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded border <?php echo $messageType === 'success' ? 'bg-green-100 text-green-800 border-green-300' : 'bg-red-100 text-red-800 border-red-300'; ?>">
            <?php echo clean($message); ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 gap-4 mb-6 p-4 bg-gray-50 rounded border">
            <div>
                <span class="text-xs text-muted block">نگارش فعلی در پایگاه داده:</span>
                <span class="text-lg font-bold text-blue-600"><?php echo clean($currentDbVer); ?></span>
            </div>
            <div>
                <span class="text-xs text-muted block">نگارش فایل‌های سیستم:</span>
                <span class="text-lg font-bold text-green-600">2.5.0</span>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="run_migration" value="1">
            <div class="flex justify-between items-center">
                <span class="text-xs text-muted">این عملیات بدون حذف اطلاعات قبلی، ساختار جداول را کامل می‌کند.</span>
                <button type="submit" class="btn btn-success px-6 py-2.5 font-bold">
                    <span><svg data-ui-icon="bolt" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m14 2-11 12h8l-1 8 11-12h-8Z"/></svg> اجرای بررسی و ارتقای دیتابیس</span>
                </button>
            </div>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
