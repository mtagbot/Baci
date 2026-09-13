/**
 * سوئیت ۱۱ — شش اصلاح نسخهٔ ۴.۱۳۱.۰
 *
 * هر بخش، کدِ واقعی محصول را اجرا می‌کند (نه بازنویسی منطق):
 *   ۱) حذف «رمز خام» از همهٔ مسیرهای ورود  → توابع واقعی در php-wasm
 *   ۲) ایندکس‌های SQLite                    → EXPLAIN QUERY PLAN روی دیتابیس واقعی
 *   ۳) db-optimizer روی SQLite              → رندر واقعی صفحه + اجرای اکشن‌ها
 *   ۴) سخت‌سازی آپلود رسانه                → POST واقعی بدون/با CSRF
 *   ۵) .htaccess پوشه‌های uploads و backups → وجود و محتوای فایل در بسته
 *   ۶) حذف آزمون بدون رکورد یتیم           → شمارش ردیف‌ها قبل/بعد
 */
import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import { resolveSite, resolveFile } from './harness/site.mjs';
import { req, run, db, dbExec, php, examId, student, loginAdmin, login } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`)) : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

/* ════════════════════════════════════════════════════════════════
   ۱) رمز عبور — دیگر هیچ مسیری رمز خام / کد ملی / کد پرسنلی نمی‌پذیرد
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۱) احراز هویت رمز عبور ══');

/* الف) هیچ فایلی الگوی «|| $pass === ...» را نداشته باشد */
const authFiles = ['admin-login.php', 'index.php', 'api/index.php', 'profile.php',
                   'my-sessions.php', 'includes/bot_webhook_engine.php'];
for (const f of authFiles) {
  const src = readFileSync(resolveFile(f), 'utf8');
  const bad = /password_verify\([^)]*\)\s*\|\|/.test(src);
  ok(`${f}: شاخهٔ «یا رمز خام» ندارد`, !bad);
}

/* ب) اجرای واقعی verify_user_password در PHP */
const vupCode = `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/functions.php';
$h = password_hash('correct-horse', PASSWORD_DEFAULT);
$r = [];
$r['hash_ok']        = verify_user_password('correct-horse', $h) ? 1 : 0;
$r['hash_wrong']     = verify_user_password('wrong', $h) ? 1 : 0;
$r['empty_pass']     = verify_user_password('', $h) ? 1 : 0;
$r['empty_stored']   = verify_user_password('x', '') ? 1 : 0;
/* رکورد قدیمیِ هش‌نشده: فقط تطابق دقیق */
$r['legacy_exact']   = verify_user_password('plain123', 'plain123') ? 1 : 0;
$r['legacy_wrong']   = verify_user_password('nope', 'plain123') ? 1 : 0;
/* هشِ رمزِ دیگر نباید با متنِ هش باز شود */
$r['hash_as_text']   = verify_user_password($h, $h) ? 1 : 0;
$r['is_hashed_yes']  = password_is_hashed($h) ? 1 : 0;
$r['is_hashed_no']   = password_is_hashed('plain123') ? 1 : 0;
echo json_encode($r);`;
php.writeFile('/harness/vup.php', vupCode);
const vup = JSON.parse((await run("<?php require '/harness/vup.php';")).out.trim());
ok('رمز درست با هش پذیرفته می‌شود',          vup.hash_ok === 1);
ok('رمز غلط رد می‌شود',                       vup.hash_wrong === 0);
ok('رمز خالی رد می‌شود',                      vup.empty_pass === 0);
ok('مقدار ذخیره‌شدهٔ خالی رد می‌شود',          vup.empty_stored === 0);
ok('رکورد قدیمی هش‌نشده با تطابق دقیق باز می‌شود', vup.legacy_exact === 1);
ok('رکورد قدیمی با رمز غلط باز نمی‌شود',      vup.legacy_wrong === 0);
ok('خودِ رشتهٔ هش به‌عنوان رمز کار نمی‌کند',   vup.hash_as_text === 0);
ok('password_is_hashed هش را می‌شناسد',       vup.is_hashed_yes === 1);
ok('password_is_hashed متن خام را هش نمی‌داند', vup.is_hashed_no === 0);

/* ج) مهاجرت خودکار: رکورد هش‌نشده بعد از ورود موفق هش می‌شود */
await dbExec(`UPDATE students SET password='legacy-raw-pw' WHERE id=${student.id}`);
const before = (await db(`SELECT password p FROM students WHERE id=${student.id}`))[0].p;
ok('پیش‌شرط: رمز در دیتابیس خام است', before === 'legacy-raw-pw');

php.writeFile('/harness/mig.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
$row = DB::fetch("SELECT * FROM students WHERE id=${student.id}");
$okv = verify_user_password('legacy-raw-pw', $row['password'], ['table'=>'students','id'=>${student.id}]);
echo json_encode(['ok'=>$okv?1:0]);`);
const mig = JSON.parse((await run("<?php require '/harness/mig.php';")).out.trim());
ok('ورود با رمز خامِ قدیمی یک‌بار موفق است', mig.ok === 1);
const after = (await db(`SELECT password p FROM students WHERE id=${student.id}`))[0].p;
ok('بعد از ورود، رمز در دیتابیس هش شده است', after !== 'legacy-raw-pw' && /^\$2y\$|^\$argon/.test(after),
   `مقدار فعلی: ${String(after).slice(0, 24)}…`);

/* د) و کد ملی دیگر رمز نیست */
php.writeFile('/harness/nid.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
$row = DB::fetch("SELECT * FROM students WHERE id=${student.id}");
echo json_encode(['nid'=>verify_user_password($row['national_id'], $row['password'])?1:0]);`);
const nid = JSON.parse((await run("<?php require '/harness/nid.php';")).out.trim());
ok('کد ملی دیگر به‌عنوان رمز پذیرفته نمی‌شود', nid.nid === 0);

/* ════════════════════════════════════════════════════════════════
   ۲) ایندکس‌های SQLite
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۲) ایندکس‌های کارایی ══');

const schema = readFileSync(resolveFile('sql/schema-sqlite.sql'), 'utf8');
const idxCount = (schema.match(/CREATE INDEX/g) || []).length;
ok(`schema بیش از ۵۰ ایندکس دارد (${idxCount})`, idxCount >= 50, `فقط ${idxCount}`);
ok('همهٔ ایندکس‌ها IF NOT EXISTS هستند',
   (schema.match(/CREATE INDEX(?! IF NOT EXISTS)/g) || []).length === 0);

const idxRows = await db("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'");
ok(`ایندکس‌ها در دیتابیس واقعی ساخته شدند (${idxRows.length})`, idxRows.length >= 50, `${idxRows.length}`);

/* برنامه‌ریز کوئری واقعاً از آن‌ها استفاده کند */
for (const [label, sql, wantIdx] of [
  ['reports.student_id',                 'SELECT * FROM reports WHERE student_id=1', 'idx_reports_student_id'],
  ['online_exam_answers.attempt_id',     'SELECT * FROM online_exam_answers WHERE attempt_id=1', 'idx_online_exam_answers_attempt_id'],
  ['online_exam_proctoring_logs.attempt','SELECT * FROM online_exam_proctoring_logs WHERE attempt_id=1', 'idx_online_exam_proctoring_logs_attempt_id'],
  ['report_grades.report_id',            'SELECT * FROM report_grades WHERE report_id=1', 'idx_report_grades_report_id'],
]) {
  const plan = await db('EXPLAIN QUERY PLAN ' + sql);
  const txt = JSON.stringify(plan);
  ok(`${label} از ایندکس استفاده می‌کند`, txt.includes(wantIdx), txt.slice(0, 120));
}

/* ════════════════════════════════════════════════════════════════
   ۳) db-optimizer روی SQLite
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۳) صفحهٔ سلامت پایگاه داده روی SQLite ══');

const dboSrc = readFileSync(resolveFile('db-optimizer.php'), 'utf8');
ok('تشخیص درایور دارد', /ATTR_DRIVER_NAME.*sqlite/s.test(dboSrc));
ok('CREATE INDEX برای SQLite دارد', /CREATE INDEX IF NOT EXISTS/.test(dboSrc));
ok('VACUUM برای SQLite دارد', /VACUUM/.test(dboSrc));
ok('DELETE چندجدولیِ MySQL باقی نمانده',
   !/DELETE\s+[a-z]{1,3}\s+FROM\s+\w+\s+[a-z]{1,3}\s+LEFT JOIN/i.test(dboSrc));
ok('NOW() خام در کوئری‌ها نمانده', !/expires_at\s*<\s*NOW\(\)/i.test(dboSrc));

await loginAdmin();
const dbo = await req('سلامت پایگاه داده', { sid: 'harnessAdm0001', file: 'db-optimizer.php' });
ok('صفحه رندر می‌شود', dbo.res.output_len > 2000, `${dbo.res.output_len}b`);
ok('بدون fatal', !dbo.res.fatal, String(dbo.res.fatal || '').slice(0, 160));
ok('موتور SQLite را تشخیص داده', /SQLite/.test(dbo.res.page || ''));
ok('دکمهٔ VACUUM نمایش داده می‌شود', /VACUUM/.test(dbo.res.page || ''));
ok('هیچ خطای SQL در صفحه چاپ نشده',
   !/(SQLSTATE|no such (table|function|column)|syntax error)/i.test(dbo.res.page || ''));

/* اجرای واقعی اکشن‌ها */
const csrf = (dbo.res.page || '').match(/name="csrf_token" value="([^"]+)"/)?.[1] || '';
ok('توکن CSRF در صفحه هست', csrf.length > 10);

for (const act of ['apply_indexes', 'cleanup_orphans', 'optimize_tables']) {
  const r = await req(`اکشن ${act}`, {
    sid: 'harnessAdm0001', method: 'POST', file: 'db-optimizer.php',
    post: { csrf_token: csrf, do: act },
  });
  const flashType = r.res.flash?.type || '';
  ok(`${act}: بدون fatal اجرا شد`, !r.res.fatal, String(r.res.fatal || '').slice(0, 160));
  ok(`${act}: پیام موفقیت/هشدار داد (${flashType})`, ['success', 'warning'].includes(flashType),
     JSON.stringify(r.res.flash || {}).slice(0, 140));
}

/* دیتابیس بعد از VACUUM هنوز سالم است */
const okCheck = await db('PRAGMA integrity_check');
ok('دیتابیس بعد از اکشن‌ها سالم است', JSON.stringify(okCheck).includes('ok'));

/* ════════════════════════════════════════════════════════════════
   ۴) سخت‌سازی آپلود رسانه
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۴) آپلود رسانهٔ سوال ══');

const upSrc = readFileSync(resolveFile('online-exam-media-upload.php'), 'utf8');
ok('CSRF چک می‌شود', /verify_csrf/.test(upSrc));
ok('سقف حجم دارد', /MEU_MAX_BYTES|10 \* 1024 \* 1024/.test(upSrc));
ok('MIME واقعی بررسی می‌شود', /finfo_open|getimagesize/.test(upSrc));
ok('نام فایل تصادفی است (نه time())', /random_bytes\(16\)/.test(upSrc) && !/time\(\)\s*\.\s*'_'/.test(upSrc));
ok('htaccess در پوشهٔ مقصد نوشته می‌شود', /\.htaccess/.test(upSrc));
ok('درخواست HEAD برای تست سرعت باز مانده', /REQUEST_METHOD.*HEAD/s.test(upSrc));

/* POST واقعی بدون توکن → باید رد شود */
const noTok = await req('آپلود بدون CSRF', {
  sid: 'harnessAdm0001', method: 'POST', file: 'online-exam-media-upload.php', post: { x: '1' },
});
const noTokBody = (noTok.res.raw_head || '') + (noTok.res.page || '');
ok('آپلود بدون توکن CSRF رد می‌شود', /CSRF|غیرمجاز/.test(noTokBody), noTokBody.slice(0, 160));
ok('پاسخ رد، JSON معتبر است', /"ok":\s*false/.test(noTokBody), noTokBody.slice(0, 160));

/* صفحهٔ طراحی سوال توکن را می‌فرستد */
const qSrc = readFileSync(resolveFile('online-exam-questions.php'), 'utf8');
ok('صفحهٔ سوال، csrf_token را به آپلود می‌دهد', /fd\.append\('csrf_token'/.test(qSrc));

/* ════════════════════════════════════════════════════════════════
   ۵) .htaccess پوشه‌های حساس
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۵) محافظت پوشه‌های آپلود و پشتیبان ══');

const upHt = resolveFile('uploads/.htaccess');
ok('uploads/.htaccess وجود دارد', existsSync(upHt));
if (existsSync(upHt)) {
  const h = readFileSync(upHt, 'utf8');
  ok('اجرای PHP در uploads خاموش است', /engine off/.test(h));
  ok('هندلرهای اسکریپت برداشته شده', /RemoveHandler/.test(h));
  ok('دسترسی به فایل‌های .php ممنوع است', /FilesMatch[\s\S]*php/.test(h));
  ok('لیست‌شدن محتوای پوشه خاموش است', /-Indexes/.test(h));
}
const bkHt = resolveFile('backups/.htaccess');
ok('backups/.htaccess وجود دارد', existsSync(bkHt));
if (existsSync(bkHt)) {
  ok('دانلود مستقیم پشتیبان‌ها ممنوع است', /Require all denied|Deny from all/.test(readFileSync(bkHt, 'utf8')));
}

/* ════════════════════════════════════════════════════════════════
   ۶) حذف آزمون — بدون رکورد یتیم
   ════════════════════════════════════════════════════════════════ */
console.log('\n══ ۶) حذف آزمون آنلاین ══');

const oeSrc = readFileSync(resolveFile('online-exams.php'), 'utf8');
ok('از helper کامل حذف attempt استفاده می‌کند', /online_exam_delete_attempt_full/.test(oeSrc));

/* یک attempt واقعی برای آزمون بساز */
await dbExec(`INSERT INTO online_exam_attempts (exam_id, student_id, start_time, status)
              VALUES (${examId}, ${student.id}, datetime('now'), 'in_progress')`);
const att = (await db(`SELECT id FROM online_exam_attempts WHERE exam_id=${examId} ORDER BY id DESC LIMIT 1`))[0];
await dbExec(`INSERT INTO online_exam_answers (attempt_id, question_id, answer_data)
              VALUES (${att.id}, 1, '{"v":"x"}')`);
await dbExec(`INSERT INTO online_exam_proctoring_logs (attempt_id, exam_id, student_id, event_type)
              VALUES (${att.id}, ${examId}, ${student.id}, 'tab_switch')`);

const cnt = async () => ({
  exams:   (await db(`SELECT COUNT(*) c FROM online_exams WHERE id=${examId}`))[0].c,
  qs:      (await db(`SELECT COUNT(*) c FROM online_questions WHERE exam_id=${examId}`))[0].c,
  atts:    (await db(`SELECT COUNT(*) c FROM online_exam_attempts WHERE exam_id=${examId}`))[0].c,
  answers: (await db(`SELECT COUNT(*) c FROM online_exam_answers WHERE attempt_id=${att.id}`))[0].c,
  logs:    (await db(`SELECT COUNT(*) c FROM online_exam_proctoring_logs WHERE exam_id=${examId}`))[0].c,
});
const b = await cnt();
console.log(`     قبل از حذف: exams=${b.exams} questions=${b.qs} attempts=${b.atts} answers=${b.answers} logs=${b.logs}`);
ok('پیش‌شرط: آزمون و دادهٔ وابسته وجود دارد',
   +b.exams === 1 && +b.atts >= 1 && +b.answers >= 1 && +b.logs >= 1);

const listPage = await req(null, { sid: 'harnessAdm0001', file: 'online-exams.php' });
const tok2 = (listPage.res.page || '').match(/name="csrf_token" value="([^"]+)"/)?.[1] || csrf;
const del = await req('حذف آزمون', {
  sid: 'harnessAdm0001', method: 'POST', file: 'online-exams.php',
  post: { csrf_token: tok2, delete_exam: '1', exam_id: String(examId) },
});
ok('حذف بدون fatal انجام شد', !del.res.fatal, String(del.res.fatal || '').slice(0, 200));

const a = await cnt();
console.log(`     بعد از حذف: exams=${a.exams} questions=${a.qs} attempts=${a.atts} answers=${a.answers} logs=${a.logs}`);
ok('خود آزمون حذف شد',                  +a.exams === 0);
ok('سوالات آزمون حذف شدند',             +a.qs === 0);
ok('attemptها یتیم نماندند',            +a.atts === 0, `${a.atts} مانده`);
ok('پاسخ‌ها یتیم نماندند',              +a.answers === 0, `${a.answers} مانده`);
ok('لاگ‌های نظارت یتیم نماندند',        +a.logs === 0, `${a.logs} مانده`);

/* ════════════════════════════════════════════════════════════════ */
console.log('\n════════════════════════════════');
console.log(`  سوئیت سخت‌سازی v4.131.0: ${pass} PASS / ${fail} FAIL`);
console.log('════════════════════════════════');
process.exit(fail ? 1 : 0);
