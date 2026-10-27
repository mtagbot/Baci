#!/usr/bin/env node
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { REPO } from './harness/site.mjs';
import { php, run, req, loginAdmin } from './harness/lib.mjs';

let pass = 0;
function ok(label, cond, detail = '') {
  assert(cond, label + (detail ? ' — ' + detail : ''));
  pass++;
  console.log('  ✅ ' + label);
}

console.log('══ ۱) رتبه پایه و رتبه کلاس در همان سال تحصیلی و همان پایه ══');
const rankRes = await run(String.raw`<?php
ini_set('display_errors', '1');
try {
require_once '/www/config/config.php';
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';

DB::query("DELETE FROM report_grades");
DB::query("DELETE FROM reports");
DB::query("DELETE FROM students");

// Insert students in 1404/1405 grade هشتم (two classes: ۸/۱ and ۸/۲)
DB::query("INSERT INTO students (id, first_name, last_name, father_name, national_id, class_name, grade_level, academic_year) VALUES
  (101, 'علی', 'الف', 'رضا', '0010000101', '۸/۱', 'هشتم', '1404/1405'),
  (102, 'حسین', 'ب', 'رضا', '0010000102', '۸/۲', 'هشتم', '1404-1405'),
  (103, 'مهدی', 'ج', 'رضا', '0010000103', '۹/۱', 'نهم', '1404/1405'),
  (104, 'سعید', 'د', 'رضا', '0010000104', '۸/۱', 'هشتم', '1403/1404')
");

DB::query("INSERT INTO reports (id, student_id, academic_year, report_month, gpa, is_locked) VALUES
  (201, 101, '1404/1405', 'آبان', 18.50, 0),
  (202, 102, '1404-1405', 'آبان', 19.50, 0),
  (203, 103, '1404/1405', 'آبان', 20.00, 0),
  (204, 104, '1403/1404', 'آبان', 19.90, 0)
");

DB::query("INSERT INTO report_grades (report_id, subject_name, score) VALUES
  (201, 'ریاضی', 18.50),
  (202, 'ریاضی', 19.50),
  (203, 'ریاضی', 20.00),
  (204, 'ریاضی', 19.90)
");

$rep101 = DB::fetch("SELECT r.*, s.class_name, s.grade_level FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = 201");
$classScope = fetch_report_rank_scope($rep101, 'class');
$gradeScope = fetch_report_rank_scope($rep101, 'grade');
$subjClass  = fetch_subject_rank_scope($rep101, 'ریاضی', 'class');
$subjGrade  = fetch_subject_rank_scope($rep101, 'ریاضی', 'grade');

$classRank = 0;
foreach ($classScope as $idx => $cr) { if ((int)$cr['student_id'] === 101) { $classRank = $idx + 1; break; } }
$gradeRank = 0;
foreach ($gradeScope as $idx => $gr) { if ((int)$gr['student_id'] === 101) { $gradeRank = $idx + 1; break; } }
$subjClassRank = 0;
foreach ($subjClass as $si => $scr) { if ((int)$scr['student_id'] === 101 || (float)$scr['score'] <= 18.50) { $subjClassRank = $si + 1; break; } }
$subjGradeRank = 0;
foreach ($subjGrade as $si => $sgr) { if ((int)$sgr['student_id'] === 101 || (float)$sgr['score'] <= 18.50) { $subjGradeRank = $si + 1; break; } }

echo json_encode([
  'class_count' => count($classScope),
  'grade_count' => count($gradeScope),
  'class_rank'  => $classRank,
  'grade_rank'  => $gradeRank,
  'subj_class_rank' => $subjClassRank,
  'subj_grade_rank' => $subjGradeRank,
]);
} catch (Throwable $e) {
  echo json_encode(['error' => $e->getMessage(), 'line' => $e->getLine()]);
}
`);
const rankData = JSON.parse(rankRes.out.trim());
ok('رتبه کلاس فقط دانش‌آموزان همان کلاس در همان سال تحصیلی را می‌شمارد', rankData.class_count === 1 && rankData.class_rank === 1);
ok('رتبه پایه فقط دانش‌آموزان همان پایه (هشتم) در همان سال تحصیلی (1404/1405) را می‌شمارد (حذف پایه نهم و سال 1403/1404)',
  rankData.grade_count === 2 && rankData.grade_rank === 2, JSON.stringify(rankData));
ok('رتبه درس در کلاس و پایه نیز دقیقاً در همان سال تحصیلی و همان پایه محاسبه می‌شود',
  rankData.subj_class_rank === 1 && rankData.subj_grade_rank === 2);

console.log('\n══ ۲) برش تصویر تا ۱۰۰٪ در ویرایشگر زنده آزمون ══');
const examPrintSite = readFileSync(join(REPO, 'update-v4.152.0/exam-print.php'), 'utf8');
const examPrintDesk = readFileSync(join(REPO, 'desktop-app-v2/patch/www-exam-print.php'), 'utf8');
for (const [label, src] of [['سایت', examPrintSite], ['دسکتاپ', examPrintDesk]]) {
  ok(`اسلایدرهای برش منبعی و ویرایش تصویر (${label}) تا max="100" باز شده‌اند`,
    src.includes('min="0" max="100" value="0" id="cropT"') &&
    src.includes('min="0" max="100" value="0" id="cropR"') &&
    src.includes('min="0" max="100" value="0" id="cropB"') &&
    src.includes('min="0" max="100" value="0" id="cropL"') &&
    src.includes('id="imgCropL" min="0" max="100"') &&
    src.includes('id="imgCropR" min="0" max="100"') &&
    src.includes('id="imgCropT" min="0" max="100"') &&
    src.includes('id="imgCropB" min="0" max="100"'));
  ok(`توابع sourceCropClamp و setCrop (${label}) برش تا ۱۰۰٪ را از بالا، پایین، چپ و راست مجاز می‌کنند`,
    src.includes('c.t=Math.max(0,Math.min(100,c.t))') &&
    src.includes('c.b=Math.max(0,Math.min(100,c.b))') &&
    src.includes('if(c.t+c.b>100)') &&
    src.includes('if(c.l+c.r>100)'));
}

console.log('\n══ ۳) مسدودسازی پیشینی کارنامه هر ماه قبل از صدور و ثبت نمرات دبیران ══');
const lockRes = await run(String.raw`<?php
require_once '/www/config/config.php';
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';

ensure_report_locks_schema();
DB::query("DELETE FROM report_locks");

// Pre-lock آذر for all classes in 1404/1405 BEFORE any آذر report exists
set_report_bulk_lock('1404/1405', 'آذر', '', 0, 1);
$preLockedAzar = is_report_prelocked('1404/1405', 'آذر', '۸/۱');
$preLockedMehr = is_report_prelocked('1404/1405', 'مهر', '۸/۱');

// Simulate teacher creating a new report row for آذر (like teacher-panel.php / grade-columns.php)
$newIsLocked = is_report_prelocked('1404/1405', 'آذر', '۸/۱') ? 1 : 0;
DB::query("INSERT INTO reports (id, student_id, academic_year, report_month, gpa, is_locked) VALUES (301, 101, '1404/1405', 'آذر', 19.00, ?)", [$newIsLocked]);
DB::query("INSERT INTO report_grades (report_id, subject_name, score) VALUES (301, 'علوم', 19.00)");

$repAzar = DB::fetch("SELECT r.*, s.class_name FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = 301");
$lockedForStudentBefore = is_report_locked_for_student($repAzar);

// Now unlock آذر via set_report_bulk_lock(..., 0)
set_report_bulk_lock('1404/1405', 'آذر', '', 0, 0);
$repAzarAfter = DB::fetch("SELECT r.*, s.class_name FROM reports r JOIN students s ON r.student_id = s.id WHERE r.id = 301");
$lockedForStudentAfter = is_report_locked_for_student($repAzarAfter);

echo json_encode([
  'pre_locked_azar' => $preLockedAzar,
  'pre_locked_mehr' => $preLockedMehr,
  'new_is_locked'   => $newIsLocked,
  'locked_before'   => $lockedForStudentBefore,
  'locked_after'    => $lockedForStudentAfter,
]);
`);
const lockData = JSON.parse(lockRes.out.trim());
ok('مسدودسازی پیشینی ماه (آذر) پیش از صدور کارنامه فعال می‌شود بدون مسدود کردن ماه‌های دیگر (مهر)',
  lockData.pre_locked_azar === true && lockData.pre_locked_mehr === false);
ok('هنگام ثبت نمره توسط دبیر برای ماه مسدودشده، کارنامه ایجاد می‌شود اما خودکار قفل است و برای دانش‌آموز مسدود می‌ماند',
  lockData.new_is_locked === 1 && lockData.locked_before === true);
ok('باز کردن قفل ماه (آذر) توسط مدیر، هم قفل پیشینی و هم کارنامه‌های صادرشدهٔ آن ماه را برای دانش‌آموز باز می‌کند',
  lockData.locked_after === false);

console.log('\n══ ۴) الزام همگام‌سازی اولیه در دسکتاپ و خودکارسازی کلید همگام‌سازی ══');
const deskSyncCheck = await run(String.raw`<?php
try {
require_once '/www/config/config.php';
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/desk_sync.php';

DB::execute("DELETE FROM settings WHERE key_name IN ('desk_sync_url','desk_sync_key','desk_sync_snapshot_done','desk_sync_last_ok')");
$beforeSync = DeskSync::isInitialSyncCompleted();

DeskSync::setCfg('desk_sync_url', 'https://school.example.ir');
DeskSync::setCfg('desk_sync_key', str_repeat('a', 64));
$configuredButNotSynced = DeskSync::isInitialSyncCompleted();

DeskSync::setCfg('desk_sync_snapshot_done', '1');
$afterSync = DeskSync::isInitialSyncCompleted();

echo json_encode(['before' => $beforeSync, 'configured_only' => $configuredButNotSynced, 'after' => $afterSync]);
} catch (Throwable $e) {
  echo json_encode(['error' => $e->getMessage()]);
}
`);
const deskInitData = JSON.parse(deskSyncCheck.out.trim());
ok('DeskSync::isInitialSyncCompleted() پیش از اولین همگام‌سازی کامل با سرور false و پس از آن true برمی‌گرداند',
  deskInitData.before === false && deskInitData.configured_only === false && deskInitData.after === true);

const headerSite = readFileSync(join(REPO, 'update-v4.152.0/includes/header.php'), 'utf8');
const headerDesk = readFileSync(join(REPO, 'desktop-app-v2/patch/includes-header.php'), 'utf8');
const installerPhp = readFileSync(join(REPO, 'release-v1.0/common/installer.php'), 'utf8');
const releaseInstallPhp = readFileSync(join(REPO, 'release-v1.0/common/includes/release_install.php'), 'utf8');
ok('هدر دسکتاپ و سایت تا پیش از تکمیل همگام‌سازی اولیه، کاربر دسکتاپ را به desk-sync.php هدایت می‌کنند',
  headerSite.includes('$isDeskInitialSyncRequired') && headerDesk.includes('$isDeskInitialSyncRequired') &&
  headerDesk.includes('desk-sync.php?initial_sync=1'));
ok('نصب‌کنندهٔ دسکتاپ (installer.php و release_install.php) آدرس سرور و کلید همگام‌سازی را الزامی کرده و همگام‌سازی اولیه را اجرا می‌کند',
  installerPhp.includes('اتصال و همگام‌سازی الزامی با سرور سایت مدرسه') &&
  releaseInstallPhp.includes('DeskSync::run(true)') &&
  releaseInstallPhp.includes('desk_require_initial_sync'));

console.log('\n══ ۵) صفحهٔ همگام‌سازی روی نسخهٔ سایت (desk-sync.php) ══');
await loginAdmin('harnessAdm0001');
const siteSyncPage = await req('صفحه همگام‌سازی سایت', { sid: 'harnessAdm0001', file: 'desk-sync.php' });
ok('صفحهٔ desk-sync.php روی نسخهٔ سایت بدون خطای PHP رندر می‌شود',
  !siteSyncPage.res.fatal && siteSyncPage.res.page.includes('همگام‌سازی با نرم‌افزار دسکتاپ'),
  siteSyncPage.res.fatal || '');
ok('صفحهٔ همگام‌سازی سایت کلید DESK_SYNC_KEY، آدرس سرور و وضعیت همگام‌سازی را نمایش می‌دهد',
  siteSyncPage.res.page.includes('deskSyncKeyInput') &&
  siteSyncPage.res.page.includes('siteSyncUrlInput') &&
  siteSyncPage.res.page.includes('کپی کلید همگام‌سازی'));

const siteSyncAjax = await req('AJAX وضعیت همگام‌سازی', { sid: 'harnessAdm0001', file: 'desk-sync.php', query: 'ajax=status' });
const ajaxJson = JSON.parse(siteSyncAjax.res.page.trim());
ok('سرویس AJAX وضعیت همگام‌سازی در صفحهٔ سایت (?ajax=status) پاسخ معتبر برمی‌گرداند',
  ajaxJson.ok === true && ajaxJson.ready === true && ajaxJson.endpoints?.key_file === true);

console.log(`\n──── همهٔ ${pass} آزمون ۵ بهبود جدید با موفقیت پاس شدند ────`);
process.exit(0);
