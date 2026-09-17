#!/usr/bin/env python3
"""Reproducible Release_V1.0 full installs. Historical patches/archives are immutable inputs."""
import argparse, base64, concurrent.futures, hashlib, json, os, re, shutil, sqlite3, subprocess, zipfile
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
os.chdir(ROOT)
CACHE = ROOT/'.cache/release-v1'
STAGE = CACHE/'package'
SOURCE = ROOT/'release-v1.0'
STAMP = (2026, 9, 17, 0, 0, 0)
def sha(b): return hashlib.sha256(b).hexdigest()
def run(*args): return subprocess.check_output(args, text=True)
def version(p): return tuple(map(int,p.name.split('v',1)[1].split('.')))
def copy(src, dest):
    dest.parent.mkdir(parents=True, exist_ok=True); shutil.copyfile(src,dest)
def clean_sql(path):
    # sqlite3.complete_statement is a lexical SQL splitter, not regex: it keeps
    # semicolons inside strings and entire BEGIN ... END trigger bodies together.
    out=[]; buf=''
    for line in path.read_text(encoding='utf-8-sig').splitlines(True):
        for c in line:
            buf+=c
            if c==';' and sqlite3.complete_statement(buf):
                s=re.sub(r'^\s*(?:(?:--[^\n]*(?:\n|$))|(?:/\*.*?\*/))\s*','',buf,flags=re.S)
                while s!=buf:
                    buf=s;s=re.sub(r'^\s*(?:(?:--[^\n]*(?:\n|$))|(?:/\*.*?\*/))\s*','',buf,flags=re.S)
                s=s.strip()
                if re.match(r'^(?:CREATE|ALTER|PRAGMA|SET)\b',s,re.I):out.append(s.strip())
                elif re.match(r'^INSERT\b',s,re.I):pass
                elif s.strip():raise ValueError('Unexpected schema operation: '+s[:100])
                buf=''
    assert not re.sub(r'--[^\n]*','',buf).strip(),buf[:80]
    return out

def vendors():
    root=CACHE/'vendor/tcpdf'
    def fetch(x):
        p=root/x['path']
        if p.is_file():
            b=p.read_bytes()
            if hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()==x['sha']:return
        j=json.loads(run('gh','api','repos/tecnickcom/TCPDF/git/blobs/'+x['sha']))
        b=base64.b64decode(j['content'])
        assert hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()==x['sha']
        p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes(b)
    with concurrent.futures.ThreadPoolExecutor(max_workers=6) as p:list(p.map(fetch,json.loads((SOURCE/'tcpdf-files.json').read_text())))
    npm=CACHE/'vendor-node'
    if not (npm/'node_modules/chart.js/dist/chart.umd.min.js').exists():
        subprocess.run(['npm','install','--prefix',str(npm),'--no-audit','--no-fund','chart.js@4.5.1'],check=True,stdout=subprocess.DEVNULL)
    chart=npm/'node_modules/chart.js'
    lock=json.loads((SOURCE/'vendor-lock.json').read_text())['chart.js']
    assert json.loads((chart/'package.json').read_text())['version']==lock['version']
    assert sha((chart/lock['dist_file']).read_bytes())==lock['sha256'], 'Chart.js checksum mismatch'
    assert sha((chart/'LICENSE.md').read_bytes())==lock['license_sha256']
    return root,chart

def launcher():
    zig=os.environ.get('ZIG') or shutil.which('zig') or str(CACHE/'compiler/ziglang/zig')
    if not Path(zig).is_file():raise SystemExit('Install Zig 0.14.1 or set ZIG. See release-v1.0/BUILD.md')
    if run(zig,'version').strip()!='0.14.1':raise SystemExit('Reproducible build requires Zig 0.14.1')
    exe=CACHE/'SchoolDeskPro.exe';res=CACHE/'app.res'
    subprocess.run([zig,'rc','/fo',str(res),'desktop-app-v2/launcher/res/app.rc'],check=True)
    subprocess.run([zig,'cc','-target','x86_64-windows-gnu','-O2','-s','desktop-app-v2/launcher/launcher.c',str(res),'-lws2_32','-ladvapi32','-lshell32','-luser32','-lgdi32','-Wl,--subsystem,windows','-o',str(exe)],check=True)
    return exe

def assemble():
    tcpdf,chart=vendors();exe=launcher()
    if STAGE.exists():shutil.rmtree(STAGE)
    STAGE.mkdir(parents=True)
    with zipfile.ZipFile('SchoolDeskPro-v2.55.0-win64.zip') as z:z.extractall(STAGE)
    desktop=STAGE/'SchoolDeskPro';dw=desktop/'www';site=STAGE/'site';site.mkdir()
    origins={'site':{},'desktop':{}}
    def put(src, dest, rel, platform, provenance=None):
        copy(src,dest/rel);origins[platform][str(rel)]=provenance or str(src.relative_to(ROOT))
    for f in dw.rglob('*'):
        if f.is_file():origins['desktop'][str(f.relative_to(dw))]='SchoolDeskPro-v2.55.0-win64.zip'
    for f in (ROOT/'upstream-reference').rglob('*'):
        if f.is_file() and f.suffix.lower() in ['.php','.css','.js','.svg','.png','.ico','.ttf','.woff2','.docx','.json','.py']:
            put(f,site,f.relative_to(ROOT/'upstream-reference'),'site')
    for d in ['assets','uploads']:
        for f in (dw/d).rglob('*'):
            if f.is_file() and (d=='assets' or f.suffix.lower() in ['.ttf','.woff2','.woff']):put(f,site,f.relative_to(dw),'site','SchoolDeskPro-v2.55.0-win64.zip')
    desktop_baseline=set(origins['desktop'])
    patches=sorted(ROOT.glob('update-v4.*'),key=version)
    for patch in patches:
        for f in sorted(patch.rglob('*')):
            if not f.is_file() or f.suffix.lower() in ['.md','.txt','.zip']:continue
            put(f,site,f.relative_to(patch),'site')
            # The full desktop baseline already includes site 4.124 with platform adjustments.
            if version(patch)>=(4,125,0) or str(f.relative_to(patch)) not in desktop_baseline:put(f,dw,f.relative_to(patch),'desktop')
    sync_source=(site/'desk-sync-api.php').read_text()
    for platform,web in [('site',site),('desktop',dw)]:
        for d in ['config','sql','backups']:
            if (web/d).exists():shutil.rmtree(web/d)
            (web/d).mkdir()
        for f in (web/'uploads').rglob('*'):
            if f.is_file() and f.suffix.lower() not in ['.ttf','.woff2','.woff']:f.unlink()
        for f in list(web.rglob('*')):
            if f.is_file() and (f.suffix.lower() in ['.sqlite','.db','.log','.bak','.zip','.csv'] or f.name in ['installer.log']):f.unlink()
        copy(ROOT/'upstream-reference/config/defaults.php',web/'config/defaults.php')
        s=(web/'config/defaults.php').read_text();s=re.sub(r"'version'\s*=>\s*'[^']+'", "'version' => '4.152.0'",s);(web/'config/defaults.php').write_text(s)
        for layer in ['common',platform]:
            for f in (SOURCE/layer).rglob('*'):
                if f.is_file():put(f,web,f.relative_to(SOURCE/layer),platform)
        (web/'config/release.php').write_text("<?php\nreturn ['release'=>'Release_V1.0','distribution'=>'"+platform+"','site_version'=>'4.152.0','desktop_version'=>'2.83.0'];\n")
        for d in ['config','includes','sql','vendor','backups']:
            (web/d).mkdir(exist_ok=True);(web/d/'.htaccess').write_text('Require all denied\n')
        copy(ROOT/'update-v4.131.0/backups/.htaccess',web/'backups/.htaccess')
        copy(ROOT/'update-v4.131.0/uploads/.htaccess',web/'uploads/.htaccess')
        for driver,source in [('mysql',ROOT/'update-v4.31.0/sql/database.sql'),('sqlite',ROOT/'update-v4.133.0/sql/schema-sqlite.sql')]:
            statements=clean_sql(source)
            if driver=='mysql':
                # Historical fresh-schema index referenced a nonexistent column.
                statements=[s.replace('ADD INDEX `idx_student_created` (`student_id`, `created_at`)', 'ADD INDEX `idx_student_created` (`student_id`, `created_at_jalali`)') for s in statements]
            (web/'sql'/('install-'+driver+'.json')).write_text(json.dumps(statements,ensure_ascii=False,indent=2)+'\n')
            (web/'sql'/('database.sql' if driver=='mysql' else 'schema-sqlite.sql')).write_text('-- Release_V1.0: STRUCTURE ONLY. Install with installer.php.\n'+'\n\n'.join(statements)+'\n')
        shutil.copytree(tcpdf,web/'vendor/tcpdf',dirs_exist_ok=True)
        # Chart.js's upstream UMD build is already minified; preserve the version/license header.
        chartfile=chart/'dist/chart.umd.min.js'
        if not chartfile.exists():chartfile=chart/'dist/chart.umd.js'
        copy(chartfile,web/'assets/vendor/chart.umd.min.js');copy(chart/'LICENSE.md',web/'assets/vendor/Chart.js-LICENSE.md')
        functions=web/'includes/functions.php'
        text=functions.read_text();text=text.replace('<?php',"<?php\nrequire_once __DIR__.'/install_guard.php';",1);functions.write_text(text)
        footer=web/'includes/footer.php'
        text=footer.read_text().replace('<script>\n/* SchoolDesk Pro: real-time auto-sync.', "<?php if ((require dirname(__DIR__).'/config/release.php')['distribution'] === 'desktop'): ?>\n<script>\n/* SchoolDesk Pro: real-time auto-sync.")
        text=text.replace('</script>\n</body>', '</script>\n<?php endif; ?>\n</body>');footer.write_text(text)
        # Both endpoints consume the NEW per-install key, never a literal shared historical key.
        text=sync_source
        text=re.sub(r"define\('DESK_SYNC_KEY',\s*'[^']+'\);", "require_once __DIR__.'/config/config.php';\ndefine('DESK_SYNC_KEY', require __DIR__.'/config/desk-sync-key.php');",text,count=1)
        text=text.replace('edit desk-sync-api.php','check config/desk-sync-key.php');(web/'desk-sync-api.php').write_text(text)
        path=web/'class-exam-sync-api.php';text=path.read_text();a=text.index('function ceg_existing_sync_key()');b=text.index("require_once __DIR__ . '/config/config.php';",a)
        text=text[:a]+"function ceg_existing_sync_key() {\n    $path=__DIR__.'/config/desk-sync-key.php';\n    $key=is_file($path) ? require $path : '';\n    return is_string($key) && preg_match('/^[a-f0-9]{64}$/D',$key) ? $key : '';\n}\n\n"+text[b:];path.write_text(text)
        copy(SOURCE/'README-FA.md',web/'README-FA.md')
        copy(SOURCE/'THIRD-PARTY.md',web/'THIRD-PARTY.md')
    # Never ship a previous school's data, browser profile, session, pairing credentials or logs.
    for d in ['data','profile']:
        if (desktop/d).exists():shutil.rmtree(desktop/d)
    for d in ['data','data/sessions','data/uploads']:(desktop/d).mkdir(parents=True,exist_ok=True)
    if (desktop/'server').exists():shutil.rmtree(desktop/'server')
    shutil.copytree(SOURCE/'desktop-runtime-licenses',desktop/'licenses',dirs_exist_ok=True)
    copy(exe,desktop/'SchoolDeskPro.exe')
    copy(ROOT/'desktop-app-v2/patch/php.ini',desktop/'php/php.ini')
    copy(SOURCE/'README-FA.md',desktop/'README-FA.md')
    copy(SOURCE/'THIRD-PARTY.md',desktop/'THIRD-PARTY.md')
    for f in desktop.iterdir():
        if f.is_file() and f.name not in ['SchoolDeskPro.exe','README-FA.md','THIRD-PARTY.md']:f.unlink()
    for platform,folder,web in [('site',site,site),('desktop',desktop,dw)]:
        manifest={
            'release':'Release_V1.0','platform':platform,'site_version':'4.152.0','desktop_version':'2.83.0',
            'base_commit':run('git','rev-parse','HEAD').strip(),
            'patches':[p.name for p in patches],
            'baseline_sha256':sha((ROOT/'SchoolDeskPro-v2.55.0-win64.zip').read_bytes()),
            'files':{str(f.relative_to(folder)):{'sha256':sha(f.read_bytes()),'bytes':f.stat().st_size,'source':origins[platform].get(str(f.relative_to(web)) if f.is_relative_to(web) else '', 'release integration / bundled dependency')} for f in sorted(folder.rglob('*')) if f.is_file()}
        }
        (folder/'RELEASE-MANIFEST.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n')
    return site,desktop

def archive(folder,name,prefix=''):
    path=ROOT/name
    with zipfile.ZipFile(path,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        for p in sorted(folder.rglob('*')):
            rel=prefix+str(p.relative_to(folder))
            if p.is_dir():
                if any(p.iterdir()):continue
                rel+='/'
            info=zipfile.ZipInfo(rel,STAMP);info.compress_type=zipfile.ZIP_DEFLATED
            info.external_attr=((0o40755 if p.is_dir() else 0o100644)<<16)
            z.writestr(info,b'' if p.is_dir() else p.read_bytes())
    return {'file':name,'bytes':path.stat().st_size,'sha256':sha(path.read_bytes())}
if __name__=='__main__':
    ap=argparse.ArgumentParser();ap.add_argument('--stage-only',action='store_true');args=ap.parse_args()
    site,desktop=assemble()
    if not args.stage_only:
        outputs=[archive(site,'Release_V1.0-Site.zip'),archive(desktop,'Release_V1.0-Desktop.zip','SchoolDeskPro/')]
        (ROOT/'Release_V1.0-SHA256SUMS.txt').write_text(''.join(x['sha256']+'  '+x['file']+'\n' for x in outputs));print(json.dumps(outputs,indent=2))
    print('Stage:',STAGE)
