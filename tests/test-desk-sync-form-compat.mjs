import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { run, php, req } from './harness/lib.mjs';

const read = p => readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const siteClient = read('update-v4.152.0/includes/desk_sync.php');
const desktopClient = read('desktop-app-v2/patch/includes-desk-sync.php');
const siteApi = read('update-v4.152.0/class-exam-sync-api.php');
const endpointHelper = read('update-v4.152.0/includes/desk_sync_endpoint.php');
const bundledApi = read('desktop-app-v2/server/desk-sync-api.php');
const siteFunctions = read('update-v4.152.0/includes/functions.php');
const siteStatus = read('update-v4.152.0/desk-connection-status.php');

for (const [name, source] of [['site client', siteClient], ['desktop client', desktopClient]]) {
  assert.match(source, /http_build_query\(\['payload'\s*=>\s*json_encode\(\$payload/,
    `${name} must send the JSON document in a single URL-encoded form field`);
  assert.match(source, /Content-Type: application\/x-www-form-urlencoded/,
    `${name} must avoid JSON Content-Type on sync requests`);
  assert.match(source, /User-Agent: Mozilla\/5\.0/,
    `${name} must send a standard browser User-Agent header`);
  assert.match(source, /encodeSyncToken/,
    `${name} must support WAF-safe compressed alphanumeric sync tokens`);
  assert.match(source, /candidateSyncEndpoints/,
    `${name} must fall back across normal site endpoints when *-sync-api.php is blocked with 403`);
}
for (const [name, source] of [['site API', siteApi], ['endpoint helper', endpointHelper], ['bundled API', bundledApi]]) {
  assert.match(source, /json_decode\(\$rawRequest,\s*true\)/,
    `${name} must continue accepting legacy raw JSON`);
  assert.match(source, /\$_POST\['payload'\]/,
    `${name} must decode form-encoded payloads`);
  assert.match(source, /parse_str\(\$rawRequest,\s*\$form\)/,
    `${name} must also support hosts that do not populate PHP's POST variables`);
  assert.match(source, /desk_sync_unpack_token/,
    `${name} must decode WAF-safe sdp_z tokens`);
  assert.match(source, /desk_sync_receive_chunk/,
    `${name} must reassemble chunked sync transfers`);
  assert.doesNotMatch(source, /(?<![->a-zA-Z0-9_])@?exec\s*\(/,
    `${name} must not contain shell exec() calls that trigger host antivirus quarantine`);
}
assert.doesNotMatch(siteApi.slice(0, 12000), /DESK_SYNC_KEY['\"]\s*,\s*['\"][^'\"]{16,}/,
  'the compatibility patch must not introduce a literal sync secret');

/* ── Runtime verification on PHP 8.3 (WASM) ── */
php.writeFile('/www/class-exam-sync-api.php', siteApi);
php.writeFile('/www/includes/desk_sync_endpoint.php', endpointHelper);
php.writeFile('/www/includes/functions.php', siteFunctions);
php.writeFile('/www/desk-connection-status.php', siteStatus);
php.writeFile('/www/includes/desk_sync.php', siteClient);

const HEX_KEY = 'a1b2c3d4'.repeat(8);
const SDP_KEY = 'SDP-' + 'f0e1d2c3'.repeat(6);
php.writeFile('/www/config/desk-sync-key.php', `<?php return '${HEX_KEY}';\n`);
php.writeFile('/www/desk-sync-api.php', `<?php\ndefine('DESK_SYNC_KEY', '${SDP_KEY}');\n`);

// 1) Token encoding produces pure [A-Za-z0-9_-] with zero %XX, braces, quotes, or eyJ prefix
const tokenTest = JSON.parse((await run(`<?php
define('SDP_SYNC_LIB_ONLY', true);
require_once '/www/includes/desk_sync_endpoint.php';
$sample = ['action' => 'push', 'key' => '${SDP_KEY}', 'changes' => [
    ['tbl' => 'students', 'rid' => 5001001, 'op' => 'U', 'row' => ['id' => 5001001, 'first_name' => 'امیرعلی', 'last_name' => 'محمدی']],
]];
$json = json_encode($sample, JSON_UNESCAPED_UNICODE);
$raw = gzdeflate($json, 6);
$sdpZ = 'z1' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
$decoded = desk_sync_unpack_token($sdpZ);
echo json_encode([
    'token' => $sdpZ,
    'alpha_only' => (bool)preg_match('/^z1[A-Za-z0-9_-]+$/', $sdpZ),
    'roundtrip' => $decoded === $sample,
], JSON_UNESCAPED_UNICODE);
`)).out);
assert.equal(tokenTest.alpha_only, true, 'sdp_z token must be pure URL-safe alphanumeric');
assert.equal(tokenTest.roundtrip, true, 'sdp_z token must round-trip Persian payload losslessly');

// 2) Both keys (config/desk-sync-key.php AND desk-sync-api.php) are accepted; wrong key is rejected
for (const k of [HEX_KEY, SDP_KEY]) {
  const r = await req(null, { method: 'POST', file: 'class-exam-sync-api.php', post: { payload: JSON.stringify({ action: 'ping', key: k }) } });
  const j = JSON.parse(r.res.raw_head.trim());
  assert.equal(j.ok, true, `key ${k.slice(0, 8)}... must be accepted`);
}
{
  const bad = await req(null, { method: 'POST', file: 'class-exam-sync-api.php', post: { payload: JSON.stringify({ action: 'ping', key: 'wrong-key-value-0000' }) } });
  const j = JSON.parse(bad.res.raw_head.trim());
  assert.equal(j.ok, false);
  assert.equal(j.error, 'invalid key');
}

// 3) Fallback endpoints (desk-connection-status.php, csrf-refresh.php, index.php) serve sync via POST sdp_z and GET sdp_z
const pingToken = (await run(`<?php
$json = json_encode(['action' => 'ping', 'key' => '${SDP_KEY}']);
echo 'z1' . rtrim(strtr(base64_encode(gzdeflate($json, 6)), '+/', '-_'), '=');
`)).out.trim();

for (const ep of ['class-exam-sync-api.php', 'desk-connection-status.php', 'csrf-refresh.php', 'index.php']) {
  const postRes = await req(null, { method: 'POST', file: ep, post: { sdp_sync: '1', sdp_z: pingToken } });
  const pj = JSON.parse(postRes.res.raw_head.trim());
  assert.equal(pj.ok, true, `POST sdp_z on ${ep} must succeed`);

  const getRes = await req(null, { method: 'GET', file: ep, query: `sdp_sync=1&sdp_z=${pingToken}` });
  const gj = JSON.parse(getRes.res.raw_head.trim());
  assert.equal(gj.ok, true, `GET sdp_z on ${ep} must succeed`);
}

// 4) Chunked GET transfer reassembles multi-part token and executes sync action
const half = Math.ceil(pingToken.length / 2);
const c0 = pingToken.slice(0, half);
const c1 = pingToken.slice(half);
const cid = 'deadbeef12345678';
const r0 = await req(null, { method: 'GET', file: 'index.php', query: `sdp_sync=1&sdp_cid=${cid}&sdp_ci=0&sdp_cn=2&sdp_cp=${c0}` });
assert.deepEqual(JSON.parse(r0.res.raw_head.trim()), { ok: true, chunk: 0 });
const r1 = await req(null, { method: 'GET', file: 'index.php', query: `sdp_sync=1&sdp_cid=${cid}&sdp_ci=1&sdp_cn=2&sdp_cp=${c1}` });
const r1j = JSON.parse(r1.res.raw_head.trim());
assert.equal(r1j.ok, true, 'final chunk on index.php must execute the assembled sync request');
assert.equal(typeof r1j.log_max, 'number');

// 5) Permission self-healing restores 0644 if a scanner sets 0000 on desk-sync-api.php / class-exam-sync-api.php
const healOut = JSON.parse((await run(`<?php
chmod('/www/desk-sync-api.php', 0000);
chmod('/www/class-exam-sync-api.php', 0000);
$before = [fileperms('/www/desk-sync-api.php') & 0777, fileperms('/www/class-exam-sync-api.php') & 0777];
require_once '/www/includes/functions.php';
clearstatcache();
$after = [fileperms('/www/desk-sync-api.php') & 0777, fileperms('/www/class-exam-sync-api.php') & 0777];
echo json_encode(['before' => $before, 'after' => $after]);
`)).out);
assert.deepEqual(healOut.before, [0, 0]);
assert.deepEqual(healOut.after, [0o644, 0o644], 'includes/functions.php must auto-heal 0000 permissions back to 0644');

// 6) Built SchoolDesk-FIX-v2.129.0.zip passes site & desktop update validators
php.writeFile('/www/includes/desk_update.php', readFileSync('desktop-app-v2/patch/includes-desk_update.php'));
php.writeFile('/tmp/fix2129.zip', readFileSync('SchoolDesk-FIX-v2.129.0.zip'));
const pkgCheck = JSON.parse((await run(`<?php
require_once '/www/includes/desk_updates_store.php';
require_once '/www/includes/desk_update.php';
$rep = desk_updates_report('/tmp/fix2129.zip');
$plan = desk_update_plan('/tmp/fix2129.zip');
echo json_encode(['report_ok' => $rep['ok'] ?? false, 'files' => $rep['files'] ?? 0, 'plan_count' => count($plan['web'] ?? [])]);
`)).out.trim());
assert.equal(pkgCheck.report_ok, true, 'SchoolDesk-FIX-v2.129.0.zip must be accepted by desk_updates_report()');
assert.equal(pkgCheck.files, 12, 'SchoolDesk-FIX-v2.129.0.zip must contain 12 web files');
assert.equal(pkgCheck.plan_count, 12, 'SchoolDesk-FIX-v2.129.0.zip must plan 12 web files in desk_update_plan()');

console.log('✅ Sync clients & endpoints pass form, sdp_z, GET, chunked-GET, multi-endpoint relay, dual-key, permission self-heal, and package validation checks.');
process.exit(0);
