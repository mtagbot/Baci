from pathlib import Path
from zipfile import ZipFile
import hashlib,json,subprocess,importlib.util
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('release',ROOT/'scripts/release-bot-outbox.py');release=importlib.util.module_from_spec(spec);spec.loader.exec_module(release)
checks=0
def check(v,m):
 global checks
 assert v,m;checks+=1
for name,prefix,desktop in release.PACKAGES:
 files=release.stage.DESKTOP if desktop else release.stage.SITE
 tested=ROOT/'.cache/bot-outbox'/('desktop/SchoolDeskPro/reports' if desktop else 'site')
 with ZipFile(ROOT/name)as z:
  check(z.testzip() is None,name+' CRC')
  check(sorted(z.namelist())==sorted(prefix+f for f in files),name+' minimal manifest')
  check(len(z.namelist())==6,name+' six files')
  for f in files:
   source=ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php' if f=='desk-sync-daemon.php' else ROOT/'update-v4.152.0'/f
   check(z.read(prefix+f)==source.read_bytes()==(tested/f).read_bytes(),name+' exact tested source '+f)
  check(not any('/www/' in f or '/config/' in f or f.endswith(('.sqlite','.exe')) for f in z.namelist()),'no legacy root/secrets/database/launcher')
for line in (ROOT/'BOT-OUTBOX-SHA256SUMS.txt').read_text().splitlines():
 h,n=line.split();check(hashlib.sha256((ROOT/n).read_bytes()).hexdigest()==h,'checksum '+n)
# Never compare rebuilt old files against mutable sources; compare Git objects at the published baseline.
base='2f8b4585fe918d7ba56a0b708b1cad9763c9482c';historical=0
for f in subprocess.check_output(['git','ls-tree','-r','--name-only',base],cwd=ROOT,text=True).splitlines():
 if '/' not in f and f.endswith('.zip'):
  old=subprocess.check_output(['git','rev-parse',base+':'+f],cwd=ROOT,text=True).strip();now=subprocess.check_output(['git','hash-object',f],cwd=ROOT,text=True).strip();check(old==now,'historical '+f);historical+=1
api=(ROOT/'update-v4.152.0/class-exam-sync-api.php').read_text();check(api.index('hash_equals(DESK_SYNC_KEY')<api.index("if ($action === 'bot_outbox')"),'authentication before queue')
check("config/desk-sync-key.php" in api,'preserve private installation key')
sync=(ROOT/'update-v4.152.0/includes/desk_sync.php').read_text();check("if (!$secureOutbox && $body === false" in sync and 'CURLOPT_SSL_VERIFYHOST => $secureOutbox?2:0' in sync,'no HTTP downgrade, verified queue TLS')
worker=(ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php').read_text();check("!empty($res['server_verified']) && DeskSync::pendingCount()===0" in worker,'school data before notifications')
regressions={}
for name in ['site','desktop']:
 log=(ROOT/'.cache/bot-outbox'/f'{name}-regressions.log').read_text();tail=log[log.index('════════════════ خلاصه'):];check(tail.count('✅')==31 and '❌' not in tail,name+' 31 regression suites');regressions[name]=31
result={'packaging_checks':checks,'historical_archives_unchanged':historical,'regression_suites':regressions,'site':json.loads((ROOT/'docs/bot-outbox/site.json').read_text())['checks'],'desktop_and_authenticated_relay':json.loads((ROOT/'docs/bot-outbox/desktop.json').read_text())['checks'],'limitations':['PHP 8.3 WASM/SQLite; provider responses and network errors mocked','No live bot messages, production DB access, native MySQL or Windows worker tests','At-least-once delivery; provider acceptance with a lost response may duplicate a message']}
(ROOT/'docs/bot-outbox/verification.json').write_text(json.dumps(result,indent=2)+'\n');print('PASS',checks,'packaging/security/regression-summary checks;',historical,'historical archives unchanged')
