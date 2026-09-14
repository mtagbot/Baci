<?php
/**
 * System Defaults & Definitions
 */
return [
    'version' => '2.5.0',
    'app_name' => 'سیستم مدیریت کارنامه دانش‌آموزی',
    'default_theme' => [
        'theme_color' => '#2563eb',
        'theme_mode'  => 'light',
    ],
    'roles' => [
        'super_admin' => 'سوپر ادمین (دسترسی کامل)',
        'edu_admin'   => 'مدیر آموزشی',
        'observer'    => 'ناظر / مشاهده‌گر',
    ],
    'permissions' => [
        'manage_students' => 'مدیریت دانش‌آموزان',
        'manage_classes'  => 'مدیریت کلاس‌ها و دروس',
        'manage_reports'  => 'مدیریت و ثبت کارنامه‌ها',
        'import_data'     => 'ایمپورت فایل CSV / XLSX',
        'manage_backups'  => 'پشتیبان‌گیری و بازیابی دیتابیس',
        'view_logs'       => 'مشاهده لاگ فعالیت‌ها',
        'send_sms'        => 'ارسال پیامک و مدیریت اعلان‌ها',
        'system_settings' => 'تنظیمات ظاهری و عمومی سیستم',
    ],
    'sms_providers' => [
        'kavenegar'    => 'کاوه نگار (Kavenegar)',
        'smsir'        => 'اس ام اس دات آی آر (Sms.ir)',
        'melipayamak'  => 'ملی پیامک (Melipayamak)',
        'farazsms'     => 'فراز اس ام اس (FarazSMS)',
        'mock'         => 'شبیه‌ساز (ثبت در لاگ و دیتابیس)',
    ]
];
