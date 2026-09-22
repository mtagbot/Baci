-- File: sql/database.sql
-- ==========================================================
-- Student Report Card Management System Database Schema
-- سیستم مدیریت کارنامه دانش‌آموزی - نگارش نهایی
-- ==========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- Table: admins (مدیران سیستم)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `role` enum('super_admin','edu_admin','observer') NOT NULL DEFAULT 'edu_admin',
  `permissions` text DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `mobile` varchar(20) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert initial super admin (password: admin123 -> hashed with SHA256/password_hash compatibility)
INSERT INTO `admins` (`id`, `username`, `password`, `name`, `role`, `permissions`, `email`, `mobile`, `status`) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'مدیر کل سیستم', 'super_admin', '["all"]', 'admin@school.ac.ir', '09120000000', 1);

-- --------------------------------------------------------
-- Table: students (دانش‌آموزان)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `national_id` varchar(50) NOT NULL,
  `student_code` varchar(50) DEFAULT NULL,
  `serial_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `birth_date` varchar(20) DEFAULT NULL,
  `gender` enum('male','female') DEFAULT 'male',
  `grade_level` varchar(50) DEFAULT 'دهم',
  `class_name` varchar(100) DEFAULT '101',
  `photo_url` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `father_phone` varchar(20) DEFAULT NULL,
  `mother_phone` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `is_temp` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `national_id` (`national_id`),
  KEY `class_name` (`class_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Student
INSERT INTO `students` (`id`, `national_id`, `student_code`, `first_name`, `last_name`, `father_name`, `birth_date`, `gender`, `grade_level`, `class_name`, `password`, `phone`, `status`, `is_temp`) VALUES
(1, '0012345678', '99101', 'علی', 'رضایی', 'محمد', '1386/05/12', 'male', 'دهم ریاضی', '101 ریاضی', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '09121111111', 'active', 0),
(2, '0023456789', '99102', 'سارا', 'احمدی', 'حسین', '1386/08/20', 'female', 'دهم تجربی', '102 تجربی', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '09122222222', 'active', 0),
(3, '0034567890', '99103', 'امیرحسین', 'کریمی', 'رضا', '1386/02/15', 'male', 'دهم ریاضی', '101 ریاضی', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '09123333333', 'active', 0);

-- --------------------------------------------------------
-- Table: classes (کلاس‌ها)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `grade` varchar(50) NOT NULL,
  `academic_year` varchar(20) NOT NULL DEFAULT '1402-1403',
  `teacher_name` varchar(150) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `classes` (`id`, `name`, `grade`, `academic_year`, `teacher_name`) VALUES
(1, '101 ریاضی', 'دهم ریاضی', '1402-1403', 'مهندس موسوی'),
(2, '102 تجربی', 'دهم تجربی', '1402-1403', 'دکتر جعفری');

-- --------------------------------------------------------
-- Table: subjects (دروس)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `teacher_name` varchar(150) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `coefficient` decimal(4,2) NOT NULL DEFAULT 1.00,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `subjects` (`id`, `name`, `code`, `grade_level`, `coefficient`) VALUES
(1, 'ریاضیات ۱', 'MATH101', 'دهم ریاضی', 4.00),
(2, 'فیزیک ۱', 'PHYS101', 'دهم ریاضی', 3.00),
(3, 'شیمی ۱', 'CHEM101', 'دهم ریاضی', 3.00),
(4, 'ادبیات فارسی ۱', 'LIT101', 'عمومی', 2.00),
(5, 'زبان انگلیسی ۱', 'ENG101', 'عمومی', 2.00),
(6, 'زیست‌شناسی ۱', 'BIO101', 'دهم تجربی', 4.00);

-- --------------------------------------------------------
-- Table: reports (کارنامه‌ها)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL DEFAULT '1402-1403',
  `term` varchar(50) NOT NULL DEFAULT 'نوبت اول',
  `report_month` varchar(50) DEFAULT 'مهر',
  `total_score` decimal(6,2) DEFAULT 0.00,
  `gpa` decimal(5,2) DEFAULT 0.00,
  `discipline_score` decimal(5,2) DEFAULT NULL,
  `rank_in_class` int(11) DEFAULT NULL,
  `rank_in_grade` int(11) DEFAULT NULL,
  `teacher_comments` text DEFAULT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reports` (`id`, `student_id`, `class_name`, `academic_year`, `term`, `report_month`, `total_score`, `gpa`, `rank_in_class`, `rank_in_grade`, `teacher_comments`, `is_locked`) VALUES
(1, 1, '101 ریاضی', '1402-1403', 'نوبت اول', 'دی', 270.00, 19.28, 1, 1, 'عملکرد بسیار عالی در دروس تحلیلی', 0),
(2, 2, '102 تجربی', '1402-1403', 'نوبت اول', 'دی', 265.00, 18.90, 1, 2, 'تلاش خوب و مستمر', 0),
(3, 3, '101 ریاضی', '1402-1403', 'نوبت اول', 'دی', 245.00, 17.50, 2, 3, 'نیاز به تمرین بیشتر در فیزیک', 0);

-- --------------------------------------------------------
-- Table: report_grades (نمرات دروس در کارنامه)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_grades` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `score` decimal(5,2) NOT NULL DEFAULT 0.00,
  `max_score` decimal(5,2) NOT NULL DEFAULT 20.00,
  `coefficient` decimal(4,2) NOT NULL DEFAULT 1.00,
  `status` enum('passed','failed','none') DEFAULT 'passed',
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `report_grades` (`report_id`, `subject_name`, `score`, `max_score`, `coefficient`, `status`) VALUES
(1, 'ریاضیات ۱', 19.50, 20.00, 4.00, 'passed'),
(1, 'فیزیک ۱', 19.00, 20.00, 3.00, 'passed'),
(1, 'شیمی ۱', 18.50, 20.00, 3.00, 'passed'),
(1, 'ادبیات فارسی ۱', 20.00, 20.00, 2.00, 'passed'),
(1, 'زبان انگلیسی ۱', 19.50, 20.00, 2.00, 'passed'),
(2, 'زیست‌شناسی ۱', 19.00, 20.00, 4.00, 'passed'),
(2, 'شیمی ۱', 18.50, 20.00, 3.00, 'passed'),
(2, 'ادبیات فارسی ۱', 19.00, 20.00, 2.00, 'passed'),
(3, 'ریاضیات ۱', 17.00, 20.00, 4.00, 'passed'),
(3, 'فیزیک ۱', 16.50, 20.00, 3.00, 'passed'),
(3, 'شیمی ۱', 18.00, 20.00, 3.00, 'passed');

-- --------------------------------------------------------
-- Table: report_locks (مسدودسازی گروهی کارنامه‌ها)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `report_locks` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: settings (تنظیمات سیستم)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` varchar(100) NOT NULL,
  `key_value` text DEFAULT NULL,
  PRIMARY KEY (`key_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key_name`, `key_value`) VALUES
('school_name', 'دبیرستان نمونه دولتی نخبگان'),
('school_phone', '021-88888888'),
('school_address', 'تهران، خیابان ولیعصر، نرسیده به میدان تجریش'),
('theme_color', '#2563eb'),
('theme_mode', 'light'),
('logo_url', ''),
('favicon_url', ''),
('sms_provider', 'kavenegar'),
('sms_api_key', 'test_api_key'),
('sms_sender_number', '10008888'),
('enable_notifications', '1'),
('system_version', '2.5.0');

-- --------------------------------------------------------
-- Table: activity_logs (لاگ فعالیت مدیران)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `admin_username` varchar(100) DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `activity_logs` (`admin_id`, `admin_username`, `action`, `description`, `ip_address`) VALUES
(1, 'admin', 'نصب اولیه سیستم', 'پایگاه داده سیستم با موفقیت راه‌اندازی شد.', '127.0.0.1');

-- --------------------------------------------------------
-- Table: notifications (اعلان‌های سیستمی)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `message` text NOT NULL,
  `target_type` enum('all','class','student') NOT NULL DEFAULT 'all',
  `target_value` varchar(100) DEFAULT NULL,
  `created_by` varchar(100) DEFAULT 'مدیریت سیستم',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` (`title`, `message`, `target_type`, `target_value`, `created_by`) VALUES
('آغاز توزیع کارنامه‌های نوبت اول', 'دانش‌آموزان عزیز، کارنامه‌های نوبت اول هم‌اکنون از طریق پنل کاربری در دسترس است. در صورت وجود هرگونه ابهام به مدیریت مراجعه فرمایید.', 'all', NULL, 'مدیریت آموزشی');

-- --------------------------------------------------------
-- Table: api_tokens (توکن‌های دسترسی API)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `user_type` enum('admin','student') NOT NULL DEFAULT 'student',
  `access_token` varchar(255) NOT NULL,
  `refresh_token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `access_token` (`access_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: import_sessions (نشست‌های ایمپورت چندمرحله‌ای)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_sessions` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: import_queue (صف پردازش فایل‌های بزرگ)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `import_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `status` enum('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  `progress` int(11) DEFAULT 0,
  `error_log` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: sms_logs (لاگ پیامک‌های ارسالی)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sms_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `sender` varchar(50) DEFAULT NULL,
  `recipient` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(50) DEFAULT 'sent',
  `response` text DEFAULT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: teachers (دبیران و کادر آموزشی)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teachers` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `teachers` (`id`, `national_id`, `personnel_code`, `full_name`, `mobile`, `academic_year`, `password`, `status`) VALUES
(1, '5560755680', '55607556', 'اسدالهی ارسلان', '09109943840', '1404/1405', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);

-- --------------------------------------------------------
-- Table: class_schedules (برنامه هفتگی کلاس‌ها و دروس)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `class_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL DEFAULT '1404/1405',
  `class_name` varchar(100) NOT NULL,
  `day_of_week` varchar(50) NOT NULL,
  `period_num` varchar(50) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `teacher_name` varchar(150) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `class_name` (`class_name`),
  KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: grade_messages (ارتباط و اعتراض به نمرات بین دانش‌آموز و دبیر)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `grade_messages` (
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
  PRIMARY KEY (`id`),
  KEY `student_id` (`student_id`),
  KEY `teacher_id` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table: bale_bot_users (کاربران متصل بازوی بله مدرسه)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bale_bot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bale_chat_id` varchar(50) NOT NULL,
  `bale_username` varchar(100) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `bale_chat_id` (`bale_chat_id`),
  KEY `student_id` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- File: sql/database.sql
-- --------------------------------------------------------
-- v3.9 Bot notification and Telegram parallel bot schema
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `telegram_bot_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `telegram_chat_id` varchar(80) NOT NULL,
  `telegram_username` varchar(150) DEFAULT NULL,
  `student_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_chat_student` (`telegram_chat_id`, `student_id`),
  KEY `idx_chat` (`telegram_chat_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bale_bot_state` (
  `bale_chat_id` varchar(80) NOT NULL,
  `step` varchar(60) NOT NULL,
  `temp_nid` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bale_chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `telegram_bot_state` (
  `telegram_chat_id` varchar(80) NOT NULL,
  `step` varchar(60) NOT NULL,
  `temp_nid` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`telegram_chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_message_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('bale','telegram') NOT NULL,
  `template_key` varchar(80) NOT NULL,
  `template_text` text NOT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_key` (`platform`, `template_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_button_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('bale','telegram') NOT NULL,
  `button_key` varchar(80) NOT NULL,
  `button_text` varchar(150) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `row_no` int(11) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_platform_button` (`platform`, `button_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_message_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('bale','telegram') NOT NULL,
  `chat_id` varchar(80) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'text',
  `message` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'pending',
  `response` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_platform_chat` (`platform`, `chat_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key_name`, `key_value`) VALUES
('telegram_bot_token', ''),
('bale_bot_token', ''),
('accent_color', '#d97706'),
('font_family', 'Vazirmatn'),
('heading_font_family', 'Vazirmatn'),
('system_version', '3.9.0');

-- --------------------------------------------------------
-- v4.0 school roles, discipline, counseling, bot secure login
-- --------------------------------------------------------
ALTER TABLE `teachers` ADD COLUMN `is_deputy` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `teachers` ADD COLUMN `is_counselor` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `bale_bot_state` ADD COLUMN `temp_payload` text DEFAULT NULL;
ALTER TABLE `telegram_bot_state` ADD COLUMN `temp_payload` text DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `discipline_titles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_discipline_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `title_id` int(11) DEFAULT NULL,
  `title_text` varchar(250) NOT NULL,
  `internal_note` text DEFAULT NULL,
  `occurred_at_jalali` varchar(30) NOT NULL,
  `notify_parents` tinyint(1) NOT NULL DEFAULT 0,
  `created_by_teacher_id` int(11) DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  `updated_at_jalali` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student` (`student_id`),
  KEY `idx_title` (`title_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `counseling_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('site','bale','telegram') NOT NULL DEFAULT 'site',
  `chat_id` varchar(80) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `requester_name` varchar(150) NOT NULL,
  `requester_phone` varchar(30) DEFAULT NULL,
  `student_name` varchar(150) DEFAULT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `topic` varchar(250) NOT NULL,
  `description` text NOT NULL,
  `status` enum('new','in_progress','replied','closed') NOT NULL DEFAULT 'new',
  `counselor_id` int(11) DEFAULT NULL,
  `counselor_reply` text DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  `replied_at_jalali` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_admin_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `platform` enum('bale','telegram') NOT NULL,
  `chat_id` varchar(80) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `role_type` enum('admin','teacher') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_chat` (`platform`, `chat_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bot_login_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token` varchar(80) NOT NULL,
  `target_type` enum('admin','teacher','student') NOT NULL,
  `target_id` int(11) NOT NULL,
  `expires_at` int(11) NOT NULL,
  `used_at_jalali` varchar(30) DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key_name`, `key_value`) VALUES ('admin_bot_login_secret', '');

-- --------------------------------------------------------
-- v4.1 exams module
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `grade_level` varchar(50) DEFAULT NULL,
  `class_name` varchar(100) DEFAULT NULL,
  `subject_name` varchar(150) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `teacher_name` varchar(150) DEFAULT NULL,
  `exam_date_jalali` varchar(20) NOT NULL,
  `start_time` varchar(10) NOT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 90,
  `exam_room_default` varchar(100) DEFAULT NULL,
  `question_file` varchar(255) DEFAULT NULL,
  `header_config` text DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_year_class` (`academic_year`, `class_name`),
  KEY `idx_date` (`exam_date_jalali`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `seat_number` varchar(20) DEFAULT NULL,
  `exam_room` varchar(100) DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_exam_student` (`exam_id`, `student_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- v4.3 exam seating and batch exam activation upgrades
-- --------------------------------------------------------
ALTER TABLE `exam_schedules` ADD COLUMN `exam_month` varchar(50) DEFAULT NULL;
ALTER TABLE `exam_schedules` ADD COLUMN `exam_day_name` varchar(30) DEFAULT NULL;
ALTER TABLE `exam_schedules` ADD COLUMN `is_active` tinyint(1) NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS `exam_student_seating` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` varchar(20) NOT NULL,
  `exam_month` varchar(50) NOT NULL,
  `student_id` int(11) NOT NULL,
  `seat_number` varchar(20) DEFAULT NULL,
  `exam_room` varchar(100) DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_month_student` (`academic_year`, `exam_month`, `student_id`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- v4.5 exam saved designs and question bank
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_designs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `design_json` longtext NOT NULL,
  `designer_teacher_id` int(11) DEFAULT NULL,
  `designer_name` varchar(150) DEFAULT NULL,
  `saved_by_admin_id` int(11) DEFAULT NULL,
  `updated_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `exam_question_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_exam_id` int(11) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `exam_month` varchar(50) DEFAULT NULL,
  `subject_name` varchar(150) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `designer_name` varchar(150) DEFAULT NULL,
  `question_type` varchar(30) DEFAULT 'text',
  `question_html` longtext NOT NULL,
  `score` varchar(20) DEFAULT NULL,
  `meta_json` text DEFAULT NULL,
  `created_at_jalali` varchar(30) NOT NULL,
  `updated_at_jalali` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_subject` (`subject_name`),
  KEY `idx_year_month` (`academic_year`, `exam_month`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- v4.12 parent review tracking and student transfer
-- --------------------------------------------------------
ALTER TABLE `students` ADD COLUMN `academic_year` varchar(20) DEFAULT NULL;
ALTER TABLE `student_discipline_records` ADD COLUMN `is_justified` tinyint(1) NOT NULL DEFAULT 0;
ALTER TABLE `student_discipline_records` ADD COLUMN `review_status` varchar(50) DEFAULT 'pending';
ALTER TABLE `student_discipline_records` ADD COLUMN `review_note` text DEFAULT NULL;

CREATE TABLE IF NOT EXISTS `report_parent_reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `platform` enum('bale','telegram') NOT NULL,
  `chat_id` varchar(80) NOT NULL,
  `reviewed_at_jalali` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_review` (`report_id`, `platform`, `chat_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- v4.13 academic years registry
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `academic_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year_name` varchar(20) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `status` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year` (`year_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v4.14 report image footer setting
INSERT IGNORE INTO `settings` (`key_name`, `key_value`) VALUES ('report_image_footer_text', 'تولید شده توسط سامانه مدیریت کارنامه - {date}');

-- --------------------------------------------------------
-- v4.20 executive deputy role
-- --------------------------------------------------------
ALTER TABLE `teachers` ADD COLUMN `is_executive` tinyint(1) NOT NULL DEFAULT 0;

-- --------------------------------------------------------
-- v4.28 Online Exams Module (Quiz Maker Pro like) with Proctoring
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `online_exam_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(20) DEFAULT '#3b82f6',
  `sort_order` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_question_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(300) NOT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `academic_year` varchar(20) NOT NULL DEFAULT '1404/1405',
  `grade_level` varchar(100) DEFAULT '',
  `class_name` varchar(100) DEFAULT '',
  `subject_name` varchar(150) DEFAULT '',
  `teacher_id` int(11) DEFAULT NULL,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `exam_kind` varchar(50) DEFAULT 'online',
  `duration_minutes` int(11) NOT NULL DEFAULT 60,
  `max_attempts` int(11) DEFAULT 1,
  `passing_score` float DEFAULT 0,
  `randomize_questions` tinyint(1) DEFAULT 0,
  `randomize_answers` tinyint(1) DEFAULT 0,
  `show_results` enum('after_submit','after_end','never','immediately') DEFAULT 'after_submit',
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `status` enum('draft','published','archived') DEFAULT 'draft',
  `is_active` tinyint(1) DEFAULT 1,
  `allow_copy` tinyint(1) DEFAULT 0,
  `enable_webcam` tinyint(1) DEFAULT 1,
  `enable_location` tinyint(1) DEFAULT 1,
  `enable_proctoring` tinyint(1) DEFAULT 1,
  `enable_watermark` tinyint(1) DEFAULT 1,
  `watermark_text` varchar(500) DEFAULT NULL,
  `settings_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_year_class` (`academic_year`, `class_name`),
  KEY `idx_status` (`status`, `is_active`),
  KEY `idx_dates` (`start_datetime`, `end_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_question_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `question_type` varchar(50) NOT NULL DEFAULT 'radio',
  `question_text` text NOT NULL,
  `question_data` longtext DEFAULT NULL,
  `points` float DEFAULT 1,
  `difficulty` enum('easy','medium','hard') DEFAULT 'medium',
  `is_public` tinyint(1) DEFAULT 0,
  `usage_count` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_teacher` (`teacher_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_type` (`question_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `bank_question_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `question_type` varchar(50) NOT NULL DEFAULT 'radio',
  `question_text` text NOT NULL,
  `question_data` longtext DEFAULT NULL,
  `points` float DEFAULT 1,
  `order_index` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam` (`exam_id`),
  KEY `idx_bank` (`bank_question_id`),
  KEY `idx_order` (`exam_id`, `order_index`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attempt_number` int(11) DEFAULT 1,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `status` enum('in_progress','submitted','auto_submitted','expired') DEFAULT 'in_progress',
  `score` float DEFAULT 0,
  `max_score` float DEFAULT 0,
  `ip_address` varchar(100) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `geo_lat` double DEFAULT NULL,
  `geo_lng` double DEFAULT NULL,
  `geo_accuracy` float DEFAULT NULL,
  `camera_ok` tinyint(1) DEFAULT 0,
  `mic_ok` tinyint(1) DEFAULT 0,
  `location_ok` tinyint(1) DEFAULT 0,
  `internet_quality` varchar(20) DEFAULT NULL,
  `tab_switch_count` int(11) DEFAULT 0,
  `exit_count` int(11) DEFAULT 0,
  `copy_attempts` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_exam_student` (`exam_id`, `student_id`),
  KEY `idx_status` (`status`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `answer_data` longtext DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `points_earned` float DEFAULT 0,
  `answered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attempt_question` (`attempt_id`, `question_id`),
  KEY `idx_attempt` (`attempt_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_proctoring_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `event_type` varchar(80) NOT NULL,
  `event_data` text DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt` (`attempt_id`),
  KEY `idx_exam` (`exam_id`),
  KEY `idx_event` (`event_type`),
  KEY `idx_student` (`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_live_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `last_heartbeat` datetime NOT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `accuracy` float DEFAULT NULL,
  `status` enum('active','idle','offline') DEFAULT 'active',
  `camera_ok` tinyint(1) DEFAULT 0,
  `mic_ok` tinyint(1) DEFAULT 0,
  `location_ok` tinyint(1) DEFAULT 0,
  `exit_count` int(11) DEFAULT 0,
  `tab_switch_count` int(11) DEFAULT 0,
  `copy_attempts` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_attempt` (`attempt_id`),
  KEY `idx_exam` (`exam_id`),
  KEY `idx_last` (`last_heartbeat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_webcam_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `requested_by_type` enum('admin','teacher') DEFAULT 'admin',
  `requested_by_id` int(11) DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','completed','failed','expired') DEFAULT 'pending',
  `snapshot_path` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt` (`attempt_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `online_exam_webcam_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_size` int(11) DEFAULT 0,
  `is_saved_by_teacher` tinyint(1) DEFAULT 0,
  `saved_by_type` enum('admin','teacher') DEFAULT NULL,
  `saved_by_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt` (`attempt_id`),
  KEY `idx_exam` (`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`key_name`, `key_value`) VALUES ('system_version', '4.28.0');

-- --------------------------------------------------------
-- v4.31.0: Critical performance indexes for fresh installs
-- (existing installs get these via db-optimizer.php)
-- --------------------------------------------------------
ALTER TABLE `students` ADD INDEX `idx_nid_year` (`national_id`, `academic_year`);
ALTER TABLE `students` ADD INDEX `idx_year_class` (`academic_year`, `class_name`);
ALTER TABLE `students` ADD INDEX `idx_status` (`status`);
ALTER TABLE `reports` ADD INDEX `idx_student_year` (`student_id`, `academic_year`, `term`);
ALTER TABLE `reports` ADD INDEX `idx_year_class` (`academic_year`, `class_name`);
ALTER TABLE `reports` ADD INDEX `idx_year_term` (`academic_year`, `term`, `report_month`);
ALTER TABLE `report_grades` ADD INDEX `idx_report_subject` (`report_id`, `subject_name`);
ALTER TABLE `report_locks` ADD INDEX `idx_lock_lookup` (`academic_year`, `class_name`, `student_id`);
ALTER TABLE `classes` ADD INDEX `idx_year_name` (`academic_year`, `name`);
ALTER TABLE `activity_logs` ADD INDEX `idx_created` (`created_at`);
ALTER TABLE `sms_logs` ADD INDEX `idx_sent` (`sent_at`);
ALTER TABLE `notifications` ADD INDEX `idx_target` (`target_type`, `target_value`);
ALTER TABLE `api_tokens` ADD INDEX `idx_expires` (`expires_at`);
ALTER TABLE `student_discipline_records` ADD INDEX `idx_student_created` (`student_id`, `created_at`);
