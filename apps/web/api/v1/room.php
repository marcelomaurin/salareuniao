<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET','PATCH','POST');
$u=api_user($pdo);
$id=(int)($_GET['id']??0);
if($id<=0)api_json(['ok'=>false,'error'=>'invalid_room_id'],400);

if($u['role']==='admin'){
    $q=$pdo->prepare('SELECT r.*,x.name owner_name,x.email owner_email FROM rooms r JOIN users x ON x.id=r.owner_user_id WHERE r.id=? LIMIT 1');
    $q->execute([$id]);
}else{
    $q=$pdo->prepare('SELECT r.*,x.name owner_name,x.email owner_email FROM rooms r JOIN users x ON x.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
    $q->execute([$id,$u['id']]);
}
$room=$q->fetch();
if(!$room)api_json(['ok'=>false,'error'=>'room_not_found'],404);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $i=$pdo->prepare('SELECT id,email,status,display_name,requested_at,approved_at,created_at FROM room_invites WHERE room_id=? ORDER BY created_at');
    $i->execute([$id]);
    $host=$pdo->prepare("SELECT token FROM room_invites WHERE room_id=? AND email=? AND status='approved' ORDER BY id LIMIT 1");
    $host->execute([$id,strtolower($room['owner_email'])]);
    $hostToken=$host->fetchColumn()?:null;
    api_json(['ok'=>true,'room'=>$room,'participants'=>$i->fetchAll(),'host_join_token'=>$hostToken]);
}

$in=api_input();
$action=(string)($in['action']??'update');

if($action==='open'){
    if($room['status']==='cancelled')api_json(['ok'=>false,'error'=>'room_cancelled'],409);
    $pdo->prepare("UPDATE rooms SET status='open' WHERE id=?")->execute([$id]);
}elseif($action==='close'){
    $pdo->prepare("UPDATE rooms SET status='closed',ends_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare('DELETE FROM room_presence WHERE room_id=?')->execute([$id]);
}elseif($action==='cancel'){
    $pdo->prepare("UPDATE rooms SET status='cancelled',ends_at=NOW() WHERE id=?")->execute([$id]);
    $pdo->prepare('DELETE FROM room_presence WHERE room_id=?')->execute([$id]);
}elseif($action==='update'){
    $name=trim((string)($in['name']??$room['name']));
    $description=(string)($in['description']??$room['description']);
    $starts=array_key_exists('starts_at',$in)?($in['starts_at']?:null):$room['starts_at'];
    if($name==='')api_json(['ok'=>false,'error'=>'name_required'],422);
    $pdo->prepare('UPDATE rooms SET name=?,description=?,starts_at=? WHERE id=?')->execute([$name,$description,$starts,$id]);
}else{
    api_json(['ok'=>false,'error'=>'unknown_action'],400);
}
api_json(['ok'=>true]);
