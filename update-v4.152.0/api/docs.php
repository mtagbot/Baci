<!-- File: api/docs.php -->
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="UTF-8">
    <title>مستندات رابط برنامه‌نویسی (API Documentation)</title>
    <style>
        @font-face { font-family: 'Vazirmatn'; src: url('../uploads/Vazirmatn/Vazirmatn-Regular.woff2') format('woff2'), url('../uploads/Vazirmatn/Vazirmatn-Regular.ttf') format('truetype'); font-display: swap; }
        @font-face { font-family: 'Vazirmatn'; src: url('../uploads/Vazirmatn/Vazirmatn-Bold.woff2') format('woff2'), url('../uploads/Vazirmatn/Vazirmatn-Bold.ttf') format('truetype'); font-weight: 700; font-display: swap; }
        body { font-family: 'Vazirmatn', Tahoma, sans-serif; background: #f8fafc; color: #1e293b; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; }
        .card { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .endpoint { border-right: 4px solid #2563eb; background: #f1f5f9; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .method { background: #2563eb; color: #fff; padding: 3px 8px; border-radius: 4px; font-weight: bold; font-size: 12px; }
        .method.post { background: #16a34a; }
        code { font-family: monospace; direction: ltr; display: inline-block; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; }
    </style>
<link rel="stylesheet" href="../assets/css/school-ui.css?v20260917d"><script defer src="../assets/js/school-icons.js?v20260917d"></script><script defer src="../assets/js/school-ui.js?v20260917d"></script></head>
<body class="ui-docs">
<div class="container">
    <div class="card">
        <h1 style="color: #2563eb;"><svg data-ui-icon="book" class="school-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 5C8 2 4 3 2 4v15c3-1 7-1 10 2 3-3 7-3 10-2V4c-3-1-7-2-10 1Zm0 0v16"/></svg> مستندات جامع API (موبایل و مدیریت)</h1>
        <p>سیستم مدیریت کارنامه دانش‌آموزی - نگارش 2.5.0</p>
        <div style="margin-top: 15px;">
            <a href="openapi.json" target="_blank" style="margin-left: 15px; color: #2563eb;">مشاهده فایل مشخصات openapi.json &larr;</a>
            <a href="openapi-admin-import.json" target="_blank" style="color: #16a34a;">مشاهده مشخصات openapi-admin-import.json &larr;</a>
        </div>
    </div>

    <div class="card">
        <h2>رابط‌های موبایل و اپلیکیشن دانش‌آموزی</h2>
        
        <div class="endpoint">
            <span class="method">GET</span> <code>api/index.php?action=config</code>
            <p>دریافت اطلاعات مدرسه، نسخه سیستم، رنگ پوسته و تاریخ شمسی سرور.</p>
        </div>

        <div class="endpoint">
            <span class="method post">POST</span> <code>api/index.php?action=student_login</code>
            <p>ورود دانش‌آموز با پارامترهای <code>national_id</code> و <code>password</code>. بازگشت Access Token و Refresh Token.</p>
        </div>

        <div class="endpoint">
            <span class="method post">POST</span> <code>api/index.php?action=admin_login</code>
            <p>ورود مدیر با <code>username</code> و <code>password</code>.</p>
        </div>

        <div class="endpoint">
            <span class="method">GET</span> <code>api/index.php?action=reports</code>
            <p>دریافت لیست کارنامه‌های دانش‌آموز (نیازمند هدر <code>Authorization: Bearer [token]</code>).</p>
        </div>

        <div class="endpoint">
            <span class="method">GET</span> <code>api/index.php?action=report_detail&id=[ID]</code>
            <p>دریافت جزئیات و نمرات یک کارنامه خاص.</p>
        </div>
    </div>

    <div class="card">
        <h2>رابط‌های مدیریتی ایمپورت و صف پردازش</h2>
        
        <div class="endpoint">
            <span class="method">GET</span> <code>api/admin-import.php?resource=sessions</code>
            <p>دریافت لیست تمامی نشست‌های ایمپورت چندمرحله‌ای.</p>
        </div>

        <div class="endpoint">
            <span class="method">GET</span> <code>api/admin-import.php?resource=queue</code>
            <p>مشاهده وضعیت فایل‌های در صف پردازش پس‌زمینه.</p>
        </div>
    </div>
</div>
</body>
</html>
