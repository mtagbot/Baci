<?php
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
