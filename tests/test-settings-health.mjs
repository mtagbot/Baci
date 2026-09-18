import assert from 'node:assert/strict';
import {readFileSync,writeFileSync,mkdirSync} from 'node:fs';
import {join} from 'node:path';
import {run,req,php,db,loginAdmin} from './harness/lib.mjs';
import {REPO} from './harness/site.mjs';
let checks=0;const check=(x,m)=>{assert(x,m);checks++;};
const desktop=!!process.env.HEALTH_DESKTOP,dir=join(REPO,'.cache/settings-health',desktop?'desktop-fixtures':'fixtures');mkdirSync(dir,{recursive:true});
const sid='healthAdmin',token='health-csrf';
async function code(s){const r=await run(s);assert(!r.err,r.err);assert(!r.out.includes('Fatal error'),r.out);return r.out.trim();}
await loginAdmin(sid);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();$_SESSION['csrf_token']='${token}';require '/www/includes/functions.php';set_setting('header_tiles','sync,dbhealth,admins,othersets');
DB::execute("INSERT INTO admins (id,username,password,name,role,permissions,status) VALUES (7001,'healthlimited','hash','مدیر محدود','edu_admin','[\\\"system_settings\\\"]',1)");`);
await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('healthLimited');session_start();$_SESSION=['admin_id'=>7001,'admin_role'=>'edu_admin','csrf_token'=>'${token}'];`);
async function request(file,{query='',post,session=sid}={}){const {res,stderr}=await req('',{file,query,post:post||{},method:post?'POST':'GET',sid:session});check(!res.fatal,res.fatal);assert(!stderr,stderr);return res;}
for(const [tab,path] of [['sync','desk-sync.php'],['health','db-optimizer.php'],['admins','admins.php']]){
 const r=await request('other-settings.php',{query:'hub_tab='+tab});check(r.page.includes('src="'+path+'?embedded=1"'),'Selected '+tab);
 check((r.page.match(/class="hub-tab/g)||[]).length===7,'Seven tabs');
 for(const p of ['desk-sync.php','db-optimizer.php','admins.php'])check(!(r.page.match(/<(?:aside|nav)\b[\s\S]*?<\/(?:aside|nav)>/g)||[]).join('').includes('href="'+p+'"'),'No standalone menu '+p);
 writeFileSync(join(dir,'parent-'+tab+'.html'),r.page);
 const child=await request(path,{query:'embedded=1'});check(!child.page.includes('در نسخهٔ مبنا موجود نیست'),'Real '+tab);
 // Never put real or generated sync keys in published visual evidence.
 const html=child.page.replace(/[a-f0-9]{64}/g,'FIXTURE-NOT-A-REAL-KEY');writeFileSync(join(dir,tab+'.html'),html);
}
const limited=await request('other-settings.php',{query:'hub_tab=admins',session:'healthLimited'});
check(!limited.page.includes('data-hub-key="admins"'),'No super-admin tab for limited admin');check(limited.page.includes('data-hub-key="health"'),'Health permission retained');
check(limited.page.includes('data-hub-key="sync"')===desktop,'Distribution-specific sync permission');
for(const session of ['healthGuest','harnessStu0001'])check((await request('other-settings.php',{session})).redirect,'Unauth parent denied');
const tiles=JSON.parse(await code(`<?php ini_set('session.save_path','/tmp/sess');session_name('BACI_TEST');session_id('${sid}');session_start();require '/www/includes/auth.php';require '/www/includes/header_tiles.php';echo json_encode([array_keys(header_tiles_catalog()),header_tiles_selected(),header_tiles_sanitize_post(['sync','dbhealth','admins'])]);`));
check(['sync','dbhealth','admins'].every(k=>!tiles[0].includes(k)),'Removed quick-tile catalog entries');assert.deepEqual(tiles[1],['othersets']);check(tiles[2]==='othersets','Old selection migrates without duplicate settings tiles');
// Trace real queries without replacing their execution. Default view must not COUNT every table.
const dbSource=php.readFileAsText('/www/includes/db.php');php.writeFile('/www/includes/db.php',dbSource.replaceAll('$sql = SQLiteCompat::rewrite($sql);',"file_put_contents('/tmp/health-sql.log',$sql.\"\\n\",FILE_APPEND); $sql = SQLiteCompat::rewrite($sql);"));
php.writeFile('/tmp/health-sql.log','');
const before=await db('SELECT * FROM students ORDER BY id');
const quick=await request('db-optimizer.php',{query:'embedded=1'});
check(!quick.page.includes('ناهماهنگی موتور/کلیشن')&&!quick.page.includes('جداول غیر InnoDB'),'SQLite false warning eliminated');check(quick.page.includes('اسکن نشده'),'Unknown counts are not reported as zero');
const quickCounts=(php.readFileAsText('/tmp/health-sql.log').match(/SELECT COUNT\(\*\) FROM /g)||[]).length;check(quickCounts===0,'No full table counts on default view');
php.writeFile('/tmp/health-sql.log','');
const detail=await request('db-optimizer.php',{query:'embedded=1&scan=1'});const fullCounts=(php.readFileAsText('/tmp/health-sql.log').match(/SELECT COUNT\(\*\) FROM /g)||[]).length;
check(fullCounts>10,'Detailed scan counts real tables');check(detail.page.includes('سالم در این بررسی'),'SQLite quick_check executed');assert.deepEqual(await db('SELECT * FROM students ORDER BY id'),before);checks++;
writeFileSync(join(dir,'health-detail.html'),detail.page);
const indexes=()=>db("SELECT name,tbl_name,sql FROM sqlite_master WHERE type='index' ORDER BY name");
const beforeIndexes=await indexes();await request('db-optimizer.php',{post:{csrf_token:'bad',do:'apply_indexes'}});assert.deepEqual(await indexes(),beforeIndexes);checks++;
await request('db-optimizer.php',{session:'healthGuest',post:{csrf_token:token,do:'apply_indexes'}});assert.deepEqual(await indexes(),beforeIndexes);checks++;
await request('db-optimizer.php',{query:'do=apply_indexes'});assert.deepEqual(await indexes(),beforeIndexes);checks++;
// Recreate the legacy name collision; remove only the fixture indexes covering year/class.
await code(`<?php require '/www/includes/functions.php';require '/www/includes/db_health.php';$p=DB::getInstance()->getPdo();foreach(['students','reports'] as $t)foreach(dbh_index_metadata($p,true,$t) as $name=>$cols)if(array_slice($cols,0,2)===['academic_year','class_name'])$p->exec('DROP INDEX '.dbh_quote($name,true));$p->exec('CREATE INDEX IF NOT EXISTS idx_year_class ON students (academic_year,class_name)');`);
const applied=await request('db-optimizer.php',{query:'embedded=1',post:{csrf_token:token,do:'apply_indexes'}});check(applied.flash?.type==='success',JSON.stringify(applied.flash));
const coverage=JSON.parse(await code(`<?php require '/www/includes/functions.php';require '/www/includes/db_health.php';$p=DB::getInstance()->getPdo();echo json_encode([dbh_index_covers(dbh_index_metadata($p,true,'students'),['academic_year','class_name']),dbh_index_covers(dbh_index_metadata($p,true,'reports'),['academic_year','class_name'])]);`));check(coverage.every(Boolean),'Both tables have actual index coverage');
const appliedIndexes=await indexes();await request('db-optimizer.php',{post:{csrf_token:token,do:'apply_indexes'}});assert.deepEqual(await indexes(),appliedIndexes);checks++;
const after=await request('db-optimizer.php',{query:'embedded=1'});check(after.page.includes('همهٔ ایندکس‌های حیاتی برقرار هستند'),'Equivalent indexes recognized');
const helper=JSON.parse(await code(`<?php require '/www/includes/db_health.php';echo json_encode([
dbh_engine_warnings('sqlite',[['name'=>'a','engine'=>'SQLite','collation'=>'—']]),
dbh_engine_warnings('mysql',[['name'=>'good','engine'=>'InnoDB','collation'=>'UTF8MB4_UNICODE_CI'],['name'=>'old','engine'=>'MyISAM','collation'=>'utf8_general_ci'],['name'=>'unknown','engine'=>null,'collation'=>null]]),
dbh_index_covers(['other'=>['a','b','c']],['a','b']),dbh_index_covers(['wrong'=>['b','a']],['a','b']),dbh_index_covers(['prefix'=>[null,'b']],['a','b']),
dbh_maintenance_ok([['Msg_type'=>'error','Msg_text'=>'failed']]),dbh_maintenance_ok([['Msg_type'=>'status','Msg_text'=>'OK']])]);`));
check(!helper[0].engine.length&&!helper[0].collation.length,'SQLite classifier');check(helper[1].engine[0].name==='old'&&helper[1].engine.length===1,'True MyISAM warning retained');check(helper[1].collation[0].name==='old'&&helper[1].unknown[0].name==='unknown','Collation and unknown are distinct');check(helper[2]&&!helper[3]&&!helper[4],'Column coverage semantics');check(!helper[5]&&helper[6],'MySQL returned errors not reported successful');
const safety=JSON.parse(await code(`<?php require '/www/includes/functions.php';require '/www/includes/db_health.php';$p=DB::getInstance()->getPdo();DB::execute('CREATE TABLE health_fixture_refs (student_id INTEGER)');DB::execute('INSERT INTO health_fixture_refs VALUES (1)');$linked=dbh_has_references($p,true,'students',1);$unlinked=dbh_has_references($p,true,'students',987654);echo json_encode([$linked,$unlinked]);`));check(safety[0]&&!safety[1],'Additional student dependencies protected');
// Only identical, unreferenced duplicate classes may be removed; keep linked/different definitions.
await code(`<?php require '/www/includes/functions.php';foreach(['هفتم','هفتم','هفتم','هشتم'] as $grade)DB::execute("INSERT INTO classes (name,grade,academic_year) VALUES ('HealthDup',?,'1404/1405')",[$grade]);$rows=DB::fetchAll("SELECT id FROM classes WHERE name='HealthDup' ORDER BY id");DB::execute('CREATE TABLE health_class_refs (class_id INTEGER)');DB::execute('INSERT INTO health_class_refs VALUES (?)',[$rows[1]['id']]);`);
await request('db-optimizer.php',{post:{csrf_token:token,do:'dedup_students'}});
const classRows=await db("SELECT * FROM classes WHERE name='HealthDup' ORDER BY id");check(classRows.length===3&&classRows.some(c=>c.grade==='هشتم'),'Duplicate cleanup protects references and different class definitions');
await request('db-optimizer.php',{post:{csrf_token:token,do:'optimize_tables'}});assert.deepEqual(await db('SELECT * FROM students ORDER BY id'),before);check(JSON.stringify(await db('PRAGMA integrity_check')).includes('ok'),'Data intact after maintenance');
writeFileSync(join(dir,'checks.json'),JSON.stringify({checks,quickCounts,fullCounts},null,2));console.log('PASS',checks,'settings/health checks;',quickCounts,'vs',fullCounts,'full-table counts');process.exit(0);
