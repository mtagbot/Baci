<?php
// File: desk-doctor.php — SchoolDesk Pro emergency diagnostics (desktop only).
// Reachable WITHOUT login, but ONLY from the local machine (127.0.0.1),
// i.e. only from inside the desktop app itself. Never exists on the website.

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$ra = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ra !== '127.0.0.1' && $ra !== '::1') { http_response_code(403); exit('forbidden'); }

header('Content-Type: text/html; charset=utf-8');

$checks = [];
function chk($name, $ok, $detail = '') {
    global $checks;
    $checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    return $ok;
}

/* ---------- 1) session storage ---------- */
$sp = ini_get('session.save_path') ?: sys_get_temp_dir();
$probe = rtrim($sp, '/\\') . DIRECTORY_SEPARATOR . '.doctor-probe';
$w = @file_put_contents($probe, '1') !== false;
if ($w) @unlink($probe);
chk('پوشه نشست‌ها (sessions) قابل نوشتن است', $w, $sp);

/* ---------- 2) session round-trip ---------- */
@session_start();
$_SESSION['doctor'] = ($_SESSION['doctor'] ?? 0) + 1;
$sess_ok = session_id() !== '';
chk('نشست (Session) ساخته می‌شود', $sess_ok, 'شناسه: ' . substr(session_id(), 0, 8) . '…، بازدید شماره ' . (int)$_SESSION['doctor']);

/* ---------- 3) cookie round-trip ---------- */
$cookie_ok = isset($_COOKIE[session_name()]) || ((int)$_SESSION['doctor'] > 1);
if (!isset($_COOKIE['sdp_doc'])) @setcookie('sdp_doc', '1', time() + 3600, '/');
$cookie_seen = isset($_COOKIE['sdp_doc']) || isset($_COOKIE[session_name()]);
chk('مرورگر کوکی‌ها را برمی‌گرداند', $cookie_seen, $cookie_seen ? '' : 'این صفحه را یک بار Refresh کنید (F5)؛ اگر باز هم قرمز بود، مرورگر کوکی ذخیره نمی‌کند');

/* ---------- 4) database ---------- */
$db_ok = false; $db_detail = ''; $admins = [];
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = DB::getInstance()->getPdo();
    $db_ok = (bool)$pdo;
    $n = $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    $db_detail = 'تعداد مدیران: ' . (int)$n;
    foreach ($pdo->query("SELECT id, username, status FROM admins ORDER BY id LIMIT 10") as $r) $admins[] = $r;
    // db writable?
    $pdo->exec("INSERT INTO desk_sync_suppress (flag) VALUES (9)");
    $pdo->exec("DELETE FROM desk_sync_suppress WHERE flag = 9");
} catch (Throwable $e) {
    $db_detail = $e->getMessage();
}
chk('بانک اطلاعاتی باز و قابل نوشتن است', $db_ok, $db_detail);

/* ---------- actions ---------- */
$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['fix_admin']) && $db_ok) {
    try {
        $h = password_hash('admin123', PASSWORD_DEFAULT);
        $st = $pdo->prepare("UPDATE admins SET password = ?, status = 1 WHERE username = 'admin'");
        $st->execute([$h]);
        if ($st->rowCount() === 0) {
            $pdo->prepare("INSERT INTO admins (username, password, name, role, status) VALUES ('admin', ?, 'مدیر سیستم', 'super_admin', 1)")->execute([$h]);
        }
        $msg = 'انجام شد: رمز کاربر admin به admin123 برگردانده شد و حساب فعال شد. حالا وارد شوید.';
    } catch (Throwable $e) { $msg = 'خطا: ' . $e->getMessage(); }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['clear_throttle'])) {
    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['lockout_time']);
    $msg = 'قفل تلاش‌های ناموفق باز شد. حالا دوباره وارد شوید.';
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>عیب‌یاب SchoolDesk Pro</title>
<style>
 body{font-family:Tahoma,sans-serif;background:#f1f5f9;margin:0;padding:30px;color:#0f172a}
 .card{max-width:640px;margin:0 auto 18px;background:#fff;border-radius:14px;padding:22px 26px;box-shadow:0 4px 14px rgba(0,0,0,.07)}
 h1{font-size:20px;margin:0 0 4px} .sub{color:#64748b;font-size:13px;margin-bottom:14px}
 .row{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-bottom:1px dashed #e2e8f0;font-size:14px}
 .row:last-child{border-bottom:0}
 .ok{color:#15803d;font-weight:bold} .bad{color:#b91c1c;font-weight:bold}
 .detail{color:#64748b;font-size:12px;direction:ltr;text-align:left;word-break:break-all}
 .msg{background:#dcfce7;border:2px solid #86efac;color:#14532d;border-radius:10px;padding:12px 14px;font-size:13px;font-weight:bold;margin-bottom:14px;line-height:1.9}
 button{background:#2563eb;color:#fff;border:0;border-radius:10px;padding:10px 18px;font-family:inherit;font-size:14px;font-weight:bold;cursor:pointer}
 button.warn{background:#dc2626}
 a{color:#2563eb}
 table{width:100%;border-collapse:collapse;font-size:13px} td,th{padding:6px 8px;border-bottom:1px solid #e2e8f0;text-align:right}
</style>
</head>
<body>
<div class="card">
  <h1>عیب‌یاب SchoolDesk Pro</h1>
  <div class="sub">این صفحه فقط روی همین کامپیوتر باز می‌شود و روی سایت وجود ندارد.</div>
  <?php if ($msg): ?><div class="msg"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php foreach ($checks as $c): ?>
  <div class="row">
    <span class="<?php echo $c['ok'] ? 'ok' : 'bad'; ?>"><?php echo $c['ok'] ? '✓' : '✗'; ?></span>
    <div style="flex:1">
      <?php echo htmlspecialchars($c['name']); ?>
      <?php if ($c['detail']): ?><div class="detail"><?php echo htmlspecialchars($c['detail']); ?></div><?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <h1 style="font-size:16px">حساب‌های مدیر موجود</h1>
  <?php if ($admins): ?>
  <table>
    <tr><th>نام کاربری</th><th>وضعیت</th></tr>
    <?php foreach ($admins as $a): ?>
    <tr><td style="direction:ltr;text-align:right;font-family:monospace"><?php echo htmlspecialchars($a['username']); ?></td>
        <td><?php echo ((int)$a['status'] === 1) ? 'فعال' : '<span class="bad">غیرفعال</span>'; ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <div class="bad" style="font-size:13px">هیچ حساب مدیری در بانک یافت نشد!</div>
  <?php endif; ?>
</div>

<div class="card">
  <h1 style="font-size:16px">ابزار رفع مشکل ورود</h1>
  <div class="sub" style="line-height:2">
    اگر رمز را فراموش کرده‌اید یا بعد از همگام‌سازی رمز سایت را نمی‌دانید،
    این دکمه رمز کاربر <b>admin</b> را روی همین دسکتاپ به <b dir="ltr">admin123</b> برمی‌گرداند.
    (اگر همگام‌سازی فعال باشد، این رمز جدید به سایت هم منتقل می‌شود.)
  </div>
  <form method="POST" style="display:inline"><button name="fix_admin" value="1" class="warn" onclick="return confirm('رمز admin به admin123 برگردانده شود؟')">بازگردانی رمز admin</button></form>
  <form method="POST" style="display:inline"><button name="clear_throttle" value="1">باز کردن قفل تلاش‌های ناموفق</button></form>
  <div style="margin-top:16px"><a href="admin-login.php">→ رفتن به صفحه ورود</a></div>
</div>
</body>
</html>
