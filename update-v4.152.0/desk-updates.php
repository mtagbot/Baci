<?php
/**
 * File: desk-updates.php — انتشار بستهٔ به‌روزرسانی نرم‌افزار دسکتاپ (سمت سایت).
 *
 * مدیر کل بستهٔ ZIP (مثل SchoolDeskPro-FIX-v2.83.0-*.zip) را بارگذاری و
 * «منتشر» می‌کند؛ از آن لحظه، همهٔ نصب‌های دسکتاپ همین مدرسه می‌توانند آن را
 * با یک کلیک دریافت و نصب کنند. بسته پیش از انتشار به‌طور کامل بررسی می‌شود.
 */
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/desk_updates_store.php';

$admin = is_admin_logged_in() ? DB::fetch('SELECT role,status FROM admins WHERE id=?', [(int)$_SESSION['admin_id']]) : null;
if (!$admin || $admin['role'] !== 'super_admin' || !(int)$admin['status']) { http_response_code(403); exit('فقط مدیر کل به انتشار به‌روزرسانی دسکتاپ دسترسی دارد.'); }

$message = ''; $error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) { $error = 'توکن امنیتی نامعتبر است؛ صفحه را دوباره باز کنید.'; }
    else {
        $action = (string)($_POST['action'] ?? '');
        $updates = desk_updates_index();
        try {
            if ($action === 'publish') {
                if (!desk_updates_ensure_dir()) throw new RuntimeException('ساخت پوشهٔ uploads/desktop-updates ممکن نشد؛ دسترسی نوشتن را بررسی کنید.');
                $file = $_FILES['package'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('فایل بسته بارگذاری نشد.');
                if ((int)$file['size'] <= 0 || (int)$file['size'] > 67108864) throw new RuntimeException('حجم بسته باید بین ۱ بایت و ۶۴ مگابایت باشد.');
                if (!is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('فایل بارگذاری‌شده معتبر نیست.');
                $report = desk_updates_report((string)$file['tmp_name']);
                if (empty($report['ok'])) throw new RuntimeException($report['error']);
                $sha = hash_file('sha256', (string)$file['tmp_name']);
                foreach ($updates as $entry) if (is_array($entry) && hash_equals((string)($entry['sha256'] ?? ''), (string)$sha)) throw new RuntimeException('همین بسته پیش‌تر منتشر شده است.');
                $label = trim((string)($_POST['version'] ?? ''));
                if ($label === '') $label = (string)preg_replace('~\A.*?(v?[\d.]+(?:-[\w.\-]+)?)\.zip$~i', '$1', (string)$file['name']);
                $label = (string)preg_replace('~[^a-zA-Z0-9._\-]~', '', $label);
                if ($label === '') $label = 'desktop-' . gmdate('Ymd');
                $id = $label . '-' . substr((string)$sha, 0, 8);
                $target = desk_updates_dir() . '/' . $id . '.zip';
                if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('انتقال فایل به پوشهٔ انتشار ناموفق بود.');
                @chmod($target, 0644);
                foreach ($updates as $index => $entry) $updates[$index]['latest'] = false;
                $updates[] = [
                    'id' => $id, 'version' => $label, 'notes' => trim((string)($_POST['notes'] ?? '')),
                    'file' => $id . '.zip', 'bytes' => (int)filesize($target), 'sha256' => (string)$sha,
                    'files' => (int)$report['files'], 'launcher' => !empty($report['launcher']),
                    'created' => jdate('Y/m/d H:i'), 'ts' => time(), 'latest' => true,
                    'published_by' => (string)($_SESSION['admin_id'] ?? ''),
                ];
                if (!desk_updates_save($updates)) { @unlink($target); throw new RuntimeException('ثبت فهرست بسته‌ها ناموفق بود.'); }
                $message = 'بستهٔ «' . $label . '» منتشر شد و از این لحظه، نصب‌های دسکتاپ این مدرسه می‌توانند آن را دریافت کنند.';
            } elseif ($action === 'latest') {
                $id = (string)($_POST['id'] ?? '');
                $found = false;
                foreach ($updates as $index => $entry) { $updates[$index]['latest'] = ((string)($entry['id'] ?? '') === $id); $found = $found || $updates[$index]['latest']; }
                if (!$found) throw new RuntimeException('بسته پیدا نشد.');
                if (!desk_updates_save($updates)) throw new RuntimeException('ثبت تغییر ناموفق بود.');
                $message = 'این بسته به‌عنوان «آخرین نسخه» علامت خورد.';
            } elseif ($action === 'delete') {
                $id = (string)($_POST['id'] ?? '');
                $entry = desk_updates_id_ok($id) ? desk_updates_find($updates, $id) : null;
                if (!$entry) throw new RuntimeException('بسته پیدا نشد.');
                $path = desk_updates_path($entry);
                if (is_file($path) && !@unlink($path)) throw new RuntimeException('حذف فایل بسته ناموفق بود.');
                $updates = array_values(array_filter($updates, fn($row) => (string)($row['id'] ?? '') !== $id));
                if (!desk_updates_save($updates)) throw new RuntimeException('ثبت فهرست پس از حذف ناموفق بود.');
                $message = 'بسته حذف شد.';
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
$updates = desk_updates_index();
$latest = desk_updates_latest($updates);
$pageTitle = 'به‌روزرسانی دسکتاپ';
require_once __DIR__.'/includes/header.php';
?>
<section class="card p-6 space-y-4">
    <h1>انتشار به‌روزرسانی نرم‌افزار دسکتاپ</h1>
    <p>بستهٔ ZIP آماده (مثل <code dir="ltr">SchoolDeskPro-FIX-v2.83.0-*.zip</code> یا بستهٔ <code dir="ltr">SchoolDeskPro-UPDATE-*.zip</code>) را این‌جا بارگذاری کنید. نصب‌های دسکتاپ با همان کلید همگام‌سازی این مدرسه، فقط همین بستهٔ منتشرشده را می‌بینند و پس از تأیید چک‌سام نصب می‌کنند. پوشه‌های <code dir="ltr">config</code> و <code dir="ltr">data</code> روی دستگاه‌ها هرگز تغییر نمی‌کنند.</p>
    <?php if ($message): ?><p class="p-3 text-green-700 font-bold" role="status"><?php echo clean($message); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="p-3 text-red-600 font-bold" role="alert"><?php echo clean($error); ?></p><?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card p-4 space-y-2">
        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
        <input type="hidden" name="action" value="publish">
        <label class="block text-xs font-semibold">فایل بسته (ZIP، حداکثر ۶۴ مگابایت)<input type="file" name="package" accept=".zip" required></label>
        <label class="block text-xs font-semibold">نام نسخه (اختیاری؛ مثل <span dir="ltr">2.83.1-scanner-focus</span>)<input type="text" name="version" dir="ltr" maxlength="64" placeholder="2.83.0-correction"></label>
        <label class="block text-xs font-semibold">توضیح تغییرات (روی دستگاه‌ها نمایش داده می‌شود)<textarea name="notes" rows="4" maxlength="2000"></textarea></label>
        <button type="submit" class="btn btn-primary">بررسی و انتشار</button>
    </form>

    <h2 class="font-bold">بسته‌های منتشرشده</h2>
    <?php if (!$updates): ?>
        <p>هنوز بسته‌ای منتشر نشده است. تا زمانی که بسته‌ای منتشر نشود، دستگاه‌های دسکتاپ چیزی برای نصب پیدا نمی‌کنند.</p>
    <?php else: ?>
    <div class="table-container"><table>
        <thead><tr><th>نسخه</th><th>وضعیت</th><th>فایل‌ها</th><th>حجم</th><th>انتشار</th><th>چک‌سام</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($updates) as $entry): ?>
            <tr>
                <td dir="ltr"><?php echo clean((string)($entry['version'] ?? '')); ?><?php echo !empty($entry['launcher']) ? ' <span title="شامل فایل اجرایی">🧩</span>' : ''; ?></td>
                <td><?php echo ($latest && (string)$latest['id'] === (string)$entry['id']) ? '<b>آخرین نسخه</b>' : 'قدیمی‌تر'; ?></td>
                <td><?php echo clean(tr_num((string)($entry['files'] ?? '0'), 'fa')); ?></td>
                <td dir="ltr"><?php echo clean(number_format(((int)($entry['bytes'] ?? 0)) / 1024, 1)); ?> KB</td>
                <td><?php echo clean((string)($entry['created'] ?? '')); ?></td>
                <td class="font-mono text-left dir-ltr" style="font-size:.7rem"><?php echo clean(substr((string)($entry['sha256'] ?? ''), 0, 16)); ?>…</td>
                <td>
                    <form method="post" class="flex gap-2">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="id" value="<?php echo clean((string)$entry['id']); ?>">
                        <?php if (!$latest || (string)$latest['id'] !== (string)$entry['id']): ?><button class="btn btn-secondary text-xs px-3" name="action" value="latest">آخرین نسخه</button><?php endif; ?>
                        <button class="btn btn-danger text-xs px-3" name="action" value="delete" onclick="return confirm('این بسته حذف شود؟ نصب‌هایی که آن را گرفته‌اند دست‌نخورده می‌مانند.');">حذف</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <p class="text-xs">نکتهٔ امنیتی: پوشهٔ <code dir="ltr">uploads/desktop-updates</code> با <code dir="ltr">.htaccess</code> از دسترسی مستقیم بسته است. اگر وب‌سرور شما Nginx است، این قاعده را هم اضافه کنید: <code dir="ltr">location ^~ /uploads/desktop-updates/ { deny all; }</code></p>
</section>
<?php require_once __DIR__.'/includes/footer.php'; ?>
