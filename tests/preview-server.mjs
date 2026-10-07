/**
 * سرور پیش‌نمایش — SchoolDesk Pro روی PHP واقعی
 *
 * چرا این فایل لازم است: در این محیط باینری PHP نصب نمی‌شود (مخازن Debian
 * بسته‌اند و sudo هم به dpkg نمی‌رسد). تنها PHP واقعیِ در دسترس، همان
 * php-wasm است که سوئیت‌های تست از آن استفاده می‌کنند. این سرور همان
 * runtime را بالا می‌آورد، وصلهٔ update-v4.152.0 را اورلی می‌کند، دیتابیس
 * SQLite را با دادهٔ آزمایشی پر می‌کند و هر درخواست .php را واقعاً اجرا
 * می‌کند. یعنی آنچه در پیش‌نمایش می‌بینید، خروجیِ کدِ واقعی است — نه یک
 * ماک‌آپ ایستا از CSS.
 *
 *   SITE=/tmp/baci-v11 PATCH=update-v4.152.0 PORT=8080 node tests/preview-server.mjs
 *
 * ── سه نکته که باید بدانید ──────────────────────────────────────────────
 *  ۱) php-wasm یک نمونهٔ تک‌نخی است، پس درخواست‌ها در یک صف سریال می‌شوند.
 *  ۲) در SAPI خط فرمان header() بی‌اثر است؛ هارنس عمداً یک فاصله echo می‌کند
 *     تا headers_sent() راست شود و redirect() به <script>window.location…</script>
 *     بیفتد. مرورگر واقعی آن را اجرا می‌کند، پس POST → redirect کار می‌کند.
 *  ۳) فایل‌های ایستا از resolveFile() می‌آیند، یعنی وصله بر سایتِ پایه اولویت
 *     دارد — همان مسیری که تست‌ها می‌سنجند.
 */
import { createServer } from 'node:http';
import { readFileSync, existsSync, statSync } from 'node:fs';
import { join, extname, normalize, resolve as resolvePath } from 'node:path';

import { php, run, loginAdmin } from './harness/lib.mjs';
import { resolveFile, REPO } from './harness/site.mjs';

const PORT = Number(process.env.PORT || 8080);
const SID = 'previewAdm0001';
const HOME = process.env.PREVIEW_HOME || 'students.php';

/* ═══════ نشست مدیر ═══════
   students.php و بقیهٔ صفحه‌های کادر مدرسه بدون admin_id به صفحهٔ ورود
   redirect می‌شوند. یک نشست ثابت برای کل پیش‌نمایش می‌سازیم. */
const loggedIn = await loginAdmin(SID);
if (loggedIn !== 'LOGIN_OK') throw new Error('ورود مدیر ناموفق بود: ' + loggedIn);
console.log('>>> نشست مدیر ساخته شد (' + SID + ')');

/* ═══════ دادهٔ پیش‌نمایش (فقط با PREVIEW_SEED=1) ═══════
   fixture خودِ هارنس یک دانش‌آموز با academic_year=NULL می‌سازد و
   students.php به‌طور پیش‌فرض بر پایهٔ get_setting('current_academic_year')
   فیلتر می‌کند، پس فهرست خالی می‌ماند و ردیف فیلتر بی‌داده دیده می‌شود.
   اینجا چند دانش‌آموز با همان سالِ تحصیلیِ تنظیمات اضافه می‌کنیم.
   این داده فقط در MEMFS همین فرایند است و به ریپو یا بسته‌ها نمی‌رسد. */
if (process.env.PREVIEW_SEED) {
  const seed = await run(`<?php
    @mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess');
    session_name('BACI_TEST'); session_id(${JSON.stringify(SID)}); session_start();
    require_once '/www/includes/functions.php';
    require_once '/www/includes/db.php';
    $year = get_setting('current_academic_year', '1404/1405');
    $rows = [
      ['آذری','بهرام','رضا','هفتم','۷۰۱'], ['احمدی','سینا','محمد','هفتم','۷۰۱'],
      ['اسدی','مهدی','علی','هفتم','۷۰۱'],   ['اکبری','پوریا','حسن','هفتم','۷۰۲'],
      ['امینی','دانیال','جواد','هفتم','۷۰۲'],
      ['بهرامی','آرش','کریم','هشتم','۸۰۱'], ['پورناد','سامان','ناصر','هشتم','۸۰۱'],
      ['تهرانی','نیما','فرهاد','هشتم','۸۰۱'], ['جمشیدی','کیان','بابک','هشتم','۸۰۲'],
      ['حسن‌زاده','مانی','سعید','هشتم','۸۰۲'],
      ['خدادادی','رهام','یعقوب','نهم','۹۰۱'], ['رادفر','آرمان','مجید','نهم','۹۰۱'],
      ['زارعی','پارسا','غلام','نهم','۹۰۱'], ['سلیمانی','بردیا','ابراهیم','نهم','۹۰۲'],
      ['شریفی','شایان','مصطفی','نهم','۹۰۲'], ['صادقی','میلاد','احسان','نهم','۹۰۲'],
      ['طاهری','کسری','حمید','نهم','۹۰۲'],  ['ظاهری','ایلیا','داوود','نهم','۹۰۲'],
    ];
    DB::execute("DELETE FROM students WHERE national_id LIKE '99900%'");
    $n = 0;
    foreach ($rows as $i => [$last, $first, $father, $grade, $class]) {
      DB::execute(
        "INSERT INTO students (national_id, first_name, last_name, father_name, grade_level, class_name, academic_year, status, is_temp)
         VALUES (?,?,?,?,?,?,?, 'active', 0)",
        ['99900' . str_pad((string)(1000 + $i), 5, '0', STR_PAD_LEFT), $first, $last, $father, $grade, $class, $year]
      );
      $n++;
    }
    $c = DB::fetch("SELECT COUNT(*) c FROM students WHERE academic_year=?", [$year]);
    echo 'SEED_OK n=' . $n . ' year=' . $year . ' total=' . $c['c'];
  `);
  const m = (seed.out.match(/SEED_OK n=(\d+) year=(\S+) total=(\d+)/) || []);
  if (!m.length) throw new Error('seed ناموفق بود:\n' + seed.out.slice(0, 800) + '\n' + seed.err.slice(0, 800));
  console.log(`>>> دادهٔ پیش‌نمایش: ${m[1]} دانش‌آموز در سال ${m[2]} (مجموع ${m[3]})`);
}

/* ═══════ صف سریال‌سازی ═══════ */
let tail = Promise.resolve();
const enqueue = (fn) => { const p = tail.then(fn, fn); tail = p.catch(() => {}); return p; };

/* ═══════ اجرای یک درخواست PHP ═══════ */
async function phpRequest({ file, query = '', method = 'GET', post = {} }) {
  php.writeFile('/harness/req.json', JSON.stringify({
    session_id: SID, file, query, method, post,
  }));
  await run("<?php require '/harness/run_request.php';");
  let page = '';
  try { page = php.readFileAsText('/harness/page.html'); } catch { page = ''; }
  let res = {};
  try { res = JSON.parse(php.readFileAsText('/harness/result.json')); } catch { /* بی‌نتیجه */ }
  return { page, res };
}

/* ═══════ فایل‌های ایستا ═══════ */
const MIME = {
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.gif': 'image/gif', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.woff': 'font/woff', '.woff2': 'font/woff2', '.ttf': 'font/ttf',
  '.ogg': 'audio/ogg', '.mp3': 'audio/mpeg', '.wav': 'audio/wav',
  '.pdf': 'application/pdf', '.txt': 'text/plain; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

/* ═══════ بدنهٔ POST ═══════ */
function readBody(req) {
  return new Promise((done) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => done(Buffer.concat(chunks)));
  });
}

const server = createServer(async (req, res) => {
  const t0 = Date.now();
  try {
    const u = new URL(req.url, 'http://localhost');
    let pathname = decodeURIComponent(u.pathname);
    if (pathname === '/' || pathname === '') {
      res.writeHead(302, { Location: '/' + HOME });
      res.end();
      return console.log(`→ 302 /  →  /${HOME}`);
    }

    /* مسیرِ امن: از ریشهٔ سایت بیرون نرود */
    const rel = normalize(pathname).replace(/^([/\\])+/, '');
    if (rel.startsWith('..')) { res.writeHead(403); return res.end('forbidden'); }

    /* ── درخواست PHP ── */
    if (extname(rel).toLowerCase() === '.php') {
      let post = {};
      if (req.method === 'POST') {
        const body = await readBody(req);
        const ct = (req.headers['content-type'] || '').toLowerCase();
        if (ct.includes('application/x-www-form-urlencoded')) {
          post = Object.fromEntries(new URLSearchParams(body.toString('utf8')));
        } else {
          res.writeHead(415, { 'Content-Type': 'text/plain; charset=utf-8' });
          res.end('پیش‌نمایش فقط فرم‌های urlencoded را پشتیبانی می‌کند (multipart نه).');
          return console.log(`→ 415 ${req.method} /${rel}`);
        }
      }
      const { page, res: info } = await enqueue(() =>
        phpRequest({ file: rel, query: u.search.replace(/^\?/, ''), method: req.method, post }));

      if (info.fatal) console.log(`   ⚠ PHP fatal: ${String(info.fatal).split('\n')[0]}`);
      const bytes = Buffer.byteLength(page);
      res.writeHead(200, {
        'Content-Type': 'text/html; charset=utf-8',
        'Content-Length': bytes,
        'Cache-Control': 'no-store',
      });
      res.end(page);
      return console.log(`→ 200 ${req.method} /${rel}${u.search}  ${bytes}b  ${Date.now() - t0}ms`);
    }

    /* ── فایل ایستا (با اولویتِ وصله) ── */
    const abs = resolveFile(rel);
    if (!existsSync(abs) || !statSync(abs).isFile()) {
      res.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
      res.end('404 — ' + rel);
      return console.log(`→ 404 /${rel}`);
    }
    /* بیرون نرفتن از سایت/وصله */
    const inPatch = process.env.PATCH ? resolvePath(join(REPO, process.env.PATCH)) : null;
    const inSite = resolvePath(resolveFile(''));
    if (!abs.startsWith(inSite) && !(inPatch && abs.startsWith(inPatch))) {
      res.writeHead(403); return res.end('forbidden');
    }
    const data = readFileSync(abs);
    res.writeHead(200, {
      'Content-Type': MIME[extname(abs).toLowerCase()] || 'application/octet-stream',
      'Content-Length': data.length,
      'Cache-Control': 'no-store',
    });
    res.end(data);
    console.log(`→ 200 static /${rel}  ${data.length}b`);
  } catch (e) {
    res.writeHead(500, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('خطای سرور پیش‌نمایش:\n' + (e && e.stack || e));
    console.log('→ 500 ' + (e && e.message));
  }
});

server.listen(PORT, '0.0.0.0', () => {
  console.log(`\n✅ پیش‌نمایش آماده است: http://0.0.0.0:${PORT}/  →  /${HOME}`);
  console.log('   صفحهٔ اصلی به «مدیریت دانش‌آموزان» می‌رود؛ از نوار کناری به بقیهٔ صفحه‌ها بروید.\n');
});
