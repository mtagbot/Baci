import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
import { REPO } from './harness/site.mjs';

const source=readFileSync(join(REPO,'update-v4.152.0/discipline-report-management.php'),'utf8');
const match=source.match(/function drm_age_label\(\$jalaliDate\)\s*\{[\s\S]*?\n\}/);
const sortMatch=source.match(/function drm_jalali_sort_key\(\$jalaliDate\)\s*\{[\s\S]*?\n\}/);
assert(match&&sortMatch,'discipline date helpers found');
const php=new PHP(await loadNodeRuntime('8.3',{emscriptenOptions:{processId:1}}));
php.mkdirTree('/www/includes');
php.writeFile('/www/includes/jdf.php',new Uint8Array(readFileSync(join(REPO,'upstream-reference/includes/jdf.php'))));
const code=`<?php
function jdate($format){return '1405/08/01';}
require '/www/includes/jdf.php';
${sortMatch[0]}
${match[0]}
echo drm_age_label('پنجشنبه 1405/07/16')."\\n";
echo drm_age_label('تاریخ نامعتبر')."\\n";
$dates=['پنجشنبه 1405/07/16','شنبه 1405/08/01'];
usort($dates,function($a,$b){return strcmp(drm_jalali_sort_key($b),drm_jalali_sort_key($a));});
echo implode('|',$dates)."\\n";
`;
const result=await php.run({code});
assert.equal(result.errors,'');
assert.equal(result.text,'۱۵ روز پیش\nتاریخ نامشخص\nشنبه 1405/08/01|پنجشنبه 1405/07/16\n');
console.log('PASS attendance weekday-prefixed Jalali date, age, and newest-first ordering');
process.exit(0);
