<?php
/**
 * Site-side Desktop Synchronization Status & Connection Info (desk-sync.php)
 *
 * بررسی وضعیت همگام‌سازی با نرم‌افزار دسکتاپ و نمایش اطلاعات لازم برای اتصال
 * (آدرس سرور و کلید همگام‌سازی DESK_SYNC_KEY) به صورت کاملاً خودکار.
 */

require_once __DIR__ . '/includes/auth.php';
$admin = is_admin_logged_in() ? DB::fetch('SELECT role,status FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]) : null;
if (!$admin || $admin['role'] !== 'super_admin' || !(int)$admin['status']) {
    http_response_code(403);
    exit('فقط مدیر کل به کلید اتصال دسترسی دارد.');
}

if (!function_exists('site_resolve_or_create_sync_key')) {
    function site_resolve_or_create_sync_key(): string {
        $cfgDir = __DIR__ . '/config';
        $keyFile = $cfgDir . '/desk-sync-key.php';
        if (is_file($keyFile)) {
            $k = require $keyFile;
            if (is_string($k) && strlen($k) >= 16 && strpos($k, 'CHANGE-ME') === false) {
                return $k;
            }
        }
        // بررسی کلید در desk-sync-api.php (سازگاری با نصب‌های قدیمی)
        $apiFile = __DIR__ . '/desk-sync-api.php';
        if (is_file($apiFile)) {
            $src = (string)@file_get_contents($apiFile, false, null, 0, 131072);
            if (preg_match("~define\\s*\\(\\s*['\"]DESK_SYNC_KEY['\"]\\s*,\\s*['\"]((?:[^'\"\\\\]|\\\\.){16,200})['\"]~", $src, $m)) {
                $lit = str_replace(["\\\\", "\\'", '\\"'], ["\\", "'", '"'], $m[1]);
                if (strlen($lit) >= 16 && strpos($lit, 'CHANGE-ME') === false) {
                    if (is_dir($cfgDir)) {
                        @file_put_contents($keyFile, "<?php\nreturn " . var_export($lit, true) . ";\n");
                    }
                    return $lit;
                }
            }
        }
        // بررسی کلید در جدول settings
        $dbKey = trim((string)get_setting('desk_sync_key', ''));
        if (strlen($dbKey) >= 16 && strpos($dbKey, 'CHANGE-ME') === false) {
            if (is_dir($cfgDir)) {
                @file_put_contents($keyFile, "<?php\nreturn " . var_export($dbKey, true) . ";\n");
            }
            return $dbKey;
        }
        // تولید خودکار کلید ۶۴ کاراکتری امن و ذخیره در config/desk-sync-key.php
        $newKey = bin2hex(random_bytes(32));
        if (is_dir($cfgDir)) {
            @file_put_contents($keyFile, "<?php\nreturn " . var_export($newKey, true) . ";\n");
        }
        return $newKey;
    }
}

if (!function_exists('site_sync_collect_status')) {
    function site_sync_collect_status(bool $ensureInfra = false): array {
        $pdo = DB::getInstance()->getPdo();
        $driver = $pdo ? (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) : 'mysql';
        if ($ensureInfra && $pdo && $driver !== 'sqlite') {
            if (!defined('SDP_SYNC_LIB_ONLY')) define('SDP_SYNC_LIB_ONLY', true);
            $epLib = is_file(__DIR__ . '/includes/desk_sync_endpoint.php')
                ? (__DIR__ . '/includes/desk_sync_endpoint.php')
                : (__DIR__ . '/class-exam-sync-api.php');
            if (is_file($epLib)) {
                require_once $epLib;
                if (function_exists('ensure_infra')) {
                    try { ensure_infra($pdo); } catch (Throwable $e) {}
                }
            }
        }

        $hasChangeLog = false;
        $changeCount = 0;
        $lastCursor = 0;
        $lastChangeTs = 0;
        $triggerCount = 0;

        if ($pdo) {
            try {
                if ($driver === 'sqlite') {
                    $chk = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='desk_change_log'")->fetchColumn();
                    $hasChangeLog = (bool)$chk;
                } else {
                    $chk = $pdo->query("SHOW TABLES LIKE 'desk_change_log'")->fetchColumn();
                    $hasChangeLog = (bool)$chk;
                    $triggerCount = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'desk_sync_%'")->fetchColumn();
                }
                if ($hasChangeLog) {
                    $row = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(ts), 0) AS max_ts FROM desk_change_log")->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        $changeCount = (int)($row['cnt'] ?? 0);
                        $lastCursor  = (int)($row['max_id'] ?? 0);
                        $lastChangeTs = (int)($row['max_ts'] ?? 0);
                    }
                }
            } catch (Throwable $e) {}
        }

        $lastContact = (int)get_setting('desk_sync_server_last_contact', '0');
        $lastAction  = (string)get_setting('desk_sync_server_last_action', '');
        $lastIp      = (string)get_setting('desk_sync_server_last_ip', '');

        $actionLabels = [
            'handshake'  => 'بررسی اتصال و نصب تریگرها (Handshake)',
            'status'     => 'بررسی وضعیت سرور',
            'snapshot'   => 'دریافت اولیه اطلاعات (Snapshot)',
            'recon'      => 'تطبیق کامل جداول (Reconcile)',
            'pull'       => 'دریافت تغییرات جدید از سایت (Pull)',
            'push'       => 'ارسال تغییرات دسکتاپ به سایت (Push)',
            'files_list' => 'همگام‌سازی فهرست فایل‌ها',
            'files_stat' => 'بررسی وضعیت فایل‌ها',
            'file_get'   => 'دریافت فایل ضمیمه از سایت',
            'file_put'   => 'ارسال فایل ضمیمه به سایت',
            'exam_pages' => 'تبدیل صفحات آزمون روی سرور',
            'bot_outbox' => 'ارسال صف پیام‌های ربات',
        ];

        $effectiveLastTs = max($lastContact, $lastChangeTs);
        $agoText = 'هنوز اتصالی از نرم‌افزار دسکتاپ ثبت نشده است';
        if ($lastContact > 0) {
            $diff = max(0, time() - $lastContact);
            if ($diff < 60) $agoText = tr_num($diff, 'fa') . ' ثانیه پیش';
            elseif ($diff < 3600) $agoText = tr_num((int)floor($diff / 60), 'fa') . ' دقیقه پیش';
            elseif ($diff < 86400) $agoText = tr_num((int)floor($diff / 3600), 'fa') . ' ساعت پیش';
            else $agoText = tr_num((int)floor($diff / 86400), 'fa') . ' روز پیش';
            if (function_exists('jdate')) {
                $agoText .= ' (' . jdate('Y/m/d H:i:s', $lastContact) . ')';
            }
        }

        $epMain = is_file(__DIR__ . '/desk-sync-api.php');
        $epAlt  = is_file(__DIR__ . '/class-exam-sync-api.php');
        $epInc  = is_file(__DIR__ . '/includes/desk_sync_endpoint.php');
        $keyFileExists = is_file(__DIR__ . '/config/desk-sync-key.php');

        return [
            'ok' => true,
            'ready' => ($epMain || $epAlt || $epInc) && $keyFileExists,
            'has_change_log' => $hasChangeLog,
            'trigger_count' => $triggerCount,
            'change_count' => $changeCount,
            'last_cursor' => $lastCursor,
            'last_contact' => $lastContact,
            'last_contact_text' => $agoText,
            'last_action' => $lastAction,
            'last_action_label' => $actionLabels[$lastAction] ?? ($lastAction ?: '—'),
            'last_ip' => $lastIp ?: '—',
            'endpoints' => [
                'desk_sync_api' => $epMain,
                'class_exam_sync_api' => $epAlt,
                'relay_engine' => $epInc,
                'key_file' => $keyFileExists,
            ],
        ];
    }
}

$key = site_resolve_or_create_sync_key();

// پاسخ JSON برای بررسی لحظه‌ای وضعیت
if (($_GET['ajax'] ?? '') === 'status') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(site_sync_collect_status(!empty($_GET['init'])), JSON_UNESCAPED_UNICODE);
    exit;
}

// عملیات آماده‌سازی/نصب خودکار جدول و تریگرهای همگام‌سازی روی سرور
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'ensure_server_sync') {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        site_sync_collect_status(true);
        set_flash_message('success', 'جدول تغییرات و تریگرهای همگام‌سازی سرور با موفقیت بررسی و فعال‌سازی شدند.');
    } else {
        set_flash_message('error', 'خطای امنیتی CSRF');
    }
    $emb = (!empty($_GET['embedded']) || !empty($_POST['embedded'])) ? '?embedded=1' : '';
    redirect('desk-sync.php' . $emb);
}

$status = site_sync_collect_status(true);

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443)
    || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'example.com';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/desk-sync.php')), '/');
$siteBaseUrl = $scheme . '://' . $host . ($scriptDir !== '' && $scriptDir !== '/' ? $scriptDir : '');
$embedded = !empty($_GET['embedded']);

require_once __DIR__ . '/includes/header.php';
?>
<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex justify-between items-center flex-wrap gap-3">
        <div>
            <h2 class="text-2xl font-bold">همگام‌سازی با نرم‌افزار دسکتاپ (وضعیت سرور و اطلاعات اتصال)</h2>
            <p class="text-xs text-muted mt-1">بررسی وضعیت ارتباط سرور با نرم‌افزار دسکتاپ و دریافت آدرس سرور و کلید همگام‌سازی بدون نیاز به ویرایش دستی فایل‌ها</p>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($status['last_contact'] > 0): ?>
                <span id="srvSyncBadge" class="px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700">● متصل به نرم‌افزار دسکتاپ</span>
            <?php else: ?>
                <span id="srvSyncBadge" class="px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700">● آماده اتصال نرم‌افزار دسکتاپ</span>
            <?php endif; ?>
            <button type="button" onclick="refreshServerSyncStatus(true)" class="btn btn-secondary text-xs">🔄 بررسی لحظه‌ای وضعیت</button>
        </div>
    </div>

    <!-- وضعیت همگام‌سازی سرور با نرم‌افزار دسکتاپ -->
    <div class="card p-5 space-y-4">
        <div class="flex justify-between items-center border-b pb-3">
            <h3 class="font-bold text-sm text-slate-800">وضعیت همگام‌سازی سرور و آخرین ارتباط نرم‌افزار دسکتاپ</h3>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="do" value="ensure_server_sync">
                <?php if ($embedded): ?><input type="hidden" name="embedded" value="1"><?php endif; ?>
                <button type="submit" class="btn btn-outline text-xs px-3 py-1">بررسی و بازسازی تریگرهای همگام‌سازی</button>
            </form>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 text-center">
            <div class="p-3 rounded-lg bg-slate-50 border">
                <div class="text-xs text-muted">وضعیت درگاه همگام‌سازی</div>
                <div id="stEndpointReady" class="font-bold text-sm mt-1 <?php echo $status['ready'] ? 'text-emerald-600' : 'text-red-600'; ?>">
                    <?php echo $status['ready'] ? 'فعال و آماده به کار ✔' : 'نیاز به بررسی'; ?>
                </div>
            </div>
            <div class="p-3 rounded-lg bg-slate-50 border">
                <div class="text-xs text-muted">آخرین ارتباط نرم‌افزار دسکتاپ</div>
                <div id="stLastContact" class="font-bold text-xs mt-1 text-slate-800"><?php echo clean($status['last_contact_text']); ?></div>
            </div>
            <div class="p-3 rounded-lg bg-slate-50 border">
                <div class="text-xs text-muted">آخرین عملیات همگام‌سازی</div>
                <div id="stLastAction" class="font-bold text-xs mt-1 text-blue-700"><?php echo clean($status['last_action_label']); ?></div>
            </div>
            <div class="p-3 rounded-lg bg-slate-50 border">
                <div class="text-xs text-muted">تغییرات ثبت‌شده / نشانگر سرور</div>
                <div id="stChangeStats" class="font-mono font-bold text-sm mt-1">
                    <?php echo tr_num($status['change_count'], 'fa'); ?> تغییر (Cursor: #<?php echo tr_num($status['last_cursor'], 'fa'); ?>)
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs text-slate-600 bg-slate-50 p-3 rounded-lg border">
            <div>جدول ثبت تغییرات (<code>desk_change_log</code>): <b id="stHasLog" class="<?php echo $status['has_change_log'] ? 'text-emerald-700' : 'text-amber-700'; ?>"><?php echo $status['has_change_log'] ? 'ایجاد شده و فعال' : 'در اولین اتصال ایجاد می‌شود'; ?></b></div>
            <div>تریگرهای خودکار دیتابیس: <b id="stTriggers"><?php echo tr_num($status['trigger_count'], 'fa'); ?> تریگر فعال</b></div>
            <div>آدرس IP آخرین نرم‌افزار متصل: <b id="stLastIp" class="font-mono"><?php echo clean($status['last_ip']); ?></b></div>
        </div>
    </div>

    <!-- اطلاعات اتصال برای وارد کردن در نرم‌افزار دسکتاپ -->
    <div class="card p-5 space-y-4 border-blue-200">
        <h3 class="font-bold text-base text-blue-900">اطلاعات لازم برای راه‌اندازی و همگام‌سازی نرم‌افزار دسکتاپ</h3>
        <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-xs text-emerald-900 leading-6">
            <b>تنظیم خودکار فعال است:</b> کلید همگام‌سازی و درگاه‌های ارتباطی سرور به‌صورت کاملاً خودکار پیکربندی شده‌اند و <b>هیچ نیازی به کپی دستی کلید در فایل <code>desk-sync-api.php</code> نیست</b>. کافی است در زمان راه‌اندازی اولیهٔ نرم‌افزار دسکتاپ (یا در بخش «همگام‌سازی با سایت» نرم‌افزار دسکتاپ)، دو مورد زیر را کپی و وارد نمایید:
        </div>

        <div class="space-y-4">
            <div>
                <label class="block text-xs font-bold mb-1">۱. آدرس سایت / سرور مدرسه (جهت وارد کردن در نرم‌افزار دسکتاپ):</label>
                <div class="flex gap-2">
                    <input id="siteSyncUrlInput" class="form-input font-mono text-xs flex-1" dir="ltr" readonly value="<?php echo clean($siteBaseUrl); ?>" onclick="this.select()">
                    <button type="button" class="btn btn-secondary text-xs px-4" onclick="copySyncField('siteSyncUrlInput','آدرس سرور کپی شد')">کپی آدرس سرور</button>
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold mb-1">۲. کلید اختصاصی همگام‌سازی (<code>DESK_SYNC_KEY</code>):</label>
                <div class="flex gap-2">
                    <input id="deskSyncKeyInput" class="form-input font-mono text-xs flex-1" dir="ltr" readonly value="<?php echo clean($key); ?>" onclick="this.select()">
                    <button type="button" class="btn btn-primary text-xs px-4" onclick="copySyncField('deskSyncKeyInput','کلید همگام‌سازی کپی شد')">کپی کلید همگام‌سازی</button>
                </div>
                <span class="text-[11px] text-muted mt-1 block">این کلید محرمانه است و فقط باید در نرم‌افزار دسکتاپ مدرسه وارد شود.</span>
            </div>
        </div>
    </div>
</div>

<script>
function copySyncField(inputId, msg) {
    var el = document.getElementById(inputId);
    if (!el) return;
    el.select();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.value);
    } else {
        document.execCommand('copy');
    }
    if (typeof showToast === 'function') showToast(msg);
}

function refreshServerSyncStatus(initInfra) {
    var url = 'desk-sync.php?ajax=status' + (initInfra ? '&init=1' : '') + '&_t=' + Date.now();
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(s) {
            if (!s || !s.ok) return;
            var bdg = document.getElementById('srvSyncBadge');
            if (bdg) {
                if (s.last_contact > 0) {
                    bdg.textContent = '● متصل به نرم‌افزار دسکتاپ';
                    bdg.className = 'px-3 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-700';
                } else {
                    bdg.textContent = '● آماده اتصال نرم‌افزار دسکتاپ';
                    bdg.className = 'px-3 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700';
                }
            }
            var lc = document.getElementById('stLastContact');
            if (lc) lc.textContent = s.last_contact_text || '—';
            var la = document.getElementById('stLastAction');
            if (la) la.textContent = s.last_action_label || '—';
            var cs = document.getElementById('stChangeStats');
            if (cs) cs.textContent = (s.change_count || 0).toLocaleString('fa-IR') + ' تغییر (Cursor: #' + (s.last_cursor || 0).toLocaleString('fa-IR') + ')';
            var hl = document.getElementById('stHasLog');
            if (hl) {
                hl.textContent = s.has_change_log ? 'ایجاد شده و فعال' : 'در اولین اتصال ایجاد می‌شود';
                hl.className = s.has_change_log ? 'text-emerald-700' : 'text-amber-700';
            }
            var tg = document.getElementById('stTriggers');
            if (tg) tg.textContent = (s.trigger_count || 0).toLocaleString('fa-IR') + ' تریگر فعال';
            var ip = document.getElementById('stLastIp');
            if (ip) ip.textContent = s.last_ip || '—';
        })
        .catch(function() {});
}
setInterval(function() { refreshServerSyncStatus(false); }, 8000);
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
