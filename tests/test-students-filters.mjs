/**
 * test-students-filters.mjs — سوئیت ۵۵ (v4.179.0)
 *
 * بازطراحی کامل نوار فیلتر «مدیریت دانش‌آموزان»:
 *   · dropdown «چینش فهرست»/«ترتیب» حذف شد؛ چینش با سرستون‌ها (data-sort)
 *   · نوار فیلتر با CSS Grid ساخته شد (بدون فضای مرده، ستون‌ها سر جای خود)
 *   · «گزینه‌های بیشتر» به‌صورت popover؛ دکمه‌ها هم‌ارتفاع در همان نوار
 *   · جستجوی زنده: هر تایپ یک fetch درجا با AbortController (بدون کندی)
 *
 * منطق چینش در PHP باید دست‌نخورده بماند ($stuSortLink همان ?sort&dir را
 * می‌سازد). این سوئیت هم «رفتار چینش» و هم «طراحی و سیم‌کشی زنده» را می‌سنجد.
 */
import { req, dbExec, db, loginAdmin } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const SID = 'filterAdmin001';
const IDS = [9301, 9302, 9303];
const CLS = { 9301: '۷۰۳', 9302: '۷۰۱', 9303: '۷۰۲' };
const FAM = { 9301: 'الفی', 9302: 'ببی', 9303: 'پپی' };

await dbExec(`DELETE FROM students WHERE id IN (${IDS.join(',')})`);
for (const id of IDS) {
  await dbExec(`INSERT INTO students (id,national_id,first_name,last_name,class_name,grade_level,status,academic_year)` +
               ` VALUES (${id},'00${id}','ن${id}','${FAM[id]}','${CLS[id]}','هفتم','active','1404/1405')`);
}
const seeded = await db(`SELECT id FROM students WHERE id IN (${IDS.join(',')})`);
ok('سه دانش‌آموز آزمایشی در سه کلاس ساخته شد', seeded.length === 3, `${seeded.length} ردیف`);

await loginAdmin(SID);
const page = (await req('مدیریت دانش‌آموزان', { file: 'students.php', sid: SID })).res.page;
ok('صفحه رندر شد', page.length > 3000, `${page.length} بایت`);

const css = readFileSync(resolveFile('assets/css/school-ui.css'), 'utf8');
const src = readFileSync(resolveFile('students.php'), 'utf8');

/* ═══════ ۱) چیزهایی که باید رفته باشند ═══════ */
console.log('\n── حذف‌شده‌ها ──');
ok('dropdown «چینش فهرست» نیست', !page.includes('چینش فهرست</label>'));
ok('فیلد name="sort" در فرم نیست', !/name="sort"/.test(page.slice(page.indexOf('studentsFilterForm'), page.indexOf('studentsResults'))));
ok('dropdown «ترتیب» نیست', !/name="dir"/.test(page.slice(page.indexOf('studentsFilterForm'), page.indexOf('studentsResults'))));

/* ═══════ ۲) ساختار نوار جدید ═══════ */
console.log('\n── ساختار نوار ──');
ok('فرم id=studentsFilterForm دارد', page.includes('id="studentsFilterForm"'));
ok('ناحیهٔ نتایج id=studentsResults دارد', page.includes('id="studentsResults"'));
ok('جستجو کلاس sf-search و آیکون دارد', page.includes('sf-search') && page.includes('sf-ico'));
ok('«کلاس» در نوار اصلی است', /<div class="sf-field"><label>کلاس<\/label><select name="class_name"/.test(page));
ok('کلاس فقط یک بار در فرم هست', (page.match(/name="class_name"/g) || []).length === 1);
ok('دکمه‌ها هم‌ارتفاع در sf-actions هستند', page.includes('class="sf-actions"'));
ok('«گزینه‌های بیشتر» popover است (sf-more + sf-more-panel)', page.includes('class="sf-more"') && page.includes('sf-more-panel'));

/* ═══════ ۳) چینش با سرستون‌ها هنوز کار می‌کند ═══════ */
console.log('\n── چینش ──');
const order = (html) => {
  const r = html.slice(html.indexOf('studentsResults'));
  return ['ببی', 'پپی', 'الفی'].sort((a, b) => r.indexOf(a) - r.indexOf(b));
};
const asc = await req('چینش با کلاس صعودی', { file: 'students.php', sid: SID, query: 'sort=class&dir=asc' });
ok('sort=class صعودی بر پایهٔ کلاس می‌چیند', order(asc.res.page).join(',') === 'ببی,پپی,الفی', order(asc.res.page).join(','));
ok('لینک‌های سرستون data-sort دارند', asc.res.page.includes('data-sort="class"'));
const desc = await req('چینش نزولی', { file: 'students.php', sid: SID, query: 'sort=class&dir=desc' });
ok('sort=class نزولی برعکس می‌کند', order(desc.res.page).join(',') === 'الفی,پپی,ببی', order(desc.res.page).join(','));
const byName = await req('چینش با نام', { file: 'students.php', sid: SID, query: 'sort=name&dir=asc' });
ok('sort=name الفبایی می‌چیند', order(byName.res.page).join(',') === 'الفی,ببی,پپی', order(byName.res.page).join(','));

/* ═══════ ۴) جستجوی زنده (سیم‌کشی) ═══════ */
console.log('\n── جستجوی زنده ──');
ok('اسکریپت جستجوی زنده در صفحه هست', page.includes('studentsFilterForm') && /AbortController/.test(page));
ok('درخواست کهنه لغو می‌شود (abort)', /\.abort\(\)/.test(page));
ok('تعویض درجا با DOMParser', /DOMParser\(\)\.parseFromString/.test(page) && /getElementById\('studentsResults'\)/.test(page));
ok('جستجو debounce دارد', /setTimeout\(function \(\) \{ swap\(url\(\)\); \}, 140\)/.test(page));
ok('تغییر select ها به‌روزرسانی درجا دارد', /addEventListener\('change'/.test(page));
ok('کلیک روی لینک چینش درجا است (data-sort)', /closest\('#studentsResults a\[data-sort\]'\)/.test(page));
ok('حالت بارگذاری sf-loading تعریف شده', css.includes('#studentsResults.sf-loading'));
ok('URL با replaceState همگام می‌ماند', /history\.replaceState/.test(page));

/* ═══════ ۵) طراحی گریدی ═══════ */
console.log('\n── طراحی ──');
ok('نوار با grid ساخته شده (نه flex)', /\.students-filters\{\s*[^}]*display:grid/.test(css));
ok('ستون‌ها صریح‌اند: جستجو بزرگ‌ترین، کلاس دوم', /grid-template-columns:minmax\(210px,1\.8fr\) 142px 108px minmax\(150px,1\.3fr\) auto auto/.test(css));
ok('ارتفاع مشترک کنترل‌ها (--sf-h)', /--sf-h:38px/.test(css) && css.includes('height:var(--sf-h)'));
ok('focus-ring برای دسترسی‌پذیری', /--sf-acc-ring/.test(css) && /box-shadow:0 0 0 3px var\(--sf-acc-ring\)/.test(css));
ok('grid در CSS به .students-filters محدود است (bot-inbox آسیب نمی‌بیند)',
   !/\.filters-line\{[^}]*display:grid/.test(css));
ok('bot-inbox.php به کلاس‌های جدید ارجاع ندارد', !readFileSync(resolveFile('bot-inbox.php'), 'utf8').includes('sf-search'));
ok('واکنش‌گرا: زیر ۹۸۰ دو ستون، زیر ۶۴۰ تک‌ستون',
   /@media\(max-width:980px\)/.test(css) && /@media\(max-width:640px\)/.test(css));
ok('کش‌شکن جلو رفته', page.includes('school-ui.css?v20261007a'));

/* ═══════ ۶) منطق چینش در PHP دست‌نخورده ═══════ */
console.log('\n── منبع ──');
ok('$sortKey باقی است', src.includes('$sortKey'));
ok('$sortDir باقی است', src.includes('$sortDir'));
ok('$stuSortLink باقی است', src.includes('$stuSortLink'));
ok('array_reverse برای نزولی باقی است', src.includes('array_reverse'));
ok('هر ۷ کلید چینش مجازند', ['name', 'first', 'class', 'grade', 'nid', 'gpa', 'disc'].every(k => src.includes(`'${k}'`)));
ok('data-sort به لینک چینش اضافه شده', src.includes('data-sort="\' . $key . \''));

console.log(`\n>>> PASS ${pass} · FAIL ${fail}`);
process.exit(0);
