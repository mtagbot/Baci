<?php
// SDP_REPORTS_ROOT_V1 — document root is SchoolDeskPro/, only reports/ is public.
// Always launched with this router; never expose data/, php/, config or executable files.
$raw = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rawurldecode(is_string($raw) ? $raw : '/');
function sdp_route_stop($status) { http_response_code($status); echo $status===403?'Forbidden':'Not Found'; return true; }
if ($uri==='' || $uri[0]!=='/' || preg_match('~[\x00-\x1f\x7f\\\\:]~',$uri) || preg_match('~%[a-f0-9]{2}~i',$uri) || preg_match('~(?:^|/)\.|[. ](?:/|$)~',$uri)) return sdp_route_stop(403);
$uri=preg_replace('~/+~','/',$uri);
$query = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
$suffix=is_string($query)&&$query!==''?'?'.$query:'';
$prefixed = strpos($uri,'/reports/')===0 || $uri==='/reports';
$local = $prefixed ? substr($uri,8) : $uri;
if ($local==='') $local='/';
// Check the app-relative path on BOTH canonical and legacy URLs before any redirect or file access.
function sdp_route_private($local) {
    return preg_match('~^/(?:config|includes|sql|vendor|backups|data|php|reports-layout-update)(?:/|$)~i',$local) || preg_match('~\.(?:sqlite(?:3|-wal|-shm)?|db|sql|ini|log|bak|backup|env|lock|py|md|json|exe|dll|bat|cmd|vbs|ps1)$~i',$local) || preg_match('~^/(?:uploads|assets)/.*\.(?:php\d?|phtml|phar|cgi|pl|py|sh)(?:/|$)~i',$local) || preg_match('~\.(?:phtml|phar|cgi|pl|sh|php\d+)(?:/|$)~i',$local) || preg_match('~(?:^|/)\.~',$local) || in_array(strtolower($local),['/router.php','/desk-prepend.php','/desk-sync-daemon.php'],true);
}
if (sdp_route_private($local)) return sdp_route_stop(403);
$root=realpath(__DIR__);
$file=realpath(__DIR__.$local);
// realpath containment prevents symlinks/junctions leading out of the web payload.
if ($file!==false && $file!==$root && strncasecmp($file,$root.DIRECTORY_SEPARATOR,strlen($root)+1)!==0) return sdp_route_stop(403);
// Resolve aliases only for app paths, not arbitrary siblings of reports/.
if (!$prefixed) {
    if ($local!=='/' && ($file===false || (!is_file($file) && !is_dir($file)))) return sdp_route_stop(404);
    header('Location: /reports'.str_replace('%2F','/',rawurlencode($local)).$suffix,true,307); return true;
}
if ($uri==='/reports') { header('Location: /reports/'.$suffix,true,307); return true; }
// Defense in depth for alternate filesystem spellings and symlinks inside the app.
if ($file!==false) {
    $resolved='/'.str_replace(DIRECTORY_SEPARATOR,'/',substr($file,strlen($root)+1));
    if (sdp_route_private($resolved)) return sdp_route_stop(403);
    if (is_file($file) && strtolower(pathinfo($file,PATHINFO_EXTENSION))==='php' && strtolower(pathinfo($local,PATHINFO_EXTENSION))!=='php') return sdp_route_stop(403);
}
$installed=is_file(__DIR__.'/config/installed.lock')&&is_file(__DIR__.'/config/database.php');
if ($file===false) return sdp_route_stop(404);
if (is_dir($file) && preg_match('~^/(?:assets|uploads)(?:/|$)~i',$resolved)) return sdp_route_stop(404);
if (!$installed && $local!=='/installer.php' && (is_dir($file) || strtolower(pathinfo($file,PATHINFO_EXTENSION))==='php')) {
    header('Location: /reports/installer.php',true,302); return true;
}
// Missing assets must never execute index.php or regenerate a captcha.
if ($file===false) return sdp_route_stop(404);
if (is_dir($file)) {
    if (!is_file($file.'/index.php')) return sdp_route_stop(404);
    if (substr($uri,-1)!=='/') { header('Location: '.str_replace('%2F','/',rawurlencode($uri)).'/'.$suffix,true,307); return true; }
}
if ($installed && is_file(__DIR__.'/desk-prepend.php')) require_once __DIR__.'/desk-prepend.php';
// Delegate normal PHP/static handling (superglobals, multipart bodies, MIME) to the built-in server.
return false;
