import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (p) => readFileSync(new URL(p, import.meta.url), 'utf8');
const sitePrint = read('../update-v4.152.0/exam-print.php');
const deskPrint = read('../desktop-app-v2/patch/www-exam-print.php');
const siteApi = read('../update-v4.152.0/exam-design-api.php');
const deskApi = read('../desktop-app-v2/patch/www-exam-design-api.php');
const siteExams = read('../update-v4.152.0/exams.php');
const deskExams = read('../desktop-app-v2/patch/www-exams.php');
const siteHelper = read('../update-v4.152.0/includes/exams_helper.php');
const deskHelper = read('../desktop-app-v2/patch/includes-exams_helper.php');
const siteTeacher = read('../update-v4.152.0/includes/teacher_class_exams.php');
const deskTeacher = read('../desktop-app-v2/patch/includes-teacher_class_exams.php');
const siteAdmin = read('../update-v4.152.0/includes/admin_class_exams.php');
const deskAdmin = read('../desktop-app-v2/patch/includes-admin_class_exams.php');
const siteClassCreate = read('../update-v4.152.0/class-exam-create.php');
const deskClassCreate = read('../desktop-app-v2/patch/www-class-exam-create.php');

let checks = 0;
const check = (condition, message) => { assert(condition, message); checks++; };

// The live editor asks for an explicit choice; local/keyboard/print saves remain drafts.
check(sitePrint.includes('openSaveChoiceModal()'), 'toolbar and mobile save open the save-choice dialog');
check(sitePrint.includes('ذخیرهٔ معمولی') && sitePrint.includes('ذخیره و ارسال برای چاپ'), 'both save options are clearly named');
check(sitePrint.includes('send_for_print:!!sendForPrint'), 'the explicit send choice is included in the API request');
check(sitePrint.includes("saveDesign(true).then(j=>showToast"), 'Ctrl+S remains a quiet normal save');
check(sitePrint.includes("saveDesign(true).then(j=>alert"), 'after-print save remains a normal save');
check(siteApi.includes("$sendForPrint = !empty($payload['send_for_print']);"), 'API reads the optional send flag');
const notifyBlock = /if\s*\(\$sendForPrint\)\s*\{[\s\S]*?bot_notify_exam_design_saved\(\$examId\);[\s\S]*?\n\s*\}/.exec(siteApi);
check(!!notifyBlock, 'only the explicit send-for-print path calls the executive notification');
check(!/bot_notify_exam_design_saved\(\$examId\);/.test(siteApi.replace(notifyBlock?.[0] ?? '', '')), 'no unconditional executive notification remains');
check(siteApi.includes('این ذخیره به‌عنوان پیش‌نویس باقی می‌ماند'), 'ordinary saves are explicitly described as drafts');

// Admin-only toggle; the persisted flag is separate from is_active and enforced server-side.
check(siteHelper.includes('teacher_design_enabled tinyint(1) NOT NULL DEFAULT 1'), 'legacy/new databases get an enabled-by-default design flag');
check(siteHelper.includes('function exam_teacher_class_design_enabled'), 'shared server-side authorization helper exists');
check(siteApi.includes('exam_teacher_class_design_enabled($exam)'), 'API rejects disabled class-exam design');
check(sitePrint.includes('exam_teacher_class_design_enabled($exam)'), 'direct designer page rejects disabled class-exam design');
check(siteClassCreate.includes('exam_teacher_class_design_enabled($designTarget)'), 'class-exam entry route checks the effective shared design');
check(siteExams.includes("if(!is_admin_logged_in())"), 'only an admin may change the design setting');
check(siteAdmin.includes('if(is_admin_logged_in())'), 'toggle control is conditional on administrator session');
check(siteTeacher.includes('طراحی آزمون کلاسی توسط مدیریت غیرفعال است'), 'teacher panel hides/labels disabled design actions');

// Monthly print list: latest saved date + countdown, sorted nearest-first, no redundant header-print button.
const monthlyTable = siteExams.match(/<table id="adminMonthlyExams">[\s\S]*?<\/table>/)?.[0] ?? '';
check(monthlyTable.includes('آخرین ذخیرهٔ طراحی') && monthlyTable.includes('تا آزمون'), 'monthly print table has design-date and countdown columns');
check(monthlyTable.includes('بدون طراحی'), 'missing design is labelled explicitly');
check(!monthlyTable.includes('چاپ سربرگ سوالات'), 'redundant print-header button is removed');
check(siteExams.includes('exam_jalali_days_until'), 'monthly exams sort and display days using Jalali-aware date math');
check(siteExams.includes('exam-no-design'), 'monthly exam rows without designs are visually muted');
check(siteHelper.includes('function exam_jalali_date_sort_key') && siteHelper.includes('function exam_jalali_days_until'), 'Jalali date sorting/countdown helpers are defined');

// Desktop patch mirrors the site files exactly so both distribution paths behave alike.
for (const [label, site, desk] of [
  ['exam designer', sitePrint, deskPrint], ['design API', siteApi, deskApi], ['monthly exams', siteExams, deskExams],
  ['exam schema/helper', siteHelper, deskHelper], ['teacher class-exam table', siteTeacher, deskTeacher],
  ['admin class-exam table', siteAdmin, deskAdmin], ['class-exam entry', siteClassCreate, deskClassCreate],
]) check(site === desk, `desktop ${label} matches the site implementation`);

console.log(`PASS ${checks} save-choice/class-exam/monthly-print checks`);
process.exit(0);
