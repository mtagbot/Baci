#!/usr/bin/env python3
"""Package/security verification for the two-file event-sync corrective."""
from pathlib import Path
from zipfile import ZipFile
import hashlib,json,subprocess
ROOT=Path(__file__).resolve().parents[1]
NAME='SchoolDeskPro-FIX-v2.83.0-event-sync.zip'
PREFIX='SchoolDeskPro/reports/'
FILES=[('includes/desk_sync.php',ROOT/'update-v4.152.0/includes/desk_sync.php'),
       ('desk-sync.php',ROOT/'desktop-app-v2/patch/www-desk-sync.php')]
BASELINE='5336dcde3b91c5087ec178c931fa859b820b048c'  # published optimized-sync commit
checks=0
def check(v,m):
 global checks
 assert v,m;checks+=1
z=ZipFile(ROOT/NAME)
check(z.testzip() is None,'ZIP CRC')
check(sorted(z.namelist())==sorted(PREFIX+r for r,_ in FILES),'exactly two files at the installed paths')
staged=(ROOT/'.cache/bot-outbox/desktop/SchoolDeskPro/reports')
for rel,src in FILES:
 check(z.read(PREFIX+rel)==src.read_bytes(),'ZIP bytes == source bytes: '+rel)
 check(z.read(PREFIX+rel)==(staged/rel).read_bytes(),'ZIP bytes == tested staged build: '+rel)
for line in (ROOT/'EVENT-SYNC-SHA256SUMS.txt').read_text().splitlines():
 h,n=line.split();check(hashlib.sha256((ROOT/n).read_bytes()).hexdigest()==h,'checksum '+n)
# Semantic invariants (behaviour is exercised by tests/test-optimized-sync.mjs
# sections 9-13 on the real staged PHP).
sync=(ROOT/'update-v4.152.0/includes/desk_sync.php').read_text()
check('const EVENT_SAFETY_INTERVAL = 3600;' in sync,'hourly mirror check constant present')
check("getCfg('desk_sync_mode', 'full') === 'event'" in sync,'mode read with safe full default')
check('if (!$due && !$event) {' in sync,'event mode disables idle probes')
check('$safety = $event ? self::EVENT_SAFETY_INTERVAL : self::MIN_INTERVAL;' in sync,'event mode relaxes the safety cycle to hourly')
check('if(!$jobs)return;' in sync,'empty-queue relay shortcut preserved')
check('const PING_INTERVAL = 30;' in sync and 'const MIN_INTERVAL = 300;' in sync,'previous optimization constants preserved')
check("CURLOPT_SSL_VERIFYHOST => $secureOutbox?2:0" in sync,'queue TLS verification preserved')
page=(ROOT/'desktop-app-v2/patch/www-desk-sync.php').read_text()
check('name="desk_sync_mode"' in page and 'value="event"' in page,'settings page offers both modes')
check("($_POST['desk_sync_mode'] ?? 'full') === 'event' ? 'event' : 'full'" in page,'save handler rejects unknown mode values')
# Daemon + optimized-sync package remain byte-identical to the baseline.
daemon=(ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php').read_bytes()
base_daemon=subprocess.check_output(['git','show',BASELINE+':desktop-app-v2/patch/www-desk-sync-daemon.php'],cwd=ROOT)
check(daemon==base_daemon,'desktop sync daemon unchanged')
base_pkg=ZipFile(ROOT/'SchoolDeskPro-FIX-v2.83.0-optimized-sync.zip')
base_pkg_bytes=base_pkg.read('SchoolDeskPro/reports/includes/desk_sync.php')
check(base_pkg_bytes!=sync.encode('utf-8'),'new engine supersedes the optimized-sync engine (different bytes expected)')
unchanged=0
for f in subprocess.check_output(['git','ls-tree','-r','--name-only',BASELINE],cwd=ROOT,text=True).splitlines():
 if '/' not in f and f.endswith('.zip'):
  old=subprocess.check_output(['git','rev-parse',BASELINE+':'+f],cwd=ROOT,text=True).strip()
  now=subprocess.check_output(['git','hash-object',f],cwd=ROOT,text=True).strip()
  check(old==now,'baseline archive unchanged: '+f);unchanged+=1
regressions={}
for name in ['site','desktop']:
 log=(ROOT/'.cache/bot-outbox'/f'{name}-regressions.log').read_text();tail=log[log.index('════════════════ خلاصه'):]
 check(tail.count('✅')==31 and '❌' not in tail,name+' 31 regression suites');regressions[name]=31
result={'packaging_checks':checks,'baseline_archives_unchanged':unchanged,'regression_suites':regressions,'limits':['PHP 8.3 WASM/SQLite; cURL cannot reach the network inside the harness','No live host or Windows daemon measurement']}
(ROOT/'docs/event-sync/verification.json').write_text(json.dumps(result,indent=2)+'\n')
print('PASS',checks,'event-sync packaging/security checks;',unchanged,'baseline archives unchanged')
