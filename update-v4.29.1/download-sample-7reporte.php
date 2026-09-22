<?php
// File: download-sample-7reporte.php - v4.29.1
/**
 * Download sample CSV template for the new comprehensive student import format (7reporte.csv - 31 columns)
 */
require_once __DIR__ . '/includes/auth.php';
require_permission('manage_students');

$filename = '7reporte-sample.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens Persian text correctly
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, [
    'Datastudents_lastYearAverage',
    'Datastudents_password',
    'Datastudents_code',
    'Datastudents_postalCode',
    'Datastudents_homeAddress',
    'Datastudents_homePhone',
    'Datastudents_religionTitle',
    'Datastudents_nationalityTitle',
    'Datastudents_coverTitle',
    'Datastudents_specificDiseaseExactTitle',
    'Datastudents_housingTitle',
    'Datastudents_motherMobile',
    'Datastudents_motherQualification',
    'Datastudents_motherJobExactTitle',
    'Datastudents_fatherMobile',
    'Datastudents_fatherQualification',
    'Datastudents_fatherJobExactTitle',
    'Datastudents_placeIssued',
    'Datastudents_birthPlace',
    'Datastudents_birthYear',
    'Datastudents_birthMonth',
    'Datastudents_birthDay',
    'Datastudents_motherName',
    'Datastudents_mobile',
    'Datastudents_fatherName',
    'Datastudents_nationalCode',
    'Datastudents_serial',
    'Datastudents_className',
    'Datastudents_firstName',
    'Datastudents_lastName',
    'Text1',
]);

// Sample row (fictional data)
fputcsv($out, [
    '19/25',            // معدل سال قبل
    '95843c33',         // پسورد (اختیاری - در صورت نبود سریال استفاده می‌شود)
    '',                 // کد دانش‌آموزی
    '1234567890',       // کد پستی 10 رقمی
    'تهران، خیابان نمونه، پلاک 1', // آدرس منزل
    '02112345678',      // تلفن منزل
    'اسلام-شیعه',       // دین
    'ایرانی',           // ملیت (غیر از ایرانی = اتباع)
    'بیمه سلامت',       // پوشش بیمه
    '',                 // بیماری خاص / محدودیت ورزش
    'مالک',             // وضعیت مسکن
    '9120000001',       // موبایل مادر
    'دیپلم',            // تحصیلات مادر
    'خانه‌دار',          // شغل مادر
    '9120000002',       // موبایل پدر
    'لیسانس',           // تحصیلات پدر
    'کارمند',           // شغل پدر
    'تهران',            // محل صدور
    'تهران',            // محل تولد
    '1393',             // سال تولد (4 رقمی)
    '04',               // ماه تولد (2 رقمی)
    '20',               // روز تولد (2 رقمی)
    'زهرا محمدی',       // نام و نام خانوادگی مادر
    '',                 // موبایل دانش‌آموز
    'علی',              // نام پدر
    '0012345678',       // کد ملی 10 رقمی
    'ب/39/237023',      // سریال شناسنامه (6 رقم آخر = رمز عبور)
    'هفتم1',            // کلاس
    'محمد',             // نام
    'رضایی',            // نام خانوادگی
    '10',               // Text1
]);

fclose($out);
exit;
