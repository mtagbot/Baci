<?php
// File: includes/bot_admin_ui.php
/**
 * Shared production admin UI/controller for Bale and Telegram bots.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bot_helpers.php';
require_once __DIR__ . '/school_sort.php';

if (!function_exists('bot_admin_platform_title')) {
    function bot_admin_platform_title($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'تلگرام' : 'بله';
    }
}

if (!function_exists('bot_admin_webhook_file')) {
    function bot_admin_webhook_file($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'telegram-webhook.php' : 'bale-webhook.php';
    }
}

if (!function_exists('bot_admin_page_file')) {
    function bot_admin_page_file($platform) {
        return bot_valid_platform($platform) === 'telegram' ? 'telegram-bot.php' : 'bale-bot.php';
    }
}

if (!function_exists('bot_admin_handle_request')) {
    function bot_admin_handle_request($platform) {
        $platform = bot_valid_platform($platform);
        ensure_bot_schema($platform);
        $page = bot_admin_page_file($platform);
        $title = bot_admin_platform_title($platform);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!verify_csrf($_POST['csrf_token'] ?? '')) {
                set_flash_message('error', 'خطای امنیتی CSRF. لطفاً صفحه را دوباره بارگذاری کنید.');
                redirect($page);
            }

            if (isset($_POST['save_bot_token'])) {
                set_setting(bot_token_key($platform), trim($_POST['bot_token'] ?? ''));
                /* v4.84.0: optional relay base URL for Telegram — for hosts that
                   block foreign traffic (common on Iranian hosting). All API
                   calls (setWebhook, sendMessage, ...) go through the relay. */
                if ($platform === 'telegram' && array_key_exists('telegram_api_base', $_POST)) {
                    $relay = trim((string)$_POST['telegram_api_base']);
                    $relay = rtrim($relay, '/');
                    if ($relay !== '' && !preg_match('#^https?://#i', $relay)) $relay = 'https://' . $relay;
                    set_setting('telegram_api_base', $relay);
                }
                set_flash_message('success', 'توکن ربات ' . $title . ' با موفقیت ذخیره شد.');
                redirect($page);
            }

            if (isset($_POST['send_targeted_message'])) {
                $scope = $_POST['target_scope'] ?? 'all';
                $allowed = ['all', 'grade', 'class', 'student'];
                if (!in_array($scope, $allowed, true)) $scope = 'all';
                $value = trim($_POST['target_value_' . $scope] ?? '');
                $message = trim($_POST['target_message'] ?? '');

                // v4.47.0: optional file attachment (photo or document).
                $hasFile = isset($_FILES['target_file']) && ($_FILES['target_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                $tmpPath = ''; $fileMime = ''; $fileName = '';
                if ($hasFile) {
                    $f = $_FILES['target_file'];
                    $allowedExt = [
                        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
                        'pdf' => 'application/pdf',
                        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                        'zip' => 'application/zip', 'rar' => 'application/x-rar-compressed',
                        'txt' => 'text/plain', 'csv' => 'text/csv',
                        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
                        'mp4' => 'video/mp4',
                    ];
                    $maxBytes = 49 * 1024 * 1024; // Bot API upload cap is 50MB
                    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        set_flash_message('error', 'بارگذاری فایل ناموفق بود (کد خطا: ' . (int)$f['error'] . '). اگر فایل بزرگ است، محدودیت upload_max_filesize هاست را بررسی کنید.');
                        redirect($page);
                    }
                    $fileName = $f['name'] ?? 'file';
                    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                    if (!isset($allowedExt[$ext])) {
                        set_flash_message('error', 'فرمت فایل مجاز نیست. فرمت‌های مجاز: تصویر، PDF، آفیس، ZIP/RAR، متن، صوت و ویدیو MP4.');
                        redirect($page);
                    }
                    if ((int)$f['size'] > $maxBytes) {
                        set_flash_message('error', 'حجم فایل بیشتر از سقف مجاز ربات (۴۹ مگابایت) است.');
                        redirect($page);
                    }
                    $fileMime = $allowedExt[$ext];
                    $dir = dirname(__DIR__) . '/uploads/bot-broadcast';
                    if (!is_dir($dir)) @mkdir($dir, 0755, true);
                    $tmpPath = $dir . '/bcast_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($f['tmp_name'], $tmpPath)) {
                        set_flash_message('error', 'ذخیره موقت فایل روی سرور ناموفق بود.');
                        redirect($page);
                    }
                }

                if ($message === '' && !$hasFile) {
                    set_flash_message('error', 'متن پیام یا فایل پیوست الزامی است.');
                } else {
                    try {
                        if ($hasFile) {
                            $result = bot_send_targeted_file($platform, $scope, $value, $message, $tmpPath, $fileMime, $fileName);
                            log_activity($_SESSION['admin_id'] ?? null, 'ارسال فایل هدفمند ربات ' . $title, "scope=$scope value=$value file=$fileName sent={$result['sent']} failed={$result['failed']}");
                        } else {
                            $result = bot_send_targeted_text($platform, $scope, $value, $message);
                            log_activity($_SESSION['admin_id'] ?? null, 'ارسال هدفمند ربات ' . $title, "scope=$scope value=$value sent={$result['sent']} failed={$result['failed']}");
                        }
                        set_flash_message($result['failed'] ? 'warning' : 'success', "ارسال انجام شد. کل گیرندگان: {$result['total']}، موفق: {$result['sent']}، ناموفق: {$result['failed']}.");
                    } catch (Exception $e) {
                        set_flash_message('error', 'خطا در ارسال پیام: ' . $e->getMessage());
                    }
                }
                if ($tmpPath !== '' && is_file($tmpPath)) @unlink($tmpPath);
                redirect($page);
            }

            if (isset($_POST['save_templates'])) {
                foreach (bot_default_messages() as $key => $default) {
                    $txt = trim($_POST['msg_' . $key] ?? $default);
                    DB::execute("INSERT INTO bot_message_templates (platform, template_key, template_text) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE template_text = VALUES(template_text)", [$platform, $key, $txt]);
                }
                foreach (bot_default_buttons() as $b) {
                    $key = $b[0];
                    $txt = trim($_POST['btn_text_' . $key] ?? $b[1]);
                    $row = max(1, (int)($_POST['btn_row_' . $key] ?? $b[3]));
                    $order = max(1, (int)($_POST['btn_order_' . $key] ?? $b[2]));
                    $active = isset($_POST['btn_active_' . $key]) ? 1 : 0;
                    DB::execute("INSERT INTO bot_button_templates (platform, button_key, button_text, sort_order, row_no, is_active) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE button_text = VALUES(button_text), sort_order = VALUES(sort_order), row_no = VALUES(row_no), is_active = VALUES(is_active)", [$platform, $key, $txt, $order, $row, $active]);
                }
                set_flash_message('success', 'متن‌های ثابت و دکمه‌های ربات ' . $title . ' ذخیره شد و بلافاصله در وبهوک اعمال می‌شود.');
                redirect($page);
            }
        }

        if (($_GET['action'] ?? '') === 'set_webhook') {
            if (!verify_csrf($_GET['csrf_token'] ?? '')) {
                set_flash_message('error', 'درخواست تنظیم وبهوک معتبر نیست.');
                redirect($page);
            }
            // SchoolDesk Pro: the webhook must always point at the LIVE SITE.
            // Setting it from the desktop would register 127.0.0.1 and break
            // the site's bot. Sending messages from the desktop works fine.
            if (PHP_SAPI === 'cli-server') {
                set_flash_message('error', 'تنظیم وبهوک فقط از روی سایت اصلی امکان‌پذیر است. (دریافت پیام‌های ربات روی سایت انجام می‌شود و اکانت‌های متصل به‌صورت خودکار با همگام‌سازی به این برنامه می‌آیند. ارسال پیام/اعلان از همین برنامه بدون مشکل کار می‌کند.)');
                redirect($page);
            }
            $token = get_setting(bot_token_key($platform), '');
            if ($token === '') {
                set_flash_message('error', 'ابتدا توکن ربات را ذخیره کنید.');
                redirect($page);
            }
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $base = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '')), '/');
            if ($base === '.') $base = '';
            $webhookUrl = $protocol . $host . $base . '/' . bot_admin_webhook_file($platform);
            try {
                $webhookPayload = ['url' => $webhookUrl];
                if ($platform === 'telegram') {
                    // v4.46.0: register a secret token so the webhook only accepts
                    // genuine updates coming from Telegram's servers.
                    $whSecret = get_setting('telegram_webhook_secret', '');
                    if ($whSecret === '') { $whSecret = bin2hex(random_bytes(24)); set_setting('telegram_webhook_secret', $whSecret); }
                    $webhookPayload['secret_token'] = $whSecret;
                }
                $res = bot_api_request($platform, 'setWebhook', $webhookPayload);
                set_flash_message('success', 'وبهوک ربات ' . $title . ' با موفقیت روی ' . $webhookUrl . ' تنظیم شد.');
                log_activity($_SESSION['admin_id'] ?? null, 'تنظیم وبهوک ربات ' . $title, json_encode($res, JSON_UNESCAPED_UNICODE));
            } catch (Exception $e) {
                set_flash_message('error', 'خطا در تنظیم وبهوک: ' . $e->getMessage());
            }
            redirect($page);
        }
    }
}

if (!function_exists('bot_admin_render_page')) {
    function bot_admin_render_page($platform) {
        $platform = bot_valid_platform($platform);
        $title = bot_admin_platform_title($platform);
        $page = bot_admin_page_file($platform);
        $userTable = bot_user_table($platform);
        $chatCol = $platform === 'telegram' ? 'telegram_chat_id' : 'bale_chat_id';
        $usernameCol = $platform === 'telegram' ? 'telegram_username' : 'bale_username';
        $token = get_setting(bot_token_key($platform), '');
        $adminSecret = get_setting('admin_bot_login_secret', '');
        if ($adminSecret === '') { $adminSecret = bin2hex(random_bytes(8)); set_setting('admin_bot_login_secret', $adminSecret); }
        $connectedUsers = DB::fetchAll("SELECT b.*, b.`$chatCol` AS chat_id, b.`$usernameCol` AS username, s.first_name, s.last_name, s.national_id, s.grade_level, s.class_name FROM `$userTable` b JOIN students s ON b.student_id = s.id ORDER BY b.id DESC LIMIT 200");
        // Target options are unified with students, manual classes and imported weekly schedules.
        $grades = get_unified_grade_options($_GET['year'] ?? get_setting('current_academic_year', '1404/1405'));
        $classes = get_unified_class_options($_GET['year'] ?? get_setting('current_academic_year', '1404/1405'));
        $students = DB::fetchAll("SELECT id, first_name, last_name, national_id, class_name, grade_level FROM students WHERE status = 'active'", []);
        persian_usort_students($students);
        $messages = [];
        foreach (bot_default_messages() as $key => $default) {
            $row = DB::fetch("SELECT template_text FROM bot_message_templates WHERE platform = ? AND template_key = ?", [$platform, $key]);
            $messages[$key] = $row ? $row['template_text'] : $default;
        }
        $buttons = [];
        foreach (bot_default_buttons() as $b) {
            $row = DB::fetch("SELECT * FROM bot_button_templates WHERE platform = ? AND button_key = ?", [$platform, $b[0]]);
            $buttons[$b[0]] = $row ?: ['button_key' => $b[0], 'button_text' => $b[1], 'sort_order' => $b[2], 'row_no' => $b[3], 'is_active' => 1];
        }
        ?>
<div class="space-y-6 bot-admin-page">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">🤖 مدیریت ربات <?php echo clean($title); ?></h2>
            <p class="text-sm text-muted">احراز هویت اولیا، دریافت کارنامه تصویری، ارسال هدفمند و سفارشی‌سازی متن‌ها/دکمه‌ها</p>
        </div>
        <div class="flex gap-2">
            <a href="<?php echo $platform === 'telegram' ? 'bale-bot.php' : 'telegram-bot.php'; ?>" class="btn btn-secondary text-xs">رفتن به ربات <?php echo $platform === 'telegram' ? 'بله' : 'تلگرام'; ?></a>
            <a href="sms-panel.php" class="btn btn-outline text-xs">بازگشت</a>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-6 responsive-grid">
        <section class="card shadow-lg space-y-4">
            <h3 class="font-bold text-primary border-b pb-2">تنظیمات توکن و Webhook</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="save_bot_token" value="1">
                <label class="block text-xs font-semibold">توکن ربات <?php echo clean($title); ?></label>
                <input type="password" name="bot_token" class="form-input dir-ltr text-left font-mono text-xs" value="<?php echo clean($token); ?>" required>
                <?php if ($platform === 'telegram'): ?>
                <label class="block text-xs font-semibold mt-2">آدرس واسط (رله) API تلگرام — اختیاری</label>
                <input type="text" name="telegram_api_base" class="form-input dir-ltr text-left font-mono text-xs" placeholder="https://my-relay.workers.dev" value="<?php echo clean(get_setting('telegram_api_base', '')); ?>">
                <p class="text-xs text-muted">اگر هاست شما دسترسی به سایت‌های خارج را بسته است (خطای Connection timed out)، یک واسط بسازید و آدرسش را اینجا وارد کنید. راهنمای کامل پایین همین صفحه است. خالی = اتصال مستقیم.</p>
                <?php endif; ?>
                <button class="btn btn-primary w-full text-xs" type="submit">💾 ذخیره توکن</button>
            </form>
            <?php if ($platform === 'telegram'): ?>
            <details class="soft-panel text-xs">
                <summary class="font-bold cursor-pointer">راهنما: هاست ایرانی و خطای Connection timed out</summary>
                <div class="space-y-2 mt-2">
                    <p>بیشتر هاست‌های داخل ایران دسترسی به سرورهای خارجی (از جمله api.telegram.org) را می‌بندند. راه‌حل: یک «واسط/رله» رایگان روی Cloudflare Workers بسازید که درخواست‌ها را به تلگرام برساند:</p>
                    <ol class="pr-4 space-y-1" style="list-style:decimal">
                        <li>در <b>workers.cloudflare.com</b> ثبت‌نام کنید (رایگان، تا ۱۰۰هزار درخواست در روز).</li>
                        <li>یک Worker جدید بسازید و کد زیر را جایگزین کنید و Deploy بزنید:</li>
                    </ol>
                    <pre class="dir-ltr text-left" style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:8px;overflow:auto;font-size:10px">export default {
  async fetch(req) {
    const u = new URL(req.url);
    if (!u.pathname.startsWith('/bot')) return new Response('ok');
    return fetch('https://api.telegram.org' + u.pathname + u.search, {
      method: req.method,
      headers: req.headers,
      body: ['GET','HEAD'].includes(req.method) ? undefined : req.body
    });
  }
};</pre>
                    <ol class="pr-4 space-y-1" start="3" style="list-style:decimal">
                        <li>آدرس Worker (مثلا <code class="dir-ltr">https://my-relay.my-name.workers.dev</code>) را در فیلد «آدرس واسط» بالا وارد و ذخیره کنید.</li>
                        <li>دکمه «ثبت خودکار Webhook» را بزنید — این‌بار درخواست از طریق واسط به تلگرام می‌رسد.</li>
                    </ol>
                    <p class="text-muted">نکته: وبهوک (دریافت پیام از تلگرام به سایت شما) سمت تلگرام است و از بیرون به سایت شما می‌آید؛ معمولا مشکلی ندارد. مشکل فقط تماس‌های خروجی سایت شماست که واسط آن را حل می‌کند. ارسال همه پیام‌ها/اعلان‌ها هم از همین واسط عبور می‌کند. اگر دامنه workers.dev هم روی هاست شما بسته بود، می‌توانید یک دامنه دلخواه به همان Worker وصل کنید یا واسط را روی هر هاست خارجی دیگری (یک فایل PHP ساده) قرار دهید.</p>
                </div>
            </details>
            <?php endif; ?>
            <div class="soft-panel text-xs space-y-2">
                <b>آدرس Webhook:</b>
                <code class="block dir-ltr text-left break-all"><?php echo clean(bot_admin_webhook_file($platform)); ?></code>
                <a class="btn btn-success w-full text-xs" href="<?php echo clean($page); ?>?action=set_webhook&amp;csrf_token=<?php echo urlencode(csrf_token()); ?>">⚡ ثبت خودکار Webhook</a>
            </div>
            <div class="soft-panel text-xs">
                <b>لینک/فرمان مخفی ورود مدیر در ربات</b>
                <p class="text-muted">این گزینه داخل ربات نمایش داده نمی‌شود. مدیر این فرمان را در چت ربات ارسال می‌کند:</p>
                <code class="block dir-ltr text-left break-all">/start admin_<?php echo clean($adminSecret); ?></code>
            </div>
            <div class="stats-mini">
                <span>کاربران متصل</span>
                <b><?php echo tr_num(count($connectedUsers), 'fa'); ?></b>
            </div>
        </section>

        <section class="card col-span-2 shadow-lg space-y-4">
            <h3 class="font-bold text-primary border-b pb-2">📢 ارسال پیام هدفمند</h3>
            <form method="POST" class="space-y-4" id="targetForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="send_targeted_message" value="1">
                <div class="grid grid-cols-4 gap-3">
                    <label class="target-card"><input type="radio" name="target_scope" value="all" checked> همه کاربران</label>
                    <label class="target-card"><input type="radio" name="target_scope" value="grade"> پایه تحصیلی</label>
                    <label class="target-card"><input type="radio" name="target_scope" value="class"> کلاس</label>
                    <label class="target-card"><input type="radio" name="target_scope" value="student"> دانش‌آموز</label>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <select name="target_value_grade" class="form-select target-value" data-scope="grade">
                        <?php foreach ($grades as $g): ?><option value="<?php echo clean($g['grade_level']); ?>"><?php echo clean($g['grade_level']); ?></option><?php endforeach; ?>
                    </select>
                    <select name="target_value_class" class="form-select target-value" data-scope="class">
                        <?php foreach ($classes as $c): ?><option value="<?php echo clean($c['class_name']); ?>"><?php echo clean($c['class_name']); ?></option><?php endforeach; ?>
                    </select>
                    <select name="target_value_student" class="form-select target-value" data-scope="student">
                        <?php foreach ($students as $s): ?><option value="<?php echo (int)$s['id']; ?>"><?php echo clean($s['first_name'] . ' ' . $s['last_name'] . ' - ' . $s['class_name'] . ' - ' . $s['national_id']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <!-- v4.48.0: messenger-style live composer/preview -->
                <div class="tgsim" dir="rtl">
                    <div class="tgsim-header">
                        <div class="tgsim-avatar" id="tgsimAvatar">👥</div>
                        <div class="tgsim-headtxt">
                            <b id="tgsimTitle">همه کاربران متصل</b>
                            <span id="tgsimSub">پیش‌نمایش زنده — دقیقاً همان چیزی که در ربات دیده می‌شود</span>
                        </div>
                        <span class="tgsim-online">online</span>
                    </div>
                    <div class="tgsim-chat" id="tgsimChat">
                        <div class="tgsim-day"><span>امروز</span></div>
                        <div class="tgsim-empty" id="tgsimEmpty">پیام خود را بنویسید یا فایلی پیوست کنید؛<br>پیش‌نمایش واقعی همین‌جا ظاهر می‌شود.</div>
                        <div class="tgsim-row" id="tgsimRow" style="display:none">
                            <div class="tgsim-bubble">
                                <div class="tgsim-media" id="tgsimMedia" style="display:none"><img id="tgsimImg" alt=""></div>
                                <div class="tgsim-media" id="tgsimVideoBox" style="display:none"><video id="tgsimVideo" controls preload="metadata" style="display:block;width:100%;max-height:300px;background:#000"></video></div>
                                <div class="tgsim-audio" id="tgsimAudioBox" style="display:none"><audio id="tgsimAudio" controls preload="metadata" style="width:250px;max-width:100%"></audio></div>
                                <div class="tgsim-doc" id="tgsimDoc" style="display:none">
                                    <span class="tgsim-doc-ic" id="tgsimDocIc">PDF</span>
                                    <span class="tgsim-doc-info">
                                        <b id="tgsimDocName">file.pdf</b>
                                        <i id="tgsimDocSize">0 KB</i>
                                    </span>
                                </div>
                                <div class="tgsim-text" id="tgsimText" style="display:none"></div>
                                <div class="tgsim-meta"><span id="tgsimTime">۰۰:۰۰</span><svg class="tgsim-checks" viewBox="0 0 24 12" width="20" height="11"><path d="M1 6.5 L4.5 10 L11 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 6.5 L11.5 10 L18 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                            </div>
                        </div>
                    </div>
                    <div class="tgsim-attachbar" id="tgsimAttachBar" style="display:none">
                        <span class="tgsim-chip">
                            <span class="tgsim-chip-ic" id="tgsimChipIc">📄</span>
                            <span class="tgsim-chip-name" id="tgsimChipName"></span>
                            <span class="tgsim-chip-size" id="tgsimChipSize"></span>
                            <button type="button" class="tgsim-chip-x" id="tgsimRemoveFile" title="حذف پیوست">&times;</button>
                        </span>
                    </div>
                    <div class="tgsim-composer">
                        <button type="button" class="tgsim-iconbtn" id="tgsimAttachBtn" title="پیوست فایل">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M21.44 11.05l-8.49 8.49a6 6 0 0 1-8.49-8.49l8.49-8.49a4 4 0 0 1 5.66 5.66l-8.49 8.48a2 2 0 0 1-2.83-2.83l7.78-7.78"/></svg>
                        </button>
                        <button type="button" class="tgsim-iconbtn" id="tgsimEmojiBtn" title="ایموجی">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8"/><circle cx="9" cy="9.6" r="0.6" fill="currentColor"/><circle cx="15" cy="9.6" r="0.6" fill="currentColor"/></svg>
                        </button>
                        <textarea name="target_message" id="tgsimInput" rows="1" class="tgsim-input" placeholder="پیام"></textarea>
                        <button type="submit" class="tgsim-sendbtn" title="ارسال به مخاطبان انتخاب‌شده">
                            <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" style="transform:scaleX(-1)"><path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/></svg>
                        </button>
                    </div>
                    <div class="tgsim-emojipanel" id="tgsimEmojiPanel" style="display:none">
                        <div class="tgsim-emoji-tabs" id="tgsimEmojiTabs"></div>
                        <div class="tgsim-emoji-grid" id="tgsimEmojiGrid"></div>
                    </div>
                    <input type="file" name="target_file" id="targetFile" style="display:none" accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt,.csv,.mp3,.ogg,.wav,.mp4">
                </div>
                <p class="text-muted text-xs">تصویر به صورت عکس با کپشن و بقیه فرمت‌ها به صورت فایل (سند) ارسال می‌شوند. حداکثر حجم: ۴۹ مگابایت — فرمت‌های مجاز: تصویر، PDF، Word، Excel، PowerPoint، ZIP/RAR، متن، صوت، ویدیو MP4.</p>
            </form>
        </section>
    </div>

    <section class="card shadow-lg space-y-5">
        <h3 class="font-bold text-primary border-b pb-2">✏️ شخصی‌سازی پیام‌های ثابت و دکمه‌های ربات</h3>
        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="save_templates" value="1">
            <div class="grid grid-cols-2 gap-4">
                <?php foreach ($messages as $key => $text): ?>
                    <div>
                        <label class="block text-xs font-semibold mb-1 dir-ltr text-left">message: <?php echo clean($key); ?></label>
                        <textarea name="msg_<?php echo clean($key); ?>" rows="3" class="form-textarea text-xs"><?php echo clean($text); ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="table-container">
                <table>
                    <thead><tr><th>کلید</th><th>متن دکمه</th><th>ردیف</th><th>ترتیب</th><th>فعال</th></tr></thead>
                    <tbody>
                    <?php foreach ($buttons as $key => $b): ?>
                        <tr>
                            <td class="font-mono dir-ltr"><?php echo clean($key); ?></td>
                            <td><input class="form-input" name="btn_text_<?php echo clean($key); ?>" value="<?php echo clean($b['button_text']); ?>"></td>
                            <td><input class="form-input dir-ltr text-left" type="number" min="1" name="btn_row_<?php echo clean($key); ?>" value="<?php echo (int)$b['row_no']; ?>"></td>
                            <td><input class="form-input dir-ltr text-left" type="number" min="1" name="btn_order_<?php echo clean($key); ?>" value="<?php echo (int)$b['sort_order']; ?>"></td>
                            <td><input type="checkbox" name="btn_active_<?php echo clean($key); ?>" <?php echo !empty($b['is_active']) ? 'checked' : ''; ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-success px-8">💾 ذخیره متن‌ها و دکمه‌ها</button>
        </form>
    </section>

    <section class="card shadow-lg">
        <h3 class="font-bold text-primary mb-3">👥 کاربران متصل به ربات <?php echo clean($title); ?></h3>
        <div class="table-container max-h-[420px]">
            <table>
                <thead><tr><th>#</th><th>Chat ID</th><th>Username</th><th>دانش‌آموز</th><th>کد ملی</th><th>پایه</th><th>کلاس</th><th>زمان اتصال</th></tr></thead>
                <tbody>
                <?php foreach ($connectedUsers as $u): ?>
                    <tr>
                        <td><?php echo (int)$u['id']; ?></td>
                        <td class="font-mono dir-ltr text-xs"><?php echo clean($u['chat_id']); ?></td>
                        <td class="font-mono dir-ltr text-xs"><?php echo clean($u['username'] ?: '---'); ?></td>
                        <td class="font-bold text-primary"><?php echo clean($u['first_name'] . ' ' . $u['last_name']); ?></td>
                        <td><?php echo tr_num($u['national_id'], 'fa'); ?></td>
                        <td><?php echo clean($u['grade_level']); ?></td>
                        <td><span class="badge badge-info"><?php echo clean($u['class_name']); ?></span></td>
                        <td class="text-xs text-muted"><?php echo clean($u['created_at']); ?></td>
                    </tr>
                <?php endforeach; if (!$connectedUsers): ?>
                    <tr><td colspan="8" class="text-center text-muted py-6">هنوز کاربری متصل نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<style>
/* ===== v4.48.0: messenger-style composer simulation ===== */
.tgsim{border:1px solid #d7dde5;border-radius:16px;overflow:hidden;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.06);font-family:inherit;}
.tgsim-header{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#fff;border-bottom:1px solid #e6eaf0;}
.tgsim-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#54a9eb,#2b7cd3);color:#fff;display:flex;align-items:center;justify-content:center;font-size:19px;flex:0 0 42px;}
.tgsim-headtxt{display:flex;flex-direction:column;line-height:1.35;min-width:0;flex:1;}
.tgsim-headtxt b{font-size:.9rem;color:#1c2733;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.tgsim-headtxt span{font-size:.7rem;color:#8a99a8;}
.tgsim-online{font-size:.7rem;color:#54a9eb;}
.tgsim-chat{min-height:270px;max-height:380px;overflow-y:auto;padding:16px 14px 10px;background:#8db3d4 url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="140" height="140" opacity="0.08"><circle cx="25" cy="25" r="9" fill="white"/><rect x="80" y="15" width="18" height="18" rx="4" fill="white" transform="rotate(20 89 24)"/><path d="M30 95 l12 -14 12 14z" fill="white"/><circle cx="105" cy="100" r="7" fill="none" stroke="white" stroke-width="3"/></svg>');background-size:140px 140px;}
.tgsim-day{text-align:center;margin-bottom:12px;}
.tgsim-day span{display:inline-block;background:rgba(0,0,0,.22);color:#fff;font-size:.68rem;padding:3px 12px;border-radius:999px;}
.tgsim-empty{text-align:center;color:rgba(255,255,255,.92);font-size:.78rem;background:rgba(0,0,0,.18);border-radius:12px;padding:14px;line-height:2;max-width:320px;margin:26px auto;}
.tgsim-row{display:flex;justify-content:flex-start;direction:ltr;}
.tgsim-bubble{position:relative;max-width:78%;min-width:110px;background:#effdde;border-radius:14px 14px 14px 3px;box-shadow:0 1px 1px rgba(0,0,0,.18);padding:5px;direction:rtl;animation:tgsimPop .18s ease-out;}
@keyframes tgsimPop{from{transform:scale(.92);opacity:.4}to{transform:scale(1);opacity:1}}
.tgsim-bubble::before{content:'';position:absolute;left:-7px;bottom:0;width:12px;height:14px;background:radial-gradient(circle at 0 0, transparent 12px, #effdde 13px);}
.tgsim-media{margin:-1px -1px 4px;border-radius:11px;overflow:hidden;max-width:330px;}
.tgsim-media img{display:block;width:100%;max-height:300px;object-fit:cover;}
.tgsim-audio{padding:6px 6px 2px;}
.tgsim-doc{display:flex;align-items:center;gap:10px;padding:8px 6px 6px;direction:rtl;}
.tgsim-doc-ic{width:46px;height:46px;flex:0 0 46px;border-radius:50%;background:#62ac55;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:800;letter-spacing:.4px;position:relative;}
.tgsim-doc-ic::after{content:'⬇';position:absolute;font-size:15px;opacity:0;}
.tgsim-doc-info{display:flex;flex-direction:column;min-width:0;line-height:1.5;}
.tgsim-doc-info b{font-size:.8rem;color:#1c2733;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;text-align:right;}
.tgsim-doc-info i{font-style:normal;font-size:.68rem;color:#62ac55;}
.tgsim-text{padding:3px 6px 2px;font-size:.84rem;color:#1c2733;line-height:1.9;white-space:pre-wrap;word-break:break-word;}
.tgsim-meta{display:flex;justify-content:flex-end;align-items:center;gap:3px;padding:0 6px 2px;color:#62ac55;}
.tgsim-meta span{font-size:.62rem;}
.tgsim-checks{color:#62ac55;}
.tgsim-media + .tgsim-meta.tgsim-onimg{position:absolute;bottom:8px;left:8px;background:rgba(0,0,0,.45);color:#fff;border-radius:999px;padding:1px 8px;}
.tgsim-media + .tgsim-meta.tgsim-onimg .tgsim-checks{color:#fff;}
.tgsim-attachbar{padding:8px 12px;background:#f6f8fa;border-top:1px solid #e6eaf0;}
.tgsim-chip{display:inline-flex;align-items:center;gap:8px;background:#fff;border:1px solid #d7dde5;border-radius:999px;padding:4px 6px 4px 12px;max-width:100%;}
.tgsim-chip-ic{font-size:15px;}
.tgsim-chip-name{font-size:.72rem;font-weight:700;color:#1c2733;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;direction:ltr;}
.tgsim-chip-size{font-size:.66rem;color:#8a99a8;}
.tgsim-chip-x{border:0;background:#eef1f5;color:#5b6b7c;width:22px;height:22px;border-radius:50%;cursor:pointer;font-size:15px;line-height:1;display:flex;align-items:center;justify-content:center;}
.tgsim-chip-x:hover{background:#e2e7ee;color:#dc2626;}
.tgsim-composer{display:flex;align-items:flex-end;gap:6px;padding:8px 10px;background:#fff;border-top:1px solid #e6eaf0;}
.tgsim-iconbtn{border:0;background:transparent;color:#8a99a8;cursor:pointer;padding:8px;border-radius:50%;display:flex;transition:color .15s;}
.tgsim-iconbtn:hover{color:#54a9eb;}
.tgsim-input{flex:1;border:0;outline:0;resize:none;font-family:inherit;font-size:.86rem;line-height:1.8;max-height:120px;padding:9px 4px;background:transparent;color:#1c2733;}
.tgsim-input:focus{box-shadow:none;outline:none;}
.tgsim-sendbtn{border:0;background:#54a9eb;color:#fff;cursor:pointer;width:40px;height:40px;flex:0 0 40px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background .15s,transform .1s;}
.tgsim-sendbtn:hover{background:#3d97e0;}
.tgsim-sendbtn:active{transform:scale(.92);}
/* v4.49.0: emoji picker */
.tgsim-iconbtn.tgsim-active{color:#54a9eb;background:#eaf4fd;}
.tgsim-emojipanel{background:#fff;border-top:1px solid #e6eaf0;animation:tgsimSlide .15s ease-out;}
@keyframes tgsimSlide{from{opacity:.4;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
.tgsim-emoji-tabs{display:flex;gap:2px;padding:6px 10px 0;border-bottom:1px solid #eef1f5;overflow-x:auto;scrollbar-width:none;}
.tgsim-emoji-tabs::-webkit-scrollbar{display:none;}
.tgsim-emoji-tab{border:0;background:transparent;font-size:17px;padding:6px 9px;cursor:pointer;border-radius:8px 8px 0 0;opacity:.55;border-bottom:2px solid transparent;transition:opacity .12s;}
.tgsim-emoji-tab:hover{opacity:.85;background:#f6f8fa;}
.tgsim-emoji-tab.tgsim-tab-on{opacity:1;border-bottom-color:#54a9eb;background:#f2f8fd;}
.tgsim-emoji-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(36px,1fr));gap:2px;max-height:186px;overflow-y:auto;padding:8px 10px;}
.tgsim-emoji-grid button{border:0;background:transparent;font-size:21px;line-height:1;padding:6px 0;cursor:pointer;border-radius:8px;transition:background .1s,transform .1s;font-family:'Apple Color Emoji','Segoe UI Emoji','Noto Color Emoji',sans-serif;}
.tgsim-emoji-grid button:hover{background:#eef4fa;transform:scale(1.18);}
</style>
<script>
(function(){
  const form = document.getElementById('targetForm');
  if (!form) return;

  /* ---- audience switch + simulator header title ---- */
  const titleEl = document.getElementById('tgsimTitle');
  const avatarEl = document.getElementById('tgsimAvatar');
  const scopeMeta = {
    all:     { icon: '👥', title: () => 'همه کاربران متصل' },
    grade:   { icon: '🎓', title: (v) => 'پایه ' + v },
    class:   { icon: '🏫', title: (v) => 'کلاس ' + v },
    student: { icon: '👤', title: (v) => v }
  };
  const refresh = () => {
    const scope = form.querySelector('input[name="target_scope"]:checked')?.value || 'all';
    let label = '';
    form.querySelectorAll('.target-value').forEach(el => {
      const on = el.dataset.scope === scope;
      el.style.display = on ? '' : 'none';
      if (on) {
        const opt = el.options[el.selectedIndex];
        label = opt ? opt.text : '';
        if (scope === 'student') label = label.split(' - ')[0];
      }
    });
    const m = scopeMeta[scope] || scopeMeta.all;
    if (avatarEl) avatarEl.textContent = m.icon;
    if (titleEl) titleEl.textContent = m.title(label) || 'مخاطبان';
  };
  form.querySelectorAll('input[name="target_scope"]').forEach(r => r.addEventListener('change', refresh));
  form.querySelectorAll('.target-value').forEach(el => el.addEventListener('change', refresh));
  refresh();

  /* ---- live messenger preview ---- */
  const BROADCAST_TPL = <?php echo json_encode($messages['broadcast_prefix'] ?? "📢 اطلاعیه آموزشگاه:\n\n{message}", JSON_UNESCAPED_UNICODE); ?>;
  const TPL_VARS = <?php echo json_encode([
      'school_name' => get_setting('school_name', 'آموزشگاه'),
      'school_phone' => get_setting('school_phone', '---'),
      'date' => function_exists('jdate') ? jdate('Y/m/d') : '',
      'student_name' => 'دانش‌آموز',
  ], JSON_UNESCAPED_UNICODE); ?>;
  const input = document.getElementById('tgsimInput');
  const fileInput = document.getElementById('targetFile');
  const chat = document.getElementById('tgsimChat');
  const emptyEl = document.getElementById('tgsimEmpty');
  const row = document.getElementById('tgsimRow');
  const mediaBox = document.getElementById('tgsimMedia');
  const imgEl = document.getElementById('tgsimImg');
  const docBox = document.getElementById('tgsimDoc');
  const docIc = document.getElementById('tgsimDocIc');
  const docName = document.getElementById('tgsimDocName');
  const docSize = document.getElementById('tgsimDocSize');
  const textEl = document.getElementById('tgsimText');
  const timeEl = document.getElementById('tgsimTime');
  const metaEl = row ? row.querySelector('.tgsim-meta') : null;
  const attachBar = document.getElementById('tgsimAttachBar');
  const chipIc = document.getElementById('tgsimChipIc');
  const chipName = document.getElementById('tgsimChipName');
  const chipSize = document.getElementById('tgsimChipSize');
  const MAX_BYTES = 49 * 1024 * 1024;
  const IMG_EXT = ['jpg','jpeg','png','webp','gif'];
  const AUDIO_EXT = ['mp3','ogg','wav'];
  const videoBox = document.getElementById('tgsimVideoBox');
  const videoEl = document.getElementById('tgsimVideo');
  const audioBox = document.getElementById('tgsimAudioBox');
  const audioEl = document.getElementById('tgsimAudio');
  const DOC_META = {
    pdf: ['PDF', '#e04f4f', '📕'], doc: ['DOC', '#2b7cd3', '📘'], docx: ['DOC', '#2b7cd3', '📘'],
    xls: ['XLS', '#1e7e46', '📗'], xlsx: ['XLS', '#1e7e46', '📗'], ppt: ['PPT', '#d35400', '📙'], pptx: ['PPT', '#d35400', '📙'],
    zip: ['ZIP', '#8e44ad', '🗜'], rar: ['RAR', '#8e44ad', '🗜'], txt: ['TXT', '#5b6b7c', '📄'], csv: ['CSV', '#1e7e46', '📄'],
    mp3: ['MP3', '#c0392b', '🎵'], ogg: ['OGG', '#c0392b', '🎵'], wav: ['WAV', '#c0392b', '🎵'], mp4: ['MP4', '#2c3e50', '🎬']
  };
  const faDigits = s => String(s).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  const fmtSize = b => faDigits(b >= 1024*1024 ? (b/(1024*1024)).toFixed(1) + ' MB' : Math.max(1, Math.round(b/1024)) + ' KB');
  const nowFa = () => { const d = new Date(); return faDigits(String(d.getHours()).padStart(2,'0') + ':' + String(d.getMinutes()).padStart(2,'0')); };
  const applyTpl = (msg) => {
    let t = BROADCAST_TPL.split('{message}').join(msg);
    Object.keys(TPL_VARS).forEach(k => { t = t.split('{' + k + '}').join(TPL_VARS[k]); });
    return t;
  };
  let currentURL = null;

  function render() {
    const msg = (input.value || '').trim();
    const f = fileInput.files && fileInput.files[0];
    if (timeEl) timeEl.textContent = nowFa();
    if (!msg && !f) {
      row.style.display = 'none';
      emptyEl.style.display = '';
      attachBar.style.display = 'none';
      return;
    }
    emptyEl.style.display = 'none';
    row.style.display = '';
    /* text: exactly as delivered, with broadcast template applied */
    if (msg) { textEl.style.display = ''; textEl.textContent = applyTpl(msg); }
    else { textEl.style.display = 'none'; textEl.textContent = ''; }
    /* attachment */
    mediaBox.style.display = 'none';
    docBox.style.display = 'none';
    if (videoBox) videoBox.style.display = 'none';
    if (audioBox) audioBox.style.display = 'none';
    if (metaEl) metaEl.classList.remove('tgsim-onimg');
    if (currentURL) { URL.revokeObjectURL(currentURL); currentURL = null; }
    if (f) {
      const ext = (f.name.split('.').pop() || '').toLowerCase();
      attachBar.style.display = '';
      chipName.textContent = f.name;
      chipSize.textContent = fmtSize(f.size);
      if (IMG_EXT.includes(ext)) {
        chipIc.textContent = '🖼';
        currentURL = URL.createObjectURL(f);
        imgEl.src = currentURL;
        mediaBox.style.display = '';
        if (!msg && metaEl) metaEl.classList.add('tgsim-onimg');
      } else if (ext === 'mp4' && videoBox && videoEl) {
        /* v4.50.0: playable video preview, exactly like the messenger */
        chipIc.textContent = '🎬';
        currentURL = URL.createObjectURL(f);
        videoEl.src = currentURL;
        videoBox.style.display = '';
      } else if (AUDIO_EXT.includes(ext) && audioBox && audioEl) {
        /* v4.50.0: playable audio preview */
        chipIc.textContent = '🎵';
        currentURL = URL.createObjectURL(f);
        audioEl.src = currentURL;
        audioBox.style.display = '';
      } else {
        const dm = DOC_META[ext] || ['FILE', '#5b6b7c', '📄'];
        chipIc.textContent = dm[2];
        docIc.textContent = dm[0];
        docIc.style.background = dm[1];
        docName.textContent = f.name;
        docSize.textContent = fmtSize(f.size);
        docBox.style.display = '';
      }
    } else {
      attachBar.style.display = 'none';
    }
    chat.scrollTop = chat.scrollHeight;
  }

  /* composer behaviour */
  if (input) {
    const autosize = () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; };
    input.addEventListener('input', () => { autosize(); render(); });
    autosize();
  }
  const attachBtn = document.getElementById('tgsimAttachBtn');
  if (attachBtn) attachBtn.addEventListener('click', () => fileInput.click());

  /* ---- v4.49.0: emoji picker (Telegram-style, categorized) ---- */
  const EMOJI_CATS = [
    ['😀', 'شکلک‌ها', ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','😍','😘','🥰','😗','🙂','🤗','🤩','🤔','🫡','😐','😑','😶','🙄','😏','😣','😥','😮','🤐','😯','😴','😫','🥱','😌','😛','😜','😝','🤤','😒','😓','😔','😕','🙃','🫠','😲','☹️','🙁','😖','😞','😟','😤','😢','😭','😦','😧','😨','😩','🤯','😬','😰','😱','🥵','🥶','😳','🤪','😵','🥴','😠','😡','🤬','😷','🤒','🤕','🤢','🤮','🤧','😇','🥳','🥺','🤠','🤡','🤥','🤫','🤭','🧐','🤓','😈','👻','💀','👽','🤖','💩','😺','😸','😹','😻','😼','😽']],
    ['👋', 'دست‌ها و افراد', ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','💪','🦾','✍️','💅','🤳','👶','🧒','👦','👧','🧑','👨','👩','🧑‍🎓','👨‍🎓','👩‍🎓','🧑‍🏫','👨‍🏫','👩‍🏫','👨‍👩‍👧','👨‍👩‍👦','🗣','👥','🫂']],
    ['📚', 'مدرسه و آموزش', ['📚','📖','📕','📗','📘','📙','📓','📒','📃','📄','📑','📊','📈','📉','🗒','📝','✏️','✒️','🖊','🖍','📏','📐','✂️','🖇','📎','📌','📍','🗂','📁','📂','🗄','📋','🧮','🔬','🔭','🧪','🧫','🧬','🌡','🏫','🎒','🎓','🏆','🥇','🥈','🥉','🏅','🎖','⏰','⏱','⏲','🕰','📅','📆','🗓','🔔','🔕','📢','📣','💡','🔍','🔎','🧠','⚗️','🖥','💻','🖨','⌨️','🖱','💾','💿','📱','☎️','📞']],
    ['❤️', 'قلب‌ها و نمادها', ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉','⭐️','🌟','✨','⚡️','💥','🔥','🌈','☀️','🌤','⛅️','🌧','⛈','❄️','☃️','💧','💦','🌊','✅','☑️','✔️','❌','❎','➕','➖','➗','✖️','💯','🔞','⚠️','🚸','🔱','⚜️','🔰','♻️','💠','🌀','➡️','⬅️','⬆️','⬇️','↗️','↘️','🔄','🔃','🔝','🔚','🔙','🆕','🆗','🆙','🆒','🆓','🆖','🈵','🚫','❓','❔','❗️','‼️','⁉️','💤']],
    ['🎉', 'جشن و فعالیت', ['🎉','🎊','🎈','🎂','🍰','🧁','🎁','🎗','🎟','🎫','🎪','🎭','🎨','🎬','🎤','🎧','🎼','🎵','🎶','🥁','🎹','🎸','🎺','🎻','🏀','⚽️','🏈','⚾️','🎾','🏐','🏓','🏸','🥅','⛳️','🏹','🎣','🥊','🥋','⛸','🎿','🏂','🏋️','🤸','🤾','⛹️','🏊','🚴','🚵','🏇','🧘','🎮','🕹','🎲','♟','🧩','🪁','🛝']],
    ['🍎', 'خوراکی و طبیعت', ['🍎','🍏','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🥦','🥕','🌽','🍞','🥖','🧀','🥚','🍳','🥞','🧇','🍗','🍔','🍟','🍕','🌭','🥪','🌮','🍝','🍜','🍲','🍛','🍣','🍙','🍚','🍦','🍩','🍪','🍫','🍬','🍭','☕️','🍵','🥤','🧃','🌹','🌺','🌸','🌼','🌻','🌷','🪷','🌱','🌲','🌳','🌴','🌵','🍀','🍁','🍂','🍃','🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🦁','🐮','🐷','🐸','🐵','🐔','🐧','🐦','🦅','🦋','🐝','🐞','🐢','🐬','🐳']],
    ['🚗', 'اشیا و مکان‌ها', ['🚗','🚕','🚙','🚌','🚎','🏎','🚓','🚑','🚒','🚐','🚚','🚛','🚜','🛵','🏍','🚲','🛴','🚨','🚔','🚖','🚡','🚠','🚟','🚃','🚋','🚝','🚄','🚅','🚈','🚂','✈️','🛫','🛬','🚀','🛸','🚁','⛵️','🚤','🛳','⚓️','⛽️','🚏','🗺','🗿','🗽','🗼','🏰','🏯','🏟','🎡','🎢','🎠','⛲️','🏖','🏝','🏔','⛰','🌋','🗻','🏕','⛺️','🏠','🏡','🏢','🏣','🏥','🏦','🏨','🏪','🏬','🏭','💒','⛪️','🕌','🕍','🛕','⌚️','🔋','🔌','🕯','🪔','🧯','🛢','💸','💵','💰','💳','💎','⚖️','🧰','🔧','🔨','⚙️','🧲','🔫','💣','🔪','🛡','🚬','⚰️','🔮','📿','💈','⚗️','🔑','🗝','🚪','🪑','🛏','🛋','🚽','🚿','🛁','🧴','🧷','🧹','🧺','🧻','🧼','🪥','🧽','🛒']]
  ];
  const emojiBtn = document.getElementById('tgsimEmojiBtn');
  const emojiPanel = document.getElementById('tgsimEmojiPanel');
  const emojiTabs = document.getElementById('tgsimEmojiTabs');
  const emojiGrid = document.getElementById('tgsimEmojiGrid');
  let emojiBuilt = false, emojiCat = 0;
  function buildEmojiTabs() {
    EMOJI_CATS.forEach((cat, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'tgsim-emoji-tab' + (i === emojiCat ? ' tgsim-tab-on' : '');
      b.textContent = cat[0];
      b.title = cat[1];
      b.addEventListener('click', () => { emojiCat = i; renderEmojiGrid(); syncTabs(); });
      emojiTabs.appendChild(b);
    });
  }
  function syncTabs() {
    emojiTabs.querySelectorAll('.tgsim-emoji-tab').forEach((b, i) => b.classList.toggle('tgsim-tab-on', i === emojiCat));
  }
  function renderEmojiGrid() {
    emojiGrid.innerHTML = '';
    EMOJI_CATS[emojiCat][2].forEach(em => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = em;
      b.addEventListener('click', () => insertEmoji(em));
      emojiGrid.appendChild(b);
    });
    emojiGrid.scrollTop = 0;
  }
  function insertEmoji(em) {
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    input.value = input.value.slice(0, start) + em + input.value.slice(end);
    const pos = start + em.length;
    input.focus();
    input.setSelectionRange(pos, pos);
    input.dispatchEvent(new Event('input'));
  }
  if (emojiBtn && emojiPanel) {
    emojiBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = emojiPanel.style.display !== 'none';
      emojiPanel.style.display = open ? 'none' : '';
      emojiBtn.classList.toggle('tgsim-active', !open);
      if (!open && !emojiBuilt) { buildEmojiTabs(); renderEmojiGrid(); emojiBuilt = true; }
    });
    emojiPanel.addEventListener('click', e => e.stopPropagation());
    document.addEventListener('click', (e) => {
      if (emojiPanel.style.display === 'none') return;
      if (!emojiPanel.contains(e.target) && e.target !== emojiBtn && !emojiBtn.contains(e.target)) {
        emojiPanel.style.display = 'none';
        emojiBtn.classList.remove('tgsim-active');
      }
    });
  }
  fileInput.addEventListener('change', () => {
    const f = fileInput.files && fileInput.files[0];
    if (f && f.size > MAX_BYTES) {
      alert('حجم فایل (' + (f.size/(1024*1024)).toFixed(1) + ' مگابایت) بیشتر از سقف مجاز ۴۹ مگابایت است.');
      fileInput.value = '';
    }
    render();
  });
  const removeBtn = document.getElementById('tgsimRemoveFile');
  if (removeBtn) removeBtn.addEventListener('click', () => { fileInput.value = ''; render(); });
  render();

  /* submit guard: message OR file required */
  form.addEventListener('submit', (e) => {
    const msg = (input.value || '').trim();
    const hasFile = !!(fileInput.files && fileInput.files.length);
    if (!msg && !hasFile) {
      e.preventDefault();
      alert('متن پیام یا فایل پیوست الزامی است.');
      return;
    }
    if (!confirm('پیام برای «' + (titleEl ? titleEl.textContent : 'مخاطبان انتخاب‌شده') + '» ارسال شود؟')) {
      e.preventDefault();
    }
  });
})();
</script>
        <?php
    }
}
