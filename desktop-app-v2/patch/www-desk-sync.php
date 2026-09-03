<?php
// File: desk-sync.php  (SchoolDesk Pro — صفحه تنظیمات و وضعیت همگام‌سازی)
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/desk_sync.php';

if (!is_admin_logged_in()) redirect('admin-login.php');

/* AJAX: run one sync cycle now (also used by the background timer) */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'run') {
    header('Content-Type: application/json; charset=utf-8');
    ignore_user_abort(true);
    set_time_limit(300);
    $res = isset($_GET['force']) ? DeskSync::run(true) : DeskSync::runIfDue();
    $res['status'] = DeskSync::status();
    $res['pending'] = DeskSync::pendingCount();
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
<div class="max-w-3xl mx-auto">
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
            <div class="flex gap-3">
                <button type="submit" name="save_sync" value="1" class="btn btn-primary">ذخیره تنظیمات</button>
                <button type="button" id="syncNowBtn" class="btn btn-success">همگام‌سازی همین حالا</button>
            </div>
        </form>
    </div>

    <div class="card p-6">
        <h4 class="font-bold mb-3">وضعیت</h4>
        <table class="w-full text-sm" id="syncStatusTable">
            <tr><td class="py-1 text-muted w-48">وضعیت</td>
                <td><?php echo $st['enabled'] ? '<span class="text-green-600 font-bold">فعال</span>' : '<span class="text-red-600 font-bold">غیرفعال</span>'; ?></td></tr>
            <tr><td class="py-1 text-muted">آخرین اجرا</td><td><?php echo fa_ago($st['last_run']); ?></td></tr>
            <tr><td class="py-1 text-muted">آخرین موفق</td><td><?php echo fa_ago($st['last_ok']); ?></td></tr>
            <tr><td class="py-1 text-muted">دریافت اولیه کامل</td><td><?php echo $st['snapshot'] ? 'انجام شده' : 'هنوز انجام نشده'; ?></td></tr>
            <tr><td class="py-1 text-muted">مجموع ارسال‌شده</td><td><?php echo tr_num((string)$st['pushed'], 'fa'); ?> رکورد</td></tr>
            <tr><td class="py-1 text-muted">مجموع دریافت‌شده</td><td><?php echo tr_num((string)$st['pulled'], 'fa'); ?> رکورد</td></tr>
            <?php if ($st['last_err']): ?>
            <tr><td class="py-1 text-muted">آخرین خطا</td><td class="text-red-600"><?php echo clean($st['last_err']); ?></td></tr>
            <?php endif; ?>
        </table>
        <div id="syncRunResult" class="mt-3 text-sm"></div>
    </div>
</div>
<script>
document.getElementById('syncNowBtn').addEventListener('click', function () {
    var btn = this, out = document.getElementById('syncRunResult');
    btn.disabled = true;
    out.textContent = 'در حال همگام‌سازی...';
    fetch('desk-sync.php?ajax=run&force=1')
        .then(function (r) { return r.json(); })
        .then(function (j) {
            if (j.ok) {
                out.innerHTML = '<span class="text-green-600">انجام شد — ارسال: '
                    + (j.pushed || 0) + '، دریافت: ' + (j.pulled || 0) + '</span>';
                if (j.warning) {
                    out.innerHTML += '<div class="mt-2 text-orange-600 font-bold">⚠ ' + j.warning + '</div>';
                    btn.disabled = false; // stay on page so the warning is read
                } else {
                    setTimeout(function () { location.reload(); }, 1200);
                }
            } else {
                out.innerHTML = '<span class="text-red-600">' + (j.error || j.skipped || 'خطا') + '</span>';
                btn.disabled = false;
            }
        })
        .catch(function (e) { out.textContent = 'خطا: ' + e; btn.disabled = false; });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
