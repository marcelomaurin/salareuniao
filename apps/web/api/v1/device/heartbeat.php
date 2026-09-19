<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('POST');
$d=api_device($pdo);
$in=api_input();

$status=trim((string)($in['status']??'online'));
$firmware=trim((string)($in['firmware_version']??$d['firmware_version']??''));
if($status==='')$status='online';

$pdo->prepare('UPDATE devices SET status=?,firmware_version=?,ip_address=?,last_seen_at=NOW() WHERE id=?')
    ->execute([$status,$firmware?:null,api_client_ip(),$d['id']]);

$payload=[
    'status'=>$status,
    'firmware_version'=>$firmware,
    'free_heap'=>$in['free_heap']??null,
    'rssi'=>$in['rssi']??null,
    'uptime_seconds'=>$in['uptime_seconds']??null,
];
$pdo->prepare("INSERT INTO device_events(device_id,event_type,payload) VALUES(?, 'device.heartbeat', ?)")
    ->execute([$d['id'],json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);

api_json(['ok'=>true,'server_time'=>date(DATE_ATOM),'device'=>[
    'id'=>(int)$d['id'],'device_uid'=>$d['device_uid'],'room_id'=>$d['room_id']
]]);
