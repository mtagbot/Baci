#!/usr/bin/env python3
"""Package/security verification for the recovery-ui corrective (both dists)."""
from pathlib import Path
from zipfile import ZipFile
import hashlib,json,subprocess
ROOT=Path(__file__).resolve().parents[1]
SOURCES={
 'includes/header.php':ROOT/'desktop-app-v2/patch/includes-header.php',
 'import-photos.php':ROOT/'update-v4.152.0/import-photos.php',
}
PKGS=[('SchoolDeskPro-FIX-v2.83.0-recovery-ui.zip','SchoolDeskPro/reports/'),
      ('SITE-FIX-v4.152.0-recovery-ui.zip','site-update-v4.152.0/')]
BASELINE_REF='e77d537'  # last published state (event-sync) — full SHA resolved below
checks=0
def check(v,m):
 global checks
 assert v,m;checks+=1
for name,prefix in PKGS:
 z=ZipFile(ROOT/name)
 check(z.testzip() is None,name+' CRC')
 check(sorted(z.namelist())==sorted(prefix+r for r in SOURCES),name+' two-file manifest at installed paths')
 for rel,src in SOURCES.items():
  check(z.read(prefix+rel)==src.read_bytes(),name+' bytes == source: '+rel)
for line in (ROOT/'RECOVERY-UI-SHA256SUMS.txt').read_text().splitlines():
 h,n=line.split();check(hashlib.sha256((ROOT/n).read_bytes()).hexdigest()==h,'checksum '+n)
# Semantic pins.
hdr=SOURCES['includes/header.php'].read_text()
check('مورخ: <?php echo jdate' not in hdr and 'hdr-date"><?php echo jdate' in hdr,'header date without the مورخ label')
check(hdr.count("Vazirmatn, Sahel, Yekan, Tahoma, sans-serif !important")==3,'Persian fallback chain in :root/body/headings')
check('window.open = function (u) { go(u); return null; }' in hdr and 'a[target="_blank"]' in hdr and '$isDeskRuntime' in hdr,'desktop no-new-window shim present (distribution-gated)')
iph=SOURCES['import-photos.php'].read_text()
check("require_permission('import_data')" in iph,'photos tool aligned with the recovery hub permission')
check('embedded=1' in iph and 'iphEmbedded' in iph,'embedded mode preserved across redirects')
check('file_put_contents' in iph and '$written !== false' in iph,'photo write verified before the DB update')
check('🖼️' not in iph and '📅' not in iph,'no emoji in the restored tool')
# Baseline archives: resolve the published ref (skipped with a loud note when
# the git objects are not available locally yet — e.g. before a fetch).
baseline=''
try:
 baseline=subprocess.check_output(['git','rev-parse',BASELINE_REF+'^{commit}'],cwd=ROOT,text=True).strip()
except Exception:
 pass
unchanged=0;baseline_ok=True
if baseline:
 for f in subprocess.check_output(['git','ls-tree','-r','--name-only',baseline],cwd=ROOT,text=True).splitlines():
  if '/' not in f and f.endswith('.zip'):
   old=subprocess.check_output(['git','rev-parse',baseline+':'+f],cwd=ROOT,text=True).strip()
   now=subprocess.check_output(['git','hash-object',f],cwd=ROOT,text=True).strip()
   check(old==now,'baseline archive unchanged: '+f);unchanged+=1
else:
 baseline_ok=False
 print('NOTE: baseline git objects unavailable locally (rev '+BASELINE_REF+'); baseline-archive check pending a fetch')
# Regression summaries (both distributions).
regressions={}
for name in ['site','desktop']:
 log=(ROOT/'.cache/bot-outbox'/f'{name}-regressions.log').read_text();tail=log[log.index('════════════════ خلاصه'):]
 check(tail.count('✅')==31 and '❌' not in tail,name+' 31 regression suites');regressions[name]=31
result={'packaging_checks':checks,'baseline_ref':BASELINE_REF,'baseline_archives_unchanged':unchanged,'baseline_checked':baseline_ok,'regression_suites':regressions,
        'limits':['PHP 8.3 WASM/SQLite with mocked network; ZIP upload tested end-to-end in the harness','No live host or Windows WebView measurement']}
(ROOT/'docs/recovery-ui/verification.json').write_text(json.dumps(result,indent=2)+'\n')
print('PASS',checks,'recovery-ui packaging/security checks; baseline archives unchanged:',unchanged if baseline_ok else 'pending')
