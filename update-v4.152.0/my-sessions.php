<?php
// File: my-sessions.php — v4.68.0
// نشست‌های فعال حساب کاربری جاری روی همه دستگاه‌ها + امکان بستن هر نشست.
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/session_tracker.php';
require_once __DIR__ . '/includes/security_confirmation.php';

$u = function_exists('st_current_user') ? st_current_user() : null;
if (!$u) { redirect('index.php'); }
list($uType, $uId, $uName) = $u;
ensure_user_sessions_schema();

$currentSid = st_session_key();

/* v4.89.0: بستن نشست فقط با تایید رمز حساب کاربری جاری.
   رمز واردشده با رکورد همان کاربر (admin/teacher/student) سنجیده می‌شود. */
if (!function_exists('ms_verify_account_password')) {
    function ms_verify_account_password($uType, $uId, $pass) {
        if (!is_string($pass) || $pass === '' || strlen($pass)>4096) return false;
        try {
            if ($uType === 'admin') {
                $row = DB::fetch("SELECT password FROM admins WHERE id=?", [$uId]);
                return $row && verify_user_password($pass, (string)$row['password'], ['table'=>'admins','id'=>$uId]);
            }
            if ($uType === 'teacher') {
                $row = DB::fetch("SELECT password FROM teachers WHERE id=?", [$uId]);
                return $row && verify_user_password($pass, (string)$row['password'], ['table'=>'teachers','id'=>$uId]);
            }
            if ($uType === 'student') {
                $row = DB::fetch("SELECT password FROM students WHERE id=?", [$uId]);
                return $row && verify_user_password($pass, (string)$row['password'], ['table'=>'students','id'=>$uId]);
            }
        } catch (Exception $e) {}
        return false;
    }
}

// بستن یک نشست (فقط نشست‌های خود کاربر) — نیازمند رمز حساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_session'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        if (!security_confirm_password('session-revoke', function()use($uType,$uId){return ms_verify_account_password($uType,$uId,$_POST['account_password']??'');})) {
            set_flash_message('error', 'رمز حساب کاربری نادرست است. بدون تأیید رمز، بستن نشست انجام نمی‌شود؛ پس از ۵ تلاش ناموفق، ۱۰ دقیقه صبر کنید.');
            redirect('my-sessions.php');
        }
        $targetId = (int)($_POST['session_row_id'] ?? 0);
        $row = DB::fetch("SELECT * FROM user_sessions WHERE id=? AND user_type=? AND user_id=?", [$targetId, $uType, $uId]);
        if ($row) {
            if ($row['session_id'] === $currentSid) {
                set_flash_message('error', 'برای خروج از همین دستگاه، از دکمه «خروج» منوی کاربری استفاده کنید.');
            } else {
                DB::execute("UPDATE user_sessions SET is_revoked=1,remember_hash=NULL WHERE id=?", [$targetId]);
                set_flash_message('success', 'نشست دستگاه «' . ($row['device_label'] ?: 'نامشخص') . '» بسته شد. آن دستگاه با اولین فعالیت بعدی خارج می‌شود.');
            }
        } else {
            set_flash_message('error', 'نشست مورد نظر یافت نشد.');
        }
    }
    redirect('my-sessions.php');
}

// بستن همه نشست‌های دیگر — نیازمند رمز حساب
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_all_others'])) {
    if (verify_csrf($_POST['csrf_token'] ?? '')) {
        if (!security_confirm_password('session-revoke', function()use($uType,$uId){return ms_verify_account_password($uType,$uId,$_POST['account_password']??'');})) {
            set_flash_message('error', 'رمز حساب کاربری نادرست است. بدون تأیید رمز، بستن نشست‌ها انجام نمی‌شود؛ پس از ۵ تلاش ناموفق، ۱۰ دقیقه صبر کنید.');
            redirect('my-sessions.php');
        }
        DB::execute("UPDATE user_sessions SET is_revoked=1,remember_hash=NULL WHERE user_type=? AND user_id=? AND session_id<>?", [$uType, $uId, $currentSid]);
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
            <button class="btn btn-danger text-xs" onclick="msAskPassword('all', 0, 'همه نشست‌های دیگر (به‌جز همین دستگاه)')">بستن همه نشست‌های دیگر</button>
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
                            <button class="btn btn-danger text-xs px-2 py-1" onclick="msAskPassword('one', <?php echo (int)$ss['id']; ?>, 'دستگاه «<?php echo clean($ss['device_label'] ?: 'نامشخص'); ?>»')">خروج این دستگاه</button>
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
        <p class="text-[11px] text-muted mt-3">توضیح: «آخرین فعالیت» با دقت حدود یک دقیقه به‌روزرسانی می‌شود. نشست‌های خاتمه‌یافته برای جلوگیری از ورود دوباره با کوکی قدیمی، در فهرست مسدود نگه داشته می‌شوند. بستن هر نشست نیازمند تایید رمز حساب کاربری است.</p>
    </div>
</div>

<!-- v4.89.0: مودال تایید رمز حساب برای بستن نشست -->
<div id="msPassOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:1000;align-items:center;justify-content:center;padding:16px">
    <div class="card shadow-lg" style="max-width:420px;width:100%">
        <h4 class="font-bold text-primary mb-2">🔐 تایید هویت برای بستن نشست</h4>
        <p class="text-xs text-muted mb-3">برای بستن <b id="msPassTarget">نشست</b>، رمز حساب کاربری خود را وارد کنید. بدون رمز صحیح، نشست بسته نمی‌شود.</p>
        <form method="POST" id="msPassForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="revoke_session" value="" id="msFldOne">
            <input type="hidden" name="revoke_all_others" value="" id="msFldAll">
            <input type="hidden" name="session_row_id" value="0" id="msFldRow">
            <label class="block text-xs font-semibold mb-1">رمز حساب کاربری</label>
            <input type="password" name="account_password" id="msPassInput" class="form-input dir-ltr text-left" placeholder="******" required autocomplete="current-password">
            <div class="flex gap-2 mt-4">
                <button type="submit" class="btn btn-danger text-xs flex-1">تایید و بستن نشست</button>
                <button type="button" class="btn btn-outline text-xs" onclick="msClosePass()">انصراف</button>
            </div>
        </form>
    </div>
</div>
<script>
function msAskPassword(mode, rowId, label){
    document.getElementById('msPassTarget').textContent = label || 'نشست';
    var one = document.getElementById('msFldOne'), all = document.getElementById('msFldAll'), row = document.getElementById('msFldRow');
    if (mode === 'all') { all.value = '1'; all.disabled = false; one.value = ''; one.disabled = true; row.disabled = true; }
    else { one.value = '1'; one.disabled = false; row.value = rowId; row.disabled = false; all.value = ''; all.disabled = true; }
    var ov = document.getElementById('msPassOverlay');
    ov.style.display = 'flex';
    var inp = document.getElementById('msPassInput');
    inp.value = '';
    setTimeout(function(){ inp.focus(); }, 50);
}
function msClosePass(){ document.getElementById('msPassOverlay').style.display = 'none'; }
document.getElementById('msPassOverlay').addEventListener('click', function(e){ if (e.target === this) msClosePass(); });
document.addEventListener('keydown', function(e){ if (e.key === 'Escape') msClosePass(); });
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
