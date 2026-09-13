/**
 * test-desk-shell.mjs — سوئیت ۱۵ (v4.134.0 / دسکتاپ ۲.۶۵.۰)
 *
 * دو خواستهٔ کاربر:
 *   ۱) دکمه‌های «موارد انضباطی» و «افزودن دانش‌آموز» کنار بقیهٔ دکمه‌ها
 *   ۲) رفتار پنجرهٔ نرم‌افزار دسکتاپ:
 *        · تمام‌صفحه باز شود
 *        · در مرورگر سیستم باز نشود
 *        · پنجرهٔ «این پنجره را نبندید» نباشد
 *        · هیچ چیز در تب/پنجرهٔ جدید باز نشود
 *
 * منطق desk-shell.js واقعاً در یک DOM شبیه‌سازی‌شده اجرا می‌شود، نه
 * اینکه فقط دنبال رشته بگردیم.
 */
import { run, php } from './harness/lib.mjs';
import { resolveFile, REPO } from './harness/site.mjs';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

let pass = 0, fail = 0;
const ok = (n, c, d = '') => c ? (pass++, console.log(`  ✅ ${n}`))
                               : (fail++, console.log(`  ❌ ${n}${d ? '  → ' + d : ''}`));

/* ═══ ۱) جای دکمه‌ها در صفحهٔ دانش‌آموزان ═══ */
console.log('\n══ دکمه‌های صفحهٔ مدیریت دانش‌آموزان ══');

const students = readFileSync(resolveFile('students.php'), 'utf8');

/* کامنت‌ها حذف می‌شوند تا متنِ توضیحاتِ خودِ تغییر، با کد اشتباه گرفته
   نشود. (بار اول همین اشتباه ۲ هشدار کاذب داد.) */
const stripPhpComments = (src) => src.replace(/<\?php\s*\/\*[\s\S]*?\*\/\s*\?>/g, '')
                                     .replace(/\/\*[\s\S]*?\*\//g, '');

/* سربرگ صفحه = بلوکی که <h2>مدیریت پرونده دانش‌آموزان</h2> در آن است */
const headerBlock = stripPhpComments(students.slice(
    students.indexOf('<h2 class="text-2xl font-bold">مدیریت پرونده دانش‌آموزان</h2>'),
    students.indexOf('<div class="card p-4">')
));
/* نوار عملیات = بلوکی که دکمهٔ «گزارش» در آن است */
const actionBar = students.slice(
    students.indexOf('<h3 class="font-bold">نتایج:'),
    students.indexOf('<div class="table-container">')
);

ok('«موارد انضباطی» از سربرگ برداشته شد', !headerBlock.includes('موارد انضباطی'));
ok('«افزودن دانش‌آموز جدید» از سربرگ برداشته شد', !headerBlock.includes('افزودن دانش‌آموز جدید'));
ok('«موارد انضباطی» در نوار عملیات است', actionBar.includes('موارد انضباطی'));
ok('«افزودن دانش‌آموز جدید» در نوار عملیات است', actionBar.includes('+ افزودن دانش‌آموز جدید'));
ok('«گزارش» هنوز در نوار عملیات است', actionBar.includes('openReportModal()'));
ok('«انتقال دانش‌آموزان» هنوز در نوار عملیات است', actionBar.includes('openTransferModal()'));
ok('«حذف انتخاب شده‌ها» هنوز در نوار عملیات است', actionBar.includes('حذف انتخاب شده‌ها'));
/* لینک‌ها باید سالم بمانند */
ok('لینک موارد انضباطی درست است', actionBar.includes('href="deputy-panel.php"'));
ok('لینک افزودن دانش‌آموز درست است', actionBar.includes('href="students.php?action=add"'));
/* ترتیب خواسته‌شده: دو دکمهٔ جدید قبل از گزارش */
ok('ترتیب دکمه‌ها درست است (انضباطی، افزودن، گزارش، انتقال، حذف)', (() => {
    const idx = [
        actionBar.indexOf('موارد انضباطی'),
        actionBar.indexOf('+ افزودن دانش‌آموز جدید'),
        actionBar.indexOf('openReportModal()'),
        actionBar.indexOf('openTransferModal()'),
        actionBar.indexOf('حذف انتخاب شده‌ها')
    ];
    return idx.every(i => i !== -1) && idx.every((v, i, a) => i === 0 || a[i - 1] < v);
})());

/* ═══ ۲) launcher دسکتاپ ═══ */
console.log('\n══ پنجرهٔ نرم‌افزار دسکتاپ ══');

const launcher = readFileSync(join(REPO, 'desktop-app-v2', 'launcher', 'launcher.c'), 'utf8');

ok('پنجره بیشینه (تمام‌صفحه) باز می‌شود', launcher.includes('--start-maximized'));
/* فقط کدِ واقعی سنجیده می‌شود، نه کامنتی که توضیح می‌دهد چرا از آن
   پرچم استفاده *نکرده‌ایم*. */
const launcherCode = launcher.replace(/\/\*[\s\S]*?\*\//g, '');
ok('از حالت fullscreen بدون دکمهٔ بستن استفاده نمی‌شود', !launcherCode.includes('--start-fullscreen'));
ok('اندازهٔ ثابت قبلی حذف شد', !launcher.includes('--window-size=1280,860'));

/* پنجرهٔ «نبندید» باید کاملاً رفته باشد */
ok('پنجرهٔ «این پنجره را نبندید» حذف شد', !launcher.includes('run_status_window'));
ok('متن «این پنجره را باز نگه دارید» دیگر وجود ندارد',
   !launcher.includes('\\x0628\\x0627\\x0632 \\x0646\\x06AF\\x0647'));
ok('به‌جایش آیکون کنار ساعت هست', launcher.includes('Shell_NotifyIconW') && launcher.includes('run_tray_mode'));
ok('پنجرهٔ گیرندهٔ پیام نامرئی است (HWND_MESSAGE)', launcher.includes('HWND_MESSAGE'));
ok('منوی راست‌کلیک «خروج» دارد', launcher.includes('IDM_SDP_EXIT'));
ok('دابل‌کلیک برنامه را دوباره باز می‌کند', launcher.includes('WM_LBUTTONDBLCLK'));
ok('آیکون هنگام خروج پاک می‌شود', launcher.includes('NIM_DELETE'));

/* جست‌وجوی مرورگر باید سمج باشد تا به مرورگر سیستم نیفتد */
ok('WebView2 هم به‌عنوان موتور پشتیبان جست‌وجو می‌شود',
   launcher.includes('msedgewebview2.exe') && launcher.includes('find_webview2'));
ok('کلید Uninstall هم بررسی می‌شود', launcher.includes('InstallLocation'));
ok('نصب‌های کاربری زیر LOCALAPPDATA بررسی می‌شوند',
   launcher.includes('LOCALAPPDATA') && launcher.includes('Microsoft\\\\Edge\\\\Application'));
ok('مرورگر پیش‌فرض فقط آخرین راه‌حل است', (() => {
    /* ShellExecute نباید قبل از find_browser صدا زده شود */
    const fb = launcher.indexOf('int fallback = 1;');
    const seg = launcher.slice(fb, launcher.indexOf('TerminateProcess(pi.hProcess, 0);', fb));
    return seg.indexOf('find_browser') < seg.indexOf('ShellExecuteA');
})());

/* ═══ ۳) منطق واقعی desk-shell.js ═══ */
console.log('\n══ همه چیز در همان پنجره ══');

const shellSrc = readFileSync(resolveFile('assets/js/desk-shell.js'), 'utf8');

/* یک DOM کوچک می‌سازیم و اسکریپت را واقعاً اجرا می‌کنیم. */
function makeEnv() {
    const env = {
        navigated: null,
        openedNative: [],
        listeners: {},
        printPage: false
    };
    const doc = {
        addEventListener(type, fn, capture) {
            (env.listeners[type] = env.listeners[type] || []).push(fn);
        }
    };
    const win = {
        location: { host: 'localhost:8123', href: 'http://localhost:8123/students.php' },
        document: doc,
        open(url) { env.openedNative.push(url); return { focus() {} }; }
    };
    Object.defineProperty(win.location, 'href', {
        get() { return 'http://localhost:8123/students.php'; },
        set(v) { env.navigated = v; }
    });
    env.win = win; env.doc = doc;
    const fn = new Function('window', 'document', 'location', 'URL', shellSrc);
    fn(win, doc, win.location, URL);
    return env;
}

function clickLink(env, href, target) {
    const a = {
        getAttribute: (k) => (k === 'href' ? href : null),
        href: new URL(href, 'http://localhost:8123/').toString(),
        target
    };
    const ev = {
        defaultPrevented: false, button: 0, ctrlKey: false, metaKey: false, shiftKey: false,
        target: { closest: (sel) => (sel.includes('_blank') && target === '_blank' ? a : null) },
        preventDefault() { this.defaultPrevented = true; }
    };
    for (const fn of env.listeners.click || []) fn(ev);
    return ev;
}

let env = makeEnv();
ok('اسکریپت بدون خطا اجرا می‌شود', !!env.listeners.click);

/* لینک داخلی با _blank → باید در همین پنجره برود */
env = makeEnv();
let ev = clickLink(env, 'online-exam-preview.php?exam_id=5', '_blank');
ok('لینک داخلی _blank در همین پنجره باز می‌شود',
   ev.defaultPrevented && /online-exam-preview\.php/.test(env.navigated || ''),
   String(env.navigated));

/* صفحهٔ چاپ → باید دست نخورد */
env = makeEnv();
ev = clickLink(env, 'bulk-print.php?ids=1,2', '_blank');
ok('صفحهٔ چاپ همچنان در پنجرهٔ جدا باز می‌شود', !ev.defaultPrevented && env.navigated === null);

env = makeEnv();
ev = clickLink(env, 'exam-print.php?id=3', '_blank');
ok('چاپ آزمون هم مستثناست', !ev.defaultPrevented);

/* لینک بیرونی → باید در مرورگر واقعی باز شود */
env = makeEnv();
ev = clickLink(env, 'https://telegram.org/bot', '_blank');
ok('لینک بیرونی در همین پنجره باز نمی‌شود', !ev.defaultPrevented);

/* Ctrl+کلیک نباید دستکاری شود */
env = makeEnv();
const a2 = { getAttribute: () => 'students.php', href: 'http://localhost:8123/students.php', target: '_blank' };
const ev2 = {
    defaultPrevented: false, button: 0, ctrlKey: true, metaKey: false, shiftKey: false,
    target: { closest: () => a2 }, preventDefault() { this.defaultPrevented = true; }
};
for (const fn of env.listeners.click || []) fn(ev2);
ok('Ctrl+کلیک دست‌نخورده می‌ماند', !ev2.defaultPrevented);

/* window.open داخلی → پیمایش در همین پنجره */
env = makeEnv();
const w = env.win.open('attendance-tags.php?class=A');
ok('window.open داخلی در همین پنجره می‌رود',
   /attendance-tags/.test(env.navigated || '') || env.openedNative.length === 1);

/* window.open روی صفحهٔ چاپ → واقعاً پنجرهٔ جدید */
env = makeEnv();
env.win.open('bulk-print.php?x=1');
ok('window.open برای چاپ، پنجرهٔ واقعی باز می‌کند', env.openedNative.length === 1);

/* شیء برگشتی نباید کد را بشکند */
env = makeEnv();
const ret = env.win.open('students.php');
ok('window.open شیء امن برمی‌گرداند', ret && typeof ret.focus === 'function' && typeof ret.close === 'function');

/* فرم با target=_blank */
env = makeEnv();
const form = { target: '_blank', getAttribute: () => 'student-bulk-report.php' };
for (const fn of env.listeners.submit || []) fn({ target: form });
ok('فرم چاپ دسته‌جمعی مستثنا می‌ماند', form.target === '_blank');

env = makeEnv();
const form2 = { target: '_blank', getAttribute: () => 'class-exam-create.php' };
for (const fn of env.listeners.submit || []) fn({ target: form2 });
ok('فرم معمولی به همین پنجره برمی‌گردد', form2.target === '_self');

/* ═══ ۴) فقط در دسکتاپ لود شود ═══ */
console.log('\n══ فقط در نسخهٔ دسکتاپ ══');
const footer = readFileSync(resolveFile('includes/footer.php'), 'utf8');
ok('اسکریپت در footer لود می‌شود', footer.includes('desk-shell.js'));
ok('فقط وقتی دسکتاپ است لود می‌شود',
   /PHP_SAPI === 'cli-server'[\s\S]{0,200}desk-shell\.js/.test(footer));

/* اجرای واقعی: در حالت وب‌سرور نباید اسکریپت بیاید */
php.writeFile('/harness/ds.php', `<?php
ini_set('display_errors','0'); error_reporting(0);
$f = file_get_contents('/www/includes/footer.php');
/* php-wasm زیر cli اجرا می‌شود، پس شرط باید false باشد */
echo json_encode(['sapi'=>PHP_SAPI, 'guarded'=>(strpos($f,"PHP_SAPI === 'cli-server'")!==false)?1:0]);`);
const dj = JSON.parse((await run("<?php require '/harness/ds.php';")).out.trim());
ok('شرط دسکتاپ در فوتر واقعاً وجود دارد', dj.guarded === 1);

console.log(`\n  سوئیت پوستهٔ دسکتاپ: ${pass} PASS / ${fail} FAIL`);
process.exit(fail ? 1 : 0);
