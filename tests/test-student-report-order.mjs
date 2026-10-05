/**
 * test-student-report-order.mjs — سوئیت ۵۴ (v4.180.0)
 *
 * باگ: در صفحهٔ مدیریت دانش‌آموزان، کاربر فهرست را با سرستون‌ها می‌چیند
 * (مثلاً «کلاس، سپس نام خانوادگی» نزولی)، همه را انتخاب می‌کند و «گزارش»
 * می‌زند — ولی فایل گزارش همیشه با الفبای نام خانوادگی چیده می‌شد.
 *
 * ریشه: لوله از v4.176.0 وجود داشت ولی دو سرش نمی‌خواندند.
 *   students.php:425  →  یک <input type=hidden name="student_order"> با
 *                        مقدارِ «رشتهٔ جدا‌شده با کاما»  (join(','))
 *   student-bulk-report.php:16 →  `if(is_array($_POST['student_order']??null))`
 * `is_array()` روی رشته false است، پس کل شاخه رد می‌شد، `$orderMap` خالی
 * می‌ماند و usort به شاخهٔ الفبایی می‌افتاد. بی‌صدا.
 *
 * این سوئیت خودِ `student-bulk-report.php` را با درخواست واقعی POST صدا
 * می‌زند و ترتیب سطرهای خروجی را می‌سنجد — نه متن کد را.
 */
import { req, dbExec, db, loginAdmin } from './harness/lib.mjs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const SID = 'rptOrderAdmin1';

/* چهار دانش‌آموز با نام خانوادگی‌ای که الفبایشان با ترتیب درخواستی فرق دارد.
   الفبای فارسی: آذری < بهرامی < پورناد < تهرانی */
const S = { azari: 9101, bahrami: 9102, pournad: 9103, tehrani: 9104 };
const NAME = { 9101: 'آذری', 9102: 'بهرامی', 9103: 'پورناد', 9104: 'تهرانی' };
const ALPHABETICAL = [S.azari, S.bahrami, S.pournad, S.tehrani];
const WANTED = [S.tehrani, S.azari, S.pournad, S.bahrami];   // نه الفبایی

await dbExec(`DELETE FROM students WHERE id IN (${Object.values(S).join(',')})`);
for (const [k, id] of Object.entries(S)) {
  await dbExec(`INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year)` +
               ` VALUES (${id},'00${id}','ن${id}','${NAME[id]}','۷۰۱','هفتم','active','1404/1405')`);
}
const seeded = await db(`SELECT id,last_name FROM students WHERE id IN (${Object.values(S).join(',')}) ORDER BY id`);
ok('چهار دانش‌آموز آزمایشی ساخته شد', seeded.length === 4, `${seeded.length} ردیف`);

/* ترتیب الفباییِ مرجع — تا ثابت کنیم «ترتیب خواسته‌شده» با آن فرق دارد */
const alphaNames = ALPHABETICAL.map(id => NAME[id]);
ok('ترتیب خواسته‌شده با الفبایی فرق دارد',
   JSON.stringify(WANTED) !== JSON.stringify(ALPHABETICAL));

await loginAdmin(SID);
const listPage = await req('فهرست دانش‌آموزان (برای CSRF)', { file: 'students.php', sid: SID });
const csrf = (listPage.res.page.match(/name="csrf_token" value="([^"]+)"/) || [])[1] || '';
ok('توکن CSRF از صفحهٔ فهرست گرفته شد', csrf !== '');
ok('فیلد student_order در فرم گزارش وجود دارد',
   listPage.res.page.includes('id="studentOrderBox"') && listPage.res.page.includes('name="student_order"'));
ok('onsubmit فرم، ترتیب را با join(",") می‌سازد', /\.join\(','\)/.test(listPage.res.page));

const report = async (label, order) => {
  const post = {
    csrf_token: csrf, report_type: 'info', format: 'html', fields_version: '2',
    fields: ['first_name', 'last_name', 'class_name'],
    student_ids: Object.values(S),
  };
  if (order !== undefined) post.student_order = order;
  const r = await req(label, { file: 'student-bulk-report.php', method: 'POST', post, sid: SID });
  const html = (r.res.page || '') + (r.res.raw_head || '');
  /* موقعیت هر نام خانوادگی در خروجی؛ ترتیب = ترتیب صعودیِ این موقعیت‌ها */
  const pos = {};
  for (const id of Object.values(S)) pos[id] = html.indexOf(NAME[id]);
  return { html, pos, fatal: r.res.fatal, status: r.res.status };
};

const orderedIds = (pos) => Object.values(S)
  .filter(id => pos[id] >= 0)
  .sort((a, b) => pos[a] - pos[b]);

const label = (ids) => ids.map(id => `${id}=${NAME[id]}`).join(' ، ');

/* ═══════ ۱) رشتهٔ کاماجدا — همان چیزی که مرورگر واقعاً می‌فرستد ═══════ */
console.log('\n══ ترتیب به شکل رشتهٔ کاماجدا (باگ اصلی) ══');
const r1 = await report('گزارش با student_order رشته‌ای', WANTED.join(','));
ok('گزارش بدون خطای PHP تولید شد', !r1.fatal, r1.fatal ? String(r1.fatal).split('\n')[0] : '');
ok('هر چهار نام در خروجی هست', Object.values(r1.pos).every(p => p >= 0), label(orderedIds(r1.pos)));
ok('ترتیب خروجی = ترتیب فهرست دانش‌آموزان',
   JSON.stringify(orderedIds(r1.pos)) === JSON.stringify(WANTED),
   `خواستیم [${label(WANTED)}] ولی شد [${label(orderedIds(r1.pos))}]`);
ok('و این ترتیب، الفباییِ پیش‌فرض نیست',
   JSON.stringify(orderedIds(r1.pos)) !== JSON.stringify(ALPHABETICAL));

/* ═══════ ۲) شکل آرایه‌ای هم هنوز کار می‌کند ═══════ */
console.log('\n══ ترتیب به شکل آرایه ══');
const r2 = await report('گزارش با student_order آرایه‌ای', WANTED);
ok('شکل آرایه‌ای هم همان ترتیب را می‌دهد',
   JSON.stringify(orderedIds(r2.pos)) === JSON.stringify(WANTED),
   `[${label(orderedIds(r2.pos))}]`);

/* ═══════ ۳) رشتهٔ کثیف: خالی و زباله تحمل می‌شود ═══════ */
console.log('\n══ رشتهٔ آلوده ══');
const r3 = await report('گزارش با رشتهٔ دارای خالی و زباله', `${S.tehrani},,abc,${S.azari}, ,${S.pournad},0,${S.bahrami}`);
ok('خالی و زباله ترتیب را نمی‌شکند',
   JSON.stringify(orderedIds(r3.pos)) === JSON.stringify(WANTED),
   `[${label(orderedIds(r3.pos))}]`);

/* ═══════ ۴) بدون ترتیب = رفتار قدیمی (الفبایی) حفظ می‌شود ═══════ */
console.log('\n══ بدون student_order (سازگاری عقب‌رو) ══');
const r4 = await report('گزارش بدون ترتیب');
ok('بدون ترتیب، به الفبای نام خانوادگی برمی‌گردد',
   JSON.stringify(orderedIds(r4.pos)) === JSON.stringify(ALPHABETICAL),
   `[${label(orderedIds(r4.pos))}]`);

const r5 = await report('گزارش با ترتیب تهی', '   ');
ok('رشتهٔ فقط-فاصله هم به الفبایی برمی‌گردد',
   JSON.stringify(orderedIds(r5.pos)) === JSON.stringify(ALPHABETICAL),
   `[${label(orderedIds(r5.pos))}]`);

/* ═══════ ۵) زیرمجموعهٔ انتخاب‌شده هم همان ترتیب نسبی را دارد ═══════ */
console.log('\n══ انتخاب جزئی ══');
const sub = [S.pournad, S.tehrani];                 // فقط دو نفر انتخاب می‌شوند
/* انتظار: همان «ترتیب نسبی» این دو در فهرست — نه ترتیبِ آرایهٔ sub.
   در WANTED تهرانی پیش از پورناد است، پس خروجی هم باید همان باشد. */
const subExpected = WANTED.filter(id => sub.includes(id));
const r6 = await (async () => {
  const post = {
    csrf_token: csrf, report_type: 'info', format: 'html', fields_version: '2',
    fields: ['first_name', 'last_name', 'class_name'],
    student_ids: sub, student_order: WANTED.join(','),
  };
  const r = await req('گزارش دو نفر از چهار نفر', { file: 'student-bulk-report.php', method: 'POST', post, sid: SID });
  const html = (r.res.page || '') + (r.res.raw_head || '');
  const pos = {}; for (const id of sub) pos[id] = html.indexOf(NAME[id]);
  return { pos };
})();
const gotSub = Object.keys(r6.pos).sort((a, b) => r6.pos[a] - r6.pos[b]).map(Number);
ok('در انتخاب جزئی هم ترتیب نسبی فهرست رعایت می‌شود',
   JSON.stringify(gotSub) === JSON.stringify(subExpected),
   `خواستیم [${label(subExpected)}] ولی شد [${label(gotSub)}]`);
ok('و نه ترتیبِ آرایهٔ student_ids',
   JSON.stringify(gotSub) !== JSON.stringify(sub),
   'اگر برابر آرایهٔ انتخاب بود، یعنی ترتیب فهرست نادیده گرفته شده');

await dbExec(`DELETE FROM students WHERE id IN (${Object.values(S).join(',')})`);

console.log(`\n>>> PASS ${pass} · FAIL ${fail}`);
process.exit(fail === 0 ? 0 : 1);
