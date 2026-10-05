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
ok('برچسب «کلاس» در ردیف اصلی دیده می‌شود', /<div class="filter-primary[^"]*"><label[^>]*>کلاس<\/label>/.test(form));
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
ok('قاعدهٔ واکنش‌گرای .students-filters اضافه شده',
   /\.school-app \.students-filters\{\s*[^}]*display:flex/.test(css));
ok('قاعده‌ها به .students-filters محدودند (bot-inbox آسیب نمی‌بیند)',
   !/\.filters-line\s*\{[^}]*display:flex/.test(css) || css.includes('.students-filters'));
/* ═══════ اندازهٔ فیلدها — خواستهٔ کاربر ═══════ */
console.log('\n── اندازهٔ فیلدها ──');
/* عددِ flex-basis یک قاعده را از CSS بیرون می‌کشد */
const basisOf = (sel) => {
  const m = css.match(new RegExp('\\.students-filters\\s+' + sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[^{}]*\\{([^}]*)\\}'));
  if (!m) return null;
  const f = m[1].match(/flex:\s*[\d.]+\s+[\d.]+\s+([\d.]+)px/);
  return f ? Number(f[1]) : null;
};
const maxOf = (sel) => {
  const m = css.match(new RegExp('\\.students-filters\\s+' + sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '[^{}]*\\{([^}]*)\\}'));
  if (!m) return null;
  const w = m[1].match(/max-width:\s*([\d.]+)px/);
  return w ? Number(w[1]) : null;
};

const bSearch = basisOf('.f-search'), bYear = basisOf('.f-year'),
      bGrade = basisOf('.f-grade'), bClass = basisOf('.f-class');
ok('هر چهار فیلد ردیف اصلی کلاس اندازهٔ خودشان را دارند',
   ['.f-search', '.f-year', '.f-grade', '.f-class'].every((s) => css.includes('.students-filters ' + s)),
   'یافت‌شده: ' + [bSearch, bYear, bGrade, bClass].join(','));

/* پایهٔ پیشین: جستجو ۲۴۰ و بقیه ۱۶۰ */
ok('جستجو نصف شد (۲۴۰ → ' + bSearch + ')', bSearch === 120, 'basis=' + bSearch);
ok('سال تحصیلی نصف شد (۱۶۰ → ' + bYear + ')', bYear === 80, 'basis=' + bYear);
ok('پایه نصف شد (۱۶۰ → ' + bGrade + ')', bGrade === 80, 'basis=' + bGrade);
ok('کلاس بزرگ‌تر شد (۱۶۰ → ' + bClass + ')', bClass > bYear * 2, 'basis=' + bClass);
ok('کلاس حدود ۲٫۵ برابرِ پایه است', Math.abs(bClass / bYear - 2.5) < 0.01,
   (bClass / bYear).toFixed(2) + ' برابر');
ok('عرض بیشینهٔ کلاس هم ≥۲٫۵ برابرِ سال تحصیلی است',
   maxOf('.f-class') >= maxOf('.f-year') * 2.5,
   maxOf('.f-class') + ' در برابر ' + maxOf('.f-year'));
ok('قاعدهٔ یک‌اندازه‌برای‌همه (flex:1 1 160px) دیگر نیست',
   !/\.students-filters \.filter-primary\{[^}]*flex:1 1 160px/.test(css));
ok('فیلدها در HTML هم کلاس اندازه‌شان را دارند',
   ['.f-search', '.f-year', '.f-grade', '.f-class'].every((s) => form.includes('class="filter-primary ' + s.slice(1) + '"')));

/* placeholder جستجو کوتاه شد تا در عرضِ نصفه جا شود؛ متن کامل در title */
ok('placeholder جستجو کوتاه شد تا در عرض نصفه جا شود',
   form.includes('placeholder="نام یا کد ملی"') && !form.includes('نام، نام خانوادگی، کد ملی، نام پدر'));
ok('متن کامل جستجو در title باقی ماند',
   /name="q"[^>]*title="[^"]*کد ملی[^"]*"/.test(form) || /title="[^"]*کد ملی[^"]*"[^>]*name="q"/.test(form));
ok('فیلدهای معدل inputmode="decimal" گرفتند',
   (form.match(/inputmode="decimal"/g) || []).length === 2);

/* ═══════ نوار «گزینه‌های بیشتر» و دکمه‌ها ═══════ */
console.log('\n── نوار پایین: گزینه‌های بیشتر + دکمه‌ها ──');
ok('نوار filter-bar در HTML هست', form.includes('<div class="filter-bar">'));
ok('دکمه‌ها طرف دیگر «گزینه‌های بیشتر» نشستند (پس از آن در DOM)',
   form.indexOf('class="filter-more"') > -1 &&
   form.indexOf('class="filter-actions"') > form.indexOf('class="filter-more"'));
ok('filter-bar در CSS space-between است',
   /\.students-filters \.filter-bar\{[^}]*justify-content:space-between/.test(css));
ok('filter-bar تمام‌عرض است تا دکمه واقعاً به آن سو برود',
   /\.students-filters \.filter-bar\{[^}]*flex:1 1 100%/.test(css));
ok('«گزینه‌های بیشتر» دیگر تمام‌عرض نیست (وگرنه دکمه به سطر بعد می‌رفت)',
   !/\.students-filters \.filter-more\{[^}]*flex:1 1 100%/.test(css));

/* ═══════ اندازهٔ خانه‌های «گزینه‌های بیشتر» ═══════ */
console.log('\n── خانه‌های «گزینه‌های بیشتر» ──');
ok('هر چهار خانه کلاس خودش را دارد',
   ['fm-disc', 'fm-acad', 'fm-subject', 'fm-gpa'].every((c) => form.includes('class="' + c) || form.includes(' ' + c + ' ')));
const gridCols = (css.match(/\.students-filters \.filter-more>div\{([^}]*)\}/) || [, ''])[1];
const mins = Array.from(gridCols.matchAll(/minmax\((\d+)px/g)).map((m) => Number(m[1]));
ok('گرید چهار ستون با اندازهٔ متفاوت دارد (نه یک‌اندازه)',
   mins.length === 4 && new Set(mins).size > 1, 'minmax ها: [' + mins.join('، ') + ']');
ok('ستون معدل از درس خاص پهن‌تر است (دو ورودی کنار هم دارد)',
   mins[3] > mins[2], mins[3] + ' > ' + mins[2]);
ok('گرید یک‌اندازهٔ قبلی (auto-fit/minmax 165) برداشته شد',
   !/filter-more>div\{[^}]*repeat\(auto-fit,minmax\(165px/.test(css));
ok('برچسب‌های «گزینه‌های بیشتر» با ellipsis می‌شکنند نه اینکه ردیف را باز کنند',
   /\.students-filters \.filter-more label\{[^}]*text-overflow:ellipsis/.test(css));
ok('در موبایل همهٔ فیلدها تمام‌عرض می‌شوند',
   /@media\(max-width:640px\)\{[\s\S]{0,800}?\.f-class\{flex:1 1 100%/.test(css));

/* ═══════ ریتم فاصله‌ها — خواستهٔ کاربر ═══════ */
console.log('\n── ریتم فاصله‌ها ──');
/* همهٔ قواعدِ .students-filters را با بدنه‌شان بیرون می‌کشد */
const sfRules = Array.from(css.matchAll(/\.students-filters[^{}]*\{([^}]*)\}/g))
  .map((m) => ({ sel: m[0].slice(0, m[0].indexOf('{')), body: m[1] }));
/* sel را بدون «{» نگه داشته‌ایم، پس با endsWith جست‌وجو می‌کنیم نه includes */
const sfBody = (frag) => (sfRules.find((r) => r.sel.trim().endsWith(frag)) || {}).body || '';
const sfHas  = (frag) => sfRules.some((r) => r.sel.trim().endsWith(frag));
/* برای قواعدی که selector چندتایی با کاما دارند */
const sfBodyAny = (frag) => (sfRules.find((r) => r.sel.includes(frag)) || {}).body || '';

/* ۱) یک ارتفاعِ مشترک برای همهٔ کنترل‌ها — این همان چیزی است که قبلاً نبود */
ok('یک متغیر ارتفاعِ مشترک تعریف شده', /--sf-h:34px/.test(css));
const heightRules = sfRules.filter((r) => /(^|;|\s)height:/.test(r.body) || /min-height:/.test(r.body));
const strays = heightRules.filter((r) => !/var\(--sf-h\)/.test(r.body) && !/--sf-h:/.test(r.body));
ok('هر قاعده‌ای که ارتفاع می‌دهد از var(--sf-h) می‌آید (بدون ارتفاعِ سرخود)',
   strays.length === 0, strays.map((r) => r.sel.trim()).join(' | ') || heightRules.length + ' قاعده، همه var(--sf-h)');
for (const [frag, lbl] of [['.filter-primary .form-input', 'ورودی ردیف اصلی'],
                           ['.filter-more .form-input', 'ورودی «گزینه‌های بیشتر»'],
                           ['.filter-actions .btn', 'دکمه'],
                           ['.filter-more>summary', 'کلید «گزینه‌های بیشتر»']]) {
  const body = sfBodyAny(frag) || sfBody(frag);
  ok(lbl + ' هم‌ارتفاعِ بقیه است (height و min-height هر دو var(--sf-h))',
     /height:var\(--sf-h\)/.test(body) && /min-height:var\(--sf-h\)/.test(body),
     body.slice(0, 70) || 'قاعده پیدا نشد');
}
ok('summary دیگر ۴۴ پیکسلیِ school-ui.css:115 را نگه نمی‌دارد',
   /\.students-filters \.filter-more>summary\{[^}]*min-height:var\(--sf-h\)/.test(css) &&
   !/\.students-filters[^{}]*summary[^{}]*\{[^}]*44px/.test(css));

/* ۲) فاصله‌ها کوچک شدند، نه همان قبلی */
const formRule = sfRules.find((r) => r.sel.trim().endsWith('.students-filters')) || { body: '' };
ok('فاصلهٔ ردیف اصلی از 10px 12px به 6px 8px کم شد',
   /gap:6px 8px/.test(formRule.body), 'gap فعلی: ' + ((formRule.body.match(/gap:[^;]*/) || ['—'])[0]));
ok('فاصلهٔ برچسب تا کنترل از 4px به 3px کم شد',
   /\.students-filters \.filter-primary\{[^}]*gap:3px/.test(css));
ok('فاصلهٔ دکمه‌ها از 8px به 6px کم شد',
   /\.students-filters \.filter-actions\{[^}]*gap:6px/.test(css));
ok('پدینگ افقی ورودی‌ها از .9rem (۱۴px) به var(--sf-pad)=10px کم شد',
   /padding:0 var\(--sf-pad\)/.test(css) && /--sf-pad:10px/.test(css));
ok('برچسب‌ها اندازهٔ مشخص گرفتند (نه text-xs پیش‌فرض)',
   /font-size:var\(--sf-lfs\)/.test(css) && /--sf-lfs:11px/.test(css));

/* ۳) جعبهٔ خط‌چینِ .filter-more خنثی شد */
const moreBody = sfBody('.filter-more');
ok('قاعدهٔ .filter-more در بلوک هست', sfHas('.filter-more'));
ok('جعبهٔ خط‌چین «گزینه‌های بیشتر» خنثی شد (border/padding/background/margin)',
   /padding:0/.test(moreBody) && /border:0/.test(moreBody) &&
   /background:transparent/.test(moreBody) && /margin-top:0/.test(moreBody), moreBody.slice(0, 80));

/* ۴) نوار پایین: جداکننده و تراز درست */
const barBody = sfBody('.filter-bar');
ok('قاعدهٔ .filter-bar در بلوک هست', sfHas('.filter-bar'));
ok('filter-bar خط جداکننده و فاصلهٔ بالا دارد',
   /border-top:1px solid/.test(barBody) && /padding-top:9px/.test(barBody) && /margin-top:8px/.test(barBody),
   barBody.slice(0, 90));
ok('filter-bar با flex-start تراز است تا دکمه‌ها وسطِ ارتفاع معلق نمانند',
   /align-items:flex-start/.test(barBody));

/* ۵) پدینگ کارت فیلتر */
ok('کارت فیلتر کلاس اختصاصی دارد', page.includes('students-filters-card'));
ok('پدینگ کارت فیلتر از ۲۴ پیکسلِ .card به 14px 16px کم شد',
   /\.students-filters-card\{padding:14px 16px\}/.test(css));

/* ۶) موبایل: هدف لمسی ۴۴ برمی‌گردد */
ok('در موبایل ارتفاع به ۴۴ پیکسل برمی‌گردد', /--sf-h:44px/.test(css));

ok('کش‌شکن school-ui.css جلو رفته است', page.includes('school-ui.css?v20261006b'),
   'کش‌شکن قدیمی یعنی CSS تازه به کلاینت نمی‌رسد');
ok('کش‌شکن قدیمی school-ui.css نمانده',
   ['v20260917e', 'v20261005a', 'v20261006a'].every((v) => !page.includes('school-ui.css?' + v)));

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
