<?php
/**
 * هارنس — اجرای یک درخواست واقعی
 *
 * ترتیب بارگذاری حیاتی است:
 *   ۱) session اول از همه (قبل از هر echo) — وگرنه session_start() خود سایت خطا
 *      می‌دهد و همهٔ صفحه‌ها به صفحهٔ ورود redirect می‌شوند.
 *   ۲) echo " " بعد از session → headers_sent() راست می‌شود → redirect() به‌جای
 *      header() هدف را echo می‌کند. در SAPI خط فرمان header() بی‌اثر است، پس این
 *      تنها راه گرفتن مقصد redirect است.
 *   ۳) include صفحهٔ واقعی سایت — نه بازنویسی آن.
 */
$spec = json_decode(file_get_contents('/harness/req.json'), true) ?: [];

$file    = $spec['file']    ?? 'index.php';
$query   = $spec['query']   ?? '';
$method  = strtoupper($spec['method'] ?? 'GET');
$get     = $spec['get']  ?? [];
$post    = $spec['post'] ?? [];
$sid     = $spec['session_id'] ?? 'harnessStu0001';

/* اگر query string داده شد، به $_GET تجزیه شود */
if ($query !== '') parse_str($query, $get);

/* ── ۱) نشست ── */
@mkdir('/tmp/sess');
ini_set('session.save_path', '/tmp/sess');
session_name('BACI_TEST');
session_id($sid);
session_start();

/* ── ۲) headers_sent ── */
echo " ";

/* ── ۳) superglobalها ── */
$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['REQUEST_URI']    = '/' . $file . ($query !== '' ? '?' . $query : '');
$_SERVER['SCRIPT_NAME']    = '/' . $file;
$_SERVER['PHP_SELF']       = '/' . $file;
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['HTTP_USER_AGENT'] = 'BaciTestHarness/1.0';
$_SERVER['HTTPS']          = '';
$_GET = $get;
$_POST = $post;
$_FILES = $spec['files'] ?? [];
$_COOKIE = $spec['cookies'] ?? [];
$_REQUEST = array_merge($_GET, $_POST);

/* ── ۴) گرفتن خطاها ── */
$GLOBALS['__harness_error'] = '';
set_error_handler(function ($no, $str, $f, $l) {
    $GLOBALS['__harness_error'] .= "[$no] $str ($f:$l)\n";
    return true;
});

/* ── ۵) ثبت نتیجه در پایان ── */
register_shutdown_function(function () use ($file, $query, $sid) {
    $out = '';
    while (ob_get_level() > 0) { $out = ob_get_clean() . $out; }

    $fatal = null;
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $fatal = $e['message'] . ' (' . $e['file'] . ':' . $e['line'] . ')';
    }

    $flash = null;
    if (!empty($_SESSION['flash_message'])) {
        $flash = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);   // مثل مرورگر واقعی: یک‌بار خوانده می‌شود
    }

    $res = [
        'file'         => $file,
        'query'        => $query,
        'output_len'   => strlen($out),
        'raw_head'     => substr($out, 0, 900),
        'has_exam_ui'  => strpos($out, 'question-box') !== false,
        'has_examform' => strpos($out, 'id="examForm"') !== false,
        'has_header'   => strpos($out, 'sidebar-item') !== false,
        'flash'        => $flash,
        'fatal'        => $fatal,
        'form_action'  => (preg_match('/<form id="examForm"[^>]*>/u', $out, $m) ? $m[0] : ''),
        'radio_inputs' => preg_match_all('/type="radio"/', $out),
        'q_boxes'      => preg_match_all('/class="question-box/', $out),
        'php_issues'   => trim((string)($GLOBALS['__harness_error'] ?? '')),
        'student_sess' => $_SESSION['student_id'] ?? null,
    ];
    file_put_contents('/harness/result.json', json_encode($res, JSON_UNESCAPED_UNICODE));
});

/* ── ۶) اجرای صفحهٔ واقعی ── */
chdir('/www');
ob_start();
include '/www/' . $file;
