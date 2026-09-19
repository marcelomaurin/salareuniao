<?php
require __DIR__.'/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$input=json_decode(file_get_contents('php://input'),true)?:[];
$token=$input['token']??($_GET['token']??'');
$st=$pdo->prepare("SELECT i.*,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();
if(!$me||!$me['participant_key']){http_response_code(403);echo json_encode(['ok'=>false]);exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
  $name=trim($me['display_name']?:$me['email']);
  $mic=!empty($input['mic'])?1:0;$cam=!empty($input['cam'])?1:0;$screen=!empty($input['screen'])?1:0;
  $q=$pdo->prepare("INSERT INTO room_presence(room_id,participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at)
    VALUES(?,?,?,?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),mic_enabled=VALUES(mic_enabled),cam_enabled=VALUES(cam_enabled),screen_sharing=VALUES(screen_sharing),last_seen_at=NOW()");
  $q->execute([$me['room_id'],$me['participant_key'],$name,$mic,$cam,$screen]);
}
$q=$pdo->prepare("SELECT participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at
FROM room_presence WHERE room_id=? AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND) ORDER BY joined_at");
$q->execute([$me['room_id']]);
echo json_encode(['ok'=>true,'room_status'=>$me['room_status'],'self'=>$me['participant_key'],'participants'=>$q->fetchAll()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
