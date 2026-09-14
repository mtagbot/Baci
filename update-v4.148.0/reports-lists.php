<?php
// File: reports-lists.php  (v4.145.0)
/**
 * «لیست‌ها و گزارشات» — خانهٔ همهٔ لیست‌های قابل تهیه در مدرسه.
 *
 * این صفحه عمداً به‌شکل «فهرست گزارش‌ها» ساخته شده، نه یک گزارش
 * تک‌منظوره: کارفرما گفت «میخواهم انواع و اقسام لیست‌ها و گزارشات را
 * تهیه کنم»، پس افزودن گزارش بعدی باید یک بلوک تازه در همین صفحه
 * باشد، نه یک فایل جدید.
 *
 * گزارش اول: لیست کلاسی دبیر (خروجی Word، از قالب خام مدرسه).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/school_sort.php';
require_once __DIR__ . '/includes/class_schedule_sync.php';
require_once __DIR__ . '/includes/docx_class_list.php';

require_permission('manage_classes');

$year    = get_setting('current_academic_year', '1404/1405');
$classes = get_unified_class_options($year);

/* ───────── دانلود: یک کلاس یا همهٔ کلاس‌ها ───────── */
$action = $_GET['action'] ?? '';

if ($action === 'class_list_docx') {
    $className = trim($_GET['class'] ?? '');
    if ($className === '') { set_flash_message('error', 'کلاس انتخاب نشده است.'); redirect('reports-lists.php'); }

    $grade = '';
    foreach ($classes as $c) if ($c['class_name'] === $className) { $grade = $c['grade_level']; break; }

    $code  = dcl_class_code($className, $grade);
    $names = dcl_students_of_class($className, $year);
    $doc   = dcl_generate($code, $names);

    if ($doc === null) {
        set_flash_message('error', 'قالب لیست پیدا نشد یا افزونهٔ Zip روی سرور فعال نیست.');
        redirect('reports-lists.php');
    }

    $fname = 'لیست کلاسی ' . $className . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . strlen($doc));
    header('Cache-Control: no-store');
    echo $doc;
    exit;
}

if ($action === 'class_list_pdf') {
    /* v4.146.0 — نمای چاپی برای PDF.
       خروجی HTML است و کاربر با «Save as PDF» مرورگر فایل را
       می‌گیرد؛ این تنها راهی بود که فونت تیتر تضمینی درست دربیاید،
       چون TCPDF در بسته نیست و برای فارسی به فونت تبدیل‌شده نیاز
       دارد. */
    $className = trim($_GET['class'] ?? '');
    if ($className === '') { set_flash_message('error', 'کلاس انتخاب نشده است.'); redirect('reports-lists.php'); }

    $grade = '';
    foreach ($classes as $c) if ($c['class_name'] === $className) { $grade = $c['grade_level']; break; }

    /* v4.148.0 — پیش‌فرض: ویرایشگر/پیش‌نمایش زنده باز می‌شود و چاپ
       خودکار نمی‌آید، تا کاربر اول همه‌چیز را ببیند. با auto=1 مستقیم
       پنجرهٔ چاپ باز می‌شود. */
    $autoPrint = (($_GET['auto'] ?? '0') === '1');

    echo dcl_render_print_html(
        dcl_class_code($className, $grade),
        dcl_students_of_class($className, $year),
        get_setting('school_name', ''),
        $autoPrint
    );
    exit;
}

if ($action === 'class_list_all') {
    /* همهٔ کلاس‌ها در یک zip — برای وقتی دفتر می‌خواهد یک‌جا چاپ کند. */
    if (!class_exists('ZipArchive')) {
        set_flash_message('error', 'افزونهٔ Zip روی سرور فعال نیست؛ لیست‌ها را تکی دانلود کنید.');
        redirect('reports-lists.php');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'dclzip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        set_flash_message('error', 'ساخت فایل فشرده ممکن نشد.');
        redirect('reports-lists.php');
    }
    $added = 0;
    foreach ($classes as $c) {
        $names = dcl_students_of_class($c['class_name'], $year);
        if (!$names) continue;                    /* کلاس بدون دانش‌آموز را نساز */
        $doc = dcl_generate(dcl_class_code($c['class_name'], $c['grade_level']), $names);
        if ($doc === null) continue;
        $zip->addFromString('لیست کلاسی ' . $c['class_name'] . '.docx', $doc);
        $added++;
    }
    $zip->close();

    if ($added === 0) {
        @unlink($tmp);
        set_flash_message('error', 'هیچ کلاسی با دانش‌آموز فعال پیدا نشد.');
        redirect('reports-lists.php');
    }
    $data = @file_get_contents($tmp);
    @unlink($tmp);
    $fname = 'لیست کلاسی همه کلاس‌ها.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . rawurlencode($fname) . '"; filename*=UTF-8\'\'' . rawurlencode($fname));
    header('Content-Length: ' . strlen($data));
    header('Cache-Control: no-store');
    echo $data;
    exit;
}

/* ───────── شمارش دانش‌آموز هر کلاس، برای نمایش در جدول ───────── */
$counts = [];
foreach ($classes as $c) {
    $counts[$c['class_name']] = count(dcl_students_of_class($c['class_name'], $year));
}

$templateOk = is_file(dcl_template_path());
$zipOk      = class_exists('ZipArchive');

require_once __DIR__ . '/includes/header.php';
?>
<div class="space-y-6">
    <div class="page-hero flex justify-between items-center no-print">
        <div>
            <h2 class="text-2xl font-bold">لیست‌ها و گزارشات</h2>
            <p class="text-sm text-muted">تهیهٔ لیست‌های اداری و گزارش‌های آماده برای چاپ — سال تحصیلی <?php echo clean(tr_num($year, 'fa')); ?></p>
        </div>
    </div>

    <?php if (!$templateOk || !$zipOk): ?>
    <div class="card" style="border-right:4px solid #dc2626">
        <h3 class="font-bold text-sm" style="color:#b91c1c;margin-bottom:6px">پیش‌نیاز فراهم نیست</h3>
        <ul class="text-xs" style="line-height:1.9;margin-right:18px;list-style:disc">
            <?php if (!$templateOk): ?><li>فایل قالب <code>assets/templates/teacher-class-list.docx</code> پیدا نشد. آن را از بستهٔ بروزرسانی کپی کنید.</li><?php endif; ?>
            <?php if (!$zipOk): ?><li>افزونهٔ <code>ZipArchive</code> در PHP فعال نیست. برای ساخت فایل Word لازم است؛ از میزبان بخواهید فعالش کند.</li><?php endif; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- ═══ گزارش ۱: لیست کلاسی دبیر ═══ -->
    <div class="card">
        <div class="flex justify-between items-center flex-wrap gap-2" style="margin-bottom:12px">
            <div>
                <h3 class="font-bold text-sm">لیست کلاسی دبیر</h3>
                <p class="text-xs text-muted">
                    دو صفحه در قالب رسمی مدرسه: جدول حضور و غیاب و ارزشیابی (۳۰ ردیف) و جدول ثبت میزان تدریس.
                    اسامی به ترتیب الفبا و با فونت تیتر، در دو ستون «نام خانوادگی» و «نام» درج می‌شوند.
                    خروجی Word برای ویرایش، و خروجی PDF برای چاپ مستقیم.
                </p>
            </div>
            <?php if ($templateOk && $zipOk): ?>
                <a href="reports-lists.php?action=class_list_all" class="btn btn-accent text-xs">دانلود همهٔ کلاس‌ها (ZIP)</a>
            <?php endif; ?>
        </div>

        <?php if (!$classes): ?>
            <div class="text-center text-muted" style="font-size:13px;padding:16px">
                هیچ کلاسی برای سال تحصیلی <?php echo clean(tr_num($year, 'fa')); ?> ثبت نشده است.
            </div>
        <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>کلاس</th>
                        <th>پایه</th>
                        <th>کد روی لیست</th>
                        <th>تعداد دانش‌آموز</th>
                        <th>دریافت</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $c):
                    $cn = $c['class_name'];
                    $n  = (int)($counts[$cn] ?? 0);
                    $code = dcl_class_code($cn, $c['grade_level']);
                ?>
                    <tr>
                        <td class="font-bold"><?php echo clean($cn); ?></td>
                        <td><?php echo clean($c['grade_level'] ?: '—'); ?></td>
                        <td class="font-mono dir-ltr text-left"><?php echo clean($code); ?></td>
                        <td>
                            <?php echo clean(tr_num($n, 'fa')); ?>
                            <?php if ($n > 30): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;font-size:.65rem">بیش از ۳۰ نفر</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($n === 0): ?>
                                <span class="text-xs text-muted">دانش‌آموزی ندارد</span>
                            <?php else: ?>
                                <?php if ($templateOk && $zipOk): ?>
                                    <a class="btn btn-primary text-xs" href="reports-lists.php?action=class_list_docx&class=<?php echo urlencode($cn); ?>">Word</a>
                                <?php endif; ?>
                                <?php /* PDF به قالب docx وابسته نیست، پس همیشه در دسترس است */ ?>
                                <a class="btn btn-accent text-xs" target="_blank" rel="noopener"
                                   href="reports-lists.php?action=class_list_pdf&class=<?php echo urlencode($cn); ?>">پیش‌نمایش و PDF</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-xs text-muted" style="margin-top:10px">
            جدول لیست ۳۰ ردیف دارد. اگر کلاسی بیش از ۳۰ دانش‌آموز داشته باشد، نام‌های بعدی در فایل جا نمی‌شوند
            و باید برای آن کلاس یک برگهٔ دوم جداگانه چاپ شود.
        </p>
        <?php endif; ?>
    </div>

    <!-- جای گزارش‌های بعدی -->
    <div class="card" style="border:1px dashed var(--border-color);background:transparent">
        <p class="text-xs text-muted" style="line-height:1.9">
            گزارش‌های بعدی در همین صفحه اضافه می‌شوند. اگر لیست یا گزارش دیگری لازم دارید،
            فایل خام آن را بدهید تا با همین روش (حفظ قالب اصلی و پرکردن خودکار از بانک اطلاعاتی) ساخته شود.
        </p>
    </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
