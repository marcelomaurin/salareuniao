<?php
require __DIR__.'/../_bootstrap.php';
api_require_method('GET');
$d=api_device($pdo);

$room=null;$agenda=[];
if(!empty($d['room_id'])){
    $q=$pdo->prepare("SELECT r.id,r.name,r.description,r.starts_at,r.ends_at,r.status,
      (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online_count
      FROM rooms r WHERE r.id=? LIMIT 1");
    $q->execute([$d['room_id']]);$room=$q->fetch()?:null;
}
$q=$pdo->prepare("SELECT r.id,r.name,r.description,r.starts_at,r.ends_at,r.status
    FROM rooms r
    WHERE r.status IN('scheduled','open')
      AND r.starts_at IS NOT NULL
      AND r.starts_at BETWEEN DATE_SUB(NOW(),INTERVAL 1 HOUR) AND DATE_ADD(NOW(),INTERVAL 1 DAY)
    ORDER BY r.starts_at LIMIT 20");
$q->execute();$agenda=$q->fetchAll();

api_json(['ok'=>true,'device'=>[
    'id'=>(int)$d['id'],'name'=>$d['name'],'device_uid'=>$d['device_uid'],
    'status'=>$d['status'],'room_id'=>$d['room_id']
],'room'=>$room,'agenda'=>$agenda,'server_time'=>date(DATE_ATOM)]);
