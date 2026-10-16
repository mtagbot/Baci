// مدیریت ستونی نمرات — v4.181.0
//
// این سوئیت سه چیز را واقعاً می‌سنجد:
//   ۱) ساختار جدول: سرستون هر درس = نام درس + فقط نام خانوادگی دبیر، ردیف به‌ازای
//      هر دانش‌آموز، «-» برای نمرهٔ نداشته، ستون معدل، سلول‌های ویرایش‌شونده.
//   ۲) مسیر ذخیره (POST + csrf): نوشتن نمره، حذف با خالی‌کردن، ۲۱ = غیبت و بی‌اثر
//      در معدل، نمرهٔ نامعتبر رد می‌شود، توکن اشتباه رد می‌شود، دانش‌آموز ناموجود.
//   ۳) منطق نابِ JS موجود در خودِ فایل (بین نشانه‌های @gc-logic-start/end):
//      تبدیل عدد فارسی/عربی/اعشاری، مرتب‌سازی صعودی و نزولی با «تهی‌ها آخر»،
//      و شماره‌گذاری ردیف‌ها با ارقام فارسی. این متن از سورسِ واقعیِ عرضه‌شده
//      خوانده می‌شود، پس اگر روزی کسی منطق را بشکند، تست هم می‌شکند.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { run, req, loginAdmin, db } from './harness/lib.mjs';
import { REPO } from './harness/site.mjs';

let n = 0;
const check = (v, m) => { assert(v, m); n++; };

/* ═══════ ۰) دادهٔ آزمایشی ═══════
   سال تحصیلی از خودِ سایت خوانده می‌شود؛ «resolve_academic_year_request» سالی را
   که در جدول master نیست به سال جاری برمی‌گرداند، پس دست‌ساز گذاشتن سال جواب نمی‌دهد. */
const curYear = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'Y=' . get_current_academic_year();`)).out.match(/Y=([\d\/\-]+)/)[1];
const Y = curYear.replace(/\//g, '%2F');
await run(String.raw`<?php require_once '/www/includes/functions.php';
set_setting('current_academic_year', ${JSON.stringify(curYear)});
DB::execute("DELETE FROM students WHERE national_id LIKE '088%'");
DB::execute("DELETE FROM class_schedules WHERE class_name = '۸/۲'");
foreach ([['آرش','کاظمی','08800000001'],['بهنام','احمدی','08800000002'],['پریسا','رضایی','08800000003']] as $st)
  DB::execute("INSERT INTO students (first_name,last_name,father_name,national_id,class_name,grade_level,academic_year,status,password) VALUES (?,?,'پدر',?,?,?,?,?,?)",
    [$st[0],$st[1],$st[2],'۸/۲','هشتم',${JSON.stringify(curYear)},'active','x']);
/* برنامهٔ هفتگی: ترتیب ستون‌ها باید شنبه/اول → یکشنبه/دوم → دوشنبه/سوم باشد */
foreach ([['شنبه','اول','ریاضی','آقای محمدی'],['یکشنبه','دوم','علوم تجربی','خانم رضایی'],['دوشنبه','سوم','زبان انگلیسی','محمد حسینی']] as $sc)
  DB::execute("INSERT INTO class_schedules (academic_year,class_name,day_of_week,period_num,subject_name,teacher_name) VALUES (?,?,?,?,?,?)",
    [${JSON.stringify(curYear)},'۸/۲',$sc[0],$sc[1],$sc[2],$sc[3]]);
$sid = (int)DB::fetch("SELECT id FROM students WHERE national_id='08800000002'")['id'];
DB::execute("DELETE FROM reports WHERE student_id = ? AND report_month = 'مهر'", [$sid]);
DB::execute("INSERT INTO reports (student_id,class_name,academic_year,term,report_month,total_score,gpa) VALUES (?,?,?,?,?,0,0)",
  [$sid,'۸/۲',${JSON.stringify(curYear)},'نوبت اول','مهر']);
$rid = (int)DB::lastInsertId();
DB::execute("INSERT INTO report_grades (report_id,subject_name,score,max_score,coefficient,status) VALUES (?,?,?,20,1,'passed')", [$rid,'ریاضی',17.5]);
echo 'IDS=' . json_encode(array_column(DB::fetchAll("SELECT id, national_id FROM students WHERE national_id LIKE '088%'"), 'id', 'national_id'));`);
const idsOut = (await run(String.raw`<?php require_once '/www/includes/functions.php';
echo 'IDS=' . json_encode(array_column(DB::fetchAll("SELECT id, national_id FROM students WHERE national_id LIKE '088%'"), 'id', 'national_id'));`)).out;
const IDS = JSON.parse(idsOut.match(/IDS=(\{.*\})/)[1]);
const sid1 = IDS['08800000001'], sid2 = IDS['08800000002'], sid3 = IDS['08800000003'];
check(!!sid1 && !!sid2 && !!sid3, 'the three fixture students exist with known ids');
const rid = +(await db(`SELECT id FROM reports WHERE report_month='مهر' AND student_id = ${sid2}`))[0].id;

await loginAdmin('gc01');
const page = await req('مدیریت ستونی', { file: 'grade-columns.php', sid: 'gc01', query: 'embedded=1&year=' + Y + '&month=' + encodeURIComponent('مهر') + '&class=' + encodeURIComponent('۸/۲') });
const html = page.res.page;

check(html.includes('id="gcTable"') && html.includes('data-class="۸/۲"') && html.includes('data-month="مهر"'),
  'the grade-column table is rendered for the chosen class and month');
check(html.includes('name="year"') && html.includes('name="month"') && html.includes('name="class"'),
  'the three filters (سال تحصیلی / ماه / کلاس) are on the page');
check((html.match(/input class="gc-cell/g) || []).length === 9,
  'one editable cell per student per subject (۳ دانش‌آموز × ۳ درس): ' + (html.match(/input class="gc-cell/g) || []).length);
check(html.includes('<th class="gc-sort gc-name" data-key="name"'), 'students can be sorted alphabetically from the name header');
check(html.includes('data-key="avg"') && /معدل\s*<span class="gc-arrow"/.test(html), 'a GPA column sits at the end');
check(/<span class="gc-teacher">محمدی<\/span>/.test(html) && /<span class="gc-teacher">رضایی<\/span>/.test(html)
      && /<span class="gc-teacher">حسینی<\/span>/.test(html),
  'the header shows only the teacher family name, not the honorific: '
  + (html.match(/gc-teacher">[^<]*/g) || []).join(' | '));
check(html.indexOf('data-key="ریاضی"') < html.indexOf('data-key="علوم تجربی"')
      && html.indexOf('data-key="علوم تجربی"') < html.indexOf('data-key="زبان انگلیسی"'),
  'columns follow the weekly schedule order (شنبه/اول → یکشنبه/دوم → دوشنبه/سوم)');
check(html.includes('value="17.5"') && html.includes('data-saved="17.5"'), 'an existing grade is loaded into its cell');
check(html.includes('placeholder="-"'), 'a student with no grade shows «-»');
check(html.includes('data-avg="17.5"'), 'the GPA of the month is in the row');
check(/کد Tab|Tab/.test(html) && /ArrowUp/.test(html) && /ArrowDown/.test(html) && /ArrowLeft/.test(html) && /ArrowRight/.test(html),
  'Tab and the four arrow keys move between cells');
check(/gcTargetIndex\(e\.key, list\.indexOf\(input\), perRow, list\.length\)/.test(html),
  'the keydown handler finds the cell by its position in the reading order, not by td index');
check(html.indexOf('Array.prototype.slice.call(table.querySelectorAll(\'input.gc-cell\'))')
      < html.indexOf('gcTargetIndex(e.key'), 'the flat cell list is built before the mapping is called');
check(html.includes('gc_action') === false || /body\.set\('gc_action', 'save'\)/.test(html), 'each change is posted for that one grade');

/* ═══════ ۱) مسیر ذخیره ═══════ */
await run(String.raw`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('gc01');session_start();$_SESSION['csrf_token']='gc-fixture';`);
const save = async (label, post) => (await req(label, {
  file: 'grade-columns.php', sid: 'gc01', method: 'POST',
  query: 'embedded=1&year=' + Y + '&month=' + encodeURIComponent('مهر') + '&class=' + encodeURIComponent('۸/۲'),
  post: { gc_action: 'save', csrf_token: 'gc-fixture', year: curYear, month: 'مهر', class_name: '۸/۲', ...post },
})).res.page;

const first = await save('ذخیرهٔ نمره', { student_id: sid2, subject: 'ریاضی', score: '19' });
const j1 = JSON.parse(first);
check(j1.ok === true && j1.value === '19' && j1.gpa === 19 && j1.message === 'ذخیره شد.',
  'saving a valid grade answers ok and echoes the value: ' + first.slice(0, 120));
check(j1.term === 'نوبت اول', 'the month sets the term (مهر → نوبت اول): ' + j1.term);
const row1 = await db(`SELECT score, status FROM report_grades WHERE report_id = ${rid} AND subject_name = 'ریاضی'`);
check(row1.length === 1 && +row1[0].score === 19 && row1[0].status === 'passed', 'the grade is written to report_grades for that student');
const rep1 = await db(`SELECT gpa, total_score FROM reports WHERE id = ${rid}`);
check(+rep1[0].gpa === 19, 'the GPA is recalculated on every change: ' + rep1[0].gpa);

const newOne = await save('ذخیرهٔ نمرهٔ درسِ بدون کارنامه', { student_id: sid3, subject: 'علوم تجربی', score: '۱۴' });
const j2 = JSON.parse(newOne);
check(j2.ok === true && j2.value === '14' && j2.report_id !== rid,
  'a month with no report gets one created automatically: report_id=' + j2.report_id);
const created = await db(`SELECT term, report_month, class_name FROM reports WHERE id = ${j2.report_id}`);
check(created.length === 1 && created[0].report_month === 'مهر' && created[0].class_name === '۸/۲',
  'the auto-created report carries the month, class and academic year');

const absence = JSON.parse(await save('ثبت غیبت ۲۱', { student_id: sid1, subject: 'ریاضی', score: '۲۱' }));
check(absence.ok === true && absence.gpa === 0 && absence.gpa_display === '',
  '21 means «غیبت» and is excluded from the GPA: gpa=' + absence.gpa + ' display="' + absence.gpa_display + '"');

const cleared = JSON.parse(await save('پاک‌کردن نمره', { student_id: sid2, subject: 'ریاضی', score: '' }));
const afterClear = await db(`SELECT score FROM report_grades WHERE report_id = ${rid} AND subject_name = 'ریاضی'`);
check(cleared.ok === true && cleared.message === 'نمرهٔ درس پاک شد.' && afterClear.length === 0,
  'emptying a cell deletes that grade from the report');

const bad = JSON.parse(await save('نمرهٔ نامعتبر', { student_id: sid2, subject: 'ریاضی', score: 'خیلی خوب' }));
check(bad.ok === false && /معتبر نیست/.test(bad.message), 'a non-numeric grade is refused: ' + bad.message);
const big = JSON.parse(await save('نمرهٔ خارج از بازه', { student_id: sid2, subject: 'ریاضی', score: '25' }));
check(big.ok === false, 'a grade above 21 is refused');
const noSubject = JSON.parse(await save('درسِ خالی', { student_id: sid2, subject: '   ', score: '12' }));
check(noSubject.ok === false && /درس/.test(noSubject.message), 'an empty subject name is refused');
const noStudent = JSON.parse(await save('دانش‌آموز ناموجود', { student_id: 999999, subject: 'ریاضی', score: '12' }));
check(noStudent.ok === false && /دانش‌آموز/.test(noStudent.message), 'an unknown student is refused');

const noToken = JSON.parse((await req('بدون توکن', {
  file: 'grade-columns.php', sid: 'gc01', method: 'POST', query: 'embedded=1',
  post: { gc_action: 'save', csrf_token: '', year: curYear, month: 'مهر', class_name: '۸/۲', student_id: sid2, subject: 'ریاضی', score: '12' },
})).res.page);
check(noToken.ok === false && /اعتبار فرم/.test(noToken.message), 'a missing/expired csrf token is refused: ' + noToken.message);
const surviving = await db(`SELECT COUNT(*) AS c FROM report_grades WHERE report_id = ${rid}`);
check(+surviving[0].c === 0, 'the refused calls wrote nothing to the database');

/* ═══════ ۲) منطق نابِ JS از خودِ فایل عرضه‌شده ═══════ */
const source = readFileSync(join(REPO, 'update-v4.152.0/grade-columns.php'), 'utf8');
const m = source.match(/\/\* @gc-logic-start[\s\S]*?\/\* @gc-logic-end \*\//);
check(!!m, 'the marked pure-JS logic block exists in the shipped file');
const logic = m[0].replace(/\/\* @gc-logic-(start|end)[^\n]*\n?/g, '');

let tbody;
function fakeRow(name, scores, avg) {
  const inputs = Object.keys(scores).map(k => ({ dataset: { key: k }, value: scores[k] }));
  const row = {
    dataset: { name, avg: avg === undefined ? '' : String(avg) },
    inputs,
    num: { textContent: '' },
    querySelectorAll: sel => (sel === 'input.gc-cell' ? inputs : []),
    querySelector: sel => (sel === '.gc-row-num' ? row.num : null),
  };
  return row;
}
/* جدول ساختگی: همان قراردادی که کد واقعی انتظار دارد */
const names = () => tbody.rows.map(r => r.dataset.name);
const makeBody = rows => ({
  rows: rows.slice(),
  appendChild(row) { this.rows = this.rows.filter(r => r !== row); this.rows.push(row); },
});
const api = new Function('tbody', 'table', logic + `
  return { toNumber: toNumber, sortBy: sortBy, renumber: renumber, rows: rows, valueOf: valueOf,
           gcTargetIndex: gcTargetIndex };`)(null, null);

const build = (rows) => {
  tbody = makeBody(rows);
  return new Function('tbody', 'table', logic + `
    return { toNumber: toNumber, sortBy: sortBy, renumber: renumber, rows: rows, valueOf: valueOf,
             gcTargetIndex: gcTargetIndex };`)(tbody, null);
};
check(typeof api.toNumber === 'function', 'the extracted block compiles and exposes toNumber/sortBy');

const g = build([
  fakeRow('رضایی پریسا', { 'ریاضی': '۱۲', 'علوم تجربی': '' }, 12),
  fakeRow('کاظمی آرش', { 'ریاضی': '20', 'علوم تجربی': '8.5' }, 14.25),
  fakeRow('احمدی بهنام', { 'ریاضی': '-', 'علوم تجربی': '۱۸٫۵' }, 18.5),
]);
check(g.toNumber('۱۶') === 16 && g.toNumber('15.5') === 15.5 && g.toNumber('۱۵/۵') === 15.5
      && g.toNumber('١٢') === 12 && g.toNumber('') === null && g.toNumber('-') === null && g.toNumber('خوب') === null,
  'toNumber handles Persian/Arabic digits, both decimal separators and the empty dash');

/* مرتب‌سازی صعودی: تهی‌ها آخر می‌آیند */
g.sortBy('ریاضی', 'asc');
check(JSON.stringify(names()) === JSON.stringify(['رضایی پریسا', 'کاظمی آرش', 'احمدی بهنام']),
  'ascending sort by a subject puts the graded students in order and the empty ones last (۱۲، ۲۰، «-»): ' + names().join(' | '));
g.sortBy('ریاضی', 'desc');
check(JSON.stringify(names()) === JSON.stringify(['کاظمی آرش', 'رضایی پریسا', 'احمدی بهنام']),
  'descending sort: ۲۰، ۱۲، و تهی آخر: ' + names().join(' | '));
g.sortBy('علوم تجربی', 'asc');
check(JSON.stringify(names()) === JSON.stringify(['کاظمی آرش', 'احمدی بهنام', 'رضایی پریسا']),
  'ascending on another subject: 8.5, 18.5, empty: ' + names().join(' | '));
g.sortBy('avg', 'desc');
check(JSON.stringify(names()) === JSON.stringify(['احمدی بهنام', 'کاظمی آرش', 'رضایی پریسا']),
  'sorting by the GPA column works: ' + names().join(' | '));
g.sortBy('avg', 'asc');
check(JSON.stringify(names()) === JSON.stringify(['رضایی پریسا', 'کاظمی آرش', 'احمدی بهنام']),
  'GPA ascending: ' + names().join(' | '));
g.sortBy('name', 'asc');
check(names()[0] === 'احمدی بهنام', 'the name column sorts alphabetically (default order): ' + names().join(' | '));
check(g.rows().map(r => r.num.textContent).join('') === '۱۲۳', 'row numbers are renumbered in Persian digits: ' + g.rows().map(r => r.num.textContent).join(''));

/* ستون‌های درس با نمرهٔ خالی و ستون معدل: همان قرارداد data-key */
const g2 = build([fakeRow('الف ب', { 'ریاضی': '' }, '')]);
check(g2.valueOf(g2.rows()[0], 'ریاضی') === null && g2.valueOf(g2.rows()[0], 'avg') === null,
  'an empty cell and an empty GPA read as "no value"');
check(g2.valueOf(g2.rows()[0], 'name') === 'الف ب', 'valueOf returns the row name for the name key');

/* ═══════ ۲٫۵) حرکت با کلیدهای جهت‌دار ═══════
   باگ گزارش‌شدهٔ کارفرما: ↓ یک ردیف پایین و یک سلول چپ می‌رفت، ↑ بالا و راست،
   → هیچ حرکتی نمی‌کرد و ← دو سلول می‌رفت. حسابِ قدیمی روی شمارهٔ ستونِ `td` بود
   (که دو ستون نام و ردیف هم دارد) و در RTL جهت‌ها را جابه‌جا می‌کرد.
   قرارداد تازه: ترتیب خواندن، در جدول راست‌به‌چپ، از بالا-راست شروع می‌شود.
   برای شبکهٔ ۳×۳ (۳ دانش‌آموز × ۳ درس)، اندیس‌ها این‌طور چیده می‌شوند:
       ۰ ۱ ۲     ۰ = بالا-راست (اولین درس)      ۱ = وسط      ۲ = بالا-چپ
       ۳ ۴ ۵     ۳ = نفر دوم، راست‌ترین ستون
       ۶ ۷ ۸     ۸ = پایین-چپ
*/
const move = api.gcTargetIndex;
const ROWS = 3, PER = 3, TOTAL = ROWS * PER;
const where = i => i < 0 ? '—' : `ردیف ${Math.floor(i / PER) + 1} · ستون ${(i % PER) + 1} از راست`;

check(typeof move === 'function', 'the shipped logic exposes gcTargetIndex (the arrow-key map)');
/* ↓ : همان ستون، یک ردیف پایین‌تر */
check(move('ArrowDown', 4, PER, TOTAL) === 7,
  '↓ از ردیف دوم می‌رود به همان ستونِ ردیف سوم (۴→۷): ' + where(move('ArrowDown', 4, PER, TOTAL)));
check(move('ArrowDown', 1, PER, TOTAL) === 4, '↓ از ردیف اول می‌رود به همان ستونِ ردیف دوم (۱→۴)');
check(move('ArrowDown', 7, PER, TOTAL) === -1, '↓ در ردیف آخر بی‌حرکت می‌ماند (خارج از جدول نمی‌رود)');
/* ↑ : همان ستون، یک ردیف بالاتر */
check(move('ArrowUp', 7, PER, TOTAL) === 4, '↑ از ردیف سوم به همان ستونِ ردیف دوم (۷→۴)');
check(move('ArrowUp', 1, PER, TOTAL) === -1, '↑ در ردیف اول بی‌حرکت می‌ماند');
/* ← : یک سلول به چپِ دیداری = یک قدم جلو در ترتیب خواندن */
check(move('ArrowLeft', 4, PER, TOTAL) === 5,
  '← دقیقاً یک سلول به چپ (۴→۵): ' + where(move('ArrowLeft', 4, PER, TOTAL)));
check(move('ArrowLeft', 0, PER, TOTAL) === 1, '← از اولین سلول یک قدم به چپ می‌رود');
check(move('ArrowLeft', 8, PER, TOTAL) === -1, '← در سلول آخرِ جدول بی‌حرکت می‌ماند');
/* → : یک سلول به راستِ دیداری = یک قدم عقب در ترتیب خواندن */
check(move('ArrowRight', 4, PER, TOTAL) === 3,
  '→ دقیقاً یک سلول به راست (۴→۳): ' + where(move('ArrowRight', 4, PER, TOTAL)));
check(move('ArrowRight', 1, PER, TOTAL) === 0, '→ از سلول وسط یک قدم به راست می‌رود');
check(move('ArrowRight', 0, PER, TOTAL) === -1,
  '→ روی اولین سلول جدول بی‌حرکت می‌ماند (قدیم همین‌جا هیچ کاری نمی‌کرد و کاربر همان را دید)');
/* Enter = ذخیره و یک ردیف پایین، مثل ↓ */
check(move('Enter', 4, PER, TOTAL) === 7 && move('Enter', 7, PER, TOTAL) === -1,
  'Enter مثل ↓ می‌رود پایین و در ردیف آخر بی‌حرکت است');
/* هیچ کلیدی نباید «یک ردیف + یک ستون» جابه‌جا کند (همان باگ قدیم) */
const walk = key => Array.from({ length: TOTAL }, (_, i) => move(key, i, PER, TOTAL));
const down = walk('ArrowDown'), up = walk('ArrowUp'), left = walk('ArrowLeft'), right = walk('ArrowRight');

check(down.every((t, i) => t === -1 || t === i + PER) && down.filter(t => t !== -1).length === TOTAL - PER,
  '↓ همه‌جا (جز ردیف آخر) فقط یک ردیف پایین و در همان ستون می‌رود: ' + down.join(','));
check(up.every((t, i) => t === -1 || t === i - PER) && up.filter(t => t !== -1).length === TOTAL - PER,
  '↑ همه‌جا (جز ردیف اول) فقط یک ردیف بالا و در همان ستون می‌رود: ' + up.join(','));
check(left.every((t, i) => t === -1 || t === i + 1), '← همیشه فقط یک قدم به چپ می‌رود: ' + left.join(','));
check(right.every((t, i) => t === -1 || t === i - 1), '→ همیشه فقط یک قدم به راست می‌رود: ' + right.join(','));
check(move('ArrowDown', -1, PER, TOTAL) === -1 && move('ArrowDown', TOTAL, PER, TOTAL) === -1,
  'اندیس نامعتبر هم امن است (بدون خطا)');

/* ═══════ ۳) تب جدید در هاب «مدیریت کارنامه‌ها» ═══════ */
const hub = await req('هاب کارنامه‌ها', { file: 'reports-management.php', sid: 'gc01', query: 'hub_tab=columns' });
check(hub.res.page.includes('مدیریت ستونی'), 'the new tab «مدیریت ستونی» sits next to the report tabs');
check(/grade-columns\.php\?embedded=1/.test(hub.res.page), 'the tab loads the grade-columns page inside the hub');
check(hub.res.page.includes('کارنامه‌ها') && hub.res.page.includes('تحلیل و مقایسه'),
  'the other report tabs are still there');
const hubDefault = await req('هاب پیش‌فرض', { file: 'reports-management.php', sid: 'gc01' });
check(hubDefault.res.page.includes('کارنامه‌ها'), 'the hub still opens on its first tab by default');

/* ═══════ ۴) فیلترها و حالت‌های خالی ═══════ */
const noSchedule = await req('کلاس بدون برنامه', { file: 'grade-columns.php', sid: 'gc01', query: 'embedded=1&class=' + encodeURIComponent('۹/۹') });
check(/برنامهٔ هفتگی ثبت نشده|دانش‌آموز فعالی ثبت نشده/.test(noSchedule.res.page),
  'a class with no schedule/students shows guidance instead of an empty grid');
const otherMonth = await req('ماه دیگر', { file: 'grade-columns.php', sid: 'gc01', query: 'embedded=1&class=' + encodeURIComponent('۸/۲') + '&month=' + encodeURIComponent('آبان') });
check(otherMonth.res.page.includes('data-month="آبان"') && otherMonth.res.page.includes('placeholder="-"'),
  'switching the month shows that month’s grades (empty → «-»)');

console.log('PASS ' + n + ' grade-column checks (v4.182.0 column manager: layout, save path, sort logic, arrow keys, hub tab)');
process.exit(0);
