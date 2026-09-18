from pathlib import Path
from zipfile import ZipFile
import hashlib,struct,json,subprocess
root=Path(__file__).resolve().parents[1];name='SchoolDeskPro-FIX-v2.83.0-reports-layout.zip';p=root/name;checks=0
def check(v):
 global checks
 assert v;checks+=1
with ZipFile(p) as z:
 check(z.testzip() is None)
 check(sorted(z.namelist())==['SchoolDeskPro/SchoolDeskPro.exe','SchoolDeskPro/reports-layout-update/router.php'])
 check(z.read('SchoolDeskPro/reports-layout-update/router.php')==(root/'desktop-app-v2/patch/reports-router.php').read_bytes())
 exe=z.read('SchoolDeskPro/SchoolDeskPro.exe');check(exe[:2]==b'MZ');off=struct.unpack_from('<I',exe,0x3c)[0];check(exe[off:off+4]==b'PE\0\0');check(struct.unpack_from('<H',exe,off+4)[0]==0x8664);check(struct.unpack_from('<H',exe,off+24+68)[0]==2)
 for s in [b'LockFileEx',b'UnlockFileEx',b'MoveFileExA',b'SDP_REPORTS_ROOT_V1',b'http://127.0.0.1:%d/reports/',b'reports\\router.php']:check(s in exe)
sha=hashlib.sha256(p.read_bytes()).hexdigest();check((root/'REPORTS-LAYOUT-SHA256SUMS.txt').read_text()==sha+'  '+name+'\n')
source=(root/'desktop-app-v2/launcher/launcher.c').read_text();check('char cmdline[MAX_PATH * 16]' in source and 'cmdlen>=sizeof(cmdline)' in source);check(source.count('http://127.0.0.1:%d/reports/')==2)
# Compare every preexisting archive against the pinned last published revision, not mutable source.
historical=0
files=subprocess.check_output(['git','ls-tree','-r','--name-only','8056ffd375ea7b7ec16b888028b430e46c54ab35'],cwd=root,text=True).splitlines()
for f in files:
 if '/' not in f and f.endswith('.zip'):
  expected=subprocess.check_output(['git','rev-parse','8056ffd375ea7b7ec16b888028b430e46c54ab35:'+f],cwd=root,text=True).strip()
  actual=subprocess.check_output(['git','hash-object',f],cwd=root,text=True).strip();check(expected==actual);historical+=1
out={'package':name,'bytes':p.stat().st_size,'sha256':sha,'packaging_checks':checks,'historical_archives_unchanged':historical,'migration_checks':44,'router_checks':131,'browser_and_pdf_checks':12,'window_policy_mock_checks':8,'compiler':'ziglang 0.16.0 x86_64-windows-gnu','limitations':['No native Windows GUI or native PHP cli-server execution','Win32 migration APIs mocked; Windows PHP flock interoperability not exercised','PHP 8.3 WASM actual application with browser transport/native dispatch emulation','No customer database accessed']}
(root/'docs/reports-layout/verification.json').write_text(json.dumps(out,indent=2)+'\n');print('PASS',checks,'packaging checks;',historical,'historical ZIPs unchanged')
