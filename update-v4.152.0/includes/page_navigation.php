<?php
/** Navigation for early GET errors, before a page has emitted its normal shell.
 * POST/API error contracts are intentionally left unchanged; no response buffering.
 */
function app_page_error($message,$status=400) {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'||stripos($_SERVER['HTTP_ACCEPT']??'','application/json')!==false)die($message);
    http_response_code($status);header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>امکان نمایش صفحه نیست</title>';
    if(function_exists('app_appearance_head'))echo app_appearance_head();
    echo '</head><body style="margin:24px;line-height:2"><h1 style="font-size:20px">امکان نمایش صفحه نیست</h1><p>'.htmlspecialchars((string)$message,ENT_QUOTES,'UTF-8').'</p></body></html>';
    exit;
}
