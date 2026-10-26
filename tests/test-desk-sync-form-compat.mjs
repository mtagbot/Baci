import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const read = p => readFileSync(new URL(`../${p}`, import.meta.url), 'utf8');
const siteClient = read('update-v4.152.0/includes/desk_sync.php');
const desktopClient = read('desktop-app-v2/patch/includes-desk-sync.php');
const siteApi = read('update-v4.152.0/class-exam-sync-api.php');
const bundledApi = read('desktop-app-v2/server/desk-sync-api.php');

for (const [name, source] of [['site client', siteClient], ['desktop client', desktopClient]]) {
  assert.match(source, /http_build_query\(\['payload'\s*=>\s*json_encode\(\$payload/,
    `${name} must send the JSON document in a single URL-encoded form field`);
  assert.match(source, /Content-Type: application\/x-www-form-urlencoded/,
    `${name} must avoid JSON Content-Type on sync requests`);
}
for (const [name, source] of [['site API', siteApi], ['bundled API', bundledApi]]) {
  assert.match(source, /json_decode\(\$rawRequest,\s*true\)/,
    `${name} must continue accepting legacy raw JSON`);
  assert.match(source, /\$_POST\['payload'\]/,
    `${name} must decode form-encoded payloads`);
  assert.match(source, /parse_str\(\$rawRequest,\s*\$form\)/,
    `${name} must also support hosts that do not populate PHP's POST variables`);
}
assert.doesNotMatch(siteApi.slice(0, 12000), /DESK_SYNC_KEY['\"]\s*,\s*['\"][^'\"]{16,}/,
  'the compatibility patch must not introduce a literal sync secret');
console.log('✅ Sync clients use form-encoded transport; both APIs accept it and retain JSON compatibility.');
