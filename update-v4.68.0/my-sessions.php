<?php
// File: my-sessions.php — v4.68.0
// نشست‌های فعال حساب کاربری جاری روی همه دستگاه‌ها + امکان بستن هر نشست.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/session_tracker.php';

$u = function_exists('st_current_user') ? st_current_user() : null;
if (!$u) { redirect('index.php'); }
list($uType, $uId, $uName) = $u;
ensure_user_sessions_schema();

$currentSid = session_id();

// بستن یک نشست (فقط نشست‌های خود کاربر)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_session'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        $targetId = (int)($_POST['session_row_id'] ?? 0);
        $row = DB::fetch("SELECT * FROM user_sessions WHERE id=? AND user_type=? AND user_id=?", [$targetId, $uType, $uId]);
        if ($row) {
            if ($row['session_id'] === $currentSid) {
                set_flash_message('error', 'برای خروج از همین دستگاه، از دکمه «خروج» منوی کاربری استفاده کنید.');
            } else {
                DB::execute("UPDATE user_sessions SET is_revoked=1 WHERE id=?", [$targetId]);
                set_flash_message('success', 'نشست دستگاه «' . ($row['device_label'] ?: 'نامشخص') . '» بسته شد. آن دستگاه با اولین فعالیت بعدی خارج می‌شود.');
            }
        } else {
            set_flash_message('error', 'نشست مورد نظر یافت نشد.');
        }
    }
    redirect('my-sessions.php');
}

// بستن همه نشست‌های دیگر
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all_others'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        DB::execute("UPDATE user_sessions SET is_revoked=1 WHERE user_type=? AND user_id=? AND session_id<>?", [$uType, $uId, $currentSid]);
        set_flash_message('success', 'همه نشست‌های دیگر بسته شدند. آن دستگاه‌ها با اولین فعالیت بعدی خارج می‌شوند.');
    }
    redirect('my-sessions.php');
}

$sessions = DB::fetchAll("SELECT * FROM user_sessions WHERE user_type=? AND user_id=? AND is_revoked=0 ORDER BY (session_id=?) DESC, last_seen_at DESC", [$uType, $uId, $currentSid]);

require_once __DIR__ . '/includes/header.php';
?>
<div class="w-full space-y-4">
    <div class="card shadow-lg">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <div>
                <h3 class="font-bold text-primary">🖥 نشست‌های فعال حساب «<?php echo clean($uName); ?>»</h3>
                <p class="text-xs text-muted mt-1">دستگاه‌هایی که هم‌اکنون با این حساب وارد سیستم شده‌اند. با بستن هر نشست، آن دستگاه با اولین فعالیت بعدی به‌صورت خودکار خارج می‌شود.</p>
            </div>
            <?php if (count($sessions) > 1): ?>
            <form method="POST" onsubmit="return confirm('همه نشست‌های دیگر (به‌جز همین دستگاه) بسته شوند؟')">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="revoke_all_others" value="1">
                <button class="btn btn-danger text-xs">بستن همه نشست‌های دیگر</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>دستگاه</th>
                        <th>سیستم‌عامل</th>
                        <th>مرورگر</th>
                        <th>IP</th>
                        <th>ورود</th>
                        <th>آخرین فعالیت (آنلاینی)</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sessions as $ss): $isCurrent = ($ss['session_id'] === $currentSid);
                    $ago = time() - (int)$ss['last_seen_at'];
                    if ($ago < 120) $agoTxt = 'هم‌اکنون آنلاین';
                    elseif ($ago < 3600) $agoTxt = tr_num((string)floor($ago / 60), 'fa') . ' دقیقه پیش';
                    elseif ($ago < 86400) $agoTxt = tr_num((string)floor($ago / 3600), 'fa') . ' ساعت پیش';
                    else $agoTxt = tr_num((string)floor($ago / 86400), 'fa') . ' روز پیش';
                ?>
                    <tr <?php echo $isCurrent ? 'style="background:rgba(16,185,129,.07)"' : ''; ?>>
                        <td class="font-bold"><?php echo clean($ss['device_label'] ?: 'نامشخص'); ?></td>
                        <td><?php echo clean($ss['os_name'] ?: '—'); ?></td>
                        <td><?php echo clean($ss['browser_name'] ?: '—'); ?></td>
                        <td class="dir-ltr text-left text-xs"><?php echo clean($ss['ip_address'] ?: '—'); ?></td>
                        <td class="text-xs"><?php echo tr_num(clean($ss['created_at_jalali'] ?: '—'), 'fa'); ?></td>
                        <td class="text-xs font-bold"><?php echo $agoTxt; ?><br><span class="text-muted font-normal"><?php echo tr_num(clean($ss['last_seen_jalali'] ?: ''), 'fa'); ?></span></td>
                        <td>
                            <?php if ($isCurrent): ?>
                                <span class="badge" style="background:#059669;color:#fff">همین دستگاه</span>
                            <?php elseif ($ago < 120): ?>
                                <span class="badge" style="background:#2563eb;color:#fff">آنلاین</span>
                            <?php else: ?>
                                <span class="badge" style="background:#64748b;color:#fff">غیرفعال</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$isCurrent): ?>
                            <form method="POST" onsubmit="return confirm('نشست این دستگاه بسته شود؟')">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="revoke_session" value="1">
                                <input type="hidden" name="session_row_id" value="<?php echo (int)$ss['id']; ?>">
                                <button class="btn btn-danger text-xs px-2 py-1">خروج این دستگاه</button>
                            </form>
                            <?php else: ?>
                                <span class="text-xs text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; if (empty($sessions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-6">نشستی ثبت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-muted mt-3">توضیح: «آخرین فعالیت» با دقت حدود یک دقیقه به‌روزرسانی می‌شود. نشست‌های بی‌تحرک بیش از ۶۰ روز خودکار حذف می‌شوند.</p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
