<?php
/**
 * SchoolDesk Pro — desktop online updates (desktop distribution only).
 *
 * Flow: check the school site for a published desktop package (authenticated
 * with the same per-install sync key), download it to data/update/, verify its
 * SHA256, validate EVERY entry before touching the disk, back up each replaced
 * file, then write atomically. Web files (PHP/CSS/JS/assets) apply instantly;
 * a new SchoolDeskPro.exe is staged for the launcher, which replaces the file
 * after the app exits and starts the new build.
 *
 * Never touched by an update: config/ (installation + sync key), data/
 * (school database, sessions, backups), php/ (portable runtime), backups/.
 */
require_once __DIR__.'/desk_update_zip.php';

if (!function_exists('desk_update_dir')) {

function desk_update_webroot(): string { return dirname(__DIR__); }
function desk_update_base(): string { return dirname(desk_update_webroot()); }
function desk_update_dir(): string {
    $dir = desk_update_base().'/data/update';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}
function desk_update_state_file(): string { return desk_update_dir().'/state.json'; }
function desk_update_state(): array {
    $file = desk_update_state_file();
    if (!is_file($file)) return [];
    $state = json_decode((string)@file_get_contents($file), true);
    return is_array($state) ? $state : [];
}
function desk_update_write_json(string $file, array $data): bool {
    $temp = $file.'.'.getmypid().'.tmp';
    if (@file_put_contents($temp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) return false;
    if (!@rename($temp, $file)) { @unlink($temp); return false; }
    return true;
}
function desk_update_set_state(array $patch): array {
    $state = array_merge(desk_update_state(), $patch);
    $state['ts'] = time();
    desk_update_write_json(desk_update_state_file(), $state);
    return $state;
}
function desk_update_local_version(): string {
    $release = is_file(desk_update_webroot().'/config/release.php') ? require desk_update_webroot().'/config/release.php' : [];
    return is_array($release) ? (string)($release['desktop_version'] ?? '') : '';
}
/* The update endpoint lives beside the sync endpoint the school already uses. */
function desk_update_endpoint(): string {
    require_once __DIR__.'/desk_sync.php';
    $url = trim((string)DeskSync::getCfg('desk_sync_url'));
    if ($url === '') return '';
    $url = preg_replace('~[^/?]+\.php(?=\?|$)~i', 'desk-update-api.php', $url, 1);
    return is_string($url) ? $url : '';
}
function desk_update_key(): string {
    require_once __DIR__.'/desk_sync.php';
    return trim((string)DeskSync::getCfg('desk_sync_key'));
}
function desk_update_ready(): array {
    $url = desk_update_endpoint(); $key = desk_update_key();
    if ($url === '' || $key === '') return ['ok' => false, 'error' => 'ابتدا در بخش «همگام‌سازی با سایت»، نشانی سرور و کلید این مدرسه را ذخیره کنید.'];
    if (stripos($url, 'https://') !== 0) return ['ok' => false, 'error' => 'برای دریافت به‌روزرسانی، نشانی سایت باید HTTPS باشد.'];
    return ['ok' => true, 'url' => $url, 'key' => $key];
}
function desk_update_request(string $url, array $payload, bool $binary, int $connectT, int $totalT) {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'افزونهٔ curl روی این نصب فعال نیست؛ دریافت به‌روزرسانی از سایت ممکن نیست.'];
    $ch = curl_init($url);
    $target = $binary ? fopen(desk_update_dir().'/package.part', 'wb') : null;
    if ($binary && !$target) return ['ok' => false, 'error' => 'امکان نوشتن فایل در پوشهٔ data نیست.'];
    $options = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: ' . ($binary ? 'application/zip' : 'application/json')],
        CURLOPT_CONNECTTIMEOUT => $connectT,
        CURLOPT_TIMEOUT => $totalT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($binary) { $options[CURLOPT_RETURNTRANSFER] = false; $options[CURLOPT_FILE] = $target; }
    else { $options[CURLOPT_RETURNTRANSFER] = true; }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($target) fclose($target);
    if ($binary) {
        if ($body === false || $status !== 200) { @unlink(desk_update_dir().'/package.part'); return ['ok' => false, 'error' => $error !== '' ? $error : 'دانلود بسته ناموفق بود (HTTP ' . $status . ')']; }
        return ['ok' => true, 'file' => desk_update_dir().'/package.part'];
    }
    if ($body === false) return ['ok' => false, 'error' => $error !== '' ? $error : 'ارتباط با سایت برقرار نشد.'];
    $json = json_decode((string)$body, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'پاسخ سایت قابل خواندن نیست.'];
    if (empty($json['ok'])) return ['ok' => false, 'error' => (string)($json['error'] ?? 'سایت بسته‌ای برای این مدرسه منتشر نکرده است.')];
    return ['ok' => true, 'data' => $json];
}
function desk_update_check(bool $force = false, int $interval = 21600): array {
    $ready = desk_update_ready();
    if (!$ready['ok']) return ['ok' => false, 'error' => $ready['error']];
    $state = desk_update_state();
    if (!$force && !empty($state['checked_at']) && time() - (int)$state['checked_at'] < $interval) {
        return ['ok' => true, 'cached' => true, 'latest' => $state['latest'] ?? null, 'available' => desk_update_available($state), 'state' => $state];
    }
    $result = desk_update_request($ready['url'], ['action' => 'latest', 'key' => $ready['key'], 'platform' => 'desktop', 'version' => desk_update_local_version()], false, 8, 20);
    if (!$result['ok']) { desk_update_set_state(['checked_at' => time(), 'error' => $result['error']]); return ['ok' => false, 'error' => $result['error']]; }
    $latest = $result['data']['update'] ?? null;
    $state = desk_update_set_state(['checked_at' => time(), 'latest' => $latest, 'error' => '']);
    return ['ok' => true, 'latest' => $latest, 'available' => desk_update_available($state), 'state' => $state];
}
function desk_update_available(array $state): bool {
    $latest = $state['latest'] ?? null;
    if (!is_array($latest) || empty($latest['id']) || empty($latest['sha256'])) return false;
    return (string)$latest['id'] !== (string)($state['applied_id'] ?? '');
}
function desk_update_sha256(string $file): string { return hash_file('sha256', $file) ?: ''; }

/* ---------------- package validation (no writes) ---------------- */

function desk_update_safe_rel(string $rel): bool {
    if ($rel === '' || strlen($rel) > 240) return false;
    if (strpos($rel, '\\') !== false || strpos($rel, ':') !== false || strpos($rel, "\0") !== false) return false;
    foreach (explode('/', $rel) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') return false;
        if (preg_match('~[\x00-\x1f]~', $segment)) return false;
    }
    return (bool)preg_match('~\A[^\x00-\x1f]+\z~', $rel);
}
function desk_update_private_path(string $rel): bool {
    return (bool)preg_match('~\A(?:config|data|php|profile|server|backups|licenses)/~i', $rel);
}
function desk_update_blocked_extension(string $rel): bool {
    return (bool)preg_match('~\A.*\.(?:exe|dll|bat|cmd|ps1|vbs|msi|scr|com|pif|so|sh|py)$~i', $rel);
}
function desk_update_pe_ok(string $binary): bool {
    if (strlen($binary) < 20000 || strlen($binary) > 2097152) return false;
    if (substr($binary, 0, 2) !== 'MZ') return false;
    $offset = unpack('V', substr($binary, 0x3c, 4))[1] ?? 0;
    if ($offset < 0x40 || $offset + 0x60 > strlen($binary)) return false;
    if (substr($binary, $offset, 4) !== "PE\0\0") return false;
    $machine = unpack('v', substr($binary, $offset + 4, 2))[1] ?? 0;
    $subsystem = unpack('v', substr($binary, $offset + 24 + 68, 2))[1] ?? 0;
    return $machine === 0x8664 && $subsystem === 2;
}

/**
 * Validate the whole package and return the planned writes. Nothing is written
 * before this succeeds, so a bad package can never leave a half-updated app.
 */
function desk_update_plan(string $zipPath, bool $forcePure = false): array {
    $zip = new DeskUpdateZip($zipPath, $forcePure);
    try {
        $plan = ['web' => [], 'launcher' => null, 'staged_router' => null, 'manifest' => null, 'bytes' => 0];
        foreach ($zip->names() as $name) {
            if (substr($name, -1) === '/') continue;
            if (strpos($name, 'SchoolDeskPro/') !== 0) throw new RuntimeException('مسیر غیرمجاز در بسته: ' . $name);
            $rel = substr($name, strlen('SchoolDeskPro/'));
            if (!desk_update_safe_rel($rel)) throw new RuntimeException('مسیر ناایمن در بسته: ' . $name);
            if ($rel === 'DESKTOP-UPDATE.json') { $plan['manifest'] = $name; continue; }
            if ($rel === 'SchoolDeskPro.exe') {
                $binary = $zip->read($name);
                if (!desk_update_pe_ok($binary)) throw new RuntimeException('فایل اجرایی داخل بسته، یک برنامهٔ ویندوزی معتبر نیست.');
                $plan['launcher'] = $name; $plan['bytes'] += strlen($binary); continue;
            }
            if ($rel === 'reports-layout-update/router.php') {
                $router = $zip->read($name);
                if (strpos($router, 'SDP_REPORTS_ROOT_V1') === false) throw new RuntimeException('روتر همراه بسته، نشانهٔ امنیتی لازم را ندارد.');
                $plan['staged_router'] = $name; $plan['bytes'] += strlen($router); continue;
            }
            $target = $rel;
            foreach (['www/', 'reports/'] as $prefix) {
                if (strpos($target, $prefix) === 0) { $target = substr($target, strlen($prefix)); break; }
            }
            if (!desk_update_safe_rel($target)) throw new RuntimeException('مسیر ناایمن در بسته: ' . $name);
            if (desk_update_private_path($target)) throw new RuntimeException('بسته اجازهٔ تغییر پوشهٔ محافظت‌شده را ندارد: ' . $target);
            if (desk_update_blocked_extension($target)) throw new RuntimeException('فایل اجرایی/اسکریپتی در بسته مجاز نیست: ' . $target);
            if (strpos($target, 'uploads/') === 0 && !preg_match('~\.(?:ttf|woff|woff2|otf)$~i', $target)) throw new RuntimeException('فقط فونت در پوشهٔ uploads مجاز است: ' . $target);
            if (basename($target) === 'router.php' && strpos($target, '/') === false) {
                $router = $zip->read($name);
                if (strpos($router, 'SDP_REPORTS_ROOT_V1') === false) throw new RuntimeException('روتر جدید نشانهٔ امنیتی SDP_REPORTS_ROOT_V1 را ندارد.');
            }
            $plan['web'][$name] = $target;
            $plan['bytes'] += (int)$zip->size($name);
        }
        if (!$plan['web'] && !$plan['launcher'] && !$plan['staged_router']) throw new RuntimeException('بسته هیچ فایل قابل نصبی ندارد.');
        if ($plan['bytes'] > DeskUpdateZip::MAX_TOTAL_BYTES) throw new RuntimeException('حجم بسته بیش از حد است.');
        if ($plan['manifest']) {
            $meta = json_decode($zip->read($plan['manifest']), true);
            $plan['meta'] = is_array($meta) ? $meta : [];
        }
        return $plan;
    } finally {
        $zip->close();
    }
}

/** Verify every file the package declares, when it ships a manifest. */
function desk_update_verify_manifest(DeskUpdateZip $zip, array $plan): void {
    $files = $plan['meta']['files'] ?? null;
    if (!is_array($files)) return;
    foreach ($plan['web'] as $entry => $target) {
        $expected = $files[$entry]['sha256'] ?? ($files[$target]['sha256'] ?? null);
        if (!is_string($expected) || $expected === '') continue;
        if (!hash_equals(strtolower($expected), desk_update_sha256_bytes($zip->read($entry)))) throw new RuntimeException('فایل ' . $entry . ' با فهرست بسته هم‌خوان نیست.');
    }
}
function desk_update_sha256_bytes(string $bytes): string { return hash('sha256', $bytes); }
function desk_update_launcher_helper(): string {
    /* Detached helper. Windows cannot overwrite a running executable and any
       process spawned by the PHP worker dies with the app's job object, so the
       launcher starts this script on the NEXT boot and exits immediately. */
    return "@echo off\r\n"
        . "setlocal enableextensions\r\n"
        . "set \"STAGE=%~dp0\"\r\n"
        . "set \"BASE=%STAGE%..\\..\\..\"\r\n"
        . "set \"TARGET=%BASE%\\SchoolDeskPro.exe\"\r\n"
        . "set \"BACKUP=%TARGET%.old\"\r\n"
        . "set \"MARK=%STAGE%failed.txt\"\r\n"
        . "del /Q \"%MARK%\" >NUL 2>&1\r\n"
        . "set /a WAIT=0\r\n"
        . ":wait\r\n"
        . "tasklist /FI \"IMAGENAME eq SchoolDeskPro.exe\" /NH 2>NUL | find /I \"SchoolDeskPro.exe\" >NUL\r\n"
        . "if errorlevel 1 goto swap\r\n"
        . "ping -n 2 127.0.0.1 >NUL\r\n"
        . "set /a WAIT+=1\r\n"
        . "if %WAIT% LSS 120 goto wait\r\n"
        . "del /Q \"%STAGE%expected.json\" >NUL 2>&1\r\n"
        . "exit /b 0\r\n"
        . ":swap\r\n"
        . "if exist \"%BACKUP%\" del /Q \"%BACKUP%\" >NUL 2>&1\r\n"
        . "move /Y \"%TARGET%\" \"%BACKUP%\" >NUL 2>&1\r\n"
        . "if errorlevel 1 goto abort\r\n"
        . "move /Y \"%STAGE%SchoolDeskPro.exe\" \"%TARGET%\" >NUL 2>&1\r\n"
        . "if errorlevel 1 goto restore\r\n"
        . "start \"\" \"%TARGET%\"\r\n"
        . "set /a CHECK=0\r\n"
        . ":verify\r\n"
        . "ping -n 4 127.0.0.1 >NUL\r\n"
        . "tasklist /FI \"IMAGENAME eq SchoolDeskPro.exe\" /NH 2>NUL | find /I \"SchoolDeskPro.exe\" >NUL\r\n"
        . "if not errorlevel 1 goto done\r\n"
        . "set /a CHECK+=1\r\n"
        . "if %CHECK% LSS 15 goto verify\r\n"
        . ":restore\r\n"
        . "del /Q \"%TARGET%\" >NUL 2>&1\r\n"
        . "move /Y \"%BACKUP%\" \"%TARGET%\" >NUL 2>&1\r\n"
        . "if exist \"%TARGET%\" start \"\" \"%TARGET%\"\r\n"
        . "echo new build did not start; previous build restored> \"%MARK%\"\r\n"
        . "del /Q \"%STAGE%expected.json\" >NUL 2>&1\r\n"
        . "exit /b 1\r\n"
        . ":done\r\n"
        . "del /Q \"%STAGE%expected.json\" >NUL 2>&1\r\n"
        . "exit /b 0\r\n"
        . ":abort\r\n"
        . "echo launcher could not be replaced> \"%MARK%\"\r\n"
        . "del /Q \"%STAGE%expected.json\" >NUL 2>&1\r\n"
        . "exit /b 1\r\n";
}
function desk_update_install(string $updateId, array $latest, bool $forcePure = false): array {
    $ready = desk_update_ready();
    if (!$ready['ok']) return ['ok' => false, 'error' => $ready['error']];
    if (empty($latest['sha256']) || empty($updateId) || $updateId !== (string)($latest['id'] ?? '')) return ['ok' => false, 'error' => 'اطلاعات بستهٔ منتشرشده کامل نیست؛ یک‌بار دیگر بررسی کنید.'];
    desk_update_set_state(['installing' => $updateId, 'error' => '']);
    $download = desk_update_request($ready['url'], ['action' => 'download', 'key' => $ready['key'], 'id' => $updateId, 'platform' => 'desktop'], true, 10, 300);
    if (!$download['ok']) { desk_update_set_state(['installing' => '']); return ['ok' => false, 'error' => $download['error']]; }
    $part = $download['file'];
    $size = (int)@filesize($part);
    if ($size <= 0 || (int)$latest['bytes'] > 0 && $size !== (int)$latest['bytes']) {
        @unlink($part); desk_update_set_state(['installing' => '']);
        return ['ok' => false, 'error' => 'حجم بستهٔ دانلودشده با اعلام سایت هم‌خوان نیست.'];
    }
    if (!hash_equals(strtolower((string)$latest['sha256']), desk_update_sha256($part))) {
        @unlink($part); desk_update_set_state(['installing' => '']);
        return ['ok' => false, 'error' => 'چک‌سام بستهٔ دانلودشده با سایت هم‌خوان نیست؛ نصب متوقف شد.'];
    }
    $package = desk_update_dir().'/package.zip';
    if (!@rename($part, $package)) { @unlink($part); desk_update_set_state(['installing' => '']); return ['ok' => false, 'error' => 'آماده‌سازی فایل بسته ناموفق بود.']; }
    try {
        $plan = desk_update_plan($package, $forcePure);
        $zip = new DeskUpdateZip($package, $forcePure);
        try { desk_update_verify_manifest($zip, $plan); } finally { $zip->close(); }
        $result = desk_update_apply($package, $plan, $updateId, $forcePure);
    } catch (Throwable $e) {
        desk_update_set_state(['installing' => '', 'error' => $e->getMessage()]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $state = desk_update_set_state(['installing' => '', 'applied_id' => $updateId, 'applied_at' => time(), 'applied_version' => (string)($latest['version'] ?? ''), 'applied_files' => count($plan['web']), 'launcher_staged' => !empty($result['launcher']), 'restart_required' => !empty($result['launcher']), 'error' => '', 'backup' => $result['backup']]);
    return ['ok' => true, 'files' => count($plan['web']), 'launcher' => !empty($result['launcher']), 'backup' => $result['backup'], 'staged_router' => !empty($plan['staged_router']), 'state' => $state];
}

function desk_update_apply(string $package, array $plan, string $updateId, bool $forcePure = false): array {
    $webroot = desk_update_webroot();
    $base = desk_update_base();
    $safeId = preg_replace('~[^a-zA-Z0-9._-]~', '', $updateId);
    $backup = desk_update_dir().'/backup-'.$safeId;
    if (!is_dir($backup) && !@mkdir($backup, 0777, true)) throw new RuntimeException('ساخت پوشهٔ پشتیبان ناموفق بود؛ بسته نصب نشد.');
    $zip = new DeskUpdateZip($package, $forcePure);
    $written = 0;
    try {
        foreach ($plan['web'] as $entry => $target) {
            $destination = $webroot.'/'.$target;
            $data = $zip->read($entry);
            if (is_file($destination)) {
                $backupFile = $backup.'/'.$target;
                if (!is_dir(dirname($backupFile))) @mkdir(dirname($backupFile), 0777, true);
                @copy($destination, $backupFile);
            }
            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0777, true)) throw new RuntimeException('ساخت پوشهٔ مقصد ناموفق بود: ' . $target);
            $temp = $destination.'.'.getmypid().'.new';
            if (@file_put_contents($temp, $data) !== strlen($data)) { @unlink($temp); throw new RuntimeException('نوشتن فایل ناموفق بود: ' . $target); }
            if (!@rename($temp, $destination)) { @unlink($temp); throw new RuntimeException('جای‌گزینی فایل ناموفق بود: ' . $target); }
            if (function_exists('opcache_invalidate')) @opcache_invalidate($destination, true);
            $written++;
        }
        if ($plan['staged_router']) {
            $directory = $base.'/reports-layout-update';
            if (!is_dir($directory)) @mkdir($directory, 0777, true);
            $data = $zip->read($plan['staged_router']);
            if (@file_put_contents($directory.'/router.php', $data) !== strlen($data)) throw new RuntimeException('نوشتن روتر جدید ناموفق بود.');
        }
        $launcherStaged = false;
        if ($plan['launcher']) {
            $directory = desk_update_dir().'/launcher';
            if (!is_dir($directory)) @mkdir($directory, 0777, true);
            $binary = $zip->read($plan['launcher']);
            if (!desk_update_pe_ok($binary)) throw new RuntimeException('فایل اجرایی بسته معتبر نیست.');
            if (@file_put_contents($directory.'/SchoolDeskPro.exe', $binary) !== strlen($binary)) throw new RuntimeException('آماده‌سازی فایل اجرایی ناموفق بود.');
            desk_update_write_json($directory.'/expected.json', ['sha256' => desk_update_sha256($directory.'/SchoolDeskPro.exe'), 'bytes' => strlen($binary), 'update_id' => $updateId, 'ts' => time()]);
            if (@file_put_contents($directory.'/apply-launcher-update.cmd', desk_update_launcher_helper()) === false) throw new RuntimeException('ساخت راه‌انداز به‌روزرسانی ناموفق بود.');
            $attempts = desk_update_dir().'/launcher/attempts.txt';
            if (is_file($attempts)) @unlink($attempts);
            $launcherStaged = true;
        }
    } finally {
        $zip->close();
    }
    @unlink($package);
    return ['written' => $written, 'launcher' => $launcherStaged, 'backup' => is_dir($backup) ? $backup : ''];
}
}
