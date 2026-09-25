// Real PHP/SQLite: «کاتالوگ بانک — بارگذاری کش‌شده» — the v4.160.1 catalog
// speed work, driven through the REAL exam-design-api.php on php-wasm.
//
// What is proven here (execution, not claims):
//   1) catalog=bank returns the same shape as before (bank/designBank/totals/filters)
//   2) the source-deduplication fingerprint still works (identical first page ⇒ one entry)
//   3) the persistent fingerprint + scan caches are actually written
//   4) a cached response is served while the data version is unchanged (a page
//      swap alone does NOT change the catalog), and
//   5) any DB write (save/delete) bumps the data version and re-runs the scan.
//   6) thumbs come back parallel to pages; with GD they are real lightweight files.
import assert from 'node:assert/strict';
import {php,run,db,loginAdmin} from './harness/lib.mjs';

const sid='testCatalogSpeed';
await loginAdmin(sid);
let checks=0;
const check=(v,m)=>{assert(v,m);checks++};

/* ── fixture: two saved exams with page images + a duplicate-source exam ─── */
const jpeg = async (rel, w, h, seed) => {
  // Try a REAL jpeg through GD; otherwise fall back to deterministic bytes
  // (the fingerprint is an md5 either way).
  const made = await run(`<?php
if (function_exists('imagejpeg')) {
  @mkdir(dirname('${rel}'), 0775, true);
  $im = imagecreatetruecolor(${w}, ${h});
  $c = imagecolorallocate($im, ${seed % 255}, ${(seed * 7) % 255}, ${(seed * 13) % 255});
  imagefilledrectangle($im, 0, 0, ${w}, ${h}, $c);
  imagejpeg($im, '${rel}', 80);
  echo 'JPEG';
} else { echo 'NOGD'; }`);
  if (!made.out.includes('JPEG')) {
    php.mkdirTree(rel.split('/').slice(0, -1).join('/'));
    php.writeFile(rel, new Uint8Array(Array.from({ length: 256 }, (_, i) => (seed + i) % 256)));
  }
};

const setup = await run(`<?php require_once '/www/includes/functions.php';
require_once '/www/includes/exams_helper.php';
set_setting('current_academic_year','1404/1405');
DB::execute("DELETE FROM exam_question_bank");
DB::execute("DELETE FROM exam_designs WHERE exam_id IN (5501,5502,5503)");
DB::execute("DELETE FROM exam_schedules WHERE id IN (5501,5502,5503)");
foreach ([[5501,'ریاضی'],[5502,'ریاضی'],[5503,'ریاضی']] as $e)
    DB::execute("INSERT INTO exam_schedules (id,academic_year,exam_month,grade_level,class_name,subject_name,exam_kind,is_active,exam_date_jalali,start_time,created_at_jalali) VALUES (?, '1404/1405','مهر','هفتم','هفتم1',?,'official',1,'1404/07/10','08:00','1404/07/01')", [$e[0], $e[1]]);
DB::execute("INSERT INTO exam_designs (exam_id,design_json,designer_name,updated_at_jalali) VALUES (5501,'{\\"questions\\":[],\\"sourceCrops\\":{\\"1\\":{\\"t\\":5}},\\"sourceOrder\\":[1]}','مدیر','1404/07/01 10:00:00')");
DB::execute("INSERT INTO exam_designs (exam_id,design_json,designer_name,updated_at_jalali) VALUES (5502,'{\\"sourceCrops\\":{},\\"sourceOrder\\":[]}','مدیر','1404/07/01 10:00:00')");
DB::execute("INSERT INTO exam_designs (exam_id,design_json,designer_name,updated_at_jalali) VALUES (5503,'{\\"sourceCrops\\":{},\\"sourceOrder\\":[]}','مدیر','1404/07/01 10:00:00')");
foreach ([[1,'۲'],[2,'1'],[3,'']] as $q)
    DB::execute("INSERT INTO exam_question_bank (id,subject_name,question_html,score,question_type,created_at_jalali) VALUES (?,'ریاضی',?,?,'text','1404/07/01')", [$q[0], '<p>سوال '.$q[0].'</p>', $q[1]]);
DB::execute("INSERT INTO exam_question_bank (id,subject_name,question_html,score,question_type,created_at_jalali) VALUES (4,'علوم','<p>سوال علوم</p>','1','text','1404/07/01')");
echo 'FIXTURE_OK';`);
check(setup.out.includes('FIXTURE_OK'), setup.out + setup.err);

await jpeg('/www/uploads/exams/pdf-pages/exam_5502/page_1.jpg', 60, 80, 11);
await jpeg('/www/uploads/exams/pdf-pages/exam_5502/page_2.jpg', 60, 80, 22);
// exam 5503 gets byte-identical pages (same first-page md5 AND same page count)
// ⇒ the legacy deduplication fingerprint must drop it
await jpeg('/www/uploads/exams/pdf-pages/exam_5503/page_1.jpg', 60, 80, 11);
await jpeg('/www/uploads/exams/pdf-pages/exam_5503/page_2.jpg', 60, 80, 22);

const api = async (query) => {
  const getLiteral = JSON.stringify(JSON.stringify({ action: 'load', exam_id: '5501', dt: '', ...query }));
  const r = await run(`<?php
@mkdir('/tmp/sess'); ini_set('session.save_path','/tmp/sess'); session_name('BACI_TEST'); session_id('${sid}'); session_start();
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/exam-design-api.php'; $_SERVER['HTTP_HOST']='localhost';
$_GET=json_decode(${getLiteral},true);
require '/www/exam-design-api.php';`);
  const m = r.out.match(/\{[\s\S]*\}/);
  let json = null; try { json = m ? JSON.parse(m[0]) : null; } catch (e) {}
  return { json, raw: r.out, err: r.err };
};

/* ── 1) first catalog=bank call: full shape, dedup, filters ─────────────── */
for (const f of ['/www/uploads/exams/.catalog-scan-cache.json', '/www/uploads/exams/.catalog-signatures.json']) {
  try { php.unlink(f); } catch (e) {}
}
const first = await api({ catalog: 'bank' });
check(first.json && first.json.ok === true, 'catalog=bank answers ok: ' + first.raw.slice(0, 200) + first.err.slice(0, 200));
check(first.json.bankTotal === 3, 'only same-subject bank questions are counted (علوم filtered): ' + first.json.bankTotal);
check(Array.isArray(first.json.bank) && first.json.bank.length === 3, 'page carries the hydrated bank rows with question_html');
check(first.json.bank.every(b => typeof b.question_html === 'string' && b.question_html.includes('سوال')), 'bank bodies are hydrated for the visible page');
check(first.json.designBankTotal === 1, 'duplicate-source exam 5503 is deduplicated by the first-page fingerprint: ' + first.json.designBankTotal);
const item = (first.json.designBank || [])[0] || {};
check(item.exam_id === 5502, 'the surviving saved exam is the oldest source (5502)');
check(Array.isArray(item.pages) && item.pages.length === 2, 'both source pages are listed');
check(item.pages.every(p => p.includes('page_') && p.includes('?v=')), 'page URLs stay versioned');
check(Array.isArray(item.thumbs) && item.thumbs.length === item.pages.length, 'thumbs array is parallel to pages');
check(item.crops !== undefined && item.order !== undefined, 'crops/order keep flowing from the saved design');
check((first.json.filters?.years || []).includes('1404/1405'), 'filter options are part of the response');

/* ── 2) the caches were really written ───────────────────────────────────── */
const scanCache = php.readFileAsText('/www/uploads/exams/.catalog-scan-cache.json');
check(scanCache.includes('"version"') && scanCache.includes('"designCandidates"'), 'the scan cache file holds the computed catalog');
const sigCache = JSON.parse(php.readFileAsText('/www/uploads/exams/.catalog-signatures.json'));
const sigKey = Object.keys(sigCache).find(k => k.includes('exam_5502/page_1.jpg'));
check(sigKey && /^[a-f0-9]{32}$/.test(sigCache[sigKey].h), 'the fingerprint cache stores a real md5 for the first page');

/* ── 3) a page swap alone does NOT change a cached catalog (same version) ── */
await jpeg('/www/uploads/exams/pdf-pages/exam_5502/page_1.jpg', 60, 80, 99); // new content, no DB write
// like any real regeneration the swap carries a fresh mtime (the whole site
// already versions page URLs by filemtime); the wasm FS clock is coarse, so
// the test sets it explicitly instead of racing the second boundary.
await run(`<?php touch('/www/uploads/exams/pdf-pages/exam_5502/page_1.jpg', time()+10); echo 'TOUCHED';`);
const cached = await api({ catalog: 'bank' });
check(cached.json.ok === true && cached.json.designBankTotal === 1, 'while the data version is unchanged the cached scan is served (still deduplicated)');

/* ── 4) any DB write invalidates the cache and the scan re-runs ──────────── */
const bump = await run(`<?php require_once '/www/includes/functions.php';
DB::execute("UPDATE exam_designs SET updated_at_jalali='1404/07/02 09:00:00' WHERE exam_id=5502");
echo 'BUMP_OK';`);
check(bump.out.includes('BUMP_OK'), 'the data-version bump fixture ran: ' + bump.out + bump.err);
const fresh = await api({ catalog: 'bank' });
check(fresh.json.ok === true && fresh.json.designBankTotal === 2, 'after a save (data-version bump) the scan re-runs and the new fingerprint splits the duplicate: ' + fresh.json.designBankTotal);

/* ── 5) GD thumbs (when the runtime has GD) ──────────────────────────────── */
const gd = await run(`<?php echo function_exists('imagejpeg') ? 'GD' : 'NOGD';`);
if (gd.out.includes('GD')) {
  const t = (fresh.json.designBank.find(x => x.exam_id === 5502) || {}).thumbs || [];
  check(t.length === 2 && t.every(x => x.startsWith('uploads/exams/page-thumbs/')), 'with GD the thumbs are the lightweight central files: ' + JSON.stringify(t));
  const firstThumb = '/www/' + t[0].split('?')[0];
  const thumbInfo = await run(`<?php $s=@getimagesize('${firstThumb}'); echo $s ? $s[0].'x'.$s[1] : 'BAD';`);
  check(/^\d+x\d+$/.test(thumbInfo.out.trim()), 'the generated thumb is a real image (' + thumbInfo.out.trim() + ')');
} else {
  const t = (fresh.json.designBank.find(x => x.exam_id === 5502) || {}).thumbs || [];
  check(t.length === 2 && t.every(x => x === ''), 'without GD the API degrades to empty thumbs (client falls back to full pages)');
}

/* ── 6) paging slices the cached scan ────────────────────────────────────── */
const page2 = await api({ catalog: 'bank', bank_page: '1', design_page: '1' });
check(page2.json.ok === true && page2.json.designBankPage === 1, 'page parameters are echoed back');

/* ── 7) legacy clients keep the original complete shape ──────────────────── */
const legacy = await api({});
check(legacy.json && legacy.json.ok === true && Array.isArray(legacy.json.bank) && Array.isArray(legacy.json.designBank) && 'design' in legacy.json,
      'the legacy load (no catalog param) still returns design+bank+designBank');
check(legacy.json.bank.every(b => 'question_html' in b), 'legacy rows still carry their bodies');

/* ── 8) catalog=design stays a single-design fast path ───────────────────── */
const designOnly = await api({ catalog: 'design' });
check(designOnly.json && designOnly.json.ok === true && !('bank' in designOnly.json) && Array.isArray(designOnly.json.design?.questions),
      'catalog=design returns only the current design');

/* ── 9) v4.163.0: the catalog can arrive in two independent halves ───────── */
const partQ = await api({ catalog: 'bank', part: 'q' });
check(partQ.json && partQ.json.ok === true, 'part=q answers ok: ' + partQ.raw.slice(0, 120) + partQ.err.slice(0, 120));
check(partQ.json.bankTotal === 3 && Array.isArray(partQ.json.bank) && partQ.json.bank.length === 3, 'part=q carries the bank questions');
check((partQ.json.filters?.years || []).includes('1404/1405'), 'part=q also carries the filter options (pure SQL)');
check(!('designBank' in partQ.json), 'part=q skips the disk-heavy design half entirely');
const partD = await api({ catalog: 'bank', part: 'd' });
check(partD.json && partD.json.ok === true && partD.json.designBankTotal === 2, 'part=d carries only the saved designs: ' + partD.json.designBankTotal);
check(!('bank' in partD.json) && !('filters' in partD.json), 'part=d omits the question half entirely');
const badPart = await api({ catalog: 'bank', part: 'x' });
check(badPart.json && badPart.json.ok === false, 'an unknown part value is rejected');

console.log(`PASS ${checks} catalog speed cases (cached scan, fingerprints, invalidation, thumbs, legacy shape)`);
process.exit(0);
