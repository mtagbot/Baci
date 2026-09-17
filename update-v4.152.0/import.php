<?php
/** Restored from Maxess/mtagbot code.zip; validated, owner-bound and transactional. */
require_once __DIR__.'/includes/auth.php';
require_permission('import_data');
require_once __DIR__.'/includes/report_import_wizard.php';
$adminId=(int)$_SESSION['admin_id'];
$step=is_scalar($_GET['step']??null)?(int)$_GET['step']:1;
if (!in_array($step,[1,2,4],true)) $step=1;
$sessionId=is_scalar($_GET['session_id']??null)?(int)$_GET['session_id']:0;
$embedded=(($_GET['embedded']??'')==='1');
function ri_go($query='') { redirect('import.php'.($query!==''?'?'.$query.(($GLOBALS['embedded']??false)?'&embedded=1':''):(($GLOBALS['embedded']??false)?'?embedded=1':''))); }
if (($_GET['download']??'')==='template') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="report-import-template.csv"');
    echo "\xEF\xBB\xBFnational_id,first_name,last_name,class_name,academic_year,term,report_month,subject_name,score,max_score,coefficient\r\n"; exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!is_string($_POST['csrf_token']??null) || !verify_csrf($_POST['csrf_token'])) throw new InvalidArgumentException('اعتبار فرم منقضی شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.');
        $action=ri_text($_POST['action']??'');
        if ($action==='upload_file') {
            $file=$_FILES['import_file']??[];
            if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_uploaded_file($file['tmp_name'])) throw new InvalidArgumentException('بارگذاری فایل کامل نشد؛ فایل را دوباره انتخاب کنید.');
            $name=basename(ri_text($file['name']??'',255)); $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            $rows=ri_parse_file($file['tmp_name'],$ext); $check=ri_prepare($rows);
            // All rows are saved server-side, not just the first fifty. No public upload file remains.
            DB::execute("INSERT INTO import_sessions (admin_id,filename,file_type,status,total_rows,processed_rows,preview_data,ambiguities_data) VALUES (?,?,?,'preview',?,0,?,?)",[$adminId,$name,$ext,count($rows),json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),json_encode($check['errors'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            ri_go('step=2&session_id='.DB::lastInsertId());
        } elseif ($action==='execute_import') {
            $sid=(int)ri_text($_POST['session_id']??'0');
            $count=ri_execute($sid,$adminId,ri_text($_POST['target_academic_year']??''));
            set_flash_message('success','ایمپورت با موفقیت انجام شد؛ '.$count.' نمره ثبت شد.'); ri_go('step=4&session_id='.$sid);
        } else throw new InvalidArgumentException('عملیات ناشناخته است.');
    } catch (InvalidArgumentException $e) { set_flash_message('error',$e->getMessage()); ri_go(); }
    catch (Throwable $e) { error_log('Report import failed: '.$e->getMessage()); set_flash_message('error','عملیات انجام نشد؛ هیچ نمره‌ای از این درخواست ثبت نشد. فایل و اتصال پایگاه‌داده را بررسی کنید.'); ri_go(); }
}
$currentSession=$sessionId>0?DB::fetch('SELECT * FROM import_sessions WHERE id=? AND admin_id=?',[$sessionId,$adminId]):null;
if ($step===4 && (!$currentSession || $currentSession['status']!=='completed')) $step=2;
$recentSessions=DB::fetchAll('SELECT * FROM import_sessions WHERE admin_id=? ORDER BY id DESC LIMIT 10',[$adminId]);
$pageCss=['assets/css/report-import.css?v=20260918a'];
require_once __DIR__.'/includes/header.php';
?>
<div class="space-y-6 report-import-wizard">
    <div class="flex justify-between items-center">
        <div>
            <h2 class="text-2xl font-bold">ایمپورت چندمرحله‌ای (Wizard) کارنامه</h2>
            <p class="text-sm text-muted">آپلود، پیش‌نمایش، تشخیص ابهام، رفع تعارض‌ها و تایید نهایی</p>
        </div>
        <a href="import.php?download=template" download class="btn btn-outline gap-1 text-sm border-blue-500 text-blue-600">
            <span>دانلود نمونه فایل ساختاریافته (report-import-template.csv)</span>
        </a>
    </div>

    <!-- Wizard Bar -->
    <div class="wizard-steps card">
        <div class="wizard-step <?php echo $step >= 1 ? 'active' : ''; ?>">۱. بارگذاری فایل</div>
        <div class="wizard-step <?php echo $step >= 2 ? 'active' : ''; ?>">۲. پیش‌نمایش و بررسی ابهام</div>
        <div class="wizard-step <?php echo $step >= 3 ? 'active' : ''; ?>">۳. تایید نهایی و پردازش</div>
        <div class="wizard-step <?php echo $step >= 4 ? 'completed' : ''; ?>">۴. نتیجه اجرای ایمپورت</div>
    </div>

    <?php if ($step == 1): ?>
    <div class="grid grid-cols-2 gap-6">
        <div class="card">
            <h3 class="font-bold mb-4 text-primary">آپلود فایل جدید کارنامه‌ها</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                <input type="hidden" name="action" value="upload_file">
                <div class="mb-4">
                    <label for="importFile" class="block text-xs font-semibold mb-2">انتخاب فایل (CSV یا XLSX تک‌برگه)</label>
                    <input type="file" id="importFile" name="import_file" class="form-input p-2" accept=".csv,.xlsx" required>
                    <small class="text-xs text-muted block mt-1">حداکثر ۱۰ مگابایت و ۵۰۰۰ ردیف؛ CSV UTF-8. فرمول‌های Excel را به مقدار تبدیل کنید. XLS قدیمی را به CSV یا XLSX ذخیره کنید.</small>
                </div>
                <div class="p-3 bg-blue-50 rounded border border-blue-200 text-xs mb-4">
                    <p class="font-bold mb-1">راهنمای ستون‌های الزامی:</p>
                    <code style="display:block;overflow-wrap:anywhere;direction:ltr">national_id, first_name, last_name, class_name, academic_year, term, report_month, subject_name, score, coefficient</code><p>ابتدا دانش‌آموزان باید در سامانه موجود باشند. نمرهٔ ۲۱ غیبت است. تا تأیید نهایی هیچ نمره‌ای ثبت نمی‌شود.</p>
                </div>
                <button type="submit" class="btn btn-primary w-full py-2.5">آپلود و ورود به مرحله پیش‌نمایش &larr;</button>
            </form>
        </div>

        <div class="card">
            <h3 class="font-bold mb-4">نشست‌های اخیر ایمپورت (سوابق بارگذاری)</h3>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>شناسه</th>
                            <th>نام فایل</th>
                            <th>وضعیت</th>
                            <th>رکوردها</th>
                            <th>ادامه</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentSessions as $sess): ?>
                        <tr>
                            <td class="font-mono">#<?php echo $sess['id']; ?></td>
                            <td class="font-mono text-xs dir-ltr truncate max-w-xs"><?php echo clean($sess['filename']); ?></td>
                            <td>
                                <?php
                                if ($sess['status']==='completed') $statusBadge = '<span class="badge badge-success">تکمیل‌شده</span>';
                                elseif ($sess['status']==='preview') $statusBadge = '<span class="badge badge-warning">در انتظار تایید</span>';
                                else $statusBadge = '<span class="badge badge-info">' . clean($sess['status']) . '</span>';
                                echo $statusBadge;
                                ?>
                            </td>
                            <td><?php echo tr_num($sess['processed_rows'] . '/' . $sess['total_rows'], 'fa'); ?></td>
                            <td>
                                <?php if ($sess['status'] !== 'completed'): ?>
                                <a href="import.php?step=2&session_id=<?php echo $sess['id']; ?>" class="btn btn-secondary text-xs px-2 py-1">ادامه &larr;</a>
                                <?php else: ?>
                                <a href="import.php?step=4&session_id=<?php echo $sess['id']; ?>" class="text-xs text-primary">مشاهده</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; if (empty($recentSessions)): ?>
                        <tr><td colspan="5" class="text-center text-muted">تاکنون نشستی ثبت نشده است.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($step == 2):
        if (!$currentSession) {
            echo '<div class="card p-8 text-center"><p class="text-red-600 font-bold mb-4">خطا: اطلاعات پیش‌نمایش نشست ایمپورت یافت نشد.</p><a href="import.php" class="btn btn-primary">آپلود مجدد فایل &larr;</a></div>';
        } else {
            $previewData = json_decode($currentSession['preview_data'] ?? '[]', true) ?: [];
            $ambiguities = json_decode($currentSession['ambiguities_data'] ?? '[]', true) ?: [];
    ?>
    <div class="space-y-6">
        <div class="card bg-amber-50 border-amber-300 p-4">
            <div class="flex justify-between items-center flex-wrap gap-3">
                <div>
                    <h3 class="font-bold text-amber-800">بررسی اولیه فایل: <code><?php echo clean($currentSession['filename']); ?></code></h3>
                    <p class="text-xs text-amber-700 mt-1">تعداد کل رکوردهای شناسایی‌شده: <b><?php echo tr_num($currentSession['total_rows'], 'fa'); ?></b> ردیف | ابهامات شناسایی‌شده: <b><?php echo tr_num(count($ambiguities), 'fa'); ?></b> مورد</p>
                </div>
                <form method="POST" action="import.php" class="flex gap-2 items-end bg-white p-3 rounded border-2 border-indigo-100">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="execute_import">
                    <input type="hidden" name="session_id" value="<?php echo $currentSession['id']; ?>">
                    <div>
                        <label for="targetYear" class="text-xs font-bold">سال تحصیلی مقصد (انتخابی - فرمت جامع - جایگزین سال داخل فایل)</label>
                        <select id="targetYear" name="target_academic_year" class="form-select font-bold w-60">
                            <option value="">از داخل فایل (همان سال داخل CSV)</option>
                            <?php foreach(get_academic_years_for_filter() as $yy): ?>
                                <option value="<?php echo clean($yy['academic_year']); ?>" <?php echo get_current_academic_year()===$yy['academic_year']?'selected':''; ?>><?php echo clean($yy['academic_year']); ?><?php echo !empty($yy['is_default'])?' - پیش‌فرض':''; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-[10px] text-muted">فقط درس‌های داخل فایل افزوده یا جایگزین می‌شوند؛ سایر نمرات و اطلاعات دانش‌آموز تغییر نمی‌کنند.</div>
                    </div>
                    <button type="submit" <?php echo (!empty($ambiguities)||$currentSession['status']!=='preview')?'disabled':''; ?> class="btn btn-success px-6 py-2.5 font-bold shadow">
                        <span>تایید و ثبت در سال انتخابی &larr;</span>
                    </button>
                </form>
            </div>
        </div>

        <?php if (!empty($ambiguities)): ?>
        <div class="card border-red-300 bg-red-50">
            <h4 class="font-bold text-red-800 mb-3">موارد ابهام یا نقص اطلاعات (نیازمند توجه مدیر):</h4>
            <div class="table-container bg-white rounded border">
                <table>
                    <thead>
                        <tr>
                            <th>ردیف در فایل</th>
                            <th>نوع ابهام</th>
                            <th>توضیحات سیستم</th>
                            <th>نام دانش‌آموز در فایل</th>
                            <th>راهکار سیستم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($ambiguities, 0, 10) as $amb): ?>
                        <tr>
                            <td>ردیف #<?php echo $amb['row_index'] + 1; ?></td>
                            <td><span class="badge badge-danger">نیازمند اصلاح</span></td>
                            <td class="text-xs"><?php echo clean($amb['message']); ?></td>
                            <td class="font-bold"><?php echo clean(($amb['data']['first_name'] ?? '') . ' ' . ($amb['data']['last_name'] ?? '')); ?></td>
                            <td class="text-xs text-green-700 font-semibold">اصلاح فایل و بارگذاری مجدد؛ دانش‌آموز جدید ایجاد نمی‌شود.</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <h4 class="font-bold mb-4">پیش‌نمایش ۱۰ ردیف اول فایل بارگذاری‌شده:</h4>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>کد ملی</th>
                            <th>نام و نام خانوادگی</th>
                            <th>کلاس</th>
                            <th>درس</th>
                            <th>نمره</th>
                            <th>ضریب</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($previewData, 0, 10) as $row): ?>
                        <tr>
                            <td class="font-mono text-xs"><?php echo clean($row['national_id'] ?? '---'); ?></td>
                            <td class="font-bold"><?php echo clean(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); ?></td>
                            <td><?php echo clean($row['class_name'] ?? ''); ?></td>
                            <td><?php echo clean($row['subject_name'] ?? ''); ?></td>
                            <td class="font-bold text-primary"><?php echo clean($row['score'] ?? ''); ?></td>
                            <td><?php echo clean($row['coefficient'] ?? '1'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php } ?>

    <?php elseif ($step == 4 && $currentSession): ?>
    <div class="card text-center py-8">

        <h2 class="text-2xl font-bold text-green-600 mb-2">ایمپورت با موفقیت انجام شد!</h2>
        <p class="text-sm text-muted mb-6">تعداد <b><?php echo tr_num($currentSession['processed_rows'], 'fa'); ?></b> ردیف نمره در پایگاه داده ذخیره گردید.</p>
        <div class="flex justify-center gap-4">
            <a href="reports.php" class="btn btn-primary px-6">مشاهده کارنامه‌های ثبت‌شده</a>
            <a href="import.php" class="btn btn-secondary px-6">ایمپورت فایل جدید</a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
