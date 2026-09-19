<?php
require __DIR__.'/_bootstrap.php';
require __DIR__.'/../../lib/invitations.php';
api_require_method('GET','POST');
$u=api_user($pdo);
$roomId=(int)($_GET['room_id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){
    $in=api_input();
    $roomId=(int)($in['room_id']??$roomId);
}
if($roomId<=0)api_json(['ok'=>false,'error'=>'room_id_required'],422);

if($u['role']==='admin'){
    $q=$pdo->prepare('SELECT r.*,x.name owner_name,x.email owner_email FROM rooms r JOIN users x ON x.id=r.owner_user_id WHERE r.id=? LIMIT 1');
    $q->execute([$roomId]);
}else{
    $q=$pdo->prepare('SELECT r.*,x.name owner_name,x.email owner_email FROM rooms r JOIN users x ON x.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
    $q->execute([$roomId,$u['id']]);
}
$room=$q->fetch();
if(!$room)api_json(['ok'=>false,'error'=>'room_not_found'],404);

if($_SERVER['REQUEST_METHOD']==='GET'){
    $q=$pdo->prepare('SELECT id,email,status,display_name,requested_at,approved_at,created_at FROM room_invites WHERE room_id=? ORDER BY created_at');
    $q->execute([$roomId]);
    api_json(['ok'=>true,'invites'=>$q->fetchAll()]);
}

$action=(string)($in['action']??'add');

if($action==='add'){
    if($room['status']==='cancelled')api_json(['ok'=>false,'error'=>'room_cancelled'],409);
    $emails=$in['emails']??[];
    if(is_string($emails))$emails=preg_split('/[\s,;]+/',$emails,-1,PREG_SPLIT_NO_EMPTY);
    if(!is_array($emails))api_json(['ok'=>false,'error'=>'emails_required'],422);

    $created=[];
    foreach(array_unique($emails) as $email){
        $email=strtolower(trim((string)$email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$email===strtolower($room['owner_email']))continue;

        $exists=$pdo->prepare("SELECT id FROM room_invites WHERE room_id=? AND email=? AND status<>'rejected' LIMIT 1");
        $exists->execute([$roomId,$email]);
        if($exists->fetch())continue;

        $token=bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO room_invites(room_id,email,token,status) VALUES(?,?,?,'invited')")
            ->execute([$roomId,$email,$token]);
        send_room_invite_mail($config,$room,$email,$token);
        $created[]=['email'=>$email,'invite_url'=>room_invite_link($config,$token)];
    }
    api_json(['ok'=>true,'created'=>$created],201);
}

$inviteId=(int)($in['invite_id']??0);
if($inviteId<=0)api_json(['ok'=>false,'error'=>'invite_id_required'],422);

$q=$pdo->prepare('SELECT * FROM room_invites WHERE id=? AND room_id=? LIMIT 1');
$q->execute([$inviteId,$roomId]);$invite=$q->fetch();
if(!$invite)api_json(['ok'=>false,'error'=>'invite_not_found'],404);

if($action==='approve'){
    $key=$invite['participant_key']?:bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE room_invites SET status='approved',participant_key=?,approved_at=NOW() WHERE id=?")
        ->execute([$key,$inviteId]);
}elseif($action==='reject'){
    $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=?")->execute([$inviteId]);
}elseif($action==='resend'){
    send_room_invite_mail($config,$room,$invite['email'],$invite['token'],'resend');
}elseif($action==='remove'){
    if($invite['participant_key']){
        $pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')->execute([$roomId,$invite['participant_key']]);
    }
    $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=?")->execute([$inviteId]);
}else{
    api_json(['ok'=>false,'error'=>'unknown_action'],400);
}

api_json(['ok'=>true]);
