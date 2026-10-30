SchoolDesk Pro — پیکربندی سرور برای حداکثر سرعت (V4.209.0)
============================================================

از V4.209.0 بخشی از این تنظیمات به‌صورت خودکار همراه بسته می‌آید:
  · .htaccess ریشه (AddType فونت‌ها، فشرده‌سازی، ۴۰۴ سریع استاتیک)
  · assets/fonts/.htaccess (کش ۳۰ روزهٔ فونت + نوع MIME درست)
  · assets/css/.htaccess و assets/js/.htaccess (کش یک‌ساعته)
پس روی Apache کار شما معمولاً تمام است. اگر nginx دارید یا می‌خواهید
OPcache را هم روشن کنید، سه فایل این پوشه را ببینید:

۱) nginx-performance.conf
   محتوای این فایل را داخل بلوک server { ... } سایت قرار دهید.
   مهم‌ترین خط: try_files $uri =404 برای /assets/ و /uploads/
   (فایل استاتیک گم‌شده نباید به PHP برسد؛ در trace واقعی یک فونت
   ناموجود ۷۲۵ میلی‌ثانیه طول کشید چون کل PHP اجرا می‌شد.)
   سپس: sudo nginx -t && sudo systemctl reload nginx

۲) apache-performance.htaccess
   مرجع کامل تنظیمات Apache. لازم نیست کپی شود (نسخهٔ لازم از V4.209.0
   خودکار در .htaccess هست)؛ برای مرور و تنظیمات دلخواه است.

۳) php-performance.ini
   این مقادیر را در php.ini یا در فایل conf.d اضافه کنید و PHP-FPM را
   restart کنید. مهم‌ترین‌ها:
     opcache.enable=1
     opcache.validate_timestamps=0   (بعد از هر آپدیت، restart لازم است)
     opcache.max_accelerated_files=20000
     realpath_cache_size=4M
   روی هاست اشتراکی معمولاً فقط بخش OPcache قابل تنظیم است.

نکته
----
اگر سایت در زیرمسیر نصب شده است (مثلاً /school/)، همهٔ locationها باید با
همان پیشوند نوشته شوند.

پس از اعمال تنظیمات، یک‌بار وارد پنل شوید و از
«تنظیمات دیگر ← کارایی و سرعت» دکمهٔ «پاک‌سازی کش و بازسازی باندل‌ها»
را بزنید تا باندل‌های CSS/JS تازه ساخته شوند.
