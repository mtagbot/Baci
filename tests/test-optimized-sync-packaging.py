#!/usr/bin/env python3
"""Package/security verification for the one-file optimized-sync corrective."""
from pathlib import Path
from zipfile import ZipFile
import hashlib,json,subprocess
ROOT=Path(__file__).resolve().parents[1]
NAME='SchoolDeskPro-FIX-v2.83.0-optimized-sync.zip'
PREFIX='SchoolDeskPro/reports/'
FILE='includes/desk_sync.php'
BASELINE='d44626fafc69f67ef2e32bde70022d5bfb73b3ad'  # published bot-outbox commit
checks=0
def check(v,m):
 global checks
 assert v,m;checks+=1
z=ZipFile(ROOT/NAME)
check(z.testzip() is None,'ZIP CRC')
check(z.namelist()==[PREFIX+FILE],'exactly one file at the installed path')
src=(ROOT/'update-v4.152.0'/FILE)
staged=(ROOT/'.cache/bot-outbox/desktop/SchoolDeskPro/reports'/FILE)
check(z.read(PREFIX+FILE)==src.read_bytes(),'ZIP bytes == source bytes')
check(z.read(PREFIX+FILE)==staged.read_bytes(),'ZIP bytes == tested staged build bytes')
for line in (ROOT/'OPTIMIZED-SYNC-SHA256SUMS.txt').read_text().splitlines():
 h,n=line.split();check(hashlib.sha256((ROOT/n).read_bytes()).hexdigest()==h,'checksum '+n)
# Semantic invariants of the optimized engine (byte-level pins; behaviour is
# exercised by tests/test-optimized-sync.mjs on the real staged PHP).
sync=src.read_text()
check('const PING_INTERVAL = 30;' in sync,'idle probe throttled to 30s')
check('const MIN_INTERVAL = 300;' in sync,'safety cycle stretched to 300s')
check('if(!$jobs)return;' in sync,'empty relay queue performs no HTTP round-trip')
check("desk_sync_ping_last" in sync and "desk_sync_verified_at" in sync,'throttle + verification bookkeeping present')
check('if (!$secureOutbox && $body === false' in sync and 'CURLOPT_SSL_VERIFYHOST => $secureOutbox?2:0' in sync,'queue TLS verification and no-downgrade preserved')
check('if($secureOutbox && strtolower((string)parse_url($url,PHP_URL_SCHEME))!==\'https\')throw new RuntimeException(\'Notification relay requires HTTPS\');' in sync,'relay still requires HTTPS')
# The daemon must remain byte-identical to the published baseline (unchanged by this corrective).
daemon=(ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php').read_bytes()
base_daemon=subprocess.check_output(['git','show',BASELINE+':desktop-app-v2/patch/www-desk-sync-daemon.php'],cwd=ROOT)
check(daemon==base_daemon,'desktop sync daemon unchanged')
# Every archive published in the baseline commit must be byte-identical now.
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
result={'packaging_checks':checks,'baseline_archives_unchanged':unchanged,'regression_suites':regressions,'limits':['PHP 8.3 WASM/SQLite; cURL cannot reach the network inside the harness — HTTP attempts are asserted via deterministic offline state transitions','No live bot traffic, production DB, native MySQL or Windows daemon measurement']}
(ROOT/'docs/optimized-sync/verification.json').write_text(json.dumps(result,indent=2)+'\n')
print('PASS',checks,'optimized-sync packaging/security checks;',unchanged,'baseline archives unchanged')
