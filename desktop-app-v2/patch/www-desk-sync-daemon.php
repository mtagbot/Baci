<?php
// File: desk-sync-daemon.php  (SchoolDesk Pro — background sync worker)
/**
 * v2.11.0: ALL sync network I/O now happens here, in a separate hidden
 * PHP process — never inside a page request.
 *
 * Why: the desktop serves the whole app from ONE PHP worker. Previously the
 * 5-second heartbeat executed the sync (with its network timeouts) inside
 * that worker, so while the internet was slow or down, every click in the
 * app queued behind a stuck network call — that was the "slow without
 * internet" problem. Now page requests only read a status file (instant),
 * and this daemon does the waiting.
 *
 * Lifecycle: spawned automatically by desk-prepend.php on app start (and
 * re-spawned if it ever dies). Exits by itself when the app has been
 * closed for a while. Single-instance via an exclusive file lock.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit('cli only'); }

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
set_time_limit(0);

$root    = dirname(__DIR__);              // SchoolDeskPro/
$dataDir = $root . '/data';
if (!is_dir($dataDir)) @mkdir($dataDir, 0777, true);

require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/desk_sync.php';

/* single instance */
$lockFh = @fopen($dataDir . '/sync-daemon.lock', 'c');
if (!$lockFh || !@flock($lockFh, LOCK_EX | LOCK_NB)) exit(0);

$hbFile    = $dataDir . '/sync-heartbeat.json';
$aliveFile = $dataDir . '/app-alive.txt';
$kickFile  = $dataDir . '/sync-kick.txt';

function desk_write_heartbeat($file,$value) {
    $temp=$file.'.'.getmypid().'.tmp';
    if(@file_put_contents($temp,json_encode($value,JSON_UNESCAPED_UNICODE),LOCK_EX)!==false){
        if(!@rename($temp,$file))@unlink($temp);
    }
}
while (true) {
    // Publish actual worker activity before potentially slow remote I/O.
    desk_write_heartbeat($hbFile,['phase'=>'syncing','ts'=>time(),'daemon'=>1]);
    $res = ['ok' => false];
    try {
        $res = DeskSync::tick();
    } catch (Throwable $e) {
        $res = ['ok' => false, 'error' => $e->getMessage(),
                'pending' => DeskSync::pendingCount(), 'fails' => 0, 'alert' => false];
    }
    if (!is_array($res)) $res = ['ok' => false];
    $res['ts']     = time();
    $res['daemon'] = 1;
    $res['phase']='settled';
    desk_write_heartbeat($hbFile,$res);

    // app closed? (no page request refreshed app-alive for 15 minutes) → stop
    if (!is_file($aliveFile) || time() - (int)@filemtime($aliveFile) > 900) break;

    /* v2.12.0: sync must follow EVERY change immediately. Any page request
       that modified data touches sync-kick.txt (see desk-prepend.php);
       we sleep in 1-second slices and wake instantly on that signal, so a
       change reaches the site within ~1 second instead of up to 5. */
    for ($i = 0; $i < 5; $i++) {
        if (is_file($kickFile)) { @unlink($kickFile); break; }
        sleep(1);
    }
}
@flock($lockFh, LOCK_UN);
@fclose($lockFh);
