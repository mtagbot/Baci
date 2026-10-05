/**
 * test-students-filters.mjs — سوئیت ۵۵ (v4.179.0)
 *
 * بازطراحی ردیف فیلترهای «مدیریت دانش‌آموزان» بنا به درخواست کاربر:
 *   · dropdown «چینش فهرست» (name="sort") حذف شد
 *   · dropdown «ترتیب» (name="dir") حذف شد
 *   · «کلاس» (name="class_name") از «گزینه‌های بیشتر» به ردیف اصلی آمد
 *   · نمایش فیلترها با CSS واکنش‌گرا بهبود یافت
 *
 * نکتهٔ مهم: منطق چینش در PHP باید **دست‌نخورده** بماند، چون سرستون‌های
 * جدول با $stuSortLink() لینک ?sort=X&dir=Y می‌سازند. اگر آن منطق برود،
 * کلیک روی سرستون دیگر کاری نمی‌کند. این سوئیت هر دو را می‌سنجد.
 */
import { req, dbExec, db, loginAdmin } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

const SID = 'filterAdmin001';

/* چند دانش‌آموز در کلاس‌های متفاوت، تا چینش واقعاً قابل سنجش باشد */
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

/* ناحیهٔ فرم فیلتر */
const fStart = page.indexOf('class="filters-line students-filters"');
const fEnd = page.indexOf('</form>', fStart);
const form = fStart >= 0 && fEnd > fStart ? page.slice(fStart, fEnd) : '';
ok('فرم فیلتر با کلاس صفحه‌ای students-filters پیدا شد', form.length > 0, `${form.length} نویسه`);

console.log('\n── چیزهایی که باید رفته باشند ──');
ok('dropdown «چینش فهرست» حذف شده است', !form.includes('چینش فهرست'));
ok('فیلد name="sort" در فرم نیست', !form.includes('name="sort"'));
ok('dropdown «ترتیب» حذف شده است', !/label[^>]*>ترتیب</.test(form));
ok('فیلد name="dir" در فرم نیست', !form.includes('name="dir"'));

console.log('\n── چیزهایی که باید آمده/مانده باشند ──');
ok('«کلاس» در ردیف اصلی است', form.includes('name="class_name"'));
ok('برچسب «کلاس» در ردیف اصلی دیده می‌شود', /<div class="filter-primary"><label[^>]*>کلاس<\/label>/.test(form));
ok('کلاس فقط یک بار در فرم هست (از «گزینه‌های بیشتر» برداشته شد)',
   (form.match(/name="class_name"/g) || []).length === 1);
ok('«کلاس» پیش از «گزینه‌های بیشتر» آمده',
   form.indexOf('name="class_name"') < form.indexOf('گزینه‌های بیشتر'));
ok('جستجو / سال تحصیلی / پایه هنوز هستند',
   form.includes('name="q"') && form.includes('name="academic_year"') && form.includes('name="grade_level"'));
ok('فیلترهای پیشرفته هنوز در «گزینه‌های بیشتر» هستند',
   form.includes('name="discipline_filter"') && form.includes('name="academic_filter"')
   && form.includes('name="subject_name"') && form.includes('name="min_gpa"'));
ok('دکمهٔ اعمال فیلتر هست', form.includes('اعمال فیلتر') && form.includes('پاک کردن'));

/* ═══════ منطق چینش در PHP باید دست‌نخورده باشد ═══════ */
console.log('\n── چینش با سرستون‌ها هنوز کار می‌کند ──');
ok('سرستون‌های جدول لینک sort می‌سازند', /href="[^"]*sort=/.test(page), 'هیچ لینک sort= در صفحه نیست');
ok('سرستون «کلاس» لینک sort=class دارد', /sort=class/.test(page));
ok('پیکان جهتِ چینش فعلی نمایش داده می‌شود', /▲|▼/.test(page));

const namesOf = (html) => IDS.map(id => FAM[id])
  .filter(f => html.includes(f))
  .sort((a, b) => html.indexOf(a) - html.indexOf(b));

const byName = (await req('چینش بر پایهٔ نام خانوادگی', { file: 'students.php', sid: SID, query: 'sort=name&dir=asc' })).res.page;
const byNameDesc = (await req('همان، نزولی', { file: 'students.php', sid: SID, query: 'sort=name&dir=desc' })).res.page;
const byClass = (await req('چینش بر پایهٔ کلاس', { file: 'students.php', sid: SID, query: 'sort=class&dir=asc' })).res.page;

const oName = namesOf(byName), oNameDesc = namesOf(byNameDesc), oClass = namesOf(byClass);
ok('هر سه دانش‌آموز در فهرست دیده می‌شوند', oName.length === 3, oName.join(' ، '));
ok('sort=name صعودی الفبایی است', oName.join(',') === 'الفی,ببی,پپی', oName.join(' ، '));
ok('dir=desc واقعاً برعکس می‌کند', oNameDesc.join(',') === 'پپی,ببی,الفی', oNameDesc.join(' ، '));
/* کلاس: ۷۰۱ < ۷۰۲ < ۷۰۳ → ببی(۹۳۰۲)، پپی(۹۳۰۳)، الفی(۹۳۰۱) */
ok('sort=class بر پایهٔ کلاس می‌چیند نه نام',
   oClass.join(',') === 'ببی,پپی,الفی',
   `شد [${oClass.join(' ، ')}] — یعنی sort از URL خوانده نمی‌شود`);

/* ═══════ CSS و کش‌شکن ═══════ */
console.log('\n── CSS ──');
const css = readFileSync(resolveFile('assets/css/school-ui.css'), 'utf8');
ok('قاعدهٔ واکنش‌گرای .students-filters اضافه شده', css.includes('.school-app .students-filters{display:flex'));
ok('قاعده‌ها به .students-filters محدودند (bot-inbox آسیب نمی‌بیند)',
   !/\.filters-line\s*\{[^}]*display:flex/.test(css) || css.includes('.students-filters'));
ok('گرید «گزینه‌های بیشتر» واکنش‌گرا شد', css.includes('.students-filters .filter-more>div'));
ok('کش‌شکن school-ui.css جلو رفته است', page.includes('school-ui.css?v20261005a'),
   'کش‌شکن قدیمی یعنی CSS تازه به کلاینت نمی‌رسد');
ok('کش‌شکن قدیمی school-ui.css نمانده', !page.includes('school-ui.css?v20260917e'));

const hdr = readFileSync(resolveFile('includes/header.php'), 'utf8');
ok('skip-link دسترسی‌پذیری در هدر باقی است', hdr.includes('href="#main-content"'));

/* ═══════ منبع students.php ═══════ */
console.log('\n── منبع ──');
const src = readFileSync(resolveFile('students.php'), 'utf8');
ok('منطق $sortKey دست‌نخورده است', src.includes("$sortKey = trim($_GET['sort'] ?? '')"));
ok('منطق $sortDir دست‌نخورده است', src.includes("$sortDir = (trim($_GET['dir'] ?? 'asc')"));
ok('$stuSortLink باقی است', src.includes('$stuSortLink'));
ok('array_reverse برای نزولی باقی است', src.includes('array_reverse($studentsList)'));
ok('هر ۷ کلید چینش هنوز مجازند',
   src.includes("['class', 'name', 'first', 'grade', 'nid', 'gpa', 'disc']"));
ok('شرط بازبودن «گزینه‌های بیشتر» دیگر $filterClass ندارد',
   !/\$filterClass\|\|\$disciplineFilter/.test(src));

await dbExec(`DELETE FROM students WHERE id IN (${IDS.join(',')})`);

console.log(`\n>>> PASS ${pass} · FAIL ${fail}`);
process.exit(fail === 0 ? 0 : 1);
