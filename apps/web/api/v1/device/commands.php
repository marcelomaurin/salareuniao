<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('GET','POST');
$d=api_device($pdo);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $q=$pdo->prepare("SELECT id,command_type,payload,created_at
        FROM device_commands
        WHERE device_id=? AND status='pending'
        ORDER BY id ASC LIMIT 20");
    $q->execute([$d['id']]);
    $rows=$q->fetchAll();

    if($rows){
        $ids=array_map(fn($r)=>(int)$r['id'],$rows);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $up=$pdo->prepare("UPDATE device_commands SET status='delivered',delivered_at=NOW() WHERE id IN ($marks)");
        $up->execute($ids);
        foreach($rows as &$r){
            $r['payload']=$r['payload']?json_decode($r['payload'],true):[];
        }
    }
    api_json(['ok'=>true,'commands'=>$rows]);
}

$in=api_input();
$id=(int)($in['command_id']??0);
$status=(string)($in['status']??'acked');
if($id<=0)api_json(['ok'=>false,'error'=>'command_id_required'],422);
if(!in_array($status,['acked','failed'],true))api_json(['ok'=>false,'error'=>'invalid_status'],422);

$ack=$in['ack_payload']??[];
if(!is_array($ack))$ack=['value'=>$ack];

$q=$pdo->prepare("UPDATE device_commands
    SET status=?,acked_at=NOW(),ack_payload=?
    WHERE id=? AND device_id=?");
$q->execute([$status,json_encode($ack,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id,$d['id']]);
api_json(['ok'=>true]);
