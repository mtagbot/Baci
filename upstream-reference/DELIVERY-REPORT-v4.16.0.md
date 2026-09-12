# گزارش تحویل v4.16.0

## خروجی‌ها

- `PROJECT-CODE-BUNDLE-v4.16.0.json`
- `FULL-PROJECT-v4.16.0.zip`
- `MODIFIED-FILES-v4.16.0.zip`

## اصلاحات انجام‌شده

### ۱. رفع مشکل طراحی زنده آزمون در مینی‌اپ بله/تلگرام

برای لینک‌های طراحی زنده آزمون، token امن اضافه شد:

- `make_exam_design_token()`
- `verify_exam_design_token()`

اکنون اگر صفحه طراحی آزمون از مینی‌اپ به مرورگر دیگری منتقل شود، با token امن امکان ادامه کار طراحی وجود دارد و پیام «غیرمجاز» نمایش داده نمی‌شود.

فایل‌های تغییر یافته:

- `includes/functions.php`
- `exam-print.php`
- `exam-design-api.php`
- `teacher-panel.php`
- `exams.php`

### ۲. session و remember-login پایدارتر

علاوه بر session یک‌ماهه، remember-login امن اضافه شد.

- کاربر حتی با تغییر اینترنت/IP تا زمانی که کوکی مرورگر را داشته باشد، وارد حساب باقی می‌ماند.
- خروج دستی، remember-login را پاک می‌کند.

### ۳. رفع flash حالت روشن قبل از تاریک شدن

در `includes/header.php` اسکریپت کوچکی قبل از بارگذاری CSS اضافه شد تا اگر کاربر حالت تاریک را انتخاب کرده باشد، کلاس `dark` قبل از رندر صفحه اعمال شود.

### ۴. بهبود خوانایی حالت تاریک

در `assets/css/style.css` قواعد تکمیلی اضافه شد تا متن‌های مشکی باقی‌مانده در حالت تاریک، روشن و خوانا شوند.

### ۵. بهبود هدر موبایل

در `assets/css/style.css`:

- هدر موبایل فشرده‌تر شد.
- تاریخ در موبایل مخفی شد.
- عنوان مدرسه کوتاه/ellipsis می‌شود.
- منوی کاربری در موبایل بهتر نمایش داده می‌شود.

### ۶. اصلاح لینک‌های طراحی سوال

در `teacher-panel.php` و `exams.php` لینک‌های طراحی سوال با token امن ساخته می‌شوند.

## فایل‌های تغییر یافته

- `includes/functions.php`
- `includes/header.php`
- `assets/css/style.css`
- `exam-print.php`
- `exam-design-api.php`
- `teacher-panel.php`
- `exams.php`
