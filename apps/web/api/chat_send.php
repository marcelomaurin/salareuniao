<?php
require __DIR__.'/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$input=json_decode(file_get_contents('php://input'),true)?:[];
$token=$input['token']??'';
$message=trim((string)($input['message']??''));

$st=$pdo->prepare("SELECT i.*,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();

if(!$me||!$me['participant_key']){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'forbidden']);exit;}
if($me['room_status']!=='open'){http_response_code(409);echo json_encode(['ok'=>false,'error'=>'room_closed']);exit;}
if($message===''){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'empty_message']);exit;}
if(mb_strlen($message)>2000){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'message_too_long']);exit;}

$name=trim((string)($me['display_name']?:$me['email']));
$q=$pdo->prepare('INSERT INTO room_messages(room_id,participant_key,display_name,message) VALUES(?,?,?,?)');
$q->execute([$me['room_id'],$me['participant_key'],$name,$message]);

echo json_encode([
  'ok'=>true,
  'message'=>[
    'id'=>(int)$pdo->lastInsertId(),
    'participant_key'=>$me['participant_key'],
    'display_name'=>$name,
    'message'=>$message,
    'created_at'=>date('Y-m-d H:i:s'),
  ]
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
