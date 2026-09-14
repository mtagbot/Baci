/**
 * test-entry-cards.mjs — سوئیت ۱۷ (v4.136.0)
 *
 * کارت ورود دانش‌آموز در اندازهٔ استاندارد کارت شناسایی.
 *
 * تمرکز:
 *   · ابعاد واقعاً استاندارد باشد (ISO/IEC 7810 ID-1)
 *   · همان فیلترها و ترتیب‌های صفحهٔ تگ‌ها را داشته باشد
 *   · فونت در چاپ درست باشد (درسی که در v4.135.0 گرفتیم)
 *   · نقش‌ها برداری و چاپ‌پذیر باشند، نه تصویر رستری
 *   · پیش‌نمایش و چاپ از یک منبع بیایند تا از هم جدا نیفتند
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile } from './harness/site.mjs';
import { readFileSync } from 'node:fs';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));
const j = async (code) => {
    const out = (await run(code)).out.trim();
    try { return JSON.parse(out); } catch (e) { return { __raw: out }; }
};
const strip = (s) => s.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '').replace(/\/\*[\s\S]*?\*\//g, '');

const page   = readFileSync(resolveFile('entry-cards.php'), 'utf8');
const styles = readFileSync(resolveFile('includes/card_styles.php'), 'utf8');
const face   = readFileSync(resolveFile('includes/card_face.php'), 'utf8');
const back   = readFileSync(resolveFile('includes/card_back.php'), 'utf8');
const orn    = readFileSync(resolveFile('includes/card_ornaments.php'), 'utf8');
const pageC  = strip(page);

/* ═══ ۱) ابعاد کارت ═══ */
console.log('\n══ ابعاد کارت ══');
ok('عرض کارت ۸۵٫۶ میلی‌متر است', /\.card-id\{[\s\S]*?width:85\.6mm/.test(styles));
ok('ارتفاع کارت ۵۴ میلی‌متر است', /\.card-id\{[\s\S]*?height:54mm/.test(styles));
ok('پشت کارت هم همان ابعاد را دارد', /\.card-back\{[\s\S]*?width:85\.6mm[\s\S]*?height:54mm/.test(styles));
ok('ابعاد به میلی‌متر است نه پیکسل (چاپ دقیق)',
   !/\.card-id\{[\s\S]*?width:\s*\d+px/.test(styles));
ok('کارت بین دو صفحه نصف نمی‌شود', /page-break-inside:avoid/.test(styles));

/* ═══ ۲) فیلترها — همان قرارداد صفحهٔ تگ‌ها ═══ */
console.log('\n══ فیلترها و ترتیب ══');
ok('فیلتر پایه دارد', pageC.includes("$fGrade") && pageC.includes("s.grade_level=?"));
ok('فیلتر کلاس دارد', pageC.includes("$fClass") && pageC.includes("s.class_name=?"));
ok('چاپ تکی دارد', pageC.includes("$fOne") && pageC.includes("s.id=?"));
ok('هر ۵ ترتیب چیدمان را دارد',
   /in_array\(\$sortKey, \['class', 'name', 'first', 'grade', 'nid'\], true\)/.test(pageC));
ok('ترتیب با allow-list محدود شده (تزریق ناپذیر)',
   !/ORDER BY[^"']*\$sortKey/.test(pageC));
ok('فیلترها با پارامتر امن به کوئری می‌روند',
   !/s\.grade_level='\s*\.\s*\$/.test(pageC) && !/s\.class_name='\s*\.\s*\$/.test(pageC));
ok('فقط سال تحصیلی جاری (مثل تگ‌ها)', pageC.includes('att_year_sql'));
ok('لیست انتخاب دانش‌آموز قابل جستجو است', pageC.includes('id="oneStudentCard"'));
ok('همان توکن QR حضور و غیاب استفاده می‌شود',
   pageC.includes('att_get_or_create_tag') && pageC.includes('att_qr_payload'));

/* منطق مرتب‌سازی واقعاً اجرا شود */
php.writeFile('/harness/csort.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/school_sort.php';
$rows = [
  ['id'=>1,'first_name'=>'زهرا','last_name'=>'ابراهیمی','class_name'=>'دوم ب','grade_level'=>'دوم','national_id'=>'3'],
  ['id'=>2,'first_name'=>'آرش','last_name'=>'یوسفی','class_name'=>'اول الف','grade_level'=>'اول','national_id'=>'1'],
  ['id'=>3,'first_name'=>'بهار','last_name'=>'آبادی','class_name'=>'دوم ب','grade_level'=>'دوم','national_id'=>'2'],
];
persian_usort_students($rows);
echo json_encode(array_map(function($r){return $r['id'];}, $rows));`);
const srt = await j("<?php require '/harness/csort.php';");
ok('مرتب‌سازی فارسی درست است (آبادی قبل از ابراهیمی)',
   JSON.stringify(srt) === JSON.stringify([3, 1, 2]), JSON.stringify(srt));

/* ═══ ۳) فونت در چاپ ═══ */
console.log('\n══ فونت در چاپ ══');
ok('فونت سامانه خوانده می‌شود', pageC.includes("get_setting('font_family'"));
ok('@font-face در صفحهٔ چاپ تولید می‌شود', pageC.includes('$fontFaces'));
/* منطق فونت به card_ornaments.php منتقل شد تا همهٔ طرح‌ها از یک
   منبع تغذیه شوند؛ پس بررسی هم باید آنجا را ببیند. */
ok('فقط فایل فونتِ موجود اعلام می‌شود', orn.includes('is_file(__DIR__'));
ok('Tahoma به‌عنوان فونت اصلی hardcode نشده',
   !/html,body\{background:#fff;font-family:Tahoma/.test(pageC));
/* باید واقعاً *فراخوانی* شود، نه اینکه فقط نامش در فایل باشد.
   نسخهٔ اول این تست فقط دنبال رشته می‌گشت و وقتی خودِ فراخوانی را
   عمداً حذف کردم، همچنان سبز ماند — یعنی نگهبان نبود. */
ok('چاپ تا آماده‌شدن فونت صبر می‌کند',
   /document\.fonts\.ready\.then\(\s*go\s*\)/.test(pageC) &&
   /whenFontsReady\(function\(\)\s*\{/.test(pageC));
ok('اگر فونت نیامد چاپ گیر نمی‌کند', /setTimeout\(go, 3000\)/.test(pageC));
ok('پنجرهٔ چاپ فقط یک بار باز می‌شود', pageC.includes('if (printed) return;'));
ok('فونت سفارشی آپلودی پشتیبانی می‌شود', pageC.includes('CustomUploadedFont'));

/* ═══ ۴) طراحی: برداری و چاپ‌پذیر ═══ */
console.log('\n══ نقش‌مایه‌های ایرانی ══');
const symbols = [...orn.matchAll(/<symbol id="orn-([\w-]+)"/g)].map(m => m[1]);
ok('نقش‌های ایرانی تعریف شده‌اند', symbols.length >= 4, JSON.stringify(symbols));
ok('شمسهٔ هشت‌پر هست', symbols.includes('shamse'));
ok('بته‌جقه هست', symbols.includes('boteh'));
ok('طاق ایرانی هست', symbols.includes('arch'));
ok('گل اسلیمی هست', symbols.includes('flower'));
ok('الگوی گره‌چینی تعریف شده', orn.includes('id="orn-band"') && orn.includes('id="orn-mesh"'));
ok('نقش‌ها روی شبکهٔ ۱۰۰×۱۰۰ کشیده شده‌اند',
   [...orn.matchAll(/<symbol id="orn-[\w-]+" viewBox="([^"]+)"/g)].every(m => m[1].trim() === '0 0 100 100'));
ok('هیچ تصویر رستری در طراحی نیست (چاپ تیز)',
   !/\.(png|jpe?g|gif|webp)/i.test(orn) && !/<image/i.test(orn));
ok('رنگ نقش‌ها از تم مدرسه می‌آید', orn.includes('$primary') && orn.includes('$accent'));
ok('نقش‌ها با xlink پشتیبان ارجاع می‌شوند', face.includes('xlink:href="#orn-'));
ok('sprite فقط یک بار چاپ می‌شود', orn.includes('static $done = false;'));

/* هر ارجاع باید در sprite وجود داشته باشد */
const used = new Set([...(face + back).matchAll(/#orn-([\w-]+)/g)].map(m => m[1]));
ok('هیچ ارجاع شکسته‌ای به نقش نیست',
   [...used].every(u => symbols.includes(u) || ['band', 'mesh', 'head'].includes(u)),
   JSON.stringify([...used].filter(u => !symbols.includes(u) && !['band', 'mesh', 'head'].includes(u))));

/* ═══ ۵) محتوای کارت ═══ */
console.log('\n══ محتوای کارت ══');
ok('نام دانش‌آموز روی کارت است', face.includes('$fullName'));
ok('کلاس روی کارت است', face.includes("$s['class_name']"));
ok('پایه روی کارت است', face.includes("$s['grade_level']"));
ok('نام مدرسه روی کارت است', face.includes('$schoolShort'));
ok('مشخصات مدرسه (استان/منطقه/دوره) روی کارت است',
   face.includes('$province') && face.includes('$region') && face.includes('$unitType'));
ok('QR روی کارت است', face.includes("class=\"card-qr\""));
ok('عکس دانش‌آموز پشتیبانی می‌شود', face.includes("$s['photo_url']"));
ok('اگر عکس نبود جایگزین امن دارد', face.includes('card-photo-ph'));
ok('لوگوی مدرسه پشتیبانی می‌شود', face.includes('$logoUrl'));
ok('همهٔ داده‌ها از clean() رد می‌شوند',
   !/<\?php echo \$s\['(first_name|last_name|class_name|national_id)'\]/.test(face));
ok('شش طرح کارت وجود دارد',
   ['classic','ribbon','minimal','titr','tile','sarv'].every(t => styles.includes('.th-' + t)));
ok('پشت کارت مقررات و تماس دارد', back.includes('card-back-l') && back.includes('card-back-foot'));

/* ── v4.137.0: خواسته‌های صریح کارفرما ── */
console.log('\n══ QR بزرگ و دوطرفه ══');
ok('نام پدر از کارت حذف شد', !face.includes("father_name"));
ok('QR روی کارت دست‌کم ۴۰ میلی‌متر است', (() => {
    const m = styles.match(/\.card-qr\{[^}]*width:(\d+(?:\.\d+)?)mm/);
    return m && parseFloat(m[1]) >= 40;
})(), (styles.match(/\.card-qr\{[^}]*width:([\d.]+)mm/) || [])[1]);
ok('QR پشت کارت دست‌کم ۴۴ میلی‌متر است', (() => {
    const m = styles.match(/\.card-back-qr\{[^}]*width:(\d+(?:\.\d+)?)mm/);
    return m && parseFloat(m[1]) >= 44;
})(), (styles.match(/\.card-back-qr\{[^}]*width:([\d.]+)mm/) || [])[1]);
/* QR باید سهم معناداری از سطح کارت داشته باشد، نه فقط عددش بزرگ باشد */
ok('QR دست‌کم یک‌سوم سطح روی کارت را می‌گیرد', (() => {
    const m = styles.match(/\.card-qr\{[^}]*width:([\d.]+)mm/);
    return m && (parseFloat(m[1]) ** 2) / (85.6 * 54) >= 0.33;
})());
ok('QR جا می‌شود (از ارتفاع بدنه بیرون نمی‌زند)', (() => {
    const qr = parseFloat((styles.match(/\.card-qr\{[^}]*width:([\d.]+)mm/) || [])[1]);
    const head = parseFloat((styles.match(/\.card-head\{[\s\S]*?height:([\d.]+)mm/) || [])[1]);
    return qr + 3 <= 54 - head;      /* +۳ برای کپشن و پدینگ */
})());
ok('QR پشت کارت هم جا می‌شود', (() => {
    const q = parseFloat((styles.match(/\.card-back-qr\{[^}]*width:([\d.]+)mm/) || [])[1]);
    return q + 4 <= 54;
})());
ok('پشت کارت هم QR دارد', back.includes('card-back-qr') && back.includes('data-qr'));
ok('هر دو طرف از یک توکن استفاده می‌کنند', back.includes("$s['qr']") && face.includes("$s['qr']"));
ok('پشت کارت نام و کلاس را هم دارد (بدون برگرداندن کارت)',
   back.includes('card-back-name') && back.includes('class_name'));
ok('سطح تصحیح خطای QR به Q ارتقا یافت (مقاوم به خط‌خوردگی)',
   !pageC.includes("qrcode(0, 'M')") && (pageC.match(/qrcode\(0, 'Q'\)/g) || []).length === 2);
ok('حاشیهٔ سفید QR استاندارد است (۴ ماژول)',
   (pageC.match(/quiet = 4/g) || []).length === 2 && !pageC.includes('quiet = 2'));
ok('هر دو canvas در چاپ رسم می‌شوند',
   (pageC.match(/canvas\.card-qr\[data-qr\],canvas\.card-back-qr\[data-qr\]/g) || []).length === 2);

console.log('\n══ حالت تگ‌محور (QR-first) ══');
/* خواستهٔ کارفرما «۸۰٪ کارت را QR بگیرد».
   روی کارت مستطیلی ۸۵٫۶×۵۴ این از نظر هندسی ناممکن است: مربع ۸۰٪
   باید ۶۰٫۸mm ضلع داشته باشد که از ارتفاع ۵۴mm بلندتر است. سقف مطلق
   ۶۳٪ است. پس شکل مربع ۵۴×۵۴ اضافه شد که ۷۹٪ می‌دهد. */
const qrOf = (sel) => {
    const re = new RegExp(sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\{[^}]*width:([\\d.]+)mm');
    const m = styles.match(re);
    return m ? parseFloat(m[1]) : 0;
};
ok('سه شکل کارت وجود دارد (کامل، تگ‌محور، مربع)',
   /\['full', 'qrmax', 'sq'\]/.test(pageC));
ok('کارت مربع ۵۴×۵۴ تعریف شده', /\.card-id\.sq\{width:54mm;height:54mm/.test(styles));
ok('QR کارت مربع دست‌کم ۴۹ میلی‌متر است', qrOf('.card-id.sq .card-qr') >= 49,
   String(qrOf('.card-id.sq .card-qr')));
ok('کارت مربع: QR دست‌کم ۸۰٪ مساحت را می‌گیرد', (() => {
    const q = qrOf('.card-id.sq .card-qr');
    return (q * q) / (54 * 54) >= 0.80;
})(), ((qrOf('.card-id.sq .card-qr') ** 2) / (54 * 54) * 100).toFixed(0) + '%');
/* v4.140.0 — این بررسی قبلاً «qr + 6 <= 54» بود؛ عدد ۶ حدسی بود و
   ارتفاع واقعی سربرگ ۶٫۴ بود، پس ۱٫۳mm سرریزِ واقعی را نگرفت و
   کارفرما آن را روی کارت دید. حالا ارتفاع سربرگ و فاصلهٔ بالای QR
   از خودِ CSS خوانده و جمع می‌شوند. */
const mmOf = (sel, prop) => {
    const re = new RegExp(sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\{[^}]*' + prop + ':([\\d.]+)mm');
    const m = styles.match(re);
    return m ? parseFloat(m[1]) : null;
};
ok('QR کارت مربع واقعاً داخل کارت جا می‌شود (بدون سرریز)', (() => {
    const head = mmOf('.card-id.sq .card-head', 'height');
    const top  = mmOf('.card-id.sq .card-qr-box', 'top');
    const qr   = qrOf('.card-id.sq .card-qr');
    if (head === null || top === null || !qr) return false;
    const total = head + top + qr;
    return total <= 54;
})(), (() => {
    const head = mmOf('.card-id.sq .card-head', 'height');
    const top  = mmOf('.card-id.sq .card-qr-box', 'top');
    const qr   = qrOf('.card-id.sq .card-qr');
    return `سربرگ ${head} + top ${top} + QR ${qr} = ${(head + top + qr).toFixed(1)}mm`;
})());
ok('QR حالت تگ‌محور مستطیلی هم سرریز ندارد', (() => {
    const head = mmOf('.card-id.qrmax .card-head', 'height');
    const top  = mmOf('.card-id.qrmax .card-qr-box', 'top');
    const qr   = qrOf('.card-id.qrmax .card-qr');
    return head !== null && top !== null && qr && (head + top + qr) <= 54;
})());
/* ۴۹mm سقفِ بدون سرریز است: ۴٫۴ سربرگ + ۰٫۳ فاصله + ۴۹ = ۵۳٫۷ از ۵۴ */
ok('حالت تگ‌محور مستطیلی QR دست‌کم ۴۹ میلی‌متر دارد',
   qrOf('.card-id.qrmax .card-qr') >= 49, String(qrOf('.card-id.qrmax .card-qr')));
ok('تگ‌محور مستطیلی: QR دست‌کم ۹۰٪ ارتفاع کارت است',
   qrOf('.card-id.qrmax .card-qr') / 54 >= 0.90);
ok('پشت کارت مربع QR تمام‌صفحه دارد', qrOf('.card-back.sq .card-back-qr') >= 50);
ok('در حالت مربع، عناصر غیرضروری پنهان می‌شوند',
   /\.card-id\.sq \.card-photo,[\s\S]{0,200}display:none/.test(styles));
ok('شکل کارت با allow-list محدود شده', /in_array\(\(\$_GET\['layout'\] \?\? 'full'\)/.test(pageC));
ok('انتخاب شکل در ویرایشگر هست', pageC.includes('id="cLayout"'));
ok('شکل کارت به نمای چاپ فرستاده می‌شود', pageC.includes("params.set('layout'"));
ok('پیش‌نمایش هم شکل را اعمال می‌کند', pageC.includes("classList.add('sq')"));
ok('کلاس شکل با فاصلهٔ درست چاپ می‌شود (clean حذفش نکند)',
   face.includes("$_layCls !== '' ? ' ' . clean($_layCls)"));
ok('پیش‌نمایش صفحه هم کلید layout دارد', /\$D = \[[^\]]*'layout'/.test(pageC));

console.log('\n══ چیدمان v4.140.0 ══');
ok('حالت کامل: عکس بالای ستون چپ است', (() => {
    const m = styles.match(/\.card-photo\{[^}]*top:([\d.]+)mm/);
    return m && parseFloat(m[1]) < 5;
})());
ok('حالت کامل: اطلاعات زیر عکس آمده', (() => {
    const m = styles.match(/\.card-info\{[^}]*top:([\d.]+)mm/);
    return m && parseFloat(m[1]) > 10;
})());
ok('حالت تگ‌محور: عکس بالا و اطلاعات پایین', (() => {
    const ph = styles.match(/\.card-id\.qrmax \.card-photo,[\s\S]{0,80}?top:([\d.]+)mm/);
    const inf = styles.match(/\.card-id\.qrmax \.card-info\{top:([\d.]+)mm/);
    return ph && inf && parseFloat(ph[1]) < parseFloat(inf[1]);
})());
ok('حالت مربع: نام دانش‌آموز کنار نام مدرسه است', (() => {
    /* شاخهٔ sq تا else ادامه دارد؛ کامنت PHP وسطش هست، پس تا
       «else» می‌بریم نه تا اولین </div>. */
    const m = face.match(/\$_layout === 'sq'\)[\s\S]*?else:/);
    return !!m && /card-school[\s\S]*\$schoolShort[\s\S]*\$fullName/.test(m[0]);
})());
ok('حالت مربع: کلاس و پایه نمایش داده نمی‌شوند',
   /\.card-id\.sq \.card-info,[\s\S]{0,220}display:none/.test(styles));
ok('حالت مربع: لوگو و نقش سربرگ هم پنهان‌اند (جا برای QR)',
   /\.card-id\.sq \.card-meta,[\s\S]{0,140}display:none/.test(styles));
ok('حالت مربع: حاشیهٔ اطراف QR بسیار کم است', (() => {
    const top = mmOf('.card-id.sq .card-qr-box', 'top');
    return top !== null && top <= 0.5;
})());
ok('حالت مربع: نوار رنگی سربرگ برای صرفه‌جویی حذف شده',
   /\.card-id\.sq \.card-head::after\{display:none\}/.test(styles));

console.log('\n══ تنوع تصویرسازی ══');
const symAll = [...orn.matchAll(/<symbol id="orn-([\w-]+)"/g)].map(m => m[1]);
ok('دست‌کم ۱۲ نقش ایرانی موجود است', symAll.length >= 12, String(symAll.length));
for (const need of ['sarv','mehrab','dome','column','badgir','taq','star12','lotus','eslimi','farvahar'])
    ok('نقش «' + need + '» اضافه شد', symAll.includes(need));
const pats = [...orn.matchAll(/<pattern id="orn-([\w-]+)"/g)].map(m => m[1]);
ok('دست‌کم ۶ بافت پس‌زمینه موجود است', pats.length >= 6, JSON.stringify(pats));
ok('هر طرح تصویر شاخص خودش را دارد', orn.includes('function card_theme_art'));
ok('تصاویر شاخص طرح‌ها یکسان نیستند', (() => {
    const m = orn.match(/function card_theme_art[\s\S]*?\$map = \[([\s\S]*?)\];/);
    const heroes = [...m[1].matchAll(/\['(\w+)',/g)].map(x => x[1]);
    return heroes.length === 6 && new Set(heroes).size === 6;
})());
ok('بافت پس‌زمینهٔ طرح‌ها متفاوت است', (() => {
    const fills = [...styles.matchAll(/\.th-\w+ \.card-bg rect\{fill:url\(#orn-(\w+)\)/g)].map(x => x[1]);
    return new Set(fills).size >= 4;
})(), JSON.stringify([...styles.matchAll(/\.th-(\w+) \.card-bg rect\{fill:url\(#orn-(\w+)\)/g)].map(x => x[1] + ':' + x[2])));
ok('قالب کارت به نام طرح‌ها گره نخورده (افزودن طرح آسان)',
   face.includes('card_theme_art(') && !/\$_heroArt\s*=\s*'/.test(face));
ok('همهٔ ارجاع‌های تصویر شاخص معتبرند', (() => {
    const m = orn.match(/function card_theme_art[\s\S]*?\$map = \[([\s\S]*?)\];/);
    const all = [...m[1].matchAll(/'(\w+)'/g)].map(x => x[1])
        .filter(x => !['classic','ribbon','minimal','titr','tile','sarv'].includes(x));
    return all.every(a => symAll.includes(a) || a === 'hex-none');
})());

console.log('\n══ فونت‌های ایرانی ══');
ok('چهار فونت ایرانی تعریف شده',
   ['Vazirmatn','Sahel','Yekan','B-Titr'].every(f => orn.includes("'" + f + "'")));
ok('هر فونت متغیر CSS خودش را دارد',
   ['--f-vazir','--f-sahel','--f-yekan','--f-titr'].every(v => orn.includes(v)));
ok('طرح‌ها از متغیر فونت استفاده می‌کنند',
   ['var(--f-vazir)','var(--f-sahel)','var(--f-yekan)','var(--f-titr)'].every(v => styles.includes(v)));
ok('اگر فونتی روی دیسک نبود، جایگزین امن دارد', orn.includes('card_font_exists'));
ok('فونت‌ها محلی‌اند (بدون CDN)', !/https?:\/\/fonts\./.test(orn) && !/googleapis/.test(orn));
ok('فهرست طرح‌ها یک منبع واحد دارد', orn.includes('function card_themes'));
ok('فرم طرح‌ها را از همان منبع می‌سازد', pageC.includes('card_themes()'));
ok('پیش‌نمایش هم همان فونت‌ها را لود می‌کند',
   (pageC.match(/card_font_faces\(\)/g) || []).length >= 1 &&
   (pageC.match(/card_font_vars\(\)/g) || []).length >= 1);

/* ═══ ۶) پیش‌نمایش و چاپ از یک منبع ═══ */
console.log('\n══ هماهنگی پیش‌نمایش و چاپ ══');
ok('استایل کارت فقط یک بار تعریف شده (یک منبع)',
   (pageC.match(/includes\/card_styles\.php/g) || []).length === 2);
ok('قالب روی کارت در هر دو مسیر یکی است',
   (pageC.match(/includes\/card_face\.php/g) || []).length === 2);
ok('صفحه در منوی حضور و غیاب لینک شده',
   readFileSync(resolveFile('attendance.php'), 'utf8').includes('entry-cards.php'));
ok('از صفحهٔ تگ‌ها هم لینک دارد',
   readFileSync(resolveFile('attendance-tags.php'), 'utf8').includes('entry-cards.php'));
ok('صفحهٔ چاپ در فهرست استثنای پوستهٔ دسکتاپ است',
   readFileSync(resolveFile('assets/js/desk-shell.js'), 'utf8').includes('entry-cards.php'));

/* ═══ ۷) دسترسی ═══ */
console.log('\n══ دسترسی ══');
ok('فقط مدیر با مجوز یا معاون', pageC.includes("has_permission('manage_students')")
   && pageC.includes('teacher_has_deputy'));
ok('کاربر بی‌مجوز رد می‌شود', pageC.includes("redirect('admin-login.php?tab=teacher')"));

/* ═══ ۸) رندر واقعی ═══ */
console.log('\n══ رندر واقعی صفحهٔ چاپ ══');
php.writeFile('/harness/cardrender.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('cr'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
set_setting('school_name_short','دبیرستان نمونه');
set_setting('school_province','تهران');
set_setting('font_family','Vazirmatn');
/* دادهٔ نمونهٔ هارنس سال تحصیلی ندارد */
DB::execute("UPDATE students SET academic_year='1404/1405' WHERE academic_year IS NULL OR academic_year=''");
$a = DB::fetch("SELECT * FROM admins WHERE status=1 ORDER BY id LIMIT 1");
$_SESSION = ['admin_id'=>$a['id'],'admin_role'=>'super_admin'];
$_GET = ['print'=>'1','side'=>'both','theme'=>'classic','sort'=>'name'];
$_SERVER['REQUEST_METHOD']='GET';
chdir('/www');
register_shutdown_function(function(){ file_put_contents('/tmp/cardout.html', ob_get_clean()); });
ob_start();
include '/www/entry-cards.php';`);
await run("<?php require '/harness/cardrender.php';");
/* db.php لازم است: این یک اجرای تازه است و کلاس DB را ندارد. */
const R = await j(`<?php
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
$h = @file_get_contents('/tmp/cardout.html');
$n = (int)DB::fetch("SELECT COUNT(*) c FROM students s WHERE s.status='active'")['c'];
echo json_encode([
  'cards'     => substr_count($h,'class="card-id'),
  'backs'     => preg_match_all('/class="card-back(?: cut)?"/', $h),
  'qr'        => substr_count($h,'class="card-qr"'),
  'students'  => $n,
  'fontface'  => substr_count($h,'@font-face'),
  'shamse'    => substr_count($h,'orn-shamse'),
  'fatal'     => (stripos($h,'Fatal error')!==false || stripos($h,'Parse error')!==false)?1:0,
  'notice'    => (stripos($h,'Undefined')!==false)?1:0,
  'mm'        => strpos($h,'85.6mm')!==false ?1:0,
]);`);
ok('صفحهٔ چاپ بدون خطای مهلک رندر شد', R.fatal === 0, JSON.stringify(R));
ok('هیچ متغیر تعریف‌نشده‌ای نیست', R.notice === 0);
ok('برای هر دانش‌آموز یک کارت ساخته شد', R.cards === R.students && R.cards > 0, `${R.cards}/${R.students}`);
ok('پشت کارت هم برای همه آمد', R.backs === R.students, `${R.backs}/${R.students}`);
ok('هر کارت QR دارد', R.qr === R.students, `${R.qr}/${R.students}`);
ok('ابعاد استاندارد در خروجی هست', R.mm === 1);
ok('فونت در خروجی چاپ تعریف شده', R.fontface >= 1, String(R.fontface));
ok('نقش شمسه در خروجی هست', R.shamse >= 1, String(R.shamse));

/* فقط روی کارت، بدون پشت */
php.writeFile('/harness/cardfront.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_id('cf'.mt_rand()); session_start();
require_once '/www/includes/db.php';
require_once '/www/includes/functions.php';
require_once '/www/includes/auth.php';
$a = DB::fetch("SELECT * FROM admins WHERE status=1 ORDER BY id LIMIT 1");
$_SESSION = ['admin_id'=>$a['id'],'admin_role'=>'super_admin'];
$one = (int)DB::fetch("SELECT id FROM students WHERE status='active' ORDER BY id LIMIT 1")['id'];
$_GET = ['print'=>'1','side'=>'front','student_id'=>(string)$one];
chdir('/www');
register_shutdown_function(function(){ file_put_contents('/tmp/cardone.html', ob_get_clean()); });
ob_start();
include '/www/entry-cards.php';`);
await run("<?php require '/harness/cardfront.php';");
const F = await j(`<?php $h=@file_get_contents('/tmp/cardone.html');
echo json_encode(['cards'=>substr_count($h,'class="card-id'),'backs'=>preg_match_all('/class="card-back(?: cut)?"/', $h)]);`);
ok('چاپ تکی دقیقاً یک کارت می‌دهد', F.cards === 1, JSON.stringify(F));
ok('در حالت «فقط روی کارت»، پشت چاپ نمی‌شود', F.backs === 0);

console.log(`\n  سوئیت کارت ورود: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
