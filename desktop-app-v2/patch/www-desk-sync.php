<?php
// File: desk-sync.php  (SchoolDesk Pro — صفحه تنظیمات و وضعیت همگام‌سازی)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/desk_sync.php';

if (!is_admin_logged_in()) redirect('admin-login.php');

/* The software-update engine may be missing on a very old install; the sync
   page must keep working (it just cannot report the update status then). */
if (!function_exists('desk_update_badge')) {
    function desk_update_badge(): array {
        $file = __DIR__ . '/includes/desk_update.php';
        if (!is_file($file)) {
            return ['ok' => false, 'code' => 'missing-engine', 'tone' => 'warn',
                    'label' => 'وضعیت به‌روزرسانی نرم‌افزار نامعلوم است',
                    'detail' => 'موتور به‌روزرسانی روی این نصب پیدا نشد؛ یک‌بار بستهٔ اصلاحی را نصب کنید.'];
        }
        require_once $file;
        return desk_update_status();
    }
}

/* AJAX: browser-direct sync bridge (when php.exe libcurl is blocked by host WAF/JA3/proxy with 403) */
if (isset($_GET['ajax']) && strpos((string)$_GET['ajax'], 'bridge_') === 0) {
    header('Content-Type: application/json; charset=utf-8');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $op = (string)$_GET['ajax'];
    try {
        if ($op === 'bridge_init') {
            echo json_encode(DeskSync::bridgeInit(), JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'bridge_next_pull') {
            echo json_encode(DeskSync::bridgeNextPull(), JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'bridge_apply_pull') {
            $raw = json_decode((string)($_POST['res'] ?? ''), true);
            if (!is_array($raw)) throw new Exception('پاسخ دریافتی نامعتبر است');
            echo json_encode(DeskSync::bridgeApplyPull($raw), JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'bridge_next_push') {
            echo json_encode(DeskSync::bridgeNextPush(), JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'bridge_ack_push') {
            echo json_encode(DeskSync::bridgeAckPush((int)($_POST['max_id'] ?? 0), (int)($_POST['count'] ?? 0)), JSON_UNESCAPED_UNICODE);
        } elseif ($op === 'bridge_finish') {
            $fin = DeskSync::bridgeFinish((string)($_POST['warn'] ?? ''));
            $hbFile = dirname(__DIR__) . '/data/sync-heartbeat.json';
            $engMtime = ((int)@filemtime(__DIR__ . '/includes/desk_sync.php')) . ':' . ((int)@filemtime(__DIR__ . '/desk-sync-daemon.php'));
            @file_put_contents($hbFile, json_encode([
                'phase' => 'settled', 'ts' => time(), 'daemon' => 1, 'ok' => true,
                'engine_mtime' => $engMtime, 'server_verified' => true,
                'fails' => 0, 'alert' => false, 'retry_in' => 0,
                'pending' => $fin['pending'], 'pushed' => (int)($_POST['pushed'] ?? 0),
                'pulled' => (int)($_POST['pulled'] ?? 0), 'update' => desk_update_badge(), 'error' => '',
            ], JSON_UNESCAPED_UNICODE), LOCK_EX);
            echo json_encode($fin, JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['ok' => false, 'error' => 'unknown bridge op'], JSON_UNESCAPED_UNICODE);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* AJAX: run one sync cycle now (also used by the background timer) */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'run') {
    header('Content-Type: application/json; charset=utf-8');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    set_time_limit(300);
    $res = isset($_GET['force']) ? DeskSync::run(true) : DeskSync::runIfDue();
    $res['status'] = DeskSync::status();
    $res['pending'] = DeskSync::pendingCount();
    $res['update'] = desk_update_badge();
    if (!empty($res['ok']) && empty($res['skipped'])) {
        $hbFile = dirname(__DIR__) . '/data/sync-heartbeat.json';
        $engMtime = ((int)@filemtime(__DIR__ . '/includes/desk_sync.php')) . ':' . ((int)@filemtime(__DIR__ . '/desk-sync-daemon.php'));
        @file_put_contents($hbFile, json_encode([
            'phase' => 'settled', 'ts' => time(), 'daemon' => 1, 'ok' => true,
            'engine_mtime' => $engMtime, 'server_verified' => true,
            'fails' => 0, 'alert' => false, 'retry_in' => 0,
            'pending' => $res['pending'], 'pushed' => $res['pushed'] ?? 0, 'pulled' => $res['pulled'] ?? 0,
            'update' => $res['update'], 'error' => '',
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: real-time heartbeat.
   v2.11.0: the page NO LONGER executes the sync itself — all network I/O
   lives in desk-sync-daemon.php (separate hidden php.exe). Here we only
   read the daemon's status file, which returns instantly even when the
   internet is down. Fallback: if the daemon is not running (old launcher,
   blocked spawn), we run the old inline tick so sync still works. */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'tick') {
    header('Content-Type: application/json; charset=utf-8');
    $hb = dirname(__DIR__) . '/data/sync-heartbeat.json';
    @touch(dirname(__DIR__) . '/data/app-alive.txt');
    if (is_file($hb) && time() - (int)@filemtime($hb) < 30) {
        $j = json_decode((string)@file_get_contents($hb), true);
        if (is_array($j)) {
            $j['pending'] = DeskSync::pendingCount();   // live number (cheap local query)
            echo json_encode($j, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    ignore_user_abort(true);
    set_time_limit(300);
    echo json_encode(DeskSync::tick(), JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: software update status (local state file only — never a network call,
   so this stays instant even when the internet is down) */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'update') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'pending' => DeskSync::pendingCount(),
                      'update' => desk_update_badge()], JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: unsent-changes count (used by the exit warning) */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'pending') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'pending' => DeskSync::pendingCount(),
                      'enabled' => DeskSync::enabled()], JSON_UNESCAPED_UNICODE);
    exit;
}

/* save settings */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sync'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('desk-sync.php');
    }
    $url = trim($_POST['sync_url'] ?? '');
    if ($url !== '') {
        // no scheme typed → default to https:// (engine falls back to http:// automatically)
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
        if (!preg_match('#/desk-sync-api\.php$#', $url)) $url = rtrim($url, '/') . '/desk-sync-api.php';
    }
    DeskSync::setCfg('desk_sync_url', $url);
    DeskSync::setCfg('desk_sync_key', trim($_POST['sync_key'] ?? ''));
    DeskSync::setCfg('desk_sync_enabled', isset($_POST['sync_enabled']) ? '1' : '0');
    DeskSync::setCfg('desk_sync_mode', ($_POST['desk_sync_mode'] ?? 'full') === 'event' ? 'event' : 'full');
    if (isset($_POST['reset_state'])) {
        DeskSync::setCfg('desk_sync_snapshot_done', '0');
        DeskSync::setCfg('desk_sync_cursor', '0');
        DeskSync::setCfg('desk_sync_state', '{}');
        DeskSync::setCfg('desk_sync_err', '');
    }
    set_flash_message('success', 'تنظیمات همگام‌سازی ذخیره شد.');
    redirect('desk-sync.php');
}

$st = DeskSync::status();
$upd = desk_update_badge();
require_once __DIR__ . '/includes/header.php';

function fa_ago($ts) {
    if (!$ts) return 'هرگز';
    $d = time() - $ts;
    if ($d < 60) return 'چند لحظه پیش';
    if ($d < 3600) return tr_num((string)floor($d / 60), 'fa') . ' دقیقه پیش';
    if ($d < 86400) return tr_num((string)floor($d / 3600), 'fa') . ' ساعت پیش';
    return tr_num((string)floor($d / 86400), 'fa') . ' روز پیش';
}
?>
<?php
$updTones = ['ok' => 'border-green-500 bg-green-50 text-green-800',
             'warn' => 'border-amber-500 bg-amber-50 text-amber-800',
             'bad' => 'border-red-500 bg-red-50 text-red-700',
             'info' => 'border-blue-500 bg-blue-50 text-blue-800'];
$updTone = $updTones[$upd['tone']] ?? $updTones['info'];
$syncLine = !$st['enabled']
    ? 'همگام‌سازی خودکار با سایت خاموش است؛ داده‌ها فقط با اجرای دستی رد و بدل می‌شوند.'
    : ($st['last_ok'] > 0
        ? 'داده‌ها: آخرین تبادل موفق ' . fa_ago($st['last_ok']) . ($st['last_err'] !== '' ? ' — آخرین خطا: ' . $st['last_err'] : ' — بدون خطا')
        : 'داده‌ها: هنوز تبادل موفقی با سایت ثبت نشده است.');
?>
<div class="max-w-3xl mx-auto">
    <div id="deskUpdateStatus" data-code="<?php echo clean($upd['code']); ?>"
         class="card p-4 mb-6 border-r-4 <?php echo $updTone; ?>" role="status" aria-live="polite">
        <div class="font-bold" id="deskUpdateLabel"><?php echo clean($upd['label']); ?></div>
        <div class="text-sm mt-1" id="deskUpdateDetail"><?php echo clean($upd['detail']); ?></div>
        <div class="text-xs mt-2 text-muted" id="deskUpdateSync"><?php echo clean($syncLine); ?></div>
    </div>
    <div class="card p-6 mb-6">
        <h3 class="text-lg font-bold mb-4 text-primary border-b pb-2">همگام‌سازی با سایت مدرسه</h3>
        <p class="text-sm mb-4 text-muted">
            برنامه به‌صورت خودکار و لحظه‌ای تغییرات را با سایت شما رد و بدل می‌کند
            (دوطرفه: هم تغییرات اینجا به سایت می‌رود، هم تغییرات سایت به اینجا می‌آید).
            همگام‌سازی در پس‌زمینه و در یک پردازه جدا انجام می‌شود؛ بنابراین حتی وقتی
            اینترنت قطع است برنامه با همان سرعت همیشگی کار می‌کند و همه تغییرات شما
            در بانک اطلاعاتی داخلی محفوظ می‌ماند تا به محض اتصال، خودکار ارسال شود.
            علاوه بر ارسال و دریافت رویدادها، در هر همگام‌سازی دستی (و خودکار ساعتی)
            محتوای جدول‌های دسکتاپ با سایت «مقایسه کامل» می‌شود و اگر جدولی ناهمسان
            باشد، عین داده سایت دوباره دریافت می‌شود تا دو بانک همیشه همسان بمانند.
            نسخهٔ نرم‌افزار هم خودکار است: برنامه در همین همگام‌سازی، نسخهٔ منتشرشده در سایت را
            بررسی می‌کند و اگر تازه‌تر باشد آن را خودش دریافت و نصب می‌کند — نیاز به دانلود یا
            نصب دستی بسته نیست (فقط اگر نسخهٔ فایل اجرایی هم عوض شده باشد، یک‌بار بستن و بازکردن
            برنامه لازم است).
            برای فعال‌سازی: فایل <code dir="ltr">desk-sync-api.php</code> (داخل پوشه server همین بسته)
            را در پوشه‌ای از سایت که سامانه در آن نصب است آپلود کنید (کنار index.php).
            کلید اتصال از قبل در فایل و در برنامه تنظیم شده و نیازی به تغییر ندارد.
            سپس آدرس همان پوشه را در پایین وارد کنید — مثلا اگر سامانه در
            <code dir="ltr">example.com/reports</code> نصب است، همان را بنویسید.
        </p>
        <form method="POST" action="desk-sync.php">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <div class="mb-4">
                <label class="form-label">آدرس سایت</label>
                <input type="text" name="sync_url" dir="ltr" class="form-input w-full"
                       placeholder="https://school.example.com/reports"
                       value="<?php echo clean($st['url']); ?>">
                <div class="text-xs text-muted mt-1">آدرس دقیق پوشه‌ای که سامانه روی سایت در آن نصب است (با یا بدون https فرقی ندارد)</div>
            </div>
            <div class="mb-4">
                <label class="form-label">کلید همگام‌سازی (همان کلید داخل desk-sync-api.php)</label>
                <input type="text" name="sync_key" dir="ltr" class="form-input w-full"
                       value="<?php echo clean(DeskSync::getCfg('desk_sync_key')); ?>">
            </div>
            <div class="mb-4 flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="sync_enabled" value="1" <?php echo $st['enabled'] ? 'checked' : ''; ?>>
                    <span>همگام‌سازی خودکار فعال باشد</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="reset_state" value="1">
                    <span class="text-sm text-muted">شروع مجدد از صفر (دریافت کامل دوباره از سرور)</span>
                </label>
            </div>
            <div class="mb-4">
                <label class="form-label">حالت ترافیک با سایت</label>
                <div class="space-y-2">
                    <label class="flex items-start gap-2">
                        <input type="radio" name="desk_sync_mode" value="full" class="mt-1" <?php echo ($st['mode'] ?? 'full') === 'full' ? 'checked' : ''; ?>>
                        <span><b>کامل (پیش‌فرض)</b>
                            <span class="block text-xs text-muted mt-0.5">هر ~۳۰ ثانیه یک‌بار بررسی می‌کند سایت تغییری داشته باشد یا نه؛ تغییری که روی وب‌سایت زده شود تا ~۳۰ ثانیه بعد در دسکتاپ دیده می‌شود. درخواست‌های روزانه: صدها.</span></span>
                    </label>
                    <label class="flex items-start gap-2">
                        <input type="radio" name="desk_sync_mode" value="event" class="mt-1" <?php echo ($st['mode'] ?? 'full') === 'event' ? 'checked' : ''; ?>>
                        <span><b>فقط رویداد (کم‌ترافیک‌ترین)</b>
                            <span class="block text-xs text-muted mt-0.5">بدون هیچ بررسی دوره‌ای؛ درخواست همگام‌سازی فقط با ثبت/ویرایش/حذف واقعی انجام می‌شود (فوری، همان‌همان). تغییری که روی وب‌سایت زده شود، در دسکتاپ تا ۱ ساعت بعد — یا بلافاصله با اولین ویرایش بعدی در دسکتاپ — اعمال می‌شود. یک چک آشتی هر ساعت کار می‌کند. درخواست‌های روزانه: ده‌ها.</span></span>
                    </label>
                </div>
            </div>
            <div class="flex gap-3">
                <button type="submit" name="save_sync" value="1" class="btn btn-primary">ذخیره تنظیمات</button>
                <button type="button" id="syncNowBtn" class="btn btn-success">همگام‌سازی همین حالا</button>
            </div>
        </form>
    </div>

    <div class="card p-6">
        <h4 class="font-bold mb-3">وضعیت <span class="text-xs font-normal text-muted">(موتور همگام‌سازی دسکتاپ: v2.130.0)</span></h4>
        <table class="w-full text-sm" id="syncStatusTable">
            <tr><td class="py-1 text-muted w-48">وضعیت</td>
                <td><?php echo $st['enabled'] ? '<span class="text-green-600 font-bold">فعال</span>' : '<span class="text-red-600 font-bold">غیرفعال</span>'; ?></td></tr>
            <tr><td class="py-1 text-muted">آخرین اجرا</td><td><?php echo fa_ago($st['last_run']); ?></td></tr>
            <tr><td class="py-1 text-muted">آخرین موفق</td><td><?php echo fa_ago($st['last_ok']); ?></td></tr>
            <tr><td class="py-1 text-muted">دریافت اولیه کامل</td><td><?php echo $st['snapshot'] ? 'انجام شده' : 'هنوز انجام نشده'; ?></td></tr>
            <tr><td class="py-1 text-muted">مجموع ارسال‌شده</td><td><?php echo tr_num((string)$st['pushed'], 'fa'); ?> رکورد</td></tr>
            <tr><td class="py-1 text-muted">مجموع دریافت‌شده</td><td><?php echo tr_num((string)$st['pulled'], 'fa'); ?> رکورد</td></tr>
            <?php if ($st['last_err']): ?>
            <tr><td class="py-1 text-muted">آخرین خطا</td><td class="text-red-600" id="syncLastErrCell"><?php echo clean($st['last_err']); ?></td></tr>
            <?php endif; ?>
        </table>
        <div id="syncRunResult" class="mt-3 text-sm"></div>
    </div>
</div>
<script>
/* Keep the update badge honest without reloading: cheap local read every 30s. */
(function () {
    var box = document.getElementById('deskUpdateStatus');
    if (!box) return;
    var tones = {ok: 'border-green-500 bg-green-50 text-green-800',
                 warn: 'border-amber-500 bg-amber-50 text-amber-800',
                 bad: 'border-red-500 bg-red-50 text-red-700',
                 info: 'border-blue-500 bg-blue-50 text-blue-800'};
    var base = 'card p-4 mb-6 border-r-4 ';
    function refresh() {
        fetch('desk-sync.php?ajax=update').then(function (r) { return r.json(); }).then(function (j) {
            var u = j && j.update;
            if (!u) return;
            box.className = base + (tones[u.tone] || tones.info);
            box.setAttribute('data-code', u.code);
            document.getElementById('deskUpdateLabel').textContent = u.label;
            document.getElementById('deskUpdateDetail').textContent = u.detail;
        }).catch(function () {});
    }
    setInterval(refresh, 30000);
    refresh();
})();

var sdpBridgeFrame = null;
var sdpBridgeReady = false;
var sdpBridgePending = {};
var sdpBridgeReqSeq = 0;

window.addEventListener('message', function (ev) {
    var d = ev.data;
    if (!d || typeof d !== 'object') return;
    if (d.sdp_bridge_ready) {
        sdpBridgeReady = true;
    } else if (d.sdp_bridge_reply && d.id && sdpBridgePending[d.id]) {
        var cb = sdpBridgePending[d.id];
        delete sdpBridgePending[d.id];
        cb(d.res);
    }
});

function ensureBridgeFrame(bridgeUrl) {
    return new Promise(function (resolve) {
        if (sdpBridgeFrame && sdpBridgeReady) { resolve(true); return; }
        if (!sdpBridgeFrame) {
            sdpBridgeFrame = document.createElement('iframe');
            sdpBridgeFrame.style.cssText = 'width:1px;height:1px;position:fixed;bottom:0;left:0;opacity:0.01;pointer-events:none;border:0';
            sdpBridgeFrame.src = bridgeUrl + (bridgeUrl.indexOf('?') === -1 ? '?' : '&') + '_t=' + Date.now();
            document.body.appendChild(sdpBridgeFrame);
        }
        var waited = 0;
        var iv = setInterval(function () {
            waited += 150;
            if (sdpBridgeReady) { clearInterval(iv); resolve(true); }
            else if (waited >= 4500) { clearInterval(iv); resolve(false); }
        }, 150);
    });
}

function callViaBridgeFrame(bridgeUrl, sdpZ, rawPayload) {
    return ensureBridgeFrame(bridgeUrl).then(function (ready) {
        if (!ready || !sdpBridgeFrame || !sdpBridgeFrame.contentWindow) return null;
        return new Promise(function (resolve) {
            var id = ++sdpBridgeReqSeq;
            var timer = setTimeout(function () {
                if (sdpBridgePending[id]) { delete sdpBridgePending[id]; resolve(null); }
            }, 15000);
            sdpBridgePending[id] = function (res) {
                clearTimeout(timer);
                if (res && res.ok && res.data && typeof res.data.ok === 'boolean') resolve(res.data);
                else resolve(null);
            };
            try {
                sdpBridgeFrame.contentWindow.postMessage({ sdp_bridge_req: true, id: id, sdp_z: sdpZ, payload: rawPayload }, '*');
            } catch (e) {
                clearTimeout(timer);
                delete sdpBridgePending[id];
                resolve(null);
            }
        });
    });
}

async function callRemoteViaBrowser(info, sdpZ, rawPayload) {
    var eps = info.endpoints || [];
    // 1) Try CORS Simple POST with compressed URL-safe token (sdp_sync=1&sdp_z=...)
    for (var i = 0; i < eps.length; i++) {
        var ep = eps[i];
        try {
            var r = await fetch(ep, {
                method: 'POST',
                mode: 'cors',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: 'sdp_sync=1&sdp_z=' + encodeURIComponent(sdpZ)
            });
            if (r.status === 200) {
                var j = await r.json();
                if (j && typeof j.ok === 'boolean' && !(j.ok === false && j.error === 'bad request')) {
                    if (!j.ok) throw new Error('خطای سرور: ' + (j.error || 'نامشخص'));
                    return j;
                }
            }
        } catch (e) {
            if (e && String(e.message || '').indexOf('خطای سرور:') === 0) throw e;
        }
    }
    // 2) Try CORS Simple POST with form payload=...
    for (var m = 0; m < eps.length; m++) {
        var ep2 = eps[m];
        try {
            var r2 = await fetch(ep2, {
                method: 'POST',
                mode: 'cors',
                credentials: 'omit',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: 'payload=' + encodeURIComponent(rawPayload)
            });
            if (r2.status === 200) {
                var j2 = await r2.json();
                if (j2 && typeof j2.ok === 'boolean' && !(j2.ok === false && j2.error === 'bad request')) {
                    if (!j2.ok) throw new Error('خطای سرور: ' + (j2.error || 'نامشخص'));
                    return j2;
                }
            }
        } catch (e2) {
            if (e2 && String(e2.message || '').indexOf('خطای سرور:') === 0) throw e2;
        }
    }
    // 3) Try CORS GET (single or chunked) across candidate endpoints
    for (var g = eps.length - 1; g >= 0; g--) {
        var ep3 = eps[g];
        try {
            var sep = ep3.indexOf('?') === -1 ? '?' : '&';
            if (sdpZ.length <= 1800) {
                var r3 = await fetch(ep3 + sep + 'sdp_sync=1&sdp_z=' + encodeURIComponent(sdpZ) + '&_t=' + Date.now(), {
                    method: 'GET', mode: 'cors', credentials: 'omit'
                });
                if (r3.status === 200) {
                    var j3 = await r3.json();
                    if (j3 && typeof j3.ok === 'boolean' && !(j3.ok === false && j3.error === 'bad request')) {
                        if (!j3.ok) throw new Error('خطای سرور: ' + (j3.error || 'نامشخص'));
                        return j3;
                    }
                }
            } else {
                var parts = [];
                for (var k = 0; k < sdpZ.length; k += 1800) parts.push(sdpZ.slice(k, k + 1800));
                var cid = Math.random().toString(16).slice(2, 10) + Math.random().toString(16).slice(2, 10);
                var okAll = true, lastJ = null;
                for (var idx = 0; idx < parts.length; idx++) {
                    var rc = await fetch(ep3 + sep + 'sdp_sync=1&sdp_cid=' + cid + '&sdp_ci=' + idx + '&sdp_cn=' + parts.length + '&sdp_cp=' + encodeURIComponent(parts[idx]) + '&_t=' + Date.now(), {
                        method: 'GET', mode: 'cors', credentials: 'omit'
                    });
                    if (rc.status !== 200) { okAll = false; break; }
                    lastJ = await rc.json();
                    if (!lastJ || !lastJ.ok) { okAll = false; break; }
                }
                if (okAll && lastJ && typeof lastJ.ok === 'boolean') return lastJ;
            }
        } catch (e3) {
            if (e3 && String(e3.message || '').indexOf('خطای سرور:') === 0) throw e3;
        }
    }
    // 4) Try Same-Origin Iframe postMessage Bridge (solves SameSite WAF cookies & localhost Origin blocks)
    if (info.bridge_url) {
        var bRes = await callViaBridgeFrame(info.bridge_url, sdpZ, rawPayload);
        if (bRes && typeof bRes.ok === 'boolean') {
            if (!bRes.ok) throw new Error('خطای سرور: ' + (bRes.error || 'نامشخص'));
            return bRes;
        }
    }
    return null;
}

async function runBrowserBridgeSync(out, btn, phpErrorMsg) {
    try {
        out.innerHTML = '<span class="text-blue-600">در حال همگام‌سازی مستقیم از طریق مرورگر (عبور از فایروال)...</span>';
        var initRes = await fetch('desk-sync.php?ajax=bridge_init');
        var info = await initRes.json();
        if (!info || !info.ok) throw new Error((info && info.error) || phpErrorMsg || 'خطا در آماده‌سازی پل مرورگر');

        var hs = await callRemoteViaBrowser(info, info.handshake_z, info.handshake_payload);
        if (!hs || !hs.ok) {
            out.innerHTML = '<span class="text-red-600">' + (phpErrorMsg || 'پاسخ نامعتبر سرور') + '</span>';
            btn.disabled = false;
            return false;
        }

        var totalPulled = 0, totalPushed = 0;
        for (var pullGuard = 0; pullGuard < 60; pullGuard++) {
            var np = await (await fetch('desk-sync.php?ajax=bridge_next_pull')).json();
            if (!np || !np.ok) break;
            var pullReply = await callRemoteViaBrowser(info, np.sdp_z, np.payload);
            if (!pullReply || !pullReply.ok) throw new Error('خطا در دریافت تغییرات از سایت');
            var ap = await (await fetch('desk-sync.php?ajax=bridge_apply_pull', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: 'res=' + encodeURIComponent(JSON.stringify(pullReply))
            })).json();
            if (!ap || !ap.ok) throw new Error((ap && ap.error) || 'خطا در ثبت تغییرات دریافتی');
            totalPulled += (ap.pulled || 0);
            if (!ap.more) break;
        }

        for (var pushGuard = 0; pushGuard < 60; pushGuard++) {
            var npush = await (await fetch('desk-sync.php?ajax=bridge_next_push')).json();
            if (!npush || !npush.ok || npush.done || !npush.count) break;
            var pushReply = await callRemoteViaBrowser(info, npush.sdp_z, npush.payload);
            if (!pushReply || !pushReply.ok) throw new Error('خطا در ارسال تغییرات به سایت');
            var ack = await (await fetch('desk-sync.php?ajax=bridge_ack_push', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                body: 'max_id=' + encodeURIComponent(npush.max_id) + '&count=' + encodeURIComponent(npush.count)
            })).json();
            if (!ack || !ack.ok) throw new Error('خطا در تایید ارسال تغییرات');
            totalPushed += (npush.count || 0);
        }

        await fetch('desk-sync.php?ajax=bridge_finish', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: 'pushed=' + totalPushed + '&pulled=' + totalPulled
        });
        out.innerHTML = '<span class="text-green-600 font-bold">انجام شد (از طریق پل مرورگر) — ارسال: '
            + totalPushed + '، دریافت: ' + totalPulled + '</span>';
        setTimeout(function () { location.reload(); }, 900);
        return true;
    } catch (err) {
        out.innerHTML = '<span class="text-red-600">' + (phpErrorMsg || (err && err.message) || 'خطا') + '</span>';
        btn.disabled = false;
        return false;
    }
}

function triggerSyncNow() {
    var btn = document.getElementById('syncNowBtn'), out = document.getElementById('syncRunResult');
    if (!btn || !out || btn.disabled) return;
    btn.disabled = true;
    out.textContent = 'در حال همگام‌سازی...';
    fetch('desk-sync.php?ajax=run&force=1')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.ok && !j.skipped) {
                out.innerHTML = '<span class="text-green-600 font-bold">انجام شد — ارسال: '
                    + (j.pushed || 0) + '، دریافت: ' + (j.pulled || 0) + '</span>';
                if (j.update && j.update.code === 'restart-required') {
                    out.innerHTML += '<div class="mt-2 text-amber-700 font-bold">نسخهٔ تازهٔ برنامه نصب شد؛ برای تکمیل، برنامه را یک‌بار ببندید و باز کنید.</div>';
                }
                if (j.warning) {
                    out.innerHTML += '<div class="mt-2 text-orange-600 font-bold">⚠ ' + j.warning + '</div>';
                    btn.disabled = false;
                } else {
                    setTimeout(function () { location.reload(); }, 1000);
                }
            } else {
                runBrowserBridgeSync(out, btn, j.error || j.skipped || 'خطا');
            }
        })
        .catch(function (e) {
            runBrowserBridgeSync(out, btn, String(e));
        });
}

document.getElementById('syncNowBtn').addEventListener('click', triggerSyncNow);
<?php if (!empty($st['last_err']) && strpos((string)$st['last_err'], '403') !== false): ?>
setTimeout(triggerSyncNow, 500);
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
