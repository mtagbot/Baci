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
const siteTeacherPanel = read('../update-v4.152.0/teacher-panel.php');
const deskTeacherPanel = read('../desktop-app-v2/patch/www-teacher-panel.php');
const siteAdmin = read('../update-v4.152.0/includes/admin_class_exams.php');
const deskAdmin = read('../desktop-app-v2/patch/includes-admin_class_exams.php');
const siteClassCreate = read('../update-v4.152.0/class-exam-create.php');
const deskClassCreate = read('../desktop-app-v2/patch/www-class-exam-create.php');
const siteSettings = read('../update-v4.152.0/settings.php');
const siteSync = read('../update-v4.152.0/includes/desk_sync.php');
const deskSync = read('../desktop-app-v2/patch/includes-desk-sync.php');

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
check(siteHelper.includes("get_setting('class_exam_design_enabled', '1') !== '0'"), 'one global enabled-by-default setting controls all class exams');
check(siteHelper.includes('function exam_teacher_class_design_enabled'), 'shared server-side authorization helper exists');
check(siteApi.includes('exam_teacher_class_design_enabled($exam)'), 'API enforces the global setting for teachers');
check(sitePrint.includes('exam_teacher_class_design_enabled($exam)'), 'direct designer page enforces the global setting');
check(sitePrint.includes('exam_can_view_design($examId)'), 'executive users can review existing designs while the save API remains role-restricted');
check(siteClassCreate.includes('exam_teacher_class_design_enabled($designTarget)'), 'class-exam entry route checks the effective shared design');
check(siteSettings.includes("if (is_admin_logged_in())") && siteSettings.includes('name="class_exam_design_enabled"'), 'the global switch is editable only in admin customization settings');
check(!siteExams.includes('set_class_design_enabled') && !siteAdmin.includes('دسترسی طراحی دبیر'), 'the previous per-row controls are removed');
check(siteAdmin.includes("empty($ce['updated_at_jalali'])?'exam-no-design':''"), 'undESigned class-exam rows are muted, designed rows stay normal');
check(siteTeacherPanel.includes("$classExamDesignEnabled = get_setting('class_exam_design_enabled', '1') !== '0'"), 'teacher panel uses the global design switch as a whole-section visibility gate');
check(siteTeacherPanel.includes('<?php if($classExamDesignEnabled): ?>') && siteTeacherPanel.includes("include __DIR__.'/includes/teacher_class_exams.php';"), 'class-exam link/table/text are wrapped and disappear when design is disabled');
const teacherMonthly = siteTeacherPanel.match(/<table id="teacherMonthlyExams">[\s\S]*?<\/table>/)?.[0] ?? '';
const teacherHeaders = [...teacherMonthly.matchAll(/<th[^>]*>([\s\S]*?)<\/th>/g)].map(m => m[1].replace(/<[^>]*>/g, '').trim());
check(JSON.stringify(teacherHeaders) === JSON.stringify(['کلاس/پایه','طراحی','طراحی پایه‌ای (مشترک کلاس‌های پایه)','تاریخ','درس','سال/ماه','ساعت']), 'teacher monthly table columns follow the requested order');
check(!siteTeacherPanel.includes('دبیر می‌تواند از بانک سوالات استفاده کند'), 'the requested bank-question explanatory text is removed');

// Monthly print list: latest saved date + countdown, sorted nearest-first, no redundant header-print button.
const monthlyTable = siteExams.match(/<table id="adminMonthlyExams">[\s\S]*?<\/table>/)?.[0] ?? '';
check(monthlyTable.includes('آخرین ذخیرهٔ طراحی') && monthlyTable.includes('تا آزمون'), 'monthly print table has design-date and countdown columns');
check(monthlyTable.includes('بدون طراحی'), 'missing design is labelled explicitly');
check(!monthlyTable.includes('چاپ سربرگ سوالات'), 'redundant print-header button is removed');
check(siteExams.includes('exam_jalali_days_until'), 'monthly exams sort and display days using Jalali-aware date math');
check(siteExams.includes('exam-no-design'), 'monthly exam rows without designs are visually muted');
check(siteHelper.includes('function exam_jalali_date_sort_key') && siteHelper.includes('function exam_jalali_days_until'), 'Jalali date sorting/countdown helpers are defined');

// HTTP 403 from one filename may be caused by a host/WAF whitelist; try its paired endpoint once.
check(siteSync.includes('alternateSyncEndpoint') && siteSync.includes('$code === 403'), 'site sync retries the paired endpoint only on HTTP 403');
check(deskSync.includes('alternateSyncEndpoint') && deskSync.includes('$code === 403'), 'desktop sync retries the paired endpoint only on HTTP 403');
check(deskSync.includes('diagnose403Response') && deskSync.includes('کلید نادرست پاسخ HTTP 200 می‌دهد'), 'remaining HTTP 403 includes safe response classification and explains that key errors are HTTP 200');
check(siteSync.includes('Content-Type: application/json') && deskSync.includes('access/error/WAF log'), '403 guidance tells the operator which server logs and POST content type to inspect');

// Desktop patch mirrors the site files exactly so both distribution paths behave alike.
for (const [label, site, desk] of [
  ['exam designer', sitePrint, deskPrint], ['design API', siteApi, deskApi], ['monthly exams', siteExams, deskExams],
  ['exam schema/helper', siteHelper, deskHelper], ['teacher class-exam table', siteTeacher, deskTeacher],
  ['admin class-exam table', siteAdmin, deskAdmin], ['class-exam entry', siteClassCreate, deskClassCreate], ['teacher panel', siteTeacherPanel, deskTeacherPanel],
]) check(site === desk, `desktop ${label} matches the site implementation`);

console.log(`PASS ${checks} save-choice/class-exam/monthly-print checks`);
process.exit(0);
