// Optimized desktop sync engine — behaviour tests on the real staged PHP.
//
// What this suite pins down (see CORRECTIONS-OPTIMIZED-SYNC-FA.md):
//   1. idle site probes are throttled to PING_INTERVAL (30s), not every loop
//   2. a pending local change still triggers a full cycle IMMEDIATELY
//      (desktop→site stays instant; only the empty-idle probe is stretched)
//   3. the periodic safety cycle now fires at 300s, not 120s
//   4. a recently verified mirror (≤60s) keeps the 15s notification-handoff
//      cadence eligible without waiting for the next probe
//   5. relayBotNotifications() makes NO HTTP round-trip while the relay queue
//      is empty (the normal state); with jobs it still attempts handover
//
// In PHP-WASM, cURL cannot reach any host, so "an HTTP attempt happened" is
// observably deterministic: the call() offline error lands in the state.
// "No HTTP" is observable as that error being absent. No live network.
import {php,run,student} from './harness/lib.mjs';
let checks=0;
const check=(v,m)=>{if(!v){console.error('❌ FAIL:',m);process.exit(1);}checks++;console.log('   · '+m);};

async function code(c){
  const r=await run(`<?php error_reporting(E_ALL & ~E_DEPRECATED); require '/www/includes/desk_sync.php'; require '/www/includes/bot_helpers.php'; ${c}`);
  if(r.err)throw new Error('stderr: '+r.err);
  if(/Fatal error|Parse error|Warning:/.test(r.out))throw new Error('PHP noise: '+r.out.slice(0,600));
  return r.out.trim();
}
async function json(c){return JSON.parse(await code(c));}
const cfg=k=>json(`echo json_encode(DeskSync::getCfg('${k}',''));`);

// Reset every engine state between scenarios (desk_* keys never enter the
// change log — the settings sync trigger ignores them).
const tsExpr=(v)=>v==='now'?'time()':(typeof v==='string'&&v.startsWith('now-'))?`(time()-${+v.slice(4)})`:`${v}`;
async function reset({pingLast=0,verifiedAt=0,last=100,pendingRow=null}={}){
  await code(`
    DeskSync::setCfg('desk_sync_fails','0');
    DeskSync::setCfg('desk_sync_next_try','0');
    DeskSync::setCfg('desk_sync_ping_last',(string)${tsExpr(pingLast)});
    DeskSync::setCfg('desk_sync_verified_at',(string)${tsExpr(verifiedAt)});
    DeskSync::setCfg('desk_sync_last',(string)(time()-${last}));
    DeskSync::setCfg('desk_sync_lock','0');
    DeskSync::setCfg('desk_bot_outbox_error','');
    DB::execute('DELETE FROM desk_change_log');
    ${pendingRow?`DB::execute('INSERT INTO desk_change_log (tbl,rid,op,ts) VALUES (?,?,?,?)',[${pendingRow},time(),'U',time()]);`:''}
  `);
  check((await json('echo json_encode(DeskSync::pendingCount());'))===(pendingRow?1:0),'reset leaves expected pending state');
}
const tick=()=>json('echo json_encode(DeskSync::tick(), JSON_UNESCAPED_SLASHES);');

await code(`
  set_setting('desk_sync_enabled','1');
  set_setting('desk_sync_url','https://school.test/reports/desk-sync-api.php');
  set_setting('desk_sync_key','fixture-sync-key-not-production');
  set_setting('desk_sync_snapshot_done','1');
  set_setting('desk_sync_fails','0'); set_setting('desk_sync_next_try','0');
  set_setting('desk_sync_ping_last','0'); set_setting('desk_sync_verified_at','0');
  set_setting('desk_sync_last','0'); set_setting('desk_sync_lock','0');
`);
check((await code(`echo json_encode(DeskSync::PING_INTERVAL);`))==='30','PING_INTERVAL is 30s');
check((await code(`echo json_encode(DeskSync::MIN_INTERVAL);`))==='300','safety cycle interval is 300s');
check((await json('echo json_encode(DeskSync::enabled());'))===true,'engine enabled for tests');

// 1) Fresh start probes immediately (no cold-start delay).
await reset({});
let r=await tick();
check(r.skipped==='offline'&&await cfg('desk_sync_fails')==='1','fresh start still probes the site at once (offline here = probe attempted)');

// 2) Throttled probe: within 30s of the last good probe, no HTTP attempt.
await reset({pingLast:'now'});
r=await tick();
check(r.skipped==='idle'&&await cfg('desk_sync_fails')==='0','idle loop inside 30s window performs zero site requests');

// 3) Window expired → probe fires again.
await reset({pingLast:'now-31'});
r=await tick();
check(r.skipped==='offline'&&await cfg('desk_sync_fails')==='1','after the 30s window the probe fires again');

// 4) A pending local change bypasses the throttle: immediate full cycle.
await reset({pingLast:'now',verifiedAt:'now',pendingRow:student.id});
r=await tick();
check(r.ok===false&&r.skipped!=='idle','pending edit triggers a full cycle despite the warm probe throttle');
check(await cfg('desk_sync_last')>Date.now()/1000-15,'full cycle ran now (desk_sync_last advanced)');
check(r.pending===1,'unpushed change is retained, not lost, while offline');
await reset({});

// 5) Periodic safety cycle: 300s yes, 299s no (the old 120s trigger is gone).
await reset({pingLast:'now',verifiedAt:'now',last:301});
r=await tick();
check(r.ok===false&&r.skipped!=='idle','safety cycle runs at 301s of quiet');
check(await cfg('desk_sync_fails')==='1','offline safety cycle enters the normal back-off ladder');
await reset({pingLast:'now',verifiedAt:'now',last:299});
const lastBefore=await cfg('desk_sync_last');
r=await tick();
check(r.skipped==='idle'&&await cfg('desk_sync_last')===lastBefore,'at 299s of quiet nothing runs (120s trigger removed)');

// 6) Recent verification (≤60s) keeps server_verified true without a fresh probe.
await reset({pingLast:'now',verifiedAt:'now-30'});
r=await tick();
check(r.skipped==='idle'&&r.server_verified===true,'≤60s-old verified mirror still counts as verified');
await reset({pingLast:'now',verifiedAt:'now-120'});
r=await tick();
check(r.skipped==='idle'&&r.server_verified===false,'stale verification (>60s) does not count');

// 7) Empty relay queue → no HTTP round-trip at all (the ~1,200/day saver).
await code(`bot_outbox_schema();bot_outbox_sql("DELETE FROM bot_outbox WHERE owner='relay'");DeskSync::setCfg('desk_bot_outbox_next','0');DeskSync::setCfg('desk_bot_outbox_error','');`);
await code('DeskSync::relayBotNotifications();');
check(await cfg('desk_bot_outbox_error')==='', 'empty queue: zero HTTP attempted (no delivery error recorded)');
const gate=+(await cfg('desk_bot_outbox_next'));
check(gate>Date.now()/1000+10&&gate<Date.now()/1000+20,'empty queue: 15s re-check cadence kept');

// 8) Non-empty relay queue → handover attempted (control for 7).
await code(`bot_outbox_store(str_repeat('f',32),'telegram',['chat_id'=>'101','text'=>'relay control'],'relay');DeskSync::setCfg('desk_bot_outbox_next','0');DeskSync::setCfg('desk_bot_outbox_error','');`);
await code('DeskSync::relayBotNotifications();');
check(await cfg('desk_bot_outbox_error')!== '', 'jobs present: handover HTTP attempted (offline error recorded here)');
const fgate=+(await cfg('desk_bot_outbox_next'));
check(fgate>Date.now()/1000+25&&fgate<Date.now()/1000+35,'failed handover re-checks in ~30s');
check((await json(`$row=DB::fetch("SELECT state FROM bot_outbox WHERE job_id=?",[str_repeat('f',32)]);echo json_encode($row['state']);`))==='pending','unacknowledged job stays pending — no false delivery');
await code(`bot_outbox_sql("DELETE FROM bot_outbox WHERE owner='relay'");`);

console.log(`PASS ${checks} optimized-sync checks (desktop, staged build, no live network)`);
process.exit(0);
