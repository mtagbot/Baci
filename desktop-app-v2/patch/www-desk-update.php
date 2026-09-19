<?php
/**
 * File: desk-update.php — به‌روزرسانی آنلاین نرم‌افزار دسکتاپ از روی سایت مدرسه.
 *
 * فقط در نسخهٔ دسکتاپ معنا دارد. بستهٔ منتشرشده در سایت، با همان کلید
 * همگام‌سازی همین مدرسه دانلود، چک‌سام و از نظر مسیر/نوع فایل اعتبارسنجی
 * می‌شود؛ سپس فایل‌های برنامه به‌صورت اتمیک جای‌گزین و نسخهٔ قبلی در
 * data/update/backup-* نگه‌داری می‌شود. فایل اجرایی جدید (در صورت وجود)
 * در اجرای بعدی برنامه توسط خود SchoolDeskPro.exe جای‌گزین می‌شود.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/desk_update.php';

$release = is_file(__DIR__ . '/config/release.php') ? require __DIR__ . '/config/release.php' : [];
if (($release['distribution'] ?? 'site') !== 'desktop') { http_response_code(404); exit('این بخش فقط در نسخهٔ دسکتاپ است.'); }
if (!is_admin_logged_in()) { redirect('admin-login.php'); exit; }
$account = current_admin();
if (!$account || !(int)$account['status'] || !in_array($account['role'], ['super_admin', 'edu_admin'], true)) {
    http_response_code(403);
    exit('فقط مدیر مجاز است به به‌روزرسانی نرم‌افزار دسترسی داشته باشد.');
}

$message = ''; $error = ''; $result = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'توکن امنیتی نامعتبر است؛ صفحه را دوباره باز کنید.'; }
    else {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'check') {
                $check = desk_update_check(true);
                if (!$check['ok']) $error = $check['error'];
                elseif (empty($check['latest'])) $message = 'سایت هنوز بستهٔ به‌روزرسانی برای دسکتاپ منتشر نکرده است.';
                elseif (!empty($check['available'])) $message = 'نسخهٔ تازه در سایت موجود است و می‌توانید همین‌جا نصب کنید.';
                else $message = 'نرم‌افزار شما به‌روز است.';
            } elseif ($action === 'install') {
                $state = desk_update_state();
                $latest = $state['latest'] ?? null;
                if (!is_array($latest)) { $error = 'ابتدا «بررسی به‌روزرسانی» را بزنید.'; }
                elseif (!desk_update_available($state)) { $message = 'همین نسخه پیش‌تر نصب شده است.'; }
                else {
                    $result = desk_update_install((string)$latest['id'], $latest);
                    if (empty($result['ok'])) $error = $result['error'];
                    else $message = 'نصب شد: ' . tr_num((string)$result['files'], 'fa') . ' فایل به‌روز شد' . ($result['launcher'] ? ' و فایل اجرایی جدید برای اجرای بعدی آماده است' : '') . '.';
                }
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$state = desk_update_state();
$latest = is_array($state['latest'] ?? null) ? $state['latest'] : null;
$ready = desk_update_ready();
$pageTitle = 'به‌روزرسانی نرم‌افزار';
require_once __DIR__ . '/includes/header.php';
?>
<section class="card p-6">
    <h1>به‌روزرسانی آنلاین نرم‌افزار</h1>
    <p>پس از هر همگام‌سازی، نسخهٔ منتشرشده در سایت مدرسه بررسی می‌شود. بستهٔ دانلودی فقط وقتی نصب می‌شود که چک‌سام آن با سایت یکی باشد و همهٔ مسیرهایش مجاز باشند. تنظیمات نصب، پایگاه داده و پشتیبان‌ها هرگز تغییر نمی‌کنند.</p>
    <?php if ($message): ?><p class="p-3 mb-3 text-green-700 font-bold" role="status"><?php echo clean($message); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="p-3 mb-3 text-red-600 font-bold" role="alert"><?php echo clean($error); ?></p><?php endif; ?>

    <div class="table-container"><table>
        <tr><th>نسخهٔ فعلی نرم‌افزار</th><td dir="ltr"><?php echo clean(desk_update_local_version()); ?></td></tr>
        <tr><th>آخرین بررسی</th><td><?php echo !empty($state['checked_at']) ? clean(tr_num(jdate('Y/m/d H:i', (int)$state['checked_at']), 'fa')) : 'انجام نشده'; ?></td></tr>
        <tr><th>آخرین بستهٔ منتشرشده در سایت</th><td><?php echo $latest ? clean((string)($latest['version'] ?? '') . ' — ' . (string)($latest['created'] ?? '')) : 'نامشخص'; ?></td></tr>
        <tr><th>وضعیت نصب</th><td><?php echo $latest && !desk_update_available($state) ? 'این نسخه نصب شده است' : (desk_update_available($state) ? 'به‌روزرسانی آمادهٔ نصب است' : 'به‌روز'); ?></td></tr>
        <?php if (!empty($state['applied_at'])): ?>
        <tr><th>آخرین نصب</th><td><?php echo clean(tr_num(jdate('Y/m/d H:i', (int)$state['applied_at']), 'fa')); ?><?php echo !empty($state['backup']) ? ' — نسخهٔ قبلی: ' . clean((string)$state['backup']) : ''; ?></td></tr>
        <?php endif; ?>
    </table></div>

    <?php if (!$ready['ok']): ?>
        <p class="p-3 mb-3 text-red-600 font-bold"><?php echo clean($ready['error']); ?></p>
    <?php endif; ?>
    <?php if (!empty($state['error'])): ?>
        <p class="p-3 mb-3 text-red-600 font-bold">آخرین خطا: <?php echo clean((string)$state['error']); ?></p>
    <?php endif; ?>
    <?php if ($latest && !empty($latest['notes'])): ?>
        <h2 style="font-size:1rem;margin-top:14px">تغییرات این نسخه</h2>
        <pre style="white-space:pre-wrap;font-family:inherit"><?php echo clean((string)$latest['notes']); ?></pre>
    <?php endif; ?>
    <?php if (!empty($state['restart_required'])): ?>
        <p class="p-3 mb-3 text-green-700 font-bold">فایل اجرایی جدید آماده است؛ با بستن و بازکردن دوبارهٔ برنامه، نسخهٔ اجرایی هم به‌روز می‌شود.</p>
    <?php endif; ?>

    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <button type="submit" name="action" value="check" <?php echo $ready['ok'] ? '' : 'disabled'; ?>>بررسی به‌روزرسانی</button>
        <button type="submit" name="action" value="install" <?php echo ($ready['ok'] && $latest && desk_update_available($state)) ? '' : 'disabled'; ?> onclick="return confirm('نصب به‌روزرسانی آغاز شود؟ فایل‌های قبلی در data/update پشتیبان‌گیری می‌شوند.');">نصب به‌روزرسانی</button>
    </form>

    <h2 style="font-size:1rem;margin-top:18px">اگر بدون اینترنت هستید</h2>
    <p>می‌توانید بستهٔ <code dir="ltr">SchoolDeskPro-UPDATE-*.zip</code> را دستی از سایت دریافت کنید و محتوای پوشهٔ <code dir="ltr">SchoolDeskPro</code> آن را (پس از بستن برنامه) روی پوشهٔ نصب کپی کنید؛ پوشه‌های <code dir="ltr">config</code> و <code dir="ltr">data</code> دست‌نخورده بمانند.</p>
</section>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
