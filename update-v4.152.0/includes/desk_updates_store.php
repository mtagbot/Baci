<?php
/**
 * SchoolDesk Pro — مخزن بسته‌های به‌روزرسانی دسکتاپ (سمت سایت).
 *
 * بسته‌ها زیر uploads/desktop-updates/ نگه‌داری می‌شوند؛ این پوشه با
 * .htaccess از دسترسی مستقیم وب بسته است و فقط از مسیر
 * desk-update-api.php و با کلید همین مدرسه تحویل داده می‌شود.
 */
if (!function_exists('desk_updates_dir')) {

function desk_updates_dir(): string { return dirname(__DIR__).'/uploads/desktop-updates'; }
function desk_updates_ensure_dir(): bool {
    $dir = desk_updates_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return false;
    $guard = $dir.'/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Require all denied\nphp_flag engine off\nRemoveHandler .php .phtml\n");
    return is_dir($dir);
}
function desk_updates_index(): array {
    $file = desk_updates_dir().'/index.json';
    if (!is_file($file)) return [];
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data['updates'] ?? null) ? $data['updates'] : [];
}
function desk_updates_save(array $updates): bool {
    if (!desk_updates_ensure_dir()) return false;
    $file = desk_updates_dir().'/index.json';
    $temp = $file.'.'.getmypid().'.tmp';
    if (@file_put_contents($temp, json_encode(['updates' => array_values($updates)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
    if (!@rename($temp, $file)) { @unlink($temp); return false; }
    return true;
}
function desk_updates_id_ok(string $id): bool { return (bool)preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9._-]{2,63}\z~', $id); }
function desk_updates_latest(array $updates): ?array {
    $latest = null;
    foreach ($updates as $entry) {
        if (!is_array($entry) || empty($entry['id'])) continue;
        if (!empty($entry['latest'])) return $entry;
        if ($latest === null || (int)($entry['ts'] ?? 0) > (int)($latest['ts'] ?? 0)) $latest = $entry;
    }
    return $latest;
}
function desk_updates_find(array $updates, string $id): ?array {
    foreach ($updates as $entry) if (is_array($entry) && (string)($entry['id'] ?? '') === $id) return $entry;
    return null;
}
function desk_updates_path(array $entry): string { return desk_updates_dir().'/'.basename((string)($entry['file'] ?? '')); }

/** نام مسیر درون بسته باید ساده، نسبی و بدون کاراکتر کنترلی باشد. */
function desk_updates_safe_rel(string $rel): bool {
    if ($rel === '' || strlen($rel) > 240 || strpos($rel, '\\') !== false || strpos($rel, ':') !== false || strpos($rel, "\0") !== false) return false;
    foreach (explode('/', $rel) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') return false;
    return !preg_match('~[\x00-\x1f]~', $rel);
}
function desk_updates_pe_ok(string $binary): bool {
    if (strlen($binary) < 20000 || strlen($binary) > 2097152 || substr($binary, 0, 2) !== 'MZ') return false;
    $offset = unpack('V', substr($binary, 0x3c, 4))[1] ?? 0;
    if ($offset < 0x40 || $offset + 0x60 > strlen($binary) || substr($binary, $offset, 4) !== "PE\0\0") return false;
    return (unpack('v', substr($binary, $offset + 4, 2))[1] ?? 0) === 0x8664
        && (unpack('v', substr($binary, $offset + 24 + 68, 2))[1] ?? 0) === 2;
}

/**
 * بررسی بستهٔ بارگذاری‌شده پیش از انتشار: شکل پوشه‌ها، مسیرهای ایمن، نبود
 * فایل اجرایی/اسکریپتی و نبود پوشه‌های محرمانه. هیچ فایلی از بسته اجرا یا
 * استخراج نمی‌شود؛ فقط خوانده و بررسی می‌شود.
 */
function desk_updates_report(string $path): array {
    require_once __DIR__.'/desk_update_zip.php';
    try { $zip = new DeskUpdateZip($path); }
    catch (Throwable $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    try {
        if ($zip->count() < 1 || $zip->count() > DeskUpdateZip::MAX_ENTRIES) return ['ok' => false, 'error' => 'تعداد فایل‌های بسته مجاز نیست.'];
        $files = 0; $total = 0; $hasExe = false; $hasWeb = false;
        foreach ($zip->names() as $name) {
            if ($name === '' || substr($name, -1) === '/') continue;
            if (strpos($name, 'SchoolDeskPro/') !== 0) return ['ok' => false, 'error' => 'مسیر غیرمجاز در بسته: '.$name];
            $rel = substr($name, strlen('SchoolDeskPro/'));
            if (!desk_updates_safe_rel($rel)) return ['ok' => false, 'error' => 'مسیر ناایمن در بسته: '.$name];
            $total += (int)$zip->size($name);
            if ($rel === 'DESKTOP-UPDATE.json') continue;
            if ($rel === 'SchoolDeskPro.exe') {
                if (!desk_updates_pe_ok($zip->read($name))) return ['ok' => false, 'error' => 'فایل اجرایی داخل بسته، برنامهٔ ویندوزی معتبری نیست.'];
                $hasExe = true; continue;
            }
            if ($rel === 'reports-layout-update/router.php') {
                if (strpos($zip->read($name), 'SDP_REPORTS_ROOT_V1') === false) return ['ok' => false, 'error' => 'روتر همراه بسته، نشانهٔ امنیتی لازم را ندارد.'];
                continue;
            }
            $target = (string)preg_replace('~\A(?:www|reports)/~', '', $rel);
            if (!desk_updates_safe_rel($target)) return ['ok' => false, 'error' => 'مسیر ناایمن در بسته: '.$name];
            if (preg_match('~\A(?:config|data|php|profile|server|backups|licenses)/~i', $target)) return ['ok' => false, 'error' => 'بسته اجازهٔ تغییر پوشهٔ محرمانه را ندارد: '.$target];
            if (preg_match('~\A.*\.(?:exe|dll|bat|cmd|ps1|vbs|msi|scr|com|pif|so|sh|py)$~i', $target)) return ['ok' => false, 'error' => 'فایل اجرایی/اسکریپتی در بسته مجاز نیست: '.$target];
            if (strpos($target, 'uploads/') === 0 && !preg_match('~\.(?:ttf|woff|woff2|otf)$~i', $target)) return ['ok' => false, 'error' => 'در پوشهٔ uploads فقط فونت مجاز است: '.$target];
            if (basename($target) === 'router.php' && strpos($target, '/') === false && strpos($zip->read($name), 'SDP_REPORTS_ROOT_V1') === false) return ['ok' => false, 'error' => 'روتر جدید نشانهٔ امنیتی SDP_REPORTS_ROOT_V1 را ندارد.'];
            $files++; $hasWeb = true;
        }
        if (!$hasWeb && !$hasExe) return ['ok' => false, 'error' => 'بسته هیچ فایل قابل نصبی ندارد.'];
        return ['ok' => true, 'files' => $files, 'expanded' => $total, 'launcher' => $hasExe];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    } finally { $zip->close(); }
}
}
