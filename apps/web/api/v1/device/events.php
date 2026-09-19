<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('POST');
$d=api_device($pdo);
$in=api_input();

$type=trim((string)($in['type']??'device.telemetry'));
if($type===''||mb_strlen($type)>80)api_json(['ok'=>false,'error'=>'invalid_event_type'],422);
$payload=$in['payload']??[];
if(!is_array($payload))api_json(['ok'=>false,'error'=>'payload_must_be_object'],422);

$q=$pdo->prepare('INSERT INTO device_events(device_id,event_type,payload) VALUES(?,?,?)');
$q->execute([$d['id'],$type,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
$pdo->prepare('UPDATE devices SET ip_address=?,last_seen_at=NOW(),status=IF(status=\'offline\',\'online\',status) WHERE id=?')
    ->execute([api_client_ip(),$d['id']]);

api_json(['ok'=>true,'event_id'=>(int)$pdo->lastInsertId()],201);
