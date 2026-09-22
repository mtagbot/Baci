CREATE TABLE IF NOT EXISTS "admins" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "username" TEXT NOT NULL,
  "password" TEXT NOT NULL,
  "name" TEXT NOT NULL,
  "role" TEXT NOT NULL DEFAULT 'edu_admin',
  "permissions" text DEFAULT NULL,
  "email" TEXT DEFAULT NULL,
  "mobile" TEXT DEFAULT NULL,
  "status" tinyint(1) NOT NULL DEFAULT 1,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("username")
);
INSERT OR IGNORE INTO "admins" ("id", "username", "password", "name", "role", "permissions", "email", "mobile", "status") VALUES
(1, 'admin', '$2y$12$QScq9jZ6VA820l1kXssmeuW7I5BAUkUoKk.exYAWumpc0t3Go2ite', 'مدیر کل سیستم', 'super_admin', '["all"]', 'admin@school.ac.ir', '09120000000', 1);
CREATE TABLE IF NOT EXISTS "students" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "national_id" TEXT NOT NULL,
  "student_code" TEXT DEFAULT NULL,
  "serial_number" TEXT DEFAULT NULL,
  "first_name" TEXT NOT NULL,
  "last_name" TEXT NOT NULL,
  "father_name" TEXT DEFAULT NULL,
  "birth_date" TEXT DEFAULT NULL,
  "gender" TEXT DEFAULT 'male',
  "grade_level" TEXT DEFAULT 'دهم',
  "class_name" TEXT DEFAULT '101',
  "photo_url" TEXT DEFAULT NULL,
  "password" TEXT DEFAULT NULL,
  "phone" TEXT DEFAULT NULL,
  "father_phone" TEXT DEFAULT NULL,
  "mother_phone" TEXT DEFAULT NULL,
  "status" TEXT DEFAULT 'active',
  "is_temp" tinyint(1) NOT NULL DEFAULT 0,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  "updated_at" TEXT DEFAULT (datetime('now','localtime')),
  "academic_year" TEXT DEFAULT NULL,
  "father_birth_date" TEXT DEFAULT NULL,
  "mother_birth_date" TEXT DEFAULT NULL
);
INSERT OR IGNORE INTO "students" ("id", "national_id", "student_code", "first_name", "last_name", "father_name", "birth_date", "gender", "grade_level", "class_name", "password", "phone", "status", "is_temp") VALUES
(1, '0012345678', '99101', 'علی', 'رضایی', 'محمد', '1386/05/12', 'male', 'دهم ریاضی', '101 ریاضی', '$2y$12$QScq9jZ6VA820l1kXssmeuW7I5BAUkUoKk.exYAWumpc0t3Go2ite', '09121111111', 'active', 0),
(2, '0023456789', '99102', 'سارا', 'احمدی', 'حسین', '1386/08/20', 'female', 'دهم تجربی', '102 تجربی', '$2y$12$QScq9jZ6VA820l1kXssmeuW7I5BAUkUoKk.exYAWumpc0t3Go2ite', '09122222222', 'active', 0),
(3, '0034567890', '99103', 'امیرحسین', 'کریمی', 'رضا', '1386/02/15', 'male', 'دهم ریاضی', '101 ریاضی', '$2y$12$QScq9jZ6VA820l1kXssmeuW7I5BAUkUoKk.exYAWumpc0t3Go2ite', '09123333333', 'active', 0);
CREATE TABLE IF NOT EXISTS "classes" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "grade" TEXT NOT NULL,
  "academic_year" TEXT NOT NULL DEFAULT '1402-1403',
  "teacher_name" TEXT DEFAULT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
INSERT OR IGNORE INTO "classes" ("id", "name", "grade", "academic_year", "teacher_name") VALUES
(1, '101 ریاضی', 'دهم ریاضی', '1402-1403', 'مهندس موسوی'),
(2, '102 تجربی', 'دهم تجربی', '1402-1403', 'دکتر جعفری');
CREATE TABLE IF NOT EXISTS "subjects" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "code" TEXT DEFAULT NULL,
  "grade_level" TEXT DEFAULT NULL,
  "teacher_name" TEXT DEFAULT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "coefficient" REAL NOT NULL DEFAULT 1.00,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
INSERT OR IGNORE INTO "subjects" ("id", "name", "code", "grade_level", "coefficient") VALUES
(1, 'ریاضیات ۱', 'MATH101', 'دهم ریاضی', 4.00),
(2, 'فیزیک ۱', 'PHYS101', 'دهم ریاضی', 3.00),
(3, 'شیمی ۱', 'CHEM101', 'دهم ریاضی', 3.00),
(4, 'ادبیات فارسی ۱', 'LIT101', 'عمومی', 2.00),
(5, 'زبان انگلیسی ۱', 'ENG101', 'عمومی', 2.00),
(6, 'زیست‌شناسی ۱', 'BIO101', 'دهم تجربی', 4.00);
CREATE TABLE IF NOT EXISTS "reports" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "student_id" int(11) NOT NULL,
  "class_name" TEXT DEFAULT NULL,
  "academic_year" TEXT NOT NULL DEFAULT '1402-1403',
  "term" TEXT NOT NULL DEFAULT 'نوبت اول',
  "report_month" TEXT DEFAULT 'مهر',
  "total_score" REAL DEFAULT 0.00,
  "gpa" REAL DEFAULT 0.00,
  "discipline_score" REAL DEFAULT NULL,
  "rank_in_class" int(11) DEFAULT NULL,
  "rank_in_grade" int(11) DEFAULT NULL,
  "teacher_comments" text DEFAULT NULL,
  "is_locked" tinyint(1) NOT NULL DEFAULT 0,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
INSERT OR IGNORE INTO "reports" ("id", "student_id", "class_name", "academic_year", "term", "report_month", "total_score", "gpa", "rank_in_class", "rank_in_grade", "teacher_comments", "is_locked") VALUES
(1, 1, '101 ریاضی', '1402-1403', 'نوبت اول', 'دی', 270.00, 19.28, 1, 1, 'عملکرد بسیار عالی در دروس تحلیلی', 0),
(2, 2, '102 تجربی', '1402-1403', 'نوبت اول', 'دی', 265.00, 18.90, 1, 2, 'تلاش خوب و مستمر', 0),
(3, 3, '101 ریاضی', '1402-1403', 'نوبت اول', 'دی', 245.00, 17.50, 2, 3, 'نیاز به تمرین بیشتر در فیزیک', 0);
CREATE TABLE IF NOT EXISTS "report_grades" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "report_id" int(11) NOT NULL,
  "subject_name" TEXT NOT NULL,
  "score" REAL NOT NULL DEFAULT 0.00,
  "max_score" REAL NOT NULL DEFAULT 20.00,
  "coefficient" REAL NOT NULL DEFAULT 1.00,
  "status" TEXT DEFAULT 'passed'
);
INSERT OR IGNORE INTO "report_grades" ("report_id", "subject_name", "score", "max_score", "coefficient", "status") VALUES
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
CREATE TABLE IF NOT EXISTS "report_locks" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "academic_year" TEXT DEFAULT NULL,
  "term" TEXT DEFAULT NULL,
  "report_month" TEXT DEFAULT NULL,
  "class_name" TEXT DEFAULT NULL,
  "student_id" int(11) DEFAULT NULL,
  "is_locked" tinyint(1) NOT NULL DEFAULT 1,
  "reason" TEXT DEFAULT 'عدم تسویه بدهی مالی یا تکمیل مدارک',
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "settings" (
  "key_name" TEXT NOT NULL PRIMARY KEY,
  "key_value" TEXT DEFAULT NULL
);
INSERT OR IGNORE INTO "settings" ("key_name", "key_value") VALUES
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
CREATE TABLE IF NOT EXISTS "activity_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "admin_id" int(11) DEFAULT NULL,
  "admin_username" TEXT DEFAULT NULL,
  "action" TEXT NOT NULL,
  "description" text DEFAULT NULL,
  "ip_address" TEXT DEFAULT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
INSERT OR IGNORE INTO "activity_logs" ("admin_id", "admin_username", "action", "description", "ip_address") VALUES
(1, 'admin', 'نصب اولیه سیستم', 'پایگاه داده سیستم با موفقیت راه‌اندازی شد.', '127.0.0.1');
CREATE TABLE IF NOT EXISTS "notifications" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "title" TEXT NOT NULL,
  "message" text NOT NULL,
  "target_type" TEXT NOT NULL DEFAULT 'all',
  "target_value" TEXT DEFAULT NULL,
  "created_by" TEXT DEFAULT 'مدیریت سیستم',
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
INSERT OR IGNORE INTO "notifications" ("title", "message", "target_type", "target_value", "created_by") VALUES
('آغاز توزیع کارنامه‌های نوبت اول', 'دانش‌آموزان عزیز، کارنامه‌های نوبت اول هم‌اکنون از طریق پنل کاربری در دسترس است. در صورت وجود هرگونه ابهام به مدیریت مراجعه فرمایید.', 'all', NULL, 'مدیریت آموزشی');
CREATE TABLE IF NOT EXISTS "api_tokens" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "user_id" int(11) NOT NULL,
  "user_type" TEXT NOT NULL DEFAULT 'student',
  "access_token" TEXT NOT NULL,
  "refresh_token" TEXT NOT NULL,
  "expires_at" TEXT NOT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("access_token")
);
CREATE TABLE IF NOT EXISTS "import_sessions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "admin_id" int(11) NOT NULL,
  "filename" TEXT NOT NULL,
  "file_type" TEXT NOT NULL DEFAULT 'csv',
  "status" TEXT NOT NULL DEFAULT 'pending',
  "total_rows" int(11) DEFAULT 0,
  "processed_rows" int(11) DEFAULT 0,
  "preview_data" TEXT DEFAULT NULL,
  "ambiguities_data" TEXT DEFAULT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  "updated_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "import_queue" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "session_id" int(11) NOT NULL,
  "file_path" TEXT NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'queued',
  "progress" int(11) DEFAULT 0,
  "error_log" TEXT DEFAULT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  "processed_at" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "sms_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "provider" TEXT NOT NULL,
  "sender" TEXT DEFAULT NULL,
  "recipient" TEXT NOT NULL,
  "message" text NOT NULL,
  "status" TEXT DEFAULT 'sent',
  "response" text DEFAULT NULL,
  "sent_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "teachers" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "national_id" TEXT NOT NULL,
  "personnel_code" TEXT DEFAULT NULL,
  "full_name" TEXT NOT NULL,
  "mobile" TEXT DEFAULT NULL,
  "academic_year" TEXT NOT NULL DEFAULT '1404/1405',
  "password" TEXT NOT NULL,
  "status" tinyint(1) NOT NULL DEFAULT 1,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  "is_deputy" INTEGER NOT NULL DEFAULT 0,
  "is_executive" INTEGER NOT NULL DEFAULT 0,
  "is_counselor" INTEGER NOT NULL DEFAULT 0,
  UNIQUE ("national_id")
);
INSERT OR IGNORE INTO "teachers" ("id", "national_id", "personnel_code", "full_name", "mobile", "academic_year", "password", "status") VALUES
(1, '5560755680', '55607556', 'اسدالهی ارسلان', '09109943840', '1404/1405', '$2y$12$QScq9jZ6VA820l1kXssmeuW7I5BAUkUoKk.exYAWumpc0t3Go2ite', 1);
CREATE TABLE IF NOT EXISTS "class_schedules" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "academic_year" TEXT NOT NULL DEFAULT '1404/1405',
  "class_name" TEXT NOT NULL,
  "day_of_week" TEXT NOT NULL,
  "period_num" TEXT NOT NULL,
  "subject_name" TEXT NOT NULL,
  "teacher_name" TEXT DEFAULT NULL,
  "teacher_id" int(11) DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "grade_messages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "student_id" int(11) NOT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "report_id" int(11) NOT NULL,
  "subject_name" TEXT NOT NULL,
  "message" text NOT NULL,
  "reply" text DEFAULT NULL,
  "status" TEXT NOT NULL DEFAULT 'pending',
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  "replied_at" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "bale_bot_users" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "bale_chat_id" TEXT NOT NULL,
  "bale_username" TEXT DEFAULT NULL,
  "student_id" int(11) NOT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "telegram_bot_users" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "telegram_chat_id" TEXT NOT NULL,
  "telegram_username" TEXT DEFAULT NULL,
  "student_id" int(11) NOT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("telegram_chat_id", "student_id")
);
CREATE TABLE IF NOT EXISTS "bale_bot_state" (
  "bale_chat_id" TEXT NOT NULL PRIMARY KEY,
  "step" TEXT NOT NULL,
  "temp_nid" TEXT DEFAULT NULL,
  "updated_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "telegram_bot_state" (
  "telegram_chat_id" TEXT NOT NULL PRIMARY KEY,
  "step" TEXT NOT NULL,
  "temp_nid" TEXT DEFAULT NULL,
  "updated_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "bot_message_templates" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "platform" TEXT NOT NULL,
  "template_key" TEXT NOT NULL,
  "template_text" text NOT NULL,
  "updated_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("platform", "template_key")
);
CREATE TABLE IF NOT EXISTS "bot_button_templates" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "platform" TEXT NOT NULL,
  "button_key" TEXT NOT NULL,
  "button_text" TEXT NOT NULL,
  "sort_order" int(11) NOT NULL DEFAULT 0,
  "row_no" int(11) NOT NULL DEFAULT 1,
  "is_active" tinyint(1) NOT NULL DEFAULT 1,
  "updated_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("platform", "button_key")
);
CREATE TABLE IF NOT EXISTS "bot_message_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "platform" TEXT NOT NULL,
  "chat_id" TEXT NOT NULL,
  "student_id" int(11) DEFAULT NULL,
  "message_type" TEXT DEFAULT 'text',
  "message" text DEFAULT NULL,
  "status" TEXT DEFAULT 'pending',
  "response" text DEFAULT NULL,
  "created_at" TEXT DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "discipline_titles" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "title" TEXT NOT NULL,
  "status" tinyint(1) NOT NULL DEFAULT 1,
  "created_at_jalali" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "student_discipline_records" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "student_id" int(11) NOT NULL,
  "title_id" int(11) DEFAULT NULL,
  "title_text" TEXT NOT NULL,
  "internal_note" text DEFAULT NULL,
  "occurred_at_jalali" TEXT NOT NULL,
  "notify_parents" tinyint(1) NOT NULL DEFAULT 0,
  "created_by_teacher_id" int(11) DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  "updated_at_jalali" TEXT DEFAULT NULL,
  "review_status" TEXT DEFAULT NULL,
  "is_justified" INTEGER NOT NULL DEFAULT 0,
  "review_note" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "counseling_requests" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "platform" TEXT NOT NULL DEFAULT 'site',
  "chat_id" TEXT DEFAULT NULL,
  "student_id" int(11) DEFAULT NULL,
  "requester_name" TEXT NOT NULL,
  "requester_phone" TEXT DEFAULT NULL,
  "student_name" TEXT DEFAULT NULL,
  "class_name" TEXT DEFAULT NULL,
  "topic" TEXT NOT NULL,
  "description" text NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'new',
  "counselor_id" int(11) DEFAULT NULL,
  "counselor_reply" text DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  "replied_at_jalali" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "bot_admin_sessions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "platform" TEXT NOT NULL,
  "chat_id" TEXT NOT NULL,
  "admin_id" int(11) DEFAULT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "role_type" TEXT NOT NULL,
  "is_active" tinyint(1) NOT NULL DEFAULT 1,
  "created_at_jalali" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "bot_login_tokens" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "token" TEXT NOT NULL,
  "target_type" TEXT NOT NULL,
  "target_id" int(11) NOT NULL,
  "expires_at" int(11) NOT NULL,
  "used_at_jalali" TEXT DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  UNIQUE ("token")
);
CREATE TABLE IF NOT EXISTS "exam_schedules" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "academic_year" TEXT NOT NULL,
  "grade_level" TEXT DEFAULT NULL,
  "class_name" TEXT DEFAULT NULL,
  "subject_name" TEXT NOT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "teacher_name" TEXT DEFAULT NULL,
  "exam_date_jalali" TEXT NOT NULL,
  "start_time" TEXT NOT NULL,
  "duration_minutes" int(11) NOT NULL DEFAULT 90,
  "exam_room_default" TEXT DEFAULT NULL,
  "question_file" TEXT DEFAULT NULL,
  "header_config" text DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  "exam_kind" TEXT DEFAULT NULL,
  "is_active" INTEGER NOT NULL DEFAULT 1,
  "exam_day_name" TEXT DEFAULT NULL,
  "exam_month" TEXT DEFAULT NULL,
  "is_printed" INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS "exam_assignments" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "exam_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "seat_number" TEXT DEFAULT NULL,
  "exam_room" TEXT DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  UNIQUE ("exam_id", "student_id")
);
CREATE TABLE IF NOT EXISTS "exam_student_seating" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "academic_year" TEXT NOT NULL,
  "exam_month" TEXT NOT NULL,
  "student_id" int(11) NOT NULL,
  "seat_number" TEXT DEFAULT NULL,
  "exam_room" TEXT DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  UNIQUE ("academic_year", "exam_month", "student_id")
);
CREATE TABLE IF NOT EXISTS "exam_designs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "exam_id" int(11) NOT NULL,
  "design_json" TEXT NOT NULL,
  "designer_teacher_id" int(11) DEFAULT NULL,
  "designer_name" TEXT DEFAULT NULL,
  "saved_by_admin_id" int(11) DEFAULT NULL,
  "updated_at_jalali" TEXT NOT NULL,
  UNIQUE ("exam_id")
);
CREATE TABLE IF NOT EXISTS "exam_design_archive" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "exam_id" int(11) NOT NULL,
  "design_json" TEXT NOT NULL,
  "designer_name" TEXT DEFAULT NULL,
  "subject_name" TEXT DEFAULT NULL,
  "exam_month" TEXT DEFAULT NULL,
  "academic_year" TEXT DEFAULT NULL,
  "grade_level" TEXT DEFAULT NULL,
  "class_name" TEXT DEFAULT NULL,
  "src_fingerprint" TEXT DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "exam_question_bank" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "source_exam_id" int(11) DEFAULT NULL,
  "academic_year" TEXT DEFAULT NULL,
  "exam_month" TEXT DEFAULT NULL,
  "subject_name" TEXT NOT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "designer_name" TEXT DEFAULT NULL,
  "question_type" TEXT DEFAULT 'text',
  "question_html" TEXT NOT NULL,
  "score" TEXT DEFAULT NULL,
  "meta_json" text DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  "updated_at_jalali" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "report_parent_reviews" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "report_id" int(11) NOT NULL,
  "platform" TEXT NOT NULL,
  "chat_id" TEXT NOT NULL,
  "reviewed_at_jalali" TEXT NOT NULL,
  UNIQUE ("report_id", "platform", "chat_id")
);
CREATE TABLE IF NOT EXISTS "academic_years" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "year_name" TEXT NOT NULL,
  "is_default" tinyint(1) NOT NULL DEFAULT 0,
  "status" tinyint(1) NOT NULL DEFAULT 1,
  "created_at" TEXT DEFAULT (datetime('now','localtime')),
  UNIQUE ("year_name")
);
CREATE TABLE IF NOT EXISTS "online_exam_categories" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "description" text DEFAULT NULL,
  "color" TEXT DEFAULT '#3b82f6',
  "sort_order" int(11) DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "online_question_categories" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "name" TEXT NOT NULL,
  "description" text DEFAULT NULL,
  "teacher_id" int(11) DEFAULT NULL,
  "academic_year" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "online_exams" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "title" TEXT NOT NULL,
  "description" text DEFAULT NULL,
  "category_id" int(11) DEFAULT NULL,
  "academic_year" TEXT NOT NULL DEFAULT '1404/1405',
  "grade_level" TEXT DEFAULT '',
  "class_name" TEXT DEFAULT '',
  "subject_name" TEXT DEFAULT '',
  "teacher_id" int(11) DEFAULT NULL,
  "created_by_admin_id" int(11) DEFAULT NULL,
  "exam_kind" TEXT DEFAULT 'online',
  "duration_minutes" int(11) NOT NULL DEFAULT 60,
  "max_attempts" int(11) DEFAULT 1,
  "passing_score" REAL DEFAULT 0,
  "randomize_questions" tinyint(1) DEFAULT 0,
  "randomize_answers" tinyint(1) DEFAULT 0,
  "show_results" TEXT DEFAULT 'after_submit',
  "start_datetime" TEXT DEFAULT NULL,
  "end_datetime" TEXT DEFAULT NULL,
  "status" TEXT DEFAULT 'draft',
  "is_active" tinyint(1) DEFAULT 1,
  "allow_copy" tinyint(1) DEFAULT 0,
  "enable_webcam" tinyint(1) DEFAULT 1,
  "enable_location" tinyint(1) DEFAULT 1,
  "enable_proctoring" tinyint(1) DEFAULT 1,
  "enable_watermark" tinyint(1) DEFAULT 1,
  "watermark_text" TEXT DEFAULT NULL,
  "settings_json" text DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  "updated_at" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "online_question_bank" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "teacher_id" int(11) DEFAULT NULL,
  "category_id" int(11) DEFAULT NULL,
  "question_type" TEXT NOT NULL DEFAULT 'radio',
  "question_text" text NOT NULL,
  "question_data" TEXT DEFAULT NULL,
  "points" REAL DEFAULT 1,
  "difficulty" TEXT DEFAULT 'medium',
  "is_public" tinyint(1) DEFAULT 0,
  "usage_count" int(11) DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  "updated_at" TEXT DEFAULT NULL
);
CREATE TABLE IF NOT EXISTS "online_questions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "exam_id" int(11) NOT NULL,
  "bank_question_id" int(11) DEFAULT NULL,
  "category_id" int(11) DEFAULT NULL,
  "question_type" TEXT NOT NULL DEFAULT 'radio',
  "question_text" text NOT NULL,
  "question_data" TEXT DEFAULT NULL,
  "points" REAL DEFAULT 1,
  "order_index" int(11) DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "online_exam_attempts" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "exam_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "attempt_number" int(11) DEFAULT 1,
  "start_time" TEXT NOT NULL,
  "end_time" TEXT DEFAULT NULL,
  "submitted_at" TEXT DEFAULT NULL,
  "status" TEXT DEFAULT 'in_progress',
  "score" REAL DEFAULT 0,
  "max_score" REAL DEFAULT 0,
  "ip_address" TEXT DEFAULT NULL,
  "user_agent" TEXT DEFAULT NULL,
  "geo_lat" REAL DEFAULT NULL,
  "geo_lng" REAL DEFAULT NULL,
  "geo_accuracy" REAL DEFAULT NULL,
  "camera_ok" tinyint(1) DEFAULT 0,
  "mic_ok" tinyint(1) DEFAULT 0,
  "location_ok" tinyint(1) DEFAULT 0,
  "internet_quality" TEXT DEFAULT NULL,
  "tab_switch_count" int(11) DEFAULT 0,
  "exit_count" int(11) DEFAULT 0,
  "copy_attempts" int(11) DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  "timer_started_at" TEXT DEFAULT NULL,
  "first_started_at" TEXT DEFAULT NULL,
  "is_timer_started" INTEGER DEFAULT 0,
  "is_timer_started_second" INTEGER DEFAULT 0
);
CREATE TABLE IF NOT EXISTS "online_exam_answers" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "attempt_id" int(11) NOT NULL,
  "question_id" int(11) NOT NULL,
  "answer_data" TEXT DEFAULT NULL,
  "is_correct" tinyint(1) DEFAULT NULL,
  "points_earned" REAL DEFAULT 0,
  "answered_at" TEXT DEFAULT NULL,
  "needs_manual" INTEGER DEFAULT 0,
  "graded_at" TEXT DEFAULT NULL,
  "graded_by_id" INTEGER DEFAULT NULL,
  "graded_by_type" TEXT DEFAULT NULL,
  "teacher_comment" TEXT DEFAULT NULL,
  UNIQUE ("attempt_id", "question_id")
);
CREATE TABLE IF NOT EXISTS "online_exam_proctoring_logs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "attempt_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "exam_id" int(11) NOT NULL,
  "event_type" TEXT NOT NULL,
  "event_data" text DEFAULT NULL,
  "ip_address" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "online_exam_live_sessions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "attempt_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "exam_id" int(11) NOT NULL,
  "last_heartbeat" TEXT NOT NULL,
  "ip_address" TEXT DEFAULT NULL,
  "lat" REAL DEFAULT NULL,
  "lng" REAL DEFAULT NULL,
  "accuracy" REAL DEFAULT NULL,
  "status" TEXT DEFAULT 'active',
  "camera_ok" tinyint(1) DEFAULT 0,
  "mic_ok" tinyint(1) DEFAULT 0,
  "location_ok" tinyint(1) DEFAULT 0,
  "exit_count" int(11) DEFAULT 0,
  "tab_switch_count" int(11) DEFAULT 0,
  "copy_attempts" int(11) DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  "updated_at" TEXT DEFAULT NULL,
  UNIQUE ("attempt_id")
);
CREATE TABLE IF NOT EXISTS "online_exam_webcam_requests" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "attempt_id" int(11) NOT NULL,
  "exam_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "requested_by_type" TEXT DEFAULT 'admin',
  "requested_by_id" int(11) DEFAULT NULL,
  "requested_at" TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  "status" TEXT DEFAULT 'pending',
  "snapshot_path" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS "online_exam_webcam_snapshots" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "attempt_id" int(11) NOT NULL,
  "exam_id" int(11) NOT NULL,
  "student_id" int(11) NOT NULL,
  "file_path" TEXT NOT NULL,
  "file_size" int(11) DEFAULT 0,
  "is_saved_by_teacher" tinyint(1) DEFAULT 0,
  "saved_by_type" TEXT DEFAULT NULL,
  "saved_by_id" int(11) DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);

-- Desktop seed: current academic year default
INSERT OR IGNORE INTO "settings" ("key_name", "key_value") VALUES ('current_academic_year', '1404/1405');

-- === DESK SYNC INFRASTRUCTURE ===

CREATE TABLE IF NOT EXISTS "desk_change_log" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "tbl" TEXT NOT NULL,
  "rid" TEXT NOT NULL,
  "op" TEXT NOT NULL,
  "ts" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_dcl_tbl ON "desk_change_log" ("tbl","rid");
CREATE TABLE IF NOT EXISTS "desk_sync_suppress" ("flag" INTEGER);

CREATE TABLE IF NOT EXISTS "student_attendance" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "student_id" INTEGER NOT NULL,
  "academic_year" TEXT DEFAULT NULL,
  "date_jalali" TEXT NOT NULL,
  "status" TEXT NOT NULL DEFAULT 'absent',
  "minutes_late" INTEGER NOT NULL DEFAULT 0,
  "note" TEXT DEFAULT NULL,
  "notified_chats" INTEGER NOT NULL DEFAULT 0,
  "review_status" TEXT NOT NULL DEFAULT 'pending',
  "created_by_admin_id" INTEGER DEFAULT NULL,
  "created_by_teacher_id" INTEGER DEFAULT NULL,
  "created_at_jalali" TEXT NOT NULL,
  "updated_at_jalali" TEXT DEFAULT NULL,
  "scan_time" TEXT DEFAULT NULL,
  "source" TEXT NOT NULL DEFAULT 'manual',
  UNIQUE ("student_id", "date_jalali")
);
CREATE TABLE IF NOT EXISTS "student_qr_tags" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "student_id" INTEGER NOT NULL,
  "token" TEXT NOT NULL,
  "created_at_jalali" TEXT NOT NULL,
  UNIQUE ("student_id"),
  UNIQUE ("token")
);
CREATE TRIGGER IF NOT EXISTS trg_sync_academic_years_i AFTER INSERT ON "academic_years"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('academic_years', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_academic_years_u AFTER UPDATE ON "academic_years"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('academic_years', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_academic_years_d AFTER DELETE ON "academic_years"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('academic_years', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_admins_i AFTER INSERT ON "admins"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('admins', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_admins_u AFTER UPDATE ON "admins"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('admins', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_admins_d AFTER DELETE ON "admins"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('admins', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_teachers_i AFTER INSERT ON "teachers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('teachers', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_teachers_u AFTER UPDATE ON "teachers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('teachers', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_teachers_d AFTER DELETE ON "teachers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('teachers', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_discipline_titles_i AFTER INSERT ON "discipline_titles"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('discipline_titles', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_discipline_titles_u AFTER UPDATE ON "discipline_titles"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('discipline_titles', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_discipline_titles_d AFTER DELETE ON "discipline_titles"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('discipline_titles', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_classes_i AFTER INSERT ON "classes"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('classes', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_classes_u AFTER UPDATE ON "classes"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('classes', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_classes_d AFTER DELETE ON "classes"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('classes', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_subjects_i AFTER INSERT ON "subjects"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('subjects', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_subjects_u AFTER UPDATE ON "subjects"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('subjects', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_subjects_d AFTER DELETE ON "subjects"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('subjects', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_class_schedules_i AFTER INSERT ON "class_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('class_schedules', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_class_schedules_u AFTER UPDATE ON "class_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('class_schedules', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_class_schedules_d AFTER DELETE ON "class_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('class_schedules', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_students_i AFTER INSERT ON "students"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('students', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_students_u AFTER UPDATE ON "students"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('students', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_students_d AFTER DELETE ON "students"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('students', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_discipline_records_i AFTER INSERT ON "student_discipline_records"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_discipline_records', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_discipline_records_u AFTER UPDATE ON "student_discipline_records"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_discipline_records', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_discipline_records_d AFTER DELETE ON "student_discipline_records"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_discipline_records', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_reports_i AFTER INSERT ON "reports"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('reports', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_reports_u AFTER UPDATE ON "reports"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('reports', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_reports_d AFTER DELETE ON "reports"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('reports', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_grades_i AFTER INSERT ON "report_grades"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_grades', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_grades_u AFTER UPDATE ON "report_grades"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_grades', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_grades_d AFTER DELETE ON "report_grades"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_grades', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_locks_i AFTER INSERT ON "report_locks"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_locks', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_locks_u AFTER UPDATE ON "report_locks"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_locks', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_report_locks_d AFTER DELETE ON "report_locks"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('report_locks', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_schedules_i AFTER INSERT ON "exam_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_schedules', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_schedules_u AFTER UPDATE ON "exam_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_schedules', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_schedules_d AFTER DELETE ON "exam_schedules"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_schedules', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_designs_i AFTER INSERT ON "exam_designs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_designs', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_designs_u AFTER UPDATE ON "exam_designs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_designs', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_designs_d AFTER DELETE ON "exam_designs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_designs', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_design_archive_i AFTER INSERT ON "exam_design_archive"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_design_archive', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_design_archive_u AFTER UPDATE ON "exam_design_archive"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_design_archive', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_design_archive_d AFTER DELETE ON "exam_design_archive"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_design_archive', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_question_bank_i AFTER INSERT ON "exam_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_question_bank', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_question_bank_u AFTER UPDATE ON "exam_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_question_bank', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_question_bank_d AFTER DELETE ON "exam_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_question_bank', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_assignments_i AFTER INSERT ON "exam_assignments"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_assignments', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_assignments_u AFTER UPDATE ON "exam_assignments"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_assignments', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_assignments_d AFTER DELETE ON "exam_assignments"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_assignments', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_student_seating_i AFTER INSERT ON "exam_student_seating"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_student_seating', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_student_seating_u AFTER UPDATE ON "exam_student_seating"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_student_seating', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_exam_student_seating_d AFTER DELETE ON "exam_student_seating"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('exam_student_seating', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_categories_i AFTER INSERT ON "online_exam_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_categories', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_categories_u AFTER UPDATE ON "online_exam_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_categories', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_categories_d AFTER DELETE ON "online_exam_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_categories', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_categories_i AFTER INSERT ON "online_question_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_categories', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_categories_u AFTER UPDATE ON "online_question_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_categories', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_categories_d AFTER DELETE ON "online_question_categories"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_categories', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_bank_i AFTER INSERT ON "online_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_bank', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_bank_u AFTER UPDATE ON "online_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_bank', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_question_bank_d AFTER DELETE ON "online_question_bank"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_question_bank', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exams_i AFTER INSERT ON "online_exams"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exams', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exams_u AFTER UPDATE ON "online_exams"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exams', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exams_d AFTER DELETE ON "online_exams"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exams', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_questions_i AFTER INSERT ON "online_questions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_questions', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_questions_u AFTER UPDATE ON "online_questions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_questions', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_questions_d AFTER DELETE ON "online_questions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_questions', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_attempts_i AFTER INSERT ON "online_exam_attempts"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_attempts', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_attempts_u AFTER UPDATE ON "online_exam_attempts"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_attempts', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_attempts_d AFTER DELETE ON "online_exam_attempts"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_attempts', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_answers_i AFTER INSERT ON "online_exam_answers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_answers', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_answers_u AFTER UPDATE ON "online_exam_answers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_answers', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_online_exam_answers_d AFTER DELETE ON "online_exam_answers"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('online_exam_answers', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_grade_messages_i AFTER INSERT ON "grade_messages"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('grade_messages', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_grade_messages_u AFTER UPDATE ON "grade_messages"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('grade_messages', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_grade_messages_d AFTER DELETE ON "grade_messages"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('grade_messages', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_counseling_requests_i AFTER INSERT ON "counseling_requests"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('counseling_requests', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_counseling_requests_u AFTER UPDATE ON "counseling_requests"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('counseling_requests', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_counseling_requests_d AFTER DELETE ON "counseling_requests"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('counseling_requests', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_settings_i AFTER INSERT ON "settings"
WHEN NEW.key_name NOT LIKE 'desk_%' AND NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('settings', NEW.key_name, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_settings_u AFTER UPDATE ON "settings"
WHEN NEW.key_name NOT LIKE 'desk_%' AND NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('settings', NEW.key_name, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_settings_d AFTER DELETE ON "settings"
WHEN OLD.key_name NOT LIKE 'desk_%' AND NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('settings', OLD.key_name, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_attendance_i AFTER INSERT ON "student_attendance"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_attendance', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_attendance_u AFTER UPDATE ON "student_attendance"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_attendance', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_attendance_d AFTER DELETE ON "student_attendance"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_attendance', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_qr_tags_i AFTER INSERT ON "student_qr_tags"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_qr_tags', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_qr_tags_u AFTER UPDATE ON "student_qr_tags"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_qr_tags', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_student_qr_tags_d AFTER DELETE ON "student_qr_tags"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('student_qr_tags', OLD.id, 'D', strftime('%s','now')); END;


-- bot tables (v2.4.0): keep bot registrations/templates in sync with the site
CREATE TRIGGER IF NOT EXISTS trg_sync_bale_bot_users_i AFTER INSERT ON "bale_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bale_bot_users', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bale_bot_users_u AFTER UPDATE ON "bale_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bale_bot_users', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bale_bot_users_d AFTER DELETE ON "bale_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bale_bot_users', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_telegram_bot_users_i AFTER INSERT ON "telegram_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('telegram_bot_users', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_telegram_bot_users_u AFTER UPDATE ON "telegram_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('telegram_bot_users', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_telegram_bot_users_d AFTER DELETE ON "telegram_bot_users"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('telegram_bot_users', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_admin_sessions_i AFTER INSERT ON "bot_admin_sessions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_admin_sessions', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_admin_sessions_u AFTER UPDATE ON "bot_admin_sessions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_admin_sessions', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_admin_sessions_d AFTER DELETE ON "bot_admin_sessions"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_admin_sessions', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_templates_i AFTER INSERT ON "bot_message_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_templates', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_templates_u AFTER UPDATE ON "bot_message_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_templates', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_templates_d AFTER DELETE ON "bot_message_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_templates', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_button_templates_i AFTER INSERT ON "bot_button_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_button_templates', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_button_templates_u AFTER UPDATE ON "bot_button_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_button_templates', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_button_templates_d AFTER DELETE ON "bot_button_templates"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_button_templates', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_login_tokens_i AFTER INSERT ON "bot_login_tokens"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_login_tokens', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_login_tokens_u AFTER UPDATE ON "bot_login_tokens"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_login_tokens', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_login_tokens_d AFTER DELETE ON "bot_login_tokens"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_login_tokens', OLD.id, 'D', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_logs_i AFTER INSERT ON "bot_message_logs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_logs', NEW.id, 'I', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_logs_u AFTER UPDATE ON "bot_message_logs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_logs', NEW.id, 'U', strftime('%s','now')); END;
CREATE TRIGGER IF NOT EXISTS trg_sync_bot_message_logs_d AFTER DELETE ON "bot_message_logs"
WHEN NOT EXISTS (SELECT 1 FROM desk_sync_suppress)
BEGIN INSERT INTO desk_change_log(tbl,rid,op,ts) VALUES ('bot_message_logs', OLD.id, 'D', strftime('%s','now')); END;

-- Desktop seed: sync key (pre-paired with bundled desk-sync-api.php)
INSERT OR IGNORE INTO "settings" ("key_name", "key_value") VALUES ('desk_sync_key', 'SDP-63fd161031e1f130a6530e6d161c65f1f86b87ca1d03fb99');
