<?php
/**
 * Professional SMS Management Panel (sms-panel.php)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/header.php';
$defaults = require __DIR__ . '/config/defaults.php';

require_permission('send_sms');

if (!function_exists('send_sms_via_provider')) {
    function send_sms_via_provider($provider, $recipient, $message, $sender) {
        $provider = strtolower((string)$provider);
        if ($provider === 'melipayamak') {
            $credential = get_setting('sms_api_key', '');
            $username = get_setting('sms_username', '');
            $password = get_setting('sms_password', '');
            // Backward compatible: admin may enter username:password in API Key field.
            if (($username === '' || $password === '') && strpos($credential, ':') !== false) {
                [$username, $password] = array_pad(explode(':', $credential, 2), 2, '');
            } elseif ($username === '') {
                $username = $credential;
            }
            if ($username === '' || $password === '') {
                throw new RuntimeException('برای ملی پیامک، نام کاربری و رمز باید در تنظیمات ثبت شود. می‌توانید در فیلد API Key مقدار username:password وارد کنید.');
            }
            if (!class_exists('SoapClient')) {
                throw new RuntimeException('افزونه SOAP روی سرور فعال نیست و برای ملی پیامک لازم است.');
            }
            @ini_set('soap.wsdl_cache_enabled', '0');
            $client = new SoapClient('http://api.payamak-panel.com/post/send.asmx?wsdl', ['encoding' => 'UTF-8', 'connection_timeout' => 15]);
            $params = [
                'username' => $username,
                'password' => $password,
                'to' => preg_replace('/^0/', '', tr_num($recipient, 'en')),
                'from' => $sender,
                'text' => $message,
                'isflash' => false,
            ];
            $res = $client->SendSimpleSMS2($params)->SendSimpleSMS2Result ?? null;
            return ['status' => ($res !== null && (string)$res !== '') ? 'sent' : 'failed', 'response' => (string)$res];
        }
        // Default/mock provider: keep previous behavior for local testing.
        return ['status' => 'sent', 'response' => 'SUCCESS (Mock/API)'];
    }
}

// Handle Send SMS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_sms'])) {
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        set_flash_message('error', 'خطای امنیتی CSRF');
        redirect('sms-panel.php');
    }

    $targetType = $_POST['target_type'] ?? 'single';
    $messageBody = trim($_POST['message'] ?? '');
    $provider = get_setting('sms_provider', 'kavenegar');
    $sender   = get_setting('sms_sender_number', '10008888');

    if (empty($messageBody)) {
        set_flash_message('error', 'متن پیامک نمی‌تواند خالی باشد.');
        redirect('sms-panel.php');
    }

    $recipients = [];
    $targetStudents = [];

    if ($targetType === 'single') {
        $mobile = tr_num(trim($_POST['single_mobile'] ?? ''), 'en');
        if (!empty($mobile)) {
            $recipients[] = ['phone' => $mobile, 'student' => []];
        }
    } else {
        $whereSql = "status='active'";
        $params = [];
        if (in_array($targetType, ['class', 'class_fathers', 'class_mothers'])) {
            $cls = trim($_POST['class_name'] ?? '');
            $whereSql .= " AND class_name = ?";
            $params[] = $cls;
        }

        $targetStudents = DB::fetchAll("SELECT s.*, (SELECT gpa FROM reports WHERE student_id=s.id ORDER BY id DESC LIMIT 1) as latest_gpa, (SELECT report_month FROM reports WHERE student_id=s.id ORDER BY id DESC LIMIT 1) as latest_month FROM students s WHERE $whereSql", $params);

        foreach ($targetStudents as $st) {
            $phone = '';
            if (in_array($targetType, ['class_fathers', 'all_fathers'])) $phone = tr_num($st['father_phone'] ?? '', 'en');
            elseif (in_array($targetType, ['class_mothers', 'all_mothers'])) $phone = tr_num($st['mother_phone'] ?? '', 'en');
            else $phone = tr_num($st['phone'] ?? ($st['father_phone'] ?? ''), 'en');

            if (!empty($phone)) {
                $recipients[] = ['phone' => $phone, 'student' => $st];
            }
        }
    }

    $sentCount = 0;
    foreach ($recipients as $item) {
        $rec = $item['phone'];
        $stData = $item['student'];
        if (preg_match('/^09\d{9}$/', $rec)) {
            $finalMsg = render_sms_template($messageBody, $stData);
            try {
                $sendRes = send_sms_via_provider($provider, $rec, $finalMsg, $sender);
                DB::execute("INSERT INTO sms_logs (provider, sender, recipient, message, status, response, sent_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$provider, $sender, $rec, $finalMsg, $sendRes['status'], $sendRes['response']]);
                if ($sendRes['status'] === 'sent') $sentCount++;
            } catch (Exception $e) {
                DB::execute("INSERT INTO sms_logs (provider, sender, recipient, message, status, response, sent_at) VALUES (?, ?, ?, ?, 'failed', ?, NOW())",
                [$provider, $sender, $rec, $finalMsg, $e->getMessage()]);
            }
        }
    }

    log_activity($_SESSION['admin_id'], 'ارسال پیامک گروهی/تکی', "تعداد $sentCount پیامک از طریق سرویس‌دهنده $provider ارسال شد.");
    set_flash_message('success', "تعداد $sentCount پیامک با موفقیت در صف ارسال قرار گرفت و ثبت شد.");
    redirect('sms-panel.php');
}

$classes = DB::fetchAll("SELECT DISTINCT class_name FROM students WHERE class_name IS NOT NULL");
$smsLogs = DB::fetchAll("SELECT * FROM sms_logs ORDER BY id DESC LIMIT 50");
$currentProvider = get_setting('sms_provider', 'kavenegar');
$providersList   = $defaults['sms_providers'] ?? ['kavenegar' => 'کاوه نگار', 'mock' => 'شبیه‌ساز محلی'];
?>
<div class="space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">سامانه پیامکی حرفه‌ای مدرسه</h2>
            <p class="text-sm text-muted">ارسال پیامک‌های تکی، کلاسی و کمپینی به اولیا و دانش‌آموزان</p>
        </div>
        <a href="settings.php" class="btn btn-outline text-xs gap-1">
            <span>⚙️ تنظیمات وب‌سرویس پیامک (API Key)</span>
        </a>
    </div>

    <div class="grid grid-cols-3 gap-6">
        <!-- SMS Sender Form -->
        <div class="card col-span-1">
            <h3 class="font-bold mb-4 text-primary">ارسال پیامک جدید</h3>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="send_sms" value="1">

                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">سرویس‌دهنده فعال</label>
                    <input type="text" class="form-input bg-gray-50 text-muted" value="<?php echo clean($providersList[$currentProvider] ?? $currentProvider); ?>" disabled>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">گیرندگان پیامک</label>
                    <select name="target_type" class="form-select font-bold" id="smsTargetType" onchange="toggleSmsTarget()">
                        <option value="single">ارسال تکی (شماره موبایل دلخواه)</option>
                        <option value="class">ارسال به یک کلاس خاص (موبایل اصلی دانش‌آموز)</option>
                        <option value="class_fathers">ارسال به موبایل پدران یک کلاس خاص 👨</option>
                        <option value="class_mothers">ارسال به موبایل مادران یک کلاس خاص 👩</option>
                        <option value="all">ارسال کمپینی به تمامی دانش‌آموزان مدرسه</option>
                        <option value="all_fathers">ارسال کمپینی به تمامی پدران مدرسه 👨</option>
                        <option value="all_mothers">ارسال کمپینی به تمامی مادران مدرسه 👩</option>
                    </select>
                </div>

                <div id="singleTargetBox" class="mb-4">
                    <label class="block text-xs font-semibold mb-1">شماره موبایل گیرنده (۰۹۱۲...)</label>
                    <input type="text" name="single_mobile" class="form-input dir-ltr text-left" placeholder="09120000000">
                </div>

                <div id="classTargetBox" class="mb-4 hidden" style="display:none;">
                    <label class="block text-xs font-semibold mb-1">انتخاب کلاس</label>
                    <select name="class_name" class="form-select">
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?php echo clean($cls['class_name']); ?>"><?php echo clean($cls['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-xs font-semibold mb-1">متن پیامک (پشتیبانی از شورت‌کدها)</label>
                    <textarea name="message" id="smsMessageArea" rows="4" class="form-textarea text-xs" required>ولی محترم {father_name}، کارنامه نوبت {report_month} دانش‌آموز {student_name} با معدل {gpa} در پنل سایت قرار گرفت.</textarea>
                </div>

                <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg border text-[11px] mb-6 space-y-2">
                    <span class="font-bold text-primary block">🏷️ شورت‌کدهای هوشمند (برای درج در پیام کلیک کنید):</span>
                    <div class="flex flex-wrap gap-1.5 font-mono">
                        <button type="button" onclick="insertShortcode('{student_name}')" class="badge bg-blue-100 text-blue-800 border hover:bg-blue-200 cursor-pointer">{student_name}</button>
                        <button type="button" onclick="insertShortcode('{student_code}')" class="badge bg-blue-100 text-blue-800 border hover:bg-blue-200 cursor-pointer">{student_code}</button>
                        <button type="button" onclick="insertShortcode('{class_name}')" class="badge bg-green-100 text-green-800 border hover:bg-green-200 cursor-pointer">{class_name}</button>
                        <button type="button" onclick="insertShortcode('{father_name}')" class="badge bg-amber-100 text-amber-800 border hover:bg-amber-200 cursor-pointer">{father_name}</button>
                        <button type="button" onclick="insertShortcode('{gpa}')" class="badge bg-purple-100 text-purple-800 border hover:bg-purple-200 cursor-pointer">{gpa}</button>
                        <button type="button" onclick="insertShortcode('{report_month}')" class="badge bg-purple-100 text-purple-800 border hover:bg-purple-200 cursor-pointer">{report_month}</button>
                        <button type="button" onclick="insertShortcode('{date}')" class="badge bg-gray-200 text-gray-800 border hover:bg-gray-300 cursor-pointer">{date}</button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-full py-2.5">📨 ارسال پیامک &larr;</button>
            </form>
        </div>

        <!-- SMS Logs Table -->
        <div class="card col-span-2">
            <h3 class="font-bold mb-4">تاریخچه و لاگ پیامک‌های ارسالی</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>گیرنده</th>
                            <th>متن پیام</th>
                            <th>سرویس‌دهنده</th>
                            <th>وضعیت</th>
                            <th>زمان ارسال</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($smsLogs as $l): ?>
                        <tr>
                            <td class="font-mono text-xs">#<?php echo $l['id']; ?></td>
                            <td class="font-mono dir-ltr text-xs"><?php echo clean($l['recipient']); ?></td>
                            <td class="text-xs max-w-xs truncate"><?php echo clean($l['message']); ?></td>
                            <td><span class="badge badge-info"><?php echo clean($l['provider']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo clean($l['status']); ?></span></td>
                            <td class="text-xs whitespace-nowrap"><?php echo jdate('Y/m/d H:i', strtotime($l['sent_at'])); ?></td>
                        </tr>
                        <?php endforeach; if (empty($smsLogs)): ?>
                        <tr><td colspan="6" class="text-center text-muted">هیچ پیامکی ثبت نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
function toggleSmsTarget() {
    const val = document.getElementById('smsTargetType').value;
    document.getElementById('singleTargetBox').style.display = val === 'single' ? 'block' : 'none';
    const isCls = (val === 'class' || val === 'class_fathers' || val === 'class_mothers');
    document.getElementById('classTargetBox').style.display = isCls ? 'block' : 'none';
}
function insertShortcode(code) {
    const area = document.getElementById('smsMessageArea');
    if (!area) return;
    area.setRangeText(code, area.selectionStart, area.selectionEnd, 'end');
    area.focus();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
