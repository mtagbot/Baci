<?php
/**
 * هارنس — ساخت دادهٔ آزمایشی
 *
 * یک آزمون با «یکی از هر ۱۲ نوع سوال» می‌سازد. شکل question_data دقیقاً همان
 * چیزی است که calc_online_question_score() در includes/online_exam_helpers.php
 * انتظار دارد — اگر این شکل اشتباه باشد، نمره‌ها صفر می‌شوند و تست دروغ می‌گوید.
 *
 * انواع دستی (needs_manual=1، نمره ۰ تا تصحیح دبیر):
 *   text, short_text, file_upload, voice_upload, whiteboard
 * انواع خودکار: radio, checkbox, dropdown, number, fill_blank, matching
 * بدون پاسخ: info
 */
ini_set('display_errors', '0');
error_reporting(0);
@mkdir('/tmp/sess');
ini_set('session.save_path', '/tmp/sess');
session_name('BACI_TEST');
session_id('harnessSetup0001');
session_start();

require_once '/www/includes/functions.php';
require_once '/www/includes/db.php';

/* ── دانش‌آموزان ── */
$students = [
    ['1111111111', 'هارنس', 'یکم'],
    ['2222222222', 'هارنس', 'دوم'],
    ['3333333333', 'هارنس', 'سوم'],
];
$first = null;
foreach ($students as $s) {
    DB::execute("INSERT INTO students (national_id, first_name, last_name, is_temp) VALUES (?,?,?,0)", $s);
    if (!$first) $first = ['id' => (int)DB::lastInsertId(), 'first_name' => $s[1], 'last_name' => $s[2]];
}

/* ── آزمون ── */
DB::execute(
    "INSERT INTO online_exams (title, description, academic_year, duration_minutes, max_attempts,
        passing_score, status, is_active, enable_webcam, enable_location, enable_proctoring,
        enable_watermark, randomize_questions, settings_json)
     VALUES (?,?,?,?,?,?,?,?,0,0,0,0,0,'{}')",
    ['آزمون آزمایشی هارنس', 'ساخته‌شده توسط tests/harness/setup.php', '1404/1405',
     60, 1, 5, 'published', 1]
);
$examId = (int)DB::lastInsertId();

/* ── یکی از هر ۱۲ نوع سوال ── */
$defs = [
    'radio' => [2, ['options' => [
        ['id' => 'a', 'text' => 'اصفهان', 'is_correct' => false],
        ['id' => 'b', 'text' => 'تهران',  'is_correct' => true],
        ['id' => 'c', 'text' => 'شیراز',  'is_correct' => false],
    ]]],
    'checkbox' => [2, ['options' => [
        ['id' => 'a', 'text' => 'خزر',    'is_correct' => true],
        ['id' => 'b', 'text' => 'نیلس',   'is_correct' => false],
        ['id' => 'c', 'text' => 'ارومیه', 'is_correct' => true],
    ]]],
    'dropdown' => [2, ['options' => [
        ['id' => 'a', 'text' => 'یک', 'is_correct' => false],
        ['id' => 'b', 'text' => 'دو', 'is_correct' => true],
    ]]],
    'number'     => [1, ['correct_answer' => 42, 'tolerance' => 0.5]],
    'short_text' => [2, ['correct_answer' => 'تهران']],
    'text'       => [2, ['correct_answer' => 'پاسخ تشریحی']],
    'fill_blank' => [2, ['blanks' => [
        ['answer' => 'تهران'],
        ['answer' => '۴۲'],
    ]]],
    'matching' => [2, ['pairs' => [
        ['left' => 'پایتخت ایران', 'right' => 'تهران'],
        ['left' => 'بزرگ‌ترین دریاچه', 'right' => 'خزر'],
    ]]],
    'file_upload'  => [2, []],
    'voice_upload' => [2, []],
    'whiteboard'   => [2, []],
    'info'         => [0, ['message' => 'این سوال نمره ندارد']],
];

$qmap = [];
$i = 0;
foreach ($defs as $type => [$points, $data]) {
    $i++;
    DB::execute(
        "INSERT INTO online_questions (exam_id, question_type, question_text, question_data, points, order_index)
         VALUES (?,?,?,?,?,?)",
        [$examId, $type, "سوال آزمایشی از نوع {$type}", json_encode($data, JSON_UNESCAPED_UNICODE), $points, $i]
    );
    $qmap[$type] = (int)DB::lastInsertId();
}

echo "EXAM_ID={$examId}\n";
echo 'QUESTIONS=' . json_encode($qmap) . "\n";
echo 'STUDENT=' . json_encode($first, JSON_UNESCAPED_UNICODE) . "\n";
