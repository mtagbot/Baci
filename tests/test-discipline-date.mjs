import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { REPO } from './harness/site.mjs';

const source=readFileSync(join(REPO,'update-v4.152.0/discipline-report-management.php'),'utf8');
const match=source.match(/function drm_age_label\(\$jalaliDate\)\s*\{[\s\S]*?\n\}/);
assert(match,'drm_age_label function found');
const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1}}));
php.mkdirTree('/www/includes');
php.writeFile('/www/includes/jdf.php',new Uint8Array(readFileSync(join(REPO,'upstream-reference/includes/jdf.php'))));
const code=`<?php
function jdate($format){return '1405/08/01';}
require '/www/includes/jdf.php';
${match[0]}
echo drm_age_label('پنجشنبه 1405/07/16')."\\n";
echo drm_age_label('تاریخ نامعتبر')."\\n";
`;
const result=await php.run({code});
assert.equal(result.errors,'');
assert.equal(result.text,'۱۵ روز پیش\nتاریخ نامشخص\n');
console.log('PASS attendance weekday-prefixed Jalali date → relative age');
process.exit(0);
