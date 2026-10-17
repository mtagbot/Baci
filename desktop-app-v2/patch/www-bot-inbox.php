<?php
// File: bot-inbox.php  (v4.184.0)
/**
 * «پیام‌های دریافتی ربات» — صندوق ورودی.
 *
 * هر متنی که کاربران متصل به ربات بفرستند (مثلاً پیام‌های بی‌هدف یا اشتباهی)
 * اینجا با نام کاربر، نام دانش‌آموز (اگر پیوند خورده باشد) و زمان ثبت می‌شود.
 * از «تنظیمات دیگر ← پیام‌های دریافتی ربات» قابل پیگیری است.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bot_helpers.php';
require_once __DIR__ . '/includes/bot_inbox.php';
require_once __DIR__ . '/includes/bot_chats.php';

require_permission('send_sms');

bot_inbox_schema();

$inboxPlatform = bot_valid_platform($_GET['platform'] ?? 'bale');
$inboxFilters  = bot_inbox_filters();
$inboxFilters['platform'] = $inboxPlatform;
$inboxPerPage  = 50;
$inboxTotal    = bot_inbox_total($inboxFilters);
$inboxPages    = max(1, (int)ceil($inboxTotal / $inboxPerPage));
$inboxPage     = max(1, min($inboxPages, (int)($_GET['page'] ?? 1)));
$inboxRows     = bot_inbox_rows($inboxFilters, $inboxPage, $inboxPerPage);
$inboxKinds    = bot_inbox_kind_counts($inboxPlatform);
$inboxTitles   = ['bale' => 'بله', 'telegram' => 'تلگرام'];

/* v4.184.0 — «گروه‌ها و کانال‌ها»: فهرست گفتگوهایی که ربات در آن‌هاست.
   دو منبع دارد: پیام‌هایی که به ربات می‌رسند (و لحظه‌ای ثبت می‌شوند) و
   getChat روی شناسه‌های شناخته‌شده. چون هیچ APIای «فهرست همهٔ گفتگوها» را
   نمی‌دهد، این فهرست چیزی را نشان می‌دهد که خودِ ربات دیده است. */
bot_chats_schema();
$inboxView = (($_GET['view'] ?? 'messages') === 'chats') ? 'chats' : 'messages';

/* عملیات فهرست گفتگوها — POST + CSRF (خروج برگشت‌ناپذیر است) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chats_action'])) {
    $back = 'bot-inbox.php?platform=' . $inboxPlatform . '&view=chats';
    if (!verify_csrf((string)($_POST['csrf_token'] ?? ''))) {
        set_flash_message('error', 'نشست منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.');
        redirect($back);
    }
    $chatsAction = (string)$_POST['chats_action'];
    $chatsChatId = trim((string)($_POST['chat_id'] ?? ''));
    if ($chatsAction === 'refresh') {
        $rr = bot_chats_refresh($inboxPlatform, 20);
        $msg = 'وضعیت ' . tr_num((string)$rr['checked'], 'fa') . ' گفتگو تازه شد';
        if ($rr['failed'] > 0) $msg .= ' — ' . tr_num((string)$rr['failed'], 'fa') . ' مورد بررسی نشد';
        if (!empty($rr['errors'])) $msg .= ' (' . implode('، ', array_slice($rr['errors'], 0, 2)) . ')';
        set_flash_message($rr['failed'] > 0 ? 'warning' : 'success', $msg);
    } elseif ($chatsAction === 'leave') {
        $rr = bot_chats_leave($inboxPlatform, $chatsChatId, null, (string)($_SESSION['admin_username'] ?? ($_SESSION['admin_id'] ?? '')));
        if (!empty($rr['ok'])) {
            set_flash_message('success', !empty($rr['title']) ? 'ربات از «' . $rr['title'] . '» خارج شد.' : 'ربات از این گفتگو خارج شد.');
        } else {
            set_flash_message('error', 'خروج انجام نشد: ' . ($rr['error'] ?? ''));
        }
    } elseif ($chatsAction === 'add') {
        $rr = bot_chats_add($inboxPlatform, $chatsChatId);
        if (!empty($rr['ok'])) {
            set_flash_message('success', 'گفتگو اضافه شد: ' . ($rr['title'] !== '' ? $rr['title'] : $chatsChatId) . ' (' . bot_chats_type_label((string)($rr['type'] ?? '')) . ')');
        } else {
            set_flash_message('error', 'افزودن انجام نشد: ' . ($rr['error'] ?? ''));
        }
    }
    redirect($back);
}

$chatFilters = [
    'platform' => $inboxPlatform,
    'type'     => trim((string)($_GET['ctype'] ?? '')),
    'status'   => trim((string)($_GET['cstatus'] ?? '')),
    'q'        => trim((string)($_GET['cq'] ?? '')),
];
$chatPerPage = 50;
$chatTotal   = $inboxView === 'chats' ? bot_chats_total($chatFilters) : 0;
$chatPages   = max(1, (int)ceil($chatTotal / $chatPerPage));
$chatPage    = max(1, min($chatPages, (int)($_GET['cpage'] ?? 1)));
$chatRows    = $inboxView === 'chats' ? bot_chats_rows($chatFilters, $chatPage, $chatPerPage) : [];
$chatSummary = $inboxView === 'chats' ? bot_chats_summary($inboxPlatform) : ['group' => 0, 'channel' => 0, 'private' => 0, 'left' => 0, 'member' => 0, 'total' => 0];
$chatOtherKey = $inboxPlatform === 'bale' ? 'telegram' : 'bale';
$chatOtherTotal = $inboxView === 'chats' ? bot_chats_total(['platform' => $chatOtherKey, 'type' => '', 'status' => '', 'q' => '']) : 0;
$inboxChatTitles = $inboxView === 'messages' && $inboxRows
    ? bot_chats_title_map($inboxPlatform, array_map(function ($r) { return (string)($r['chat_id'] ?? ''); }, $inboxRows))
    : [];

/* v4.177.1 — اگر برای این پیام‌رسان پیامی نیست ولی برای آن یکی هست، به مدیر
   می‌گوییم پیام‌ها کجا هستند (تب پیش‌فرض «بله» است و تلگرام را نشان نمی‌دهد). */
$inboxOtherKey  = $inboxPlatform === 'bale' ? 'telegram' : 'bale';
$inboxOtherRows = bot_inbox_total(['platform' => $inboxOtherKey, 'q' => '', 'kind' => '', 'student' => '']);

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold"><svg data-ui-icon="bot" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="7" width="18" height="14" rx="4"/><path d="M12 7V2M10 2h4M7 12v2m10-2v2m-9 3h8"/></svg> پیام‌های دریافتی ربات</h2>
            <p class="text-sm text-muted">هر متنی که کاربران به ربات فرستادند اینجا ثبت می‌شود — از جمله پیام‌های بی‌هدف یا اشتباهی. فهرست گروه‌ها و کانال‌هایی که ربات در آن‌هاست در تب «گروه‌ها و کانال‌ها» است.</p>
        </div>
        <div class="flex gap-2">
            <a href="bot-inbox.php?platform=bale" class="btn <?php echo $inboxPlatform === 'bale' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">بله</a>
            <a href="bot-inbox.php?platform=telegram" class="btn <?php echo $inboxPlatform === 'telegram' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">تلگرام</a>
            <a href="other-settings.php" class="btn btn-outline text-xs">بازگشت به تنظیمات دیگر</a>
        </div>
    </div>

    <div class="flex gap-2 flex-wrap" style="margin-bottom:2px">
        <a href="bot-inbox.php?platform=<?php echo clean($inboxPlatform); ?>" class="btn <?php echo $inboxView === 'messages' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">پیام‌های دریافتی <span style="opacity:.75">(<?php echo clean(tr_num((string)$inboxTotal, 'fa')); ?>)</span></a>
        <a href="bot-inbox.php?platform=<?php echo clean($inboxPlatform); ?>&amp;view=chats" class="btn <?php echo $inboxView === 'chats' ? 'btn-primary' : 'btn-secondary'; ?> text-xs">گروه‌ها و کانال‌ها <span style="opacity:.75">(<?php echo clean(tr_num((string)bot_chats_total(['platform' => $inboxPlatform, 'type' => '', 'status' => '', 'q' => '']), 'fa')); ?>)</span></a>
    </div>

    <?php if ($inboxView === 'messages'): ?>
    <div class="card p-4">
        <form method="GET" class="filters-line">
            <input type="hidden" name="platform" value="<?php echo clean($inboxPlatform); ?>">
            <div class="filter-primary"><label class="text-xs font-bold">جستجو</label>
                <input type="text" name="q" class="form-input" placeholder="متن پیام، نام کاربری یا شناسهٔ گفتگو…" value="<?php echo clean($inboxFilters['q']); ?>"></div>
            <div class="filter-primary"><label class="text-xs font-bold">نوع پیام</label>
                <select name="kind" class="form-select">
                    <option value="">همه</option>
                    <?php foreach ($inboxKinds as $k => $c): if ($c <= 0 && $k === 'other') continue; ?>
                        <option value="<?php echo clean($k); ?>" <?php echo $inboxFilters['kind'] === $k ? 'selected' : ''; ?>>
                            <?php echo clean(['text' => 'متن', 'photo' => 'عکس', 'document' => 'فایل', 'voice' => 'صدا', 'video' => 'ویدیو', 'sticker' => 'استیکر', 'contact' => 'مخاطب', 'location' => 'موقعیت', 'other' => 'سایر'][$k] ?? $k); ?>
                            (<?php echo clean(tr_num((string)$c, 'fa')); ?>)
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="filter-primary"><label class="text-xs font-bold">فرستنده</label>
                <select name="student" class="form-select">
                    <option value="">همه</option>
                    <option value="linked" <?php echo $inboxFilters['student'] === 'linked' ? 'selected' : ''; ?>>دانش‌آموز پیوندخورده</option>
                    <option value="unknown" <?php echo $inboxFilters['student'] === 'unknown' ? 'selected' : ''; ?>>ناشناس</option>
                </select></div>
            <div class="flex gap-2"><button class="btn btn-primary">فیلتر</button><a href="bot-inbox.php?platform=<?php echo clean($inboxPlatform); ?>" class="btn btn-secondary">حذف</a></div>
        </form>
    </div>

    <div class="card p-4">
        <div class="flex justify-between items-center flex-wrap gap-2" style="margin-bottom:10px">
            <h3 class="font-bold text-sm">پیام‌های <?php echo clean($inboxTitles[$inboxPlatform]); ?></h3>
            <span class="text-xs text-muted">
                مجموع <?php echo clean(tr_num((string)$inboxTotal, 'fa')); ?> پیام ·
                صفحهٔ <?php echo clean(tr_num((string)$inboxPage, 'fa')); ?> از <?php echo clean(tr_num((string)$inboxPages, 'fa')); ?>
            </span>
        </div>
        <?php if ($inboxTotal === 0 && $inboxOtherRows > 0): ?>
        <div class="card p-3" style="margin-bottom:10px;border-right:3px solid var(--primary,#2563eb)">
            <p class="text-xs" style="line-height:2">
                برای «<?php echo clean($inboxTitles[$inboxPlatform]); ?>» پیامی ثبت نشده است، اما
                <?php echo clean(tr_num((string)$inboxOtherRows, 'fa')); ?> پیام در
                «<?php echo clean($inboxTitles[$inboxOtherKey]); ?>» هست —
                <a href="bot-inbox.php?platform=<?php echo clean($inboxOtherKey); ?>" class="font-bold">دیدن <?php echo clean($inboxTitles[$inboxOtherKey]); ?></a>
            </p>
        </div>
        <?php endif; ?>
        <?php if ($inboxPages > 1): ?>
        <nav class="flex gap-2 items-center flex-wrap" style="margin-bottom:10px" aria-label="صفحه‌های پیام‌ها">
            <?php for ($pg = 1; $pg <= $inboxPages; $pg++):
                $qs = ['platform=' . $inboxPlatform, 'page=' . $pg];
                if ($inboxFilters['q'] !== '') $qs[] = 'q=' . urlencode($inboxFilters['q']);
                if ($inboxFilters['kind'] !== '') $qs[] = 'kind=' . urlencode($inboxFilters['kind']);
                if ($inboxFilters['student'] !== '') $qs[] = 'student=' . urlencode($inboxFilters['student']);
            ?>
                <a class="btn <?php echo $pg === $inboxPage ? 'btn-primary' : 'btn-outline'; ?> text-xs" href="bot-inbox.php?<?php echo implode('&amp;', $qs); ?>"><?php echo clean(tr_num((string)$pg, 'fa')); ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <div class="table-container">
            <table>
                <thead><tr><th style="width:60px">#</th><th style="width:150px">فرستنده</th><th style="width:150px">دانش‌آموز</th><th>متن پیام</th><th style="width:90px">نوع</th><th style="width:150px">زمان</th></tr></thead>
                <tbody>
                <?php foreach ($inboxRows as $r): ?>
                    <tr>
                        <td class="font-mono text-xs"><?php echo clean(tr_num((string)$r['id'], 'fa')); ?></td>
                        <td class="font-mono text-xs dir-ltr">
                            <?php echo clean($r['username'] !== null && $r['username'] !== '' ? '@' . $r['username'] : $r['chat_id']); ?>
                            <?php $ci = $inboxChatTitles[(string)$r['chat_id']] ?? null;
                            if ($ci && !empty($ci['title']) && in_array($ci['chat_type'], ['group', 'channel'], true)): ?>
                                <div class="text-xs" style="direction:rtl;color:#475569;margin-top:2px">در <?php echo $ci['chat_type'] === 'channel' ? 'کانال' : 'گروه'; ?> «<?php echo clean((string)$ci['title']); ?>»</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs">
                            <?php if (!empty($r['first_name']) || !empty($r['last_name'])): ?>
                                <span class="font-bold"><?php echo clean(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))); ?></span>
                                <div class="text-muted"><?php echo clean($r['class_name'] ?? ''); ?></div>
                            <?php else: ?><span class="text-muted">ناشناس</span><?php endif; ?>
                        </td>
                        <td style="white-space:pre-wrap;line-height:1.9"><?php echo clean((string)$r['message']); ?></td>
                        <td class="text-xs"><?php echo clean($r['kind'] ?? 'text'); ?></td>
                        <td class="text-xs text-muted" dir="ltr"><?php echo clean(jdate('Y/m/d H:i:s', strtotime((string)$r['created_at']))); ?></td>
                    </tr>
                <?php endforeach; if (!$inboxRows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-6">هنوز پیامی دریافت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$inboxRows && $inboxOtherRows <= 0): ?>
        <div class="card p-4" style="margin-top:12px;border-right:3px solid var(--warning,#d97706)">
            <h4 class="font-bold text-sm" style="margin-bottom:6px">چرا پیامی نمی‌بینم؟</h4>
            <ul class="text-xs text-muted" style="line-height:2.2;list-style:disc;padding-inline-start:18px">
                <li>این صفحه فقط پیام‌های <b>دریافتیِ بعد از نصب نسخهٔ ۴.۱۷۷.۱</b> را نشان می‌دهد؛ پیام‌های قدیمی‌تر هیچ‌گاه نگه داشته نمی‌شدند.</li>
                <li>هر پیامی که ربات ببیند ثبت می‌شود — گفتگوی خصوصی، پیام گروه، یا پستی که ربات در کانال می‌بیند. پیام سرویسیِ «افزودن/حذف عضو» پیام نیست، ولی همان لحظه در تب «گروه‌ها و کانال‌ها» ثبت می‌شود.</li>
                <li>اگر ربات به پیام شما جواب داد ولی اینجا نیست، یعنی قلاب ثبت در
                    <span dir="ltr">includes/bot_webhook_engine.php</span> و ماژول
                    <span dir="ltr">includes/bot_inbox.php</span> نصب نشده‌اند — هر دو فایل باید روی سایت باشند.</li>
                <li>پاک‌سازی خودکار (پیش‌فرض ۶۰ روز) پیام‌های کهنه‌تر را حذف می‌کند؛ مقدار را در «سلامت پایگاه داده ← قواعد نگهداشت» تغییر دهید یا خاموش کنید.</li>
            </ul>
        </div>
        <?php endif; ?>
        <p class="text-muted text-xs" style="line-height:2;margin-top:10px">
            پیام‌های ورودی تا زمانی که لازم باشد نگه داشته می‌شوند؛ پاک‌سازی خودکارِ این جدول (پیش‌فرض ۶۰ روز) را می‌توانید در
            «سلامت پایگاه داده ← قواعد نگهداشت» تغییر دهید یا خاموش کنید. برای پیام‌های خروجی، «لاگ فعالیت» و صف اعلان‌ها را ببینید.
        </p>
    </div>
    <?php else: /* v4.184.0 — تب «گروه‌ها و کانال‌ها» */ ?>
    <div class="card p-4">
        <div class="flex justify-between items-center flex-wrap gap-2" style="margin-bottom:10px">
            <div class="text-xs" style="line-height:2">
                <span class="font-bold"><?php echo clean($inboxTitles[$inboxPlatform]); ?>:</span>
                <?php echo clean(tr_num((string)$chatSummary['group'], 'fa')); ?> گروه ·
                <?php echo clean(tr_num((string)$chatSummary['channel'], 'fa')); ?> کانال ·
                <?php echo clean(tr_num((string)$chatSummary['private'], 'fa')); ?> گفتگوی خصوصی ·
                <?php echo clean(tr_num((string)$chatSummary['left'], 'fa')); ?> خارج‌شده
            </div>
            <form method="POST" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="chats_action" value="refresh">
                <button class="btn btn-secondary text-xs" title="getChat برای گفتگوهایی که مدتی بررسی نشده‌اند">بروزرسانی وضعیت (۲۰ مورد)</button>
            </form>
        </div>
        <form method="GET" class="filters-line">
            <input type="hidden" name="platform" value="<?php echo clean($inboxPlatform); ?>">
            <input type="hidden" name="view" value="chats">
            <div class="filter-primary"><label class="text-xs font-bold">جستجو</label>
                <input type="text" name="cq" class="form-input" placeholder="عنوان، نام کاربری یا شناسه…" value="<?php echo clean($chatFilters['q']); ?>"></div>
            <div class="filter-primary"><label class="text-xs font-bold">نوع</label>
                <select name="ctype" class="form-select">
                    <option value="">همه</option>
                    <?php foreach (['group', 'channel', 'private'] as $ct): ?>
                        <option value="<?php echo $ct; ?>" <?php echo $chatFilters['type'] === $ct ? 'selected' : ''; ?>><?php echo clean(bot_chats_type_label($ct)); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="filter-primary"><label class="text-xs font-bold">وضعیت</label>
                <select name="cstatus" class="form-select">
                    <option value="">همه</option>
                    <option value="member" <?php echo $chatFilters['status'] === 'member' ? 'selected' : ''; ?>>عضو</option>
                    <option value="left" <?php echo $chatFilters['status'] === 'left' ? 'selected' : ''; ?>>خارج‌شده</option>
                    <option value="unknown" <?php echo $chatFilters['status'] === 'unknown' ? 'selected' : ''; ?>>نامشخص</option>
                </select></div>
            <div class="flex gap-2"><button class="btn btn-primary">فیلتر</button><a href="bot-inbox.php?platform=<?php echo clean($inboxPlatform); ?>&amp;view=chats" class="btn btn-secondary">حذف</a></div>
        </form>
    </div>

    <?php if ($chatTotal === 0 && $chatOtherTotal > 0): ?>
    <div class="card p-3" style="border-right:3px solid var(--primary,#2563eb)">
        <p class="text-xs" style="line-height:2">
            برای «<?php echo clean($inboxTitles[$inboxPlatform]); ?>» گفتگویی ثبت نشده، اما
            <?php echo clean(tr_num((string)$chatOtherTotal, 'fa')); ?> گفتگو در
            «<?php echo clean($inboxTitles[$chatOtherKey]); ?>» هست —
            <a href="bot-inbox.php?platform=<?php echo clean($chatOtherKey); ?>&amp;view=chats" class="font-bold">دیدن <?php echo clean($inboxTitles[$chatOtherKey]); ?></a>
        </p>
    </div>
    <?php endif; ?>

    <div class="card p-4">
        <div class="flex justify-between items-center flex-wrap gap-2" style="margin-bottom:10px">
            <h3 class="font-bold text-sm">گفتگوهای <?php echo clean($inboxTitles[$inboxPlatform]); ?></h3>
            <span class="text-xs text-muted">مجموع <?php echo clean(tr_num((string)$chatTotal, 'fa')); ?> گفتگو · صفحهٔ <?php echo clean(tr_num((string)$chatPage, 'fa')); ?> از <?php echo clean(tr_num((string)$chatPages, 'fa')); ?></span>
        </div>
        <?php if ($chatPages > 1): ?>
        <nav class="flex gap-2 items-center flex-wrap" style="margin-bottom:10px" aria-label="صفحه‌های گفتگوها">
            <?php for ($pg = 1; $pg <= $chatPages; $pg++):
                $cqs = ['platform=' . $inboxPlatform, 'view=chats', 'cpage=' . $pg];
                if ($chatFilters['q'] !== '') $cqs[] = 'cq=' . urlencode($chatFilters['q']);
                if ($chatFilters['type'] !== '') $cqs[] = 'ctype=' . urlencode($chatFilters['type']);
                if ($chatFilters['status'] !== '') $cqs[] = 'cstatus=' . urlencode($chatFilters['status']);
            ?>
                <a class="btn <?php echo $pg === $chatPage ? 'btn-primary' : 'btn-outline'; ?> text-xs" href="bot-inbox.php?<?php echo implode('&amp;', $cqs); ?>"><?php echo clean(tr_num((string)$pg, 'fa')); ?></a>
            <?php endfor; ?>
        </nav>
        <?php endif; ?>
        <div class="table-container">
            <table>
                <thead><tr>
                    <th style="width:60px">#</th>
                    <th>عنوان</th>
                    <th style="width:120px">نوع</th>
                    <th style="width:170px">شناسه</th>
                    <th style="width:110px">وضعیت</th>
                    <th style="width:150px">آخرین پیام</th>
                    <th style="width:80px">پیام‌ها</th>
                    <th style="width:190px">عملیات</th>
                </tr></thead>
                <tbody>
                <?php foreach ($chatRows as $c):
                    $st = (string)($c['status'] ?? 'unknown');
                    $stStyle = $st === 'member' ? 'background:#dcfce7;color:#166534'
                             : ($st === 'left' ? 'background:#fee2e2;color:#991b1b' : 'background:#e2e8f0;color:#334155');
                    $ct = (string)($c['chat_type'] ?? 'unknown');
                ?>
                    <tr>
                        <td class="font-mono text-xs"><?php echo clean(tr_num((string)$c['id'], 'fa')); ?></td>
                        <td class="text-xs">
                            <span class="font-bold"><?php echo clean((string)($c['title'] ?? '') !== '' ? (string)$c['title'] : '—'); ?></span>
                            <?php if (!empty($c['last_error'])): ?>
                                <div class="text-muted" style="color:#b91c1c">آخرین خطا: <?php echo clean((string)$c['last_error']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-xs"><?php echo clean(bot_chats_type_label($ct)); ?></td>
                        <td class="font-mono text-xs dir-ltr"><?php echo clean((string)$c['chat_id']); ?><?php if (!empty($c['username'])): ?><div style="color:#475569">@<?php echo clean((string)$c['username']); ?></div><?php endif; ?></td>
                        <td><span class="text-xs" style="display:inline-block;padding:2px 10px;border-radius:999px;<?php echo $stStyle; ?>"><?php echo clean(bot_chats_status_label($st)); ?></span></td>
                        <td class="text-xs text-muted" dir="ltr"><?php echo !empty($c['last_message_at']) ? clean(jdate('Y/m/d H:i', strtotime((string)$c['last_message_at']))) : '—'; ?></td>
                        <td class="text-xs"><?php echo clean(tr_num((string)(int)($c['msg_count'] ?? 0), 'fa')); ?></td>
                        <td>
                            <?php if (in_array($ct, ['group', 'channel'], true) && $st !== 'left'): ?>
                            <form method="POST" style="margin:0" onsubmit="return confirm('ربات از این گفتگو خارج شود؟ این کار برگشت‌پذیر نیست و برای برگشت باید مدیر گروه دوباره ربات را اضافه کند.');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="chats_action" value="leave">
                                <input type="hidden" name="chat_id" value="<?php echo clean((string)$c['chat_id']); ?>">
                                <button class="btn btn-outline text-xs" style="color:#b91c1c;border-color:#fca5a5">خروج از گروه</button>
                            </form>
                            <?php elseif ($ct === 'private'): ?>
                                <span class="text-xs text-muted">گفتگوی خصوصی — خروج معنا ندارد</span>
                            <?php elseif ($st === 'left'): ?>
                                <span class="text-xs text-muted">قبلاً خارج شده</span>
                            <?php else: ?>
                                <span class="text-xs text-muted">نوع نامشخص — اول «بروزرسانی وضعیت»</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; if (!$chatRows): ?>
                    <tr><td colspan="8" class="text-center text-muted py-6">هنوز گفتگویی ثبت نشده است.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted text-xs" style="line-height:2;margin-top:10px">
            این فهرست از همان چیزی ساخته می‌شود که ربات دیده است: هر پیام یا دکمه‌ای که به ربات برسد، گفتگو را همان‌جا ثبت می‌کند؛
            پیام سرویسیِ «افزودن/حذف عضو» هم همان لحظه ثبت می‌شود. برای گفتگوهای قدیمی‌تر، «بروزرسانی وضعیت» با
            <span dir="ltr">getChat</span> عنوان و عضویت را تازه می‌کند.
        </p>
    </div>

    <div class="card p-4">
        <h3 class="font-bold text-sm" style="margin-bottom:6px">افزودن دستی با شناسهٔ گفتگو</h3>
        <p class="text-xs text-muted" style="line-height:2;margin-bottom:8px">
            هیچ APIای فهرست کامل گفتگوها را نمی‌دهد؛ گروهی که ربات پیش‌تر به آن اضافه شده و از آن زمان هیچ پیامی نفرستاده، تا اولین پیام
            دیده نمی‌شود. اگر شناسه‌اش را می‌دانید، همین‌جا اضافه کنید تا بررسی و به فهرست اضافه شود.
        </p>
        <form method="POST" class="filters-line">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
            <input type="hidden" name="chats_action" value="add">
            <div class="filter-primary"><label class="text-xs font-bold">شناسهٔ گفتگو</label>
                <input type="text" name="chat_id" class="form-input dir-ltr" placeholder="-1001234567890 یا @channelusername"></div>
            <div class="flex gap-2"><button class="btn btn-primary">افزودن / بررسی</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/includes/footer.php';
