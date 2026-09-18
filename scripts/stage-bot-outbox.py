from pathlib import Path
import importlib.util,shutil,sys
ROOT=Path(__file__).resolve().parents[1]
COMMON=['attendance.php','includes/bot_helpers.php','includes/bot_outbox.php','includes/bot_admin_ui.php']
SITE=COMMON+['class-exam-sync-api.php','cron/bot-outbox-worker.php']
DESKTOP=COMMON+['includes/desk_sync.php','desk-sync-daemon.php']
def stage(desktop=False):
 spec=importlib.util.spec_from_file_location('prior',ROOT/'scripts/stage-settings-health.py');prior=importlib.util.module_from_spec(spec);spec.loader.exec_module(prior)
 old=prior.stage(desktop)
 dest=ROOT/'.cache/bot-outbox'/('desktop/SchoolDeskPro/reports' if desktop else 'site')
 shutil.copytree(old,dest,dirs_exist_ok=True)
 if desktop:shutil.copyfile(ROOT/'desktop-app-v2/patch/reports-router.php',dest/'router.php')
 for f in DESKTOP if desktop else SITE:
  source=ROOT/'desktop-app-v2/patch/www-desk-sync-daemon.php' if f=='desk-sync-daemon.php' else ROOT/'update-v4.152.0'/f
  p=dest/f;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,p)
 return dest
if __name__=='__main__':print(stage('--desktop' in sys.argv))
