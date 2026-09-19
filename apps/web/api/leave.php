<?php
require __DIR__.'/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$input=json_decode(file_get_contents('php://input'),true)?:[];
$token=$input['token']??'';

$st=$pdo->prepare("SELECT i.* FROM room_invites i WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();
if(!$me||!$me['participant_key']){http_response_code(403);echo json_encode(['ok'=>false]);exit;}

$pdo->beginTransaction();
try{
    $p=$pdo->prepare('SELECT last_seen_at FROM room_presence WHERE room_id=? AND participant_key=? LIMIT 1');
    $p->execute([$me['room_id'],$me['participant_key']]);
    $presence=$p->fetch();
    $leftAt=$presence['last_seen_at']??date('Y-m-d H:i:s');

    $close=$pdo->prepare("UPDATE room_attendance SET left_at=?,duration_seconds=TIMESTAMPDIFF(SECOND,joined_at,?) WHERE room_id=? AND participant_key=? AND left_at IS NULL ORDER BY id DESC LIMIT 1");
    $close->execute([$leftAt,$leftAt,$me['room_id'],$me['participant_key']]);

    $pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')->execute([$me['room_id'],$me['participant_key']]);

    $q=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload) VALUES(?,?,NULL,'leave',JSON_OBJECT())");
    $q->execute([$me['room_id'],$me['participant_key']]);
    $pdo->commit();
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    http_response_code(500);echo json_encode(['ok'=>false]);exit;
}
echo json_encode(['ok'=>true]);
