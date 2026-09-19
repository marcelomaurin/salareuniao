<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET','POST');
$admin=api_admin($pdo);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $q=$pdo->query("SELECT d.*,
      r.name room_name,
      (d.last_seen_at IS NOT NULL AND d.last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) online
      FROM devices d
      LEFT JOIN rooms r ON r.id=d.room_id
      ORDER BY d.name");
    api_json(['ok'=>true,'devices'=>$q->fetchAll()]);
}

$in=api_input();
$action=(string)($in['action']??'create');

if($action==='create'){
    $name=trim((string)($in['name']??''));
    $uid=trim((string)($in['device_uid']??''));
    $type=(string)($in['type']??'esp32');
    $roomId=!empty($in['room_id'])?(int)$in['room_id']:null;
    if($name===''||$uid==='')api_json(['ok'=>false,'error'=>'name_and_device_uid_required'],422);
    if(!in_array($type,['esp32','esp8266','desktop','other'],true))api_json(['ok'=>false,'error'=>'invalid_device_type'],422);

    $pdo->beginTransaction();
    try{
        $q=$pdo->prepare('INSERT INTO devices(name,device_uid,room_id,type,active,status) VALUES(?,?,?,?,1,?)');
        $q->execute([$name,$uid,$roomId,$type,'offline']);
        $deviceId=(int)$pdo->lastInsertId();

        $token=bin2hex(random_bytes(32));
        $hash=hash('sha256',$token);
        $ttl=(int)($config['api']['device_token_ttl']??0);
        $expires=$ttl>0?(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s'):null;
        $t=$pdo->prepare('INSERT INTO device_tokens(device_id,token_hash,expires_at) VALUES(?,?,?)');
        $t->execute([$deviceId,$hash,$expires]);
        $pdo->commit();
        api_audit($pdo,$admin,'device.create','device',$deviceId,['name'=>$name,'uid'=>$uid,'type'=>$type,'room_id'=>$roomId,'source'=>'api']);

        api_json(['ok'=>true,'device'=>[
            'id'=>$deviceId,'name'=>$name,'device_uid'=>$uid,'type'=>$type,'room_id'=>$roomId
        ],'token'=>$token,'token_type'=>'Bearer','expires_at'=>$expires],201);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        api_json(['ok'=>false,'error'=>'device_create_failed'],409);
    }
}

$deviceId=(int)($in['device_id']??0);
if($deviceId<=0)api_json(['ok'=>false,'error'=>'device_id_required'],422);

if($action==='rotate_token'){
    $pdo->beginTransaction();
    try{
        $pdo->prepare('UPDATE device_tokens SET revoked_at=NOW() WHERE device_id=? AND revoked_at IS NULL')->execute([$deviceId]);
        $token=bin2hex(random_bytes(32));$hash=hash('sha256',$token);
        $ttl=(int)($config['api']['device_token_ttl']??0);
        $expires=$ttl>0?(new DateTimeImmutable())->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s'):null;
        $pdo->prepare('INSERT INTO device_tokens(device_id,token_hash,expires_at) VALUES(?,?,?)')->execute([$deviceId,$hash,$expires]);
        $pdo->commit();
        api_audit($pdo,$admin,'device.rotate_token','device',$deviceId,['source'=>'api']);
        api_json(['ok'=>true,'token'=>$token,'token_type'=>'Bearer','expires_at'=>$expires]);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        api_json(['ok'=>false,'error'=>'token_rotation_failed'],500);
    }
}elseif($action==='revoke'){
    $pdo->prepare('UPDATE device_tokens SET revoked_at=NOW() WHERE device_id=? AND revoked_at IS NULL')->execute([$deviceId]);
    $pdo->prepare("UPDATE devices SET active=0,status='offline' WHERE id=?")->execute([$deviceId]);
    api_audit($pdo,$admin,'device.revoke','device',$deviceId,['source'=>'api']);
    api_json(['ok'=>true]);
}elseif($action==='update'){
    $name=trim((string)($in['name']??''));
    $roomId=array_key_exists('room_id',$in)&&$in['room_id']!==null?(int)$in['room_id']:null;
    $active=array_key_exists('active',$in)?(!empty($in['active'])?1:0):1;
    if($name==='')api_json(['ok'=>false,'error'=>'name_required'],422);
    $pdo->prepare('UPDATE devices SET name=?,room_id=?,active=?,status=IF(?=1,status,\'offline\') WHERE id=?')
        ->execute([$name,$roomId,$active,$active,$deviceId]);
    api_audit($pdo,$admin,'device.update','device',$deviceId,['name'=>$name,'room_id'=>$roomId,'active'=>(bool)$active,'source'=>'api']);
    api_json(['ok'=>true]);
}

api_json(['ok'=>false,'error'=>'unknown_action'],400);
