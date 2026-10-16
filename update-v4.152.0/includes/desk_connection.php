<?php
/**
 * v2.107.0: heartbeat evidence, still pure and still credential-free.
 *
 * A successful full cycle writes `desk_sync_verified_at` + `desk_sync_last_ok`
 * in the local database (see DeskSync::run), and a good idle probe writes
 * `desk_sync_verified_at` too (see DeskSync::tick). Old/hand-patched background
 * workers write a heartbeat file without the `server_verified` field, and the
 * dashboard used to sit on «در انتظار وضعیت همگام‌سازی» forever because of it —
 * even seconds after a successful manual sync. This helper only ADDS the
 * missing flag, in two cases:
 *   1) the heartbeat is alive and reports no problem, while the local engine
 *      recorded a real exchange within $idleMaxAge (default 1h) — an idle
 *      school machine is not a broken one;
 *   2) there is no usable heartbeat at all (the worker is between two loops),
 *      but an exchange succeeded within $maxAge (default 90s).
 * Any real problem (fails/offline/backoff/error/probe_failed/disabled/syncing)
 * is preserved untouched, so nothing is masked — the reducer stays strict.
 */
function desk_connection_verified_heartbeat($heartbeat,$verifiedAt,$lastOk,$now,$maxAge=90,$idleMaxAge=3600) {
    $h=is_array($heartbeat)?$heartbeat:[];
    $ts=(int)($h['ts']??0);
    $fresh=$ts>0&&$ts<=$now+5&&$now-$ts<30;
    /* A fresh heartbeat that reports a real problem must always win: nothing is
       masked, a cut link or a rejected answer still shows as an alarm. */
    if($fresh){
        if(!empty($h['fails'])||!empty($h['error'])||!empty($h['probe_failed']))return $h;
        if(($h['phase']??'')==='syncing')return $h;
        if(in_array((string)($h['skipped']??''),['offline','backoff','disabled'],true))return $h;
        if(($h['server_verified']??false)===true)return $h;    // already proven by the worker itself
        /* v2.107.0: a living worker that has nothing to do is NOT a problem.
           Full cycles are rare (5-minute safety cycle, edit-driven otherwise,
           hourly in event mode), so an idle school machine used to flip to
           «در انتظار وضعیت همگام‌سازی» seconds after a successful sync even
           though every exchange so far had worked. Here the local record of a
           real exchange (desk_sync_last_ok / desk_sync_verified_at, written only
           after the site answered) counts as proof for $idleMaxAge. */
        $idleProof=max((int)$verifiedAt,(int)$lastOk);
        if($idleProof<=0||$idleProof>$now+5||$now-$idleProof>max(1,(int)$idleMaxAge))return $h;
        return desk_connection_verified_copy($h,max($ts,$idleProof));
    }
    /* No usable heartbeat at all: only an exchange from moments ago justifies
       speaking for the worker (e.g. the manual button ran while the daemon was
       between two loops). A stale heartbeat is otherwise left stale. */
    $proof=max((int)$verifiedAt,(int)$lastOk);
    if($proof<=0||$proof>$now+5||$now-$proof>max(1,(int)$maxAge))return $h;
    return desk_connection_verified_copy($h,max($ts,$proof));
}
/** Copy the heartbeat with the verified flag added; unrelated fields survive. */
function desk_connection_verified_copy(array $h,$ts) {
    $out=$h;                        // keep every unrelated field (warning, retry_in, update…)
    $out['ts']=$ts;
    $out['phase']='settled';
    $out['ok']=true;
    $out['server_verified']=true;
    $out['skipped']='idle';
    $out['fails']=0;
    $out['error']='';
    unset($out['probe_failed']);
    $out['proof']='local';          // local successful exchange, not a daemon report
    return $out;
}
/** Pure status reduction; no network I/O and no credentials in the public result. */
function desk_connection_snapshot($enabled,$heartbeat,$pending,$lastOk,$snapshot,$now,$hasIssue=false) {
    $h=is_array($heartbeat)?$heartbeat:[];$ts=(int)($h['ts']??0);
    $fresh=$ts>0&&$ts<=$now+5&&$now-$ts<30;
    $out=['state'=>'waiting','server'=>'unknown','pending'=>max(0,(int)$pending),'last_success'=>max(0,(int)$lastOk),'checked_at'=>$fresh?$ts:0,'retry_in'=>0,'warning'=>$hasIssue||!empty($h['warning'])];
    if(!$enabled){$out['state']='disabled';return $out;}
    if(!$fresh)return $out;
    if(($h['phase']??'')==='syncing'){$out['state']='syncing';return $out;}
    $skip=$h['skipped']??'';
    if($skip==='disabled')return $out; // configuration changed since the previous worker result
    if(!empty($h['fails'])||in_array($skip,['offline','backoff'],true)){
        $out['state']='retrying';$out['server']='unreachable';$out['retry_in']=max(0,(int)($h['retry_in']??0)-max(0,$now-$ts));return $out;
    }
    if(!empty($h['error'])||!empty($h['probe_failed'])||empty($h['ok'])){$out['state']='error';return $out;}
    if($skip!==''&&$skip!=='idle')return $out; // locked/recent are NOT a successful server check
    if(($h['server_verified']??false)!==true)return $out; // older workers may report idle even after a rejected ping
    $out['server']='reachable';
    $out['state']=$out['warning']?'warning':($pending>0?'queued':($snapshot&&$lastOk>0?'synced':'waiting'));
    return $out;
}
