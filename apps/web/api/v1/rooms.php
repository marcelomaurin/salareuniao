<?php
require __DIR__.'/_bootstrap.php';
api_require_method('GET','POST');
$u=api_user($pdo);

if($_SERVER['REQUEST_METHOD']==='GET'){
    if($u['role']==='admin'&&($_GET['scope']??'mine')==='all'){
        $q=$pdo->query("SELECT r.*,x.name owner_name,
          (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invite_count,
          (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online_count
          FROM rooms r JOIN users x ON x.id=r.owner_user_id
          ORDER BY COALESCE(r.starts_at,r.created_at) DESC LIMIT 500");
    }else{
        $q=$pdo->prepare("SELECT r.*,
          (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invite_count,
          (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online_count
          FROM rooms r WHERE r.owner_user_id=?
          ORDER BY COALESCE(r.starts_at,r.created_at) DESC LIMIT 500");
        $q->execute([$u['id']]);
    }
    api_json(['ok'=>true,'rooms'=>$q->fetchAll()]);
}

$in=api_input();
$name=trim((string)($in['name']??''));
if($name==='')api_json(['ok'=>false,'error'=>'name_required'],422);
$description=trim((string)($in['description']??''));
$starts=trim((string)($in['starts_at']??''))?:null;

$pdo->beginTransaction();
try{
    $q=$pdo->prepare("INSERT INTO rooms(owner_user_id,name,description,starts_at,status) VALUES(?,?,?,?, 'scheduled')");
    $q->execute([$u['id'],$name,$description,$starts]);
    $roomId=(int)$pdo->lastInsertId();

    $hostToken=bin2hex(random_bytes(32));$hostKey=bin2hex(random_bytes(32));
    $h=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status,display_name,participant_key,requested_at,approved_at)
      VALUES(?,?,?,'approved',?,?,NOW(),NOW())");
    $h->execute([$roomId,$u['email'],$hostToken,$u['name'],$hostKey]);
    $pdo->commit();
    api_audit($pdo,$u,'room.create','room',$roomId,['name'=>$name,'starts_at'=>$starts,'source'=>'api']);

    api_json(['ok'=>true,'room'=>[
        'id'=>$roomId,'name'=>$name,'description'=>$description,'starts_at'=>$starts,'status'=>'scheduled',
        'host_join_token'=>$hostToken
    ]],201);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    api_json(['ok'=>false,'error'=>'room_create_failed'],500);
}
