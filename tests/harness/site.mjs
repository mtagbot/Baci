/**
 * پیدا کردن سورس سایت — بدون بالا آوردن PHP.
 * سوئیت‌های جاوااسکریپتی از این استفاده می‌کنند تا سریع و مستقل بمانند.
 *
 * ترتیب جست‌وجو:
 *   ۱) متغیر SITE (مسیر نسبی از ریشهٔ ریپو)
 *   ۲) .arena/current/SchoolDeskPro/www
 *   ۳) جدیدترین SchoolDeskPro-v*-win64.zip در ریشهٔ ریپو (استخراج خودکار)
 */
import { readdirSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname, isAbsolute } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';

const HERE = dirname(fileURLToPath(import.meta.url));
export const REPO = join(HERE, '..', '..');

export function newestDesktopBundle() {
  const v = s => s.match(/v([\d.]+)-/)[1].split('.').map(Number);
  return readdirSync(REPO)
    .filter(f => /^SchoolDeskPro-v[\d.]+-win64\.zip$/.test(f))
    .sort((a, b) => { const [x, y] = [v(a), v(b)]; for (let i = 0; i < 3; i++) if ((x[i] || 0) !== (y[i] || 0)) return (y[i] || 0) - (x[i] || 0); return 0; })
    .map(f => join(REPO, f))[0] || null;
}

export function resolveSite() {
  const good = p => p && existsSync(join(p, 'includes', 'db.php'));

  if (process.env.SITE) {
    /* هم مسیر مطلق قبول است هم مسیر نسبی از ریشهٔ ریپو */
    const p = isAbsolute(process.env.SITE) ? process.env.SITE : join(REPO, process.env.SITE);
    if (good(p)) return p;
    throw new Error(`SITE=${process.env.SITE} معتبر نیست (includes/db.php پیدا نشد)`);
  }
  const local = join(REPO, '.arena', 'current', 'SchoolDeskPro', 'www');
  if (good(local)) return local;

  const zip = newestDesktopBundle();
  if (!zip) throw new Error('سورسی پیدا نشد: SITE را ست کنید یا بستهٔ دسکتاپ را در ریشهٔ ریپو بگذارید');
  // A delivery can now be patch-only even when it retains the old bundle name.
  // Never extract it over stale /tmp files and mistake that mixture for a full app.
  const entries = execFileSync('unzip', ['-Z1', zip], { encoding: 'utf8' }).split('\n');
  if (!entries.includes('SchoolDeskPro/www/includes/db.php')) {
    throw new Error('جدیدترین ZIP فقط وصله است؛ SITE را روی www نسخهٔ کامل نصب‌شده و PATCH را روی update-v4.152.0 تنظیم کنید.');
  }
  const dest = join(tmpdir(), 'baci-site');
  mkdirSync(dest, { recursive: true });
  execFileSync('unzip', ['-qo', zip, '-d', dest]);
  return join(dest, 'SchoolDeskPro', 'www');
}

/* اگر PATCH ست شده باشد، فایل وصله جایگزین فایل سایت می‌شود */
export function resolveFile(rel) {
  if (process.env.PATCH) {
    const p = join(REPO, process.env.PATCH, rel);
    if (existsSync(p)) return p;
  }
  return join(resolveSite(), rel);
}
