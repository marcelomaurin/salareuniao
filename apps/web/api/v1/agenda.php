<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET');
$u=api_user($pdo);
$days=min(90,max(1,(int)($_GET['days']??30)));

if($u['role']==='admin'&&($_GET['scope']??'mine')==='all'){
    $q=$pdo->prepare("SELECT r.id,r.name,r.description,r.starts_at,r.ends_at,r.status,u.name owner_name
      FROM rooms r JOIN users u ON u.id=r.owner_user_id
      WHERE r.status IN('scheduled','open') AND (r.starts_at IS NULL OR r.starts_at<=DATE_ADD(NOW(),INTERVAL ? DAY))
      ORDER BY r.starts_at IS NULL,r.starts_at,r.created_at");
    $q->execute([$days]);
}else{
    $q=$pdo->prepare("SELECT r.id,r.name,r.description,r.starts_at,r.ends_at,r.status
      FROM rooms r
      WHERE r.owner_user_id=? AND r.status IN('scheduled','open')
        AND (r.starts_at IS NULL OR r.starts_at<=DATE_ADD(NOW(),INTERVAL ? DAY))
      ORDER BY r.starts_at IS NULL,r.starts_at,r.created_at");
    $q->execute([$u['id'],$days]);
}
api_json(['ok'=>true,'agenda'=>$q->fetchAll()]);
