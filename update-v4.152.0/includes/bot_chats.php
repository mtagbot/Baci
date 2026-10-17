<?php
// File: includes/bot_chats.php  (v4.184.0)
/**
 * «گفتگوهای ربات» — فهرست گروه‌ها، کانال‌ها و گفتگوهای خصوصیِ ربات/بازو.
 *
 * چرا این ماژول لازم است: هیچ‌کدام از APIهای بله، تلگرام و سروش پلاس متدی برای «فهرست
 * همهٔ گفتگوهایی که ربات در آن‌ها عضو است» ندارند. تنها راه، ساختنِ فهرست از
 * همان چیزی است که ربات واقعاً می‌بیند:
 *
 *   • هر پیام/دکمه‌ای که به ربات می‌رسد ⇒ شناسه، نوع (group/channel/private) و
 *     عنوانِ گفتگو همان‌جا ثبت می‌شود (bot_chats_touch).
 *   • پیام سرویسیِ new_chat_members / left_chat_member ⇒ همان لحظه می‌فهمیم
 *     ربات به گروه اضافه یا از آن حذف شده است.
 *   • تلگرام آپدیت my_chat_member هم می‌فرستد (bot_chats_touch_my_member).
 *   • getChat روی شناسه‌های شناخته‌شده ⇒ تازه‌کردن عنوان و سنجش عضویت
 *     (bot_chats_refresh) — برای گفتگوهایی که پیش از نصب این نسخه دیده شده‌اند.
 *
 * خروج از گروه/کانال با leaveChat انجام می‌شود (هر دو پلتفرم این متد را دارند).
 * توابع شبکه «تزریق‌پذیر» هستند ($transport) تا بدون اینترنت هم قابل تست باشند.
 *
 * اصول:
 *   • هیچ تابعی استثنا به بیرون نمی‌دهد؛ همه‌چیز try/catch و لاگ می‌شود.
 *   • ردیف‌ها هرگز حذف نمی‌شوند؛ گفتگویی که ربات از آن خارج شده با
 *     status='left' می‌ماند تا سابقه بماند.
 *   • کلید/توکن هرگز در خروجی عمومی این ماژول نمی‌آید.
 */

if (!function_exists('bot_chats_is_mysql')) {
    /** درایور جاری — برای تفاوت‌های نحوی MySQL و SQLite. */
    function bot_chats_is_mysql() {
        if (function_exists('bot_inbox_is_mysql')) return bot_inbox_is_mysql();
        static $m = null;
        if ($m !== null) return $m;
        $m = false;
        try {
            $m = (DB::getInstance()->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql');
        } catch (Throwable $e) { $m = false; }
        return $m;
    }
}

if (!function_exists('bot_chats_schema')) {
    /** جدول گفتگوها — idempotent و سازگار با MySQL و SQLite. */
    function bot_chats_schema() {
        static $done = false;
        if ($done) return true;
        try {
            if (bot_chats_is_mysql()) {
                DB::execute(
                    "CREATE TABLE IF NOT EXISTS bot_chats (
                        id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        platform VARCHAR(20) NOT NULL,
                        chat_id VARCHAR(64) NOT NULL,
                        chat_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
                        title VARCHAR(255) DEFAULT NULL,
                        username VARCHAR(190) DEFAULT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'member',
                        added_at DATETIME NULL,
                        first_seen DATETIME NULL,
                        last_seen DATETIME NULL,
                        last_message_at DATETIME NULL,
                        msg_count INT NOT NULL DEFAULT 0,
                        checked_at DATETIME NULL,
                        left_at DATETIME NULL,
                        left_by VARCHAR(190) DEFAULT NULL,
                        last_error VARCHAR(255) DEFAULT NULL,
                        UNIQUE KEY uniq_bot_chat (platform, chat_id),
                        KEY bot_chats_type (platform, chat_type, status)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            } else {
                DB::execute(
                    "CREATE TABLE IF NOT EXISTS bot_chats (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        platform VARCHAR(20) NOT NULL,
                        chat_id VARCHAR(64) NOT NULL,
                        chat_type VARCHAR(20) NOT NULL DEFAULT 'unknown',
                        title VARCHAR(255) DEFAULT NULL,
                        username VARCHAR(190) DEFAULT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'member',
                        added_at TEXT,
                        first_seen TEXT,
                        last_seen TEXT,
                        last_message_at TEXT,
                        msg_count INTEGER NOT NULL DEFAULT 0,
                        checked_at TEXT,
                        left_at TEXT,
                        left_by VARCHAR(190) DEFAULT NULL,
                        last_error VARCHAR(255) DEFAULT NULL
                    )"
                );
                try { DB::execute('CREATE UNIQUE INDEX IF NOT EXISTS "uniq_bot_chat" ON bot_chats (platform, chat_id)'); } catch (Throwable $e) {}
                try { DB::execute('CREATE INDEX IF NOT EXISTS "bot_chats_type" ON bot_chats (platform, chat_type, status)'); } catch (Throwable $e) {}
            }
        } catch (Throwable $e) {
            error_log('bot_chats_schema: ' . $e->getMessage());
            return false;
        }
        $done = true;
        return true;
    }
}

if (!function_exists('bot_chats_norm_type')) {
    /** نوع گفتگو را به یکی از private/group/channel/unknown تبدیل می‌کند. */
    function bot_chats_norm_type($type) {
        $type = strtolower(trim((string)$type));
        if ($type === 'supergroup') return 'group';
        return in_array($type, ['private', 'group', 'channel'], true) ? $type : 'unknown';
    }
}

if (!function_exists('bot_chats_type_label')) {
    function bot_chats_type_label($type) {
        return ['private' => 'گفتگوی خصوصی', 'group' => 'گروه', 'channel' => 'کانال'][$type] ?? 'نامشخص';
    }
}

if (!function_exists('bot_chats_status_label')) {
    function bot_chats_status_label($status) {
        return ['member' => 'عضو', 'left' => 'خارج‌شده', 'unknown' => 'نامشخص'][$status] ?? 'نامشخص';
    }
}

if (!function_exists('bot_chats_capabilities')) {
    /**
     * v4.185.0: هر پیام‌رسان کدام متدهای API ربات را دارد.
     * بر پایهٔ مستندات رسمی سروش پلاس (soroushplus.com/p/documents): getChat
     * و leaveChat هنوز در فهرست متدها نیستند («getChat — به‌زودی»)، پس برای
     * سروش پلاس فهرست فقط از پیام‌های دریافتی ساخته می‌شود و دکمه‌های
     * خروج/افزودن دستی/بروزرسانی وضعیت نمایش داده نمی‌شوند.
     */
    function bot_chats_capabilities($platform) {
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        return [
            'get_chat' => $platform !== 'soroush',
            'leave'    => $platform !== 'soroush',
        ];
    }
}

if (!function_exists('bot_chats_save')) {
    /**
     * درج یا به‌روزرسانی یک گفتگو با فیلدهای مجاز.
     * هیچ کلید یا مقدار غیرمجازی وارد SQL نمی‌شود (فهرست سفید ستون‌ها).
     */
    function bot_chats_save($platform, $chatId, array $fields) {
        if (!bot_chats_schema()) return false;
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $chatId = (string)$chatId;
        if ($chatId === '' || strlen($chatId) > 64) return false;
        $allowed = ['chat_type', 'title', 'username', 'status', 'added_at', 'first_seen',
                    'last_seen', 'last_message_at', 'msg_count', 'checked_at', 'left_at',
                    'left_by', 'last_error'];
        $data = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) $data[$k] = $v;
        }
        if (!$data) return false;
        try {
            $row = DB::fetch("SELECT id FROM bot_chats WHERE platform = ? AND chat_id = ?", [$platform, $chatId]);
            if ($row) {
                $set = []; $params = [];
                foreach ($data as $k => $v) { $set[] = $k . ' = ?'; $params[] = $v; }
                $params[] = (int)$row['id'];
                return DB::execute("UPDATE bot_chats SET " . implode(', ', $set) . " WHERE id = ?", $params);
            }
            $data['platform'] = $platform;
            $data['chat_id'] = $chatId;
            if (empty($data['first_seen'])) $data['first_seen'] = date('Y-m-d H:i:s');
            $cols = array_keys($data);
            $ph = array_fill(0, count($cols), '?');
            return DB::execute("INSERT INTO bot_chats (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $ph) . ")", array_values($data));
        } catch (Throwable $e) {
            error_log('bot_chats_save: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bot_chats_identity')) {
    /**
     * هویت خودِ ربات (getMe) با کش در settings.
     * لازم است تا در new_chat_members بفهمیم «خودِ ربات» اضافه شده یا یک کاربر.
     * اگر توکن عوض شود، کش خودکار باطل می‌شود.
     */
    function bot_chats_identity($platform, $transport = null) {
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $empty = ['id' => '', 'username' => ''];
        try {
            if (!function_exists('get_setting') || !function_exists('set_setting')) return $empty;
            $token = (string)get_setting(bot_token_key($platform), '');
            if ($token === '') return $empty;
            $hash = substr(sha1($token), 0, 12);
            $raw = (string)get_setting($platform . '_bot_identity', '');
            if ($raw !== '') {
                $cache = json_decode($raw, true);
                if (is_array($cache) && (string)($cache['token'] ?? '') === $hash) {
                    return ['id' => (string)($cache['id'] ?? ''), 'username' => (string)($cache['username'] ?? '')];
                }
            }
            $res = $transport ? $transport('getMe', []) : bot_api_request($platform, 'getMe', []);
            $u = is_array($res) ? ($res['result'] ?? null) : null;
            if (!is_array($u)) return $empty;
            $out = ['id' => (string)($u['id'] ?? ''), 'username' => (string)($u['username'] ?? '')];
            set_setting($platform . '_bot_identity', json_encode($out + ['token' => $hash], JSON_UNESCAPED_UNICODE));
            return $out;
        } catch (Throwable $e) {
            return $empty;
        }
    }
}

if (!function_exists('bot_chats_is_this_bot')) {
    /** آیا این عضو، خودِ ربات است؟ */
    function bot_chats_is_this_bot(array $member, array $identity) {
        if (empty($member['is_bot'])) return false;
        $id = (string)($identity['id'] ?? '');
        $un = (string)($identity['username'] ?? '');
        if ($id !== '' && (string)($member['id'] ?? '') === $id) return true;
        if ($un !== '' && isset($member['username']) && strcasecmp((string)$member['username'], $un) === 0) return true;
        return false;
    }
}

if (!function_exists('bot_chats_touch')) {
    /**
     * ثبت/به‌روزرسانی یک گفتگو از روی یک پیام یا دکمهٔ دریافتی.
     * هرگز استثنا نمی‌دهد (مسیر داغِ وبهوک).
     *
     * @param array      $chat      شیء chat آپدیت
     * @param array      $from      فرستنده (برای گفتگوی خصوصی که chat نام ندارد)
     * @param array      $message   خود پیام (new_chat_members / left_chat_member)
     * @param array|null $identity  هویت ربات؛ null = در صورت نیاز از getMe گرفته می‌شود
     */
    function bot_chats_touch($platform, $chat, $from = [], $message = [], $identity = null) {
        try {
            if (!is_array($chat)) return false;
            $chatId = (string)($chat['id'] ?? '');
            if ($chatId === '') return false;
            $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
            $type = bot_chats_norm_type($chat['type'] ?? '');
            $title = (string)($chat['title'] ?? '');
            if ($title === '' && $type === 'private') {
                $title = trim((string)($chat['first_name'] ?? ($from['first_name'] ?? '')) . ' ' . (string)($chat['last_name'] ?? ($from['last_name'] ?? '')));
            }
            $username = (string)($chat['username'] ?? ($from['username'] ?? ''));

            $newMembers = is_array($message['new_chat_members'] ?? null) ? $message['new_chat_members'] : [];
            $leftMember = is_array($message['left_chat_member'] ?? null) ? $message['left_chat_member'] : [];
            $isService = !empty($newMembers) || !empty($leftMember);
            $isMessage = is_array($message) && !empty($message) && !$isService;

            $botAdded = false; $botRemoved = false;
            if ($newMembers || $leftMember) {
                if ($identity === null) $identity = bot_chats_identity($platform);
                foreach ($newMembers as $m) {
                    if (is_array($m) && bot_chats_is_this_bot($m, is_array($identity) ? $identity : [])) { $botAdded = true; break; }
                }
                if (!$botAdded && $leftMember && bot_chats_is_this_bot($leftMember, is_array($identity) ? $identity : [])) $botRemoved = true;
            }

            $existing = null;
            try { $existing = DB::fetch("SELECT * FROM bot_chats WHERE platform = ? AND chat_id = ?", [$platform, $chatId]); }
            catch (Throwable $e) { $existing = null; }

            $now = date('Y-m-d H:i:s');
            $fields = ['chat_type' => $type, 'last_seen' => $now];
            if ($title !== '') $fields['title'] = function_exists('mb_substr') ? mb_substr($title, 0, 240, 'UTF-8') : substr($title, 0, 240);
            if ($username !== '') $fields['username'] = substr($username, 0, 180);
            if ($isMessage) {
                $fields['last_message_at'] = $now;
                $fields['msg_count'] = (int)($existing['msg_count'] ?? 0) + 1;
                /* پیام رسیدن یعنی ربات داخل گفتگوست — حتی اگر قبلاً «خارج‌شده» ثبت شده بود. */
                $fields['status'] = 'member';
                $fields['last_error'] = null;
            }
            if ($botAdded) {
                $fields['status'] = 'member';
                $fields['added_at'] = $now;
                $fields['left_at'] = null;
                $fields['last_error'] = null;
            } elseif ($botRemoved) {
                $fields['status'] = 'left';
                $fields['left_at'] = $now;
            }
            if (!$existing) {
                $fields['status'] = $fields['status'] ?? 'member';
                $fields['first_seen'] = $now;
                if (empty($fields['msg_count'])) $fields['msg_count'] = 0;
            }
            return bot_chats_save($platform, $chatId, $fields);
        } catch (Throwable $e) {
            error_log('bot_chats_touch: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bot_chats_touch_my_member')) {
    /**
     * آپدیت my_chat_member تلگرام — دقیق‌ترین منبع برای «ربات اضافه/حذف شد».
     * (بله چنین آپدیتی نمی‌فرستد؛ آنجا new_chat_members/left_chat_member کار را می‌کند.)
     */
    function bot_chats_touch_my_member($platform, $update) {
        try {
            if (!is_array($update)) return false;
            $chat = $update['chat'] ?? null;
            if (!is_array($chat)) return false;
            $status = strtolower((string)($update['new_chat_member']['status'] ?? ''));
            $member = '';
            if (in_array($status, ['member', 'administrator', 'creator'], true)) $member = 'member';
            elseif (in_array($status, ['left', 'kicked'], true)) $member = 'left';
            $chatId = (string)($chat['id'] ?? '');
            if ($chatId === '') return false;
            $fields = [
                'chat_type' => bot_chats_norm_type($chat['type'] ?? ''),
                'last_seen' => date('Y-m-d H:i:s'),
            ];
            $title = (string)($chat['title'] ?? '');
            if ($title !== '') $fields['title'] = $title;
            if (!empty($chat['username'])) $fields['username'] = (string)$chat['username'];
            if ($member !== '') {
                $fields['status'] = $member;
                if ($member === 'member') { $fields['added_at'] = date('Y-m-d H:i:s'); $fields['left_at'] = null; $fields['last_error'] = null; }
                else { $fields['left_at'] = date('Y-m-d H:i:s'); }
            }
            return bot_chats_save($platform, $chatId, $fields);
        } catch (Throwable $e) {
            error_log('bot_chats_touch_my_member: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('bot_chats_error_text')) {
    /** متن خطای API را کوتاه و بی‌خطر می‌کند (توکن هرگز بخشی از متن نیست). */
    function bot_chats_error_text($e) {
        $m = is_string($e) ? $e : (string)$e->getMessage();
        if (preg_match('/"description"\s*:\s*"([^"]+)"/u', $m, $x)) $m = $x[1];
        $m = trim(preg_replace('/^پاسخ ناموفق API:\s*HTTP\s*\d+\s*-?\s*/u', '', $m));
        if ($m === '') $m = 'پاسخ نامعتبر از سرور پیام‌رسان';
        if (function_exists('mb_strlen') && mb_strlen($m, 'UTF-8') > 180) $m = mb_substr($m, 0, 180, 'UTF-8') . '…';
        return $m;
    }
}

if (!function_exists('bot_chats_call')) {
    /** یک فراخوانی API با امکان تزریق transport برای تست بدون شبکه. */
    function bot_chats_call($platform, $method, array $payload, $transport = null) {
        if (is_callable($transport)) return $transport($method, $payload);
        return bot_api_request($platform, $method, $payload);
    }
}

if (!function_exists('bot_chats_refresh')) {
    /**
     * تازه‌کردن عنوان/نوع/عضویتِ گفتگوهایی که مدت‌ها بررسی نشده‌اند.
     * getChat برای هر گفتگو یک درخواست است؛ پس دسته‌ای کار می‌کند.
     *
     * @return array{ok:bool,checked:int,failed:int,left:int,errors:array}
     *   checked = نتیجهٔ قطعی (عضو یا خارج‌شده) · failed = خطای موقت · left = زیرمجموعهٔ checked
     */
    function bot_chats_refresh($platform, $limit = 20, $transport = null) {
        $out = ['ok' => true, 'checked' => 0, 'failed' => 0, 'left' => 0, 'errors' => []];
        if (!bot_chats_schema()) { $out['ok'] = false; return $out; }
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $caps = bot_chats_capabilities($platform);
        if (empty($caps['get_chat'])) {
            $out['ok'] = false;
            $out['unsupported'] = true;
            $out['note'] = 'سروش پلاس هنوز متد getChat ندارد؛ فهرست و وضعیت گفتگوها از همان پیام‌های دریافتی ساخته می‌شود.';
            return $out;
        }
        $limit = max(1, min(50, (int)$limit));
        $cut = date('Y-m-d H:i:s', time() - 600);      // ۱۰ دقیقه: بیشتر از این، تازه است
        try {
            $rows = DB::fetchAll(
                "SELECT chat_id, chat_type FROM bot_chats WHERE platform = ? AND (checked_at IS NULL OR checked_at < ?)
                 ORDER BY checked_at ASC LIMIT " . $limit,
                [$platform, $cut]
            );
        } catch (Throwable $e) {
            $out['ok'] = false; return $out;
        }
        /* بودجهٔ زمانی: هر getChat تا ۲۰ ثانیه صبر می‌کند؛ ۲۰ مورد روی شبکهٔ
           قطع می‌تواند از max_execution_time بگذرد و صفحهٔ مدیر را با خطای
           ۵۰۰ ببندد. با ۴۵ ثانیه، هر کلیک تعداد کمی را تازه می‌کند و بقیه
           (که checked_at‌شان دست‌نخورده می‌ماند) در کلیک بعدی می‌آیند. */
        $deadline = microtime(true) + 45.0;
        foreach ($rows as $r) {
            if (microtime(true) >= $deadline) break;
            $chatId = (string)$r['chat_id'];
            $now = date('Y-m-d H:i:s');
            try {
                $res = bot_chats_call($platform, 'getChat', ['chat_id' => $chatId], $transport);
                $c = is_array($res) ? ($res['result'] ?? null) : null;
                if (!is_array($c)) throw new RuntimeException('پاسخ getChat قابل خواندن نیست');
                $fields = ['checked_at' => $now, 'last_error' => null,
                           'chat_type' => bot_chats_norm_type($c['type'] ?? '')];
                if (!empty($c['title'])) $fields['title'] = (string)$c['title'];
                if (!empty($c['username'])) $fields['username'] = (string)$c['username'];
                if (!empty($c['first_name']) && ($fields['chat_type'] ?? '') === 'private') {
                    $fields['title'] = trim((string)$c['first_name'] . ' ' . (string)($c['last_name'] ?? ''));
                }
                bot_chats_save($platform, $chatId, $fields);
                $out['checked']++;
            } catch (Throwable $e) {
                $msg = bot_chats_error_text($e);
                $low = strtolower($msg);
                $gone = (strpos($low, 'not found') !== false || strpos($low, 'kicked') !== false
                         || strpos($low, 'not a member') !== false || strpos($low, 'deactivated') !== false
                         /* v4.184.0: در گفتگوی خصوصی «مسدود شدن ربات» یعنی دیگر
                            نمی‌توان به او پیام داد — همان وضعیت «خارج‌شده»، نه «عضو». */
                         || ((string)($r['chat_type'] ?? '') === 'private'
                             && (strpos($low, 'blocked by the user') !== false || strpos($low, 'user is deactivated') !== false)));
                $fields = ['checked_at' => $now, 'last_error' => $msg];
                if ($gone) {
                    /* «پیدا نشد/اخراج شد» یک نتیجهٔ قطعی است، نه شکست بررسی:
                       ربات قطعاً در این گفتگو نیست. پس در «تازه‌شده» شمرده می‌شود
                       و فقط خطاهای موقت در «بررسی نشد» می‌مانند. */
                    $fields['status'] = 'left';
                    $fields['left_at'] = $now;
                    $out['left']++;
                    $out['checked']++;
                } else {
                    $out['failed']++;
                }
                bot_chats_save($platform, $chatId, $fields);
                if (count($out['errors']) < 5) $out['errors'][] = $msg;
            }
        }
        return $out;
    }
}

if (!function_exists('bot_chats_leave')) {
    /**
     * خروج ربات از یک گروه/کانال. برگشت‌ناپذیر است؛ فراخوان باید تأیید بگیرد.
     *
     * @param string        $actor نام کاربری مدیر برای ثبت در لاگ
     * @return array{ok:bool,error?:string,title?:string}
     */
    function bot_chats_leave($platform, $chatId, $transport = null, $actor = '') {
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $chatId = trim((string)$chatId);
        if ($chatId === '') return ['ok' => false, 'error' => 'شناسهٔ گفتگو خالی است.'];
        if (empty(bot_chats_capabilities($platform)['leave'])) {
            return ['ok' => false, 'error' => 'سروش پلاس هنوز متد leaveChat ندارد؛ خروج از گروه از داخل سایت ممکن نیست.'];
        }
        $row = null;
        try { $row = DB::fetch("SELECT * FROM bot_chats WHERE platform = ? AND chat_id = ?", [$platform, $chatId]); }
        catch (Throwable $e) { $row = null; }
        if ($row && (string)$row['chat_type'] === 'private') {
            return ['ok' => false, 'error' => 'خروج از گفتگوی خصوصی معنا ندارد؛ ربات را کاربر باید مسدود کند.'];
        }
        try {
            $res = bot_chats_call($platform, 'leaveChat', ['chat_id' => $chatId], $transport);
            if (!is_array($res) || (isset($res['ok']) && !$res['ok'])) throw new RuntimeException('پاسخ leaveChat ناموفق بود');
        } catch (Throwable $e) {
            $msg = bot_chats_error_text($e);
            /* فقط ردیف موجود خطا می‌گیرد؛ گفتگوی ناشناخته با یک خروجِ ناموفق
               ساخته نمی‌شود (وگرنه ردیفی «عضو» تلقی می‌شد که هرگز دیده نشده). */
            if ($row) {
                bot_chats_save($platform, $chatId, ['last_error' => $msg, 'checked_at' => date('Y-m-d H:i:s')]);
            }
            return ['ok' => false, 'error' => $msg];
        }
        $now = date('Y-m-d H:i:s');
        $title = (string)($row['title'] ?? '');
        $fields = ['status' => 'left', 'left_at' => $now, 'checked_at' => $now, 'last_error' => null,
                   'left_by' => (string)$actor];
        if (!$row) {
            $fields['chat_type'] = 'unknown';
            $fields['first_seen'] = $now;
            $fields['msg_count'] = 0;
        }
        bot_chats_save($platform, $chatId, $fields);
        if (function_exists('log_activity')) {
            try {
                log_activity($_SESSION['admin_id'] ?? null, 'خروج ربات از گروه',
                    'platform=' . $platform . ' chat_id=' . $chatId . ($title !== '' ? ' title=' . $title : ''));
            } catch (Throwable $e) {}
        }
        return ['ok' => true, 'title' => $title];
    }
}

if (!function_exists('bot_chats_add')) {
    /**
     * افزودن دستی یک گفتگو با شناسه (برای گروه‌هایی که هنوز هیچ پیامی نفرستاده‌اند).
     * getChat هم صحت شناسه و هم نوع/عنوان را برمی‌گرداند.
     *
     * @return array{ok:bool,error?:string,title?:string,type?:string}
     */
    function bot_chats_add($platform, $chatId, $transport = null) {
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $chatId = trim((string)$chatId);
        if ($chatId === '') return ['ok' => false, 'error' => 'شناسهٔ گفتگو را وارد کنید.'];
        if (empty(bot_chats_capabilities($platform)['get_chat'])) {
            return ['ok' => false, 'error' => 'سروش پلاس هنوز متد getChat ندارد؛ افزودن دستی با شناسه ممکن نیست.'];
        }
        if (!preg_match('/^@?[A-Za-z0-9_\-]{2,64}$/', $chatId)) {
            return ['ok' => false, 'error' => 'شناسه باید عددی (مثل ‎-1001234567890‎) یا نام کاربری با @ باشد.'];
        }
        try {
            $res = bot_chats_call($platform, 'getChat', ['chat_id' => $chatId], $transport);
            $c = is_array($res) ? ($res['result'] ?? null) : null;
            if (!is_array($c) || empty($c['id'])) throw new RuntimeException('این شناسه در پیام‌رسان پیدا نشد.');
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => bot_chats_error_text($e)];
        }
        $type = bot_chats_norm_type($c['type'] ?? '');
        $title = (string)($c['title'] ?? '');
        if ($title === '' && !empty($c['first_name'])) $title = trim((string)$c['first_name'] . ' ' . (string)($c['last_name'] ?? ''));
        $now = date('Y-m-d H:i:s');
        bot_chats_save($platform, (string)$c['id'], [
            'chat_type' => $type, 'title' => $title, 'username' => (string)($c['username'] ?? ''),
            'status' => 'member', 'last_seen' => $now, 'checked_at' => $now, 'last_error' => null,
        ]);
        return ['ok' => true, 'title' => $title, 'type' => $type];
    }
}

if (!function_exists('bot_chats_where')) {
    function bot_chats_where(array $f) {
        $w = ['platform = ?']; $p = [$f['platform']];
        if (!empty($f['type']) && in_array($f['type'], ['group', 'channel', 'private'], true)) { $w[] = 'chat_type = ?'; $p[] = $f['type']; }
        if (!empty($f['status']) && in_array($f['status'], ['member', 'left', 'unknown'], true)) { $w[] = 'status = ?'; $p[] = $f['status']; }
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $w[] = '(title LIKE ? OR username LIKE ? OR chat_id LIKE ?)';
            for ($i = 0; $i < 3; $i++) $p[] = '%' . $q . '%';
        }
        return [implode(' AND ', $w), $p];
    }
}

if (!function_exists('bot_chats_total')) {
    function bot_chats_total(array $f) {
        if (!bot_chats_schema()) return 0;
        list($w, $p) = bot_chats_where($f);
        try {
            $row = DB::fetch("SELECT COUNT(*) c FROM bot_chats WHERE $w", $p);
            return is_array($row) ? (int)($row['c'] ?? 0) : 0;
        } catch (Throwable $e) { return 0; }
    }
}

if (!function_exists('bot_chats_rows')) {
    /** یک صفحه از فهرست گفتگوها؛ گروه‌ها اول، بعد کانال‌ها، بعد خصوصی. */
    function bot_chats_rows(array $f, $page = 1, $perPage = 50) {
        if (!bot_chats_schema()) return [];
        list($w, $p) = bot_chats_where($f);
        $perPage = max(1, min(200, (int)$perPage));
        $offset = (max(1, (int)$page) - 1) * $perPage;
        $sql = "SELECT * FROM bot_chats WHERE $w
                ORDER BY CASE WHEN chat_type = 'group' THEN 0 WHEN chat_type = 'channel' THEN 1
                              WHEN chat_type = 'private' THEN 2 ELSE 3 END,
                         (title IS NULL), title, id DESC
                LIMIT $perPage OFFSET $offset";
        try { return DB::fetchAll($sql, $p); } catch (Throwable $e) { return []; }
    }
}

if (!function_exists('bot_chats_summary')) {
    /** شمارش‌ها برای نوار خلاصهٔ بالای فهرست. */
    function bot_chats_summary($platform) {
        $out = ['group' => 0, 'channel' => 0, 'private' => 0, 'left' => 0, 'member' => 0, 'total' => 0];
        if (!bot_chats_schema()) return $out;
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        try {
            $rows = DB::fetchAll("SELECT chat_type, status, COUNT(*) c FROM bot_chats WHERE platform = ? GROUP BY chat_type, status", [$platform]);
            foreach ($rows as $r) {
                $t = (string)($r['chat_type'] ?? 'unknown');
                $s = (string)($r['status'] ?? 'unknown');
                $c = (int)$r['c'];
                if (isset($out[$t])) $out[$t] += $c;
                if (isset($out[$s])) $out[$s] += $c;
                $out['total'] += $c;
            }
        } catch (Throwable $e) {}
        return $out;
    }
}

if (!function_exists('bot_chats_title_map')) {
    /** نگاشت شناسهٔ گفتگو → عنوان/نوع، برای نمایش زیر نام فرستنده در جدول پیام‌ها. */
    function bot_chats_title_map($platform, array $chatIds) {
        $ids = [];
        foreach ($chatIds as $id) { $id = (string)$id; if ($id !== '') $ids[$id] = true; }
        if (!$ids) return [];
        if (!bot_chats_schema()) return [];
        $platform = function_exists('bot_valid_platform') ? bot_valid_platform($platform) : 'bale';
        $in = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$platform], array_keys($ids));
        try {
            $rows = DB::fetchAll("SELECT chat_id, title, chat_type, status FROM bot_chats WHERE platform = ? AND chat_id IN ($in)", $params);
        } catch (Throwable $e) { return []; }
        $out = [];
        foreach ($rows as $r) $out[(string)$r['chat_id']] = $r;
        return $out;
    }
}
