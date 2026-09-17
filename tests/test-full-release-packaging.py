#!/usr/bin/env python3
"""Validate full release ZIPs, provenance, clean schemas and portable payloads."""
import hashlib, importlib.util, json, re, sqlite3, struct, unittest, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SPEC=importlib.util.spec_from_file_location('full_builder',ROOT/'scripts/build-full-release.py')
BUILDER=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(BUILDER)
class FullRelease(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.bundles={}
        for platform,title,prefix in [('site','Site',''),('desktop','Desktop','SchoolDeskPro/')]:
            z=zipfile.ZipFile(ROOT/f'Release_V1.0-{title}.zip')
            cls.bundles[platform]=(z,prefix,'' if platform=='site' else 'www/')
    def test_01_complete_payload(self):
        required=['index.php','admin-login.php','installer.php','includes/release_install.php','includes/db.php','includes/install_guard.php','includes/db_sqlite_compat.php','entry-cards.php','entry-card-logo.php','attendance-scanner.php','attendance-scanner-legacy.php','class-exam-delete.php','includes/teacher_weekly_schedule.php','class-exam-sync-api.php','desk-sync-api.php','assets/js/jsqr.min.js','assets/js/qrcode-generator.js','assets/vendor/chart.umd.min.js','vendor/tcpdf/tcpdf.php','vendor/tcpdf/fonts/dejavusans.z','assets/templates/teacher-class-list.docx','assets/templates/school-students.docx','uploads/Vazirmatn/Vazirmatn-Regular.ttf','uploads/B-Titr/B-Titr.ttf','config/install-access.example.php','README-FA.md']
        for platform,(z,p,w) in self.bundles.items():
            self.assertGreater(len(z.namelist()),200)
            for rel in required:self.assertIn(p+w+rel,z.namelist(),(platform,rel))
    def test_02_no_live_state(self):
        for platform,(z,p,w) in self.bundles.items():
            for rel in ['config/database.php','config/installed.lock','config/desk-sync-key.php','config/install-access.php','database.sqlite']:
                self.assertNotIn(p+w+rel,z.namelist())
            for name in z.namelist():
                if name.endswith('/'):continue
                self.assertFalse(re.search(r'\.(sqlite(?:3|-wal|-shm)?|db|log|bak|env)$',name,re.I),name)
                self.assertFalse('/profile/' in name or '/data/' in name,name)
                if '/uploads/' in '/'+name:self.assertTrue(name.endswith(('.ttf','.woff','.woff2','/.htaccess')),name)
    def test_03_structure_only_sqlite_and_live_triggers(self):
        for platform,(z,p,w) in self.bundles.items():
            statements=json.loads(z.read(p+w+'sql/install-sqlite.json'))
            self.assertTrue(all(re.match(r'^(CREATE|ALTER|PRAGMA|SET)\b',s) for s in statements))
            db=sqlite3.connect(':memory:');db.executescript(z.read(p+w+'sql/schema-sqlite.sql').decode())
            tables=[x[0] for x in db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]
            self.assertGreaterEqual(len(tables),47)
            for table in tables:self.assertEqual(db.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0],0,(platform,table))
            self.assertGreater(db.execute("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger'").fetchone()[0],90)
            db.execute("INSERT INTO admins(username,password,name,role,status) VALUES('trigger-test','not-a-real-password','test','super_admin',1)")
            self.assertGreater(db.execute("SELECT COUNT(*) FROM desk_change_log WHERE tbl='admins'").fetchone()[0],0)
    def test_04_mysql_schema_no_samples_and_correct_index(self):
        for _,(z,p,w) in self.bundles.items():
            statements=json.loads(z.read(p+w+'sql/install-mysql.json'))
            self.assertTrue(all(re.match(r'^(CREATE|ALTER|SET)\b',s) for s in statements))
            self.assertEqual(sum(s.startswith('CREATE TABLE') for s in statements),47)
            index=next(s for s in statements if 'ADD INDEX `idx_student_created`' in s)
            self.assertIn('`created_at_jalali`',index)
    def test_05_cumulative_latest_bytes(self):
        latest={}
        for patch in sorted(ROOT.glob('update-v4.*'),key=BUILDER.version):
            for f in patch.rglob('*'):
                if f.is_file() and f.suffix.lower() not in ['.txt','.md','.zip','.csv']:
                    latest[str(f.relative_to(patch))]=f
        transformed={'.htaccess','includes/functions.php','includes/footer.php','class-exam-sync-api.php','sql/database.sql','sql/schema-sqlite.sql'}
        for platform,(z,p,w) in self.bundles.items():
            for rel,source in latest.items():
                if rel in transformed:continue
                if rel=='desk-sync-api.php':
                    expected=re.sub(r"define\('DESK_SYNC_KEY',\s*'[^']+'\);", "require_once __DIR__.'/config/config.php';\ndefine('DESK_SYNC_KEY', require __DIR__.'/config/desk-sync-key.php');",source.read_text(),count=1).replace('edit desk-sync-api.php','check config/desk-sync-key.php')
                    self.assertEqual(z.read(p+w+rel).decode(),expected,(platform,rel));continue
                expected=source.read_bytes()
                patch=next(parent for parent in source.parents if parent.name.startswith('update-v4.'))
                if platform=='desktop' and BUILDER.version(patch)<(4,125,0):
                    # Preserve documented desktop adaptations already present in the full 4.124 baseline.
                    with zipfile.ZipFile(ROOT/'SchoolDeskPro-v2.55.0-win64.zip') as baseline:
                        if 'SchoolDeskPro/www/'+rel in baseline.namelist():expected=baseline.read('SchoolDeskPro/www/'+rel)
                self.assertEqual(z.read(p+w+rel),expected,(platform,rel))
    def test_06_no_historical_shared_sync_key(self):
        old=(ROOT/'desktop-app-v2/server/desk-sync-api.php').read_text()
        old_key=re.search(r"define\('DESK_SYNC_KEY',\s*'([^']+)'",old).group(1).encode()
        for _,(z,p,w) in self.bundles.items():
            for name in z.namelist():
                if name.endswith(('.php','.sql','.json','.md','.txt')):self.assertNotIn(old_key,z.read(name),name)
            self.assertIn(b"config/desk-sync-key.php",z.read(p+w+'desk-sync-api.php'))
            self.assertIn(b"config/desk-sync-key.php",z.read(p+w+'class-exam-sync-api.php'))
    def test_07_manifests_and_safe_zip_paths(self):
        for platform,(z,p,w) in self.bundles.items():
            manifest=json.loads(z.read(p+'RELEASE-MANIFEST.json'))
            self.assertEqual(manifest['platform'],platform)
            self.assertEqual(manifest['patches'][-1],'update-v4.152.0')
            files={x.filename[len(p):] for x in z.infolist() if not x.is_dir() and x.filename!=p+'RELEASE-MANIFEST.json'}
            self.assertEqual(files,set(manifest['files']))
            for rel,entry in manifest['files'].items():
                b=z.read(p+rel);self.assertEqual(hashlib.sha256(b).hexdigest(),entry['sha256']);self.assertEqual(len(b),entry['bytes'])
            for item in z.infolist():
                self.assertFalse(item.filename.startswith('/') or '..' in Path(item.filename).parts)
                self.assertNotEqual((item.external_attr>>16)&0o170000,0o120000)
                self.assertEqual(item.date_time,BUILDER.STAMP)
    def test_08_windows_runtime_and_rebuilt_launcher(self):
        z,p,w=self.bundles['desktop']
        for rel in ['php/php.exe','php/php8.dll','php/ext/php_pdo_sqlite.dll','php/ext/php_gd.dll','php/ext/php_curl.dll','php/cacert.pem','php/php.ini','www/router.php','www/desk-prepend.php','www/desk-sync-daemon.php','licenses/PHP-LICENSE.txt']:
            self.assertIn(p+rel,z.namelist())
        exe=z.read(p+'SchoolDeskPro.exe');self.assertEqual(exe[:2],b'MZ');pe=struct.unpack_from('<I',exe,0x3c)[0]
        self.assertEqual(exe[pe:pe+4],b'PE\0\0');self.assertEqual(struct.unpack_from('<H',exe,pe+4)[0],0x8664)
        self.assertEqual(struct.unpack_from('<H',exe,pe+24)[0],0x20b)
        self.assertEqual(struct.unpack_from('<H',exe,pe+24+68)[0],2)
        with zipfile.ZipFile(ROOT/'SchoolDeskPro-v2.55.0-win64.zip') as old:self.assertNotEqual(exe,old.read('SchoolDeskPro/SchoolDeskPro.exe'))
        self.assertNotIn(b'libgcc_s',exe.lower())
    def test_09_static_access_and_distribution(self):
        for platform,(z,p,w) in self.bundles.items():
            for d in ['config','includes','sql','backups','vendor']:
                self.assertIn(b'Require all denied',z.read(p+w+d+'/.htaccess'))
            uploads=z.read(p+w+'uploads/.htaccess');self.assertIn(b'php_flag engine off',uploads);self.assertIn(b'RemoveHandler',uploads)
            self.assertIn(platform.encode(),z.read(p+w+'config/release.php'))
        z,p,w=self.bundles['site'];self.assertIn(b"'super_admin'",z.read(p+w+'desk-sync.php'))
    def test_10_outer_checksums(self):
        lines=(ROOT/'Release_V1.0-SHA256SUMS.txt').read_text().splitlines();self.assertEqual(len(lines),2)
        for line in lines:
            digest,name=line.split();self.assertEqual(hashlib.sha256((ROOT/name).read_bytes()).hexdigest(),digest)
if __name__=='__main__':unittest.main(verbosity=2)
