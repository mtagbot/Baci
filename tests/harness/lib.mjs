/**
 * هارنس تست — بوت‌استرپ
 *
 * سایت واقعی SchoolDesk Pro را با php-wasm (PHP 8.3 + pdo_sqlite) در حافظه بالا
 * می‌آورد و امکان ارسال درخواست واقعی به هر صفحه/اکشن را می‌دهد.
 *
 * چرا این هارنس لازم است: در محیط توسعه باینری PHP وجود ندارد، پس تنها راه
 * «اجرای واقعی کد» همین است. ادعای رفع باگ بدون اجرای کد پذیرفته نیست.
 *
 * ── چهار نکتهٔ حیاتی که با آزمون و خطا به دست آمده (دست نزنید) ──────────
 *  ۱) ini_set / session_name / session_id / session_start باید «قبل از هر
 *     echo» اجرا شوند، وگرنه session_start() خود سایت خطا می‌دهد و هر صفحه
 *     به صفحهٔ ورود redirect می‌شود.
 *  ۲) echo " " بعد از session → headers_sent() راست می‌شود → redirect() به‌جای
 *     header() هدف را echo می‌کند. تنها راه گرفتن redirect در SAPI خط فرمان.
 *  ۳) شناسهٔ نشست فقط [A-Za-z0-9-,] — زیرخط (_) رد می‌شود.
 *  ۴) require_once برای includes/functions.php و includes/db.php؛ با require
 *     معمولی کلاس SQLitePDO دوباره declare می‌شود و fatal می‌دهد.
 */
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { readFileSync, readdirSync, existsSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { resolveSite, REPO } from './site.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));

/* ═══════ ۱) پیدا کردن سورس سایت ═══════ */
const SITE = resolveSite();
console.log(`>>> سورس سایت: ${relative(REPO, SITE) || SITE}`);

/* ═══════ ۲) راه‌اندازی php-wasm ═══════ */
export const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 1 } }));
php.mkdirTree('/www');
php.mkdirTree('/data');
php.mkdirTree('/harness');
php.mkdirTree('/tmp/sess');

const dirs = new Set(), files = [];
(function walk(d) {
  for (const e of readdirSync(d, { withFileTypes: true })) {
    const abs = join(d, e.name);
    const rel = '/www/' + relative(SITE, abs).split('\\').join('/');
    if (e.isDirectory()) { dirs.add(rel); walk(abs); } else files.push([abs, rel]);
  }
})(SITE);
for (const d of [...dirs].sort()) php.mkdirTree(d);
for (const [abs, rel] of files) php.writeFile(rel, new Uint8Array(readFileSync(abs)));
console.log(`>>> کپی شد: ${files.length} فایل، ${dirs.size} پوشه`);

/* فایل‌های خودِ هارنس */
for (const f of ['setup.php', 'run_request.php']) {
  php.writeFile('/harness/' + f, new Uint8Array(readFileSync(join(HERE, f))));
}

/* ═══════ ۳) پوشهٔ وصله (اختیاری) ═══════
   PATCH=update-v4.126.0  →  آن فایل‌ها روی سایت اورلی می‌شوند.
   این‌طور قبل از بسته‌بندی می‌شود وصله را تست کرد. */
if (process.env.PATCH) {
  const pd = join(REPO, process.env.PATCH);
  if (!existsSync(pd)) throw new Error(`پوشهٔ وصله پیدا نشد: ${process.env.PATCH}`);
  let n = 0;
  (function walk(d) {
    for (const e of readdirSync(d, { withFileTypes: true })) {
      const abs = join(d, e.name), rel = '/' + relative(pd, abs).split('\\').join('/');
      if (e.isDirectory()) { php.mkdirTree(rel); walk(abs); }
      else if (e.name.endsWith('.php')) { php.writeFile(rel, new Uint8Array(readFileSync(abs))); n++; }
    }
  })(pd);
  console.log(`>>> وصلهٔ ${process.env.PATCH} اورلی شد (${n} فایل PHP)`);
}

/* دیتابیس SQLite داخل MEMFS */
php.writeFile('/www/config/database.php',
  "<?php\nreturn ['driver' => 'sqlite', 'database' => '/data/school.sqlite'];\n");
php.writeFile('/www/config/installed.lock', 'harness');

/* ═══════ ۴) اجرا ═══════ */
export async function run(code) {
  const r = await php.runStream({ code });
  return { out: await r.stdoutText, err: await r.stderrText };
}

const setup = await run("<?php require '/harness/setup.php';");
if (/^(EXAM_ID)/m.test(setup.out) === false) {
  console.log('--- خروجی setup ---\n' + setup.out.slice(0, 2000));
  if (setup.err.trim()) console.log('--- stderr ---\n' + setup.err.slice(0, 2000));
  throw new Error('راه‌اندازی دادهٔ آزمایشی ناموفق بود');
}
export const examId = +(setup.out.match(/EXAM_ID=(\d+)/) || [])[1];
export const qmap = JSON.parse(setup.out.match(/QUESTIONS=(\{.*\})/)[1]);
export const student = JSON.parse(setup.out.match(/STUDENT=(\{.*\})/)[1]);
console.log(`>>> آزمون ${examId} · دانش‌آموز ${student.id} · ${Object.keys(qmap).length} نوع سوال`);

/* ═══════ ۵) ارسال درخواست ═══════ */
export async function req(label, spec) {
  php.writeFile('/harness/req.json', JSON.stringify({ session_id: spec.sid || 'harnessStu0001', ...spec }));
  const r = await run("<?php require '/harness/run_request.php';");
  const res = JSON.parse(php.readFileAsText('/harness/result.json'));
  res.redirect = (r.out.match(/window\.location\.href="([^"]+)"/) || [])[1] || null;
  if (label) {
    const flag = res.flash ? `FLASH[${res.flash.type}] ${res.flash.message}`
      : res.has_exam_ui ? `exam UI (${res.q_boxes} box, ${res.radio_inputs} radio)`
      : res.redirect ? `redirect → ${res.redirect}`
      : `rendered ${res.output_len}b`;
    console.log(`\n[${label}] ${spec.method || 'GET'} ${spec.file}?${spec.query || ''}\n   ${flag}`);
    if (res.fatal) console.log('   PHP: ' + res.fatal.trim().split('\n').slice(0, 3).join(' | '));
    if (r.err.trim()) console.log('   stderr: ' + r.err.trim().split('\n').slice(0, 3).join(' | '));
  }
  return { res, stdout: r.out, stderr: r.err };
}

/* اجرای کوئری روی دیتابیس و گرفتن نتیجه به شکل JSON */
export async function db(sql) {
  php.writeFile('/harness/dbq.php', `<?php ini_set('display_errors','0'); error_reporting(0);
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST');
session_id('harnessStu0001'); session_start();
require_once '/www/includes/functions.php'; require_once '/www/includes/db.php';
file_put_contents('/harness/db.json', json_encode(DB::fetchAll(${JSON.stringify(sql)}), JSON_UNESCAPED_UNICODE));`);
  await run("<?php require '/harness/dbq.php';");
  return JSON.parse(php.readFileAsText('/harness/db.json'));
}

/* فراخوانی online-exam-api.php و گرفتن JSON پاسخ */
export async function api(label, post) {
  php.writeFile('/harness/req.json', JSON.stringify({
    session_id: 'harnessStu0001', method: 'POST', file: 'online-exam-api.php', query: '', post,
  }));
  await run("<?php require '/harness/run_request.php';");
  const res = JSON.parse(php.readFileAsText('/harness/result.json'));
  let j = null; try { j = JSON.parse((res.raw_head || '').trim()); } catch (e) {}
  if (label) console.log(`     · ${label} → ok=${j ? j.ok : '?'} ${j && j.msg ? '(' + j.msg + ')' : ''}`);
  return j;
}

/* ورود دانش‌آموز با شناسهٔ نشست دلخواه */
export async function login(sid = 'harnessStu0001', studentId = student.id) {
  php.writeFile('/harness/login.php', `<?php @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
session_name('BACI_TEST'); session_id(${JSON.stringify(sid)}); session_start();
$_SESSION['student_id']=${Number(studentId)}; echo 'LOGIN_OK';`);
  return (await run("<?php require '/harness/login.php';")).out.trim();
}
await login();
