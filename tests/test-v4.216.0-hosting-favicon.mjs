import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const header = read('update-v4.216.0/includes/header.php');
const doctor = read('update-v4.216.0/doctor.php');
const perf = read('update-v4.216.0/includes/perf.php');
const uploads = read('update-v4.216.0/uploads/.htaccess');
const backups = read('update-v4.216.0/backups/.htaccess');
const version = read('update-v4.216.0/config/version.php');

// Saved favicon setting is now rendered, cache-busted, local-only, and safe for subdirectory installs.
assert.match(header, /get_setting\('favicon_url'/);
assert.match(header, /<link rel="icon"/);
assert.match(header, /filemtime\(\$faviconFs\)/);
assert.match(header, /realpath\(\$appFsRoot/);
assert.ok(header.includes("strpos($faviconInput, '..') === false")); // path traversal is rejected
assert.ok(header.includes('strpos($faviconInput, chr(0)) === false')); // NUL is rejected
assert.match(header, /\$appUrlBase/);
assert.match(header, /\['ico'=>'image\/x-icon','png'=>'image\/png'/);

// Site diagnostics must not demand SQLite and must distinguish web diagnostics from local desktop diagnostics.
assert.match(doctor, /\$distribution === 'desktop' \? 'pdo_sqlite' : 'pdo_mysql'/);
assert.match(doctor, /if \(!in_array\(\$distribution, \['site', 'desktop'\], true\)\) \$distribution = 'site'/);
assert.match(doctor, /غایب — اختیاری برای این توزیع/);
assert.match(doctor, /همین میزبان اجرا می‌شود/);
assert.match(doctor, /desk-doctor\.php/);
assert.match(doctor, /cPanel، DirectAdmin و LiteSpeed/);
assert.match(doctor, /فایل اصلی <code>\.htaccess<\/code> را تغییرنام ندهید/);
assert.match(doctor, /htUnconditional/);

// Generated protections keep deny rules without incompatible Options directives; old generated files are migrated narrowly.
assert.doesNotMatch(uploads, /^\s*Options\s/m);
assert.doesNotMatch(backups, /^\s*Options\s/m);
assert.match(uploads, /RemoveHandler/);
assert.match(backups, /Require all denied/);
assert.match(perf, /Require all denied/);
assert.match(perf, /immutable/);
assert.match(perf, /index\.html/);
assert.match(perf, /Options\\s\+-Indexes/); // migration removes only this legacy generated directive
assert.match(version, /4\.216\.0/);

console.log('V4.216.0 hosting and favicon checks passed.');
