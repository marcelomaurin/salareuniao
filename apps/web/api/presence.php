<?php
require __DIR__.'/../lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$input=json_decode(file_get_contents('php://input'),true)?:[];
$token=$input['token']??($_GET['token']??'');

$st=$pdo->prepare("SELECT i.*,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? AND i.status='approved' LIMIT 1");
$st->execute([$token]);$me=$st->fetch();
if(!$me||!$me['participant_key']){http_response_code(403);echo json_encode(['ok'=>false]);exit;}

$roomId=(int)$me['room_id'];
$key=$me['participant_key'];
$name=trim($me['display_name']?:$me['email']);

// Fecha sessões abandonadas por perda de heartbeat.
$stale=$pdo->prepare("SELECT room_id,participant_key,last_seen_at FROM room_presence WHERE room_id=? AND last_seen_at<DATE_SUB(NOW(),INTERVAL 20 SECOND)");
$stale->execute([$roomId]);
foreach($stale->fetchAll() as $p){
    $close=$pdo->prepare("UPDATE room_attendance SET left_at=?,duration_seconds=TIMESTAMPDIFF(SECOND,joined_at,?) WHERE room_id=? AND participant_key=? AND left_at IS NULL ORDER BY id DESC LIMIT 1");
    $close->execute([$p['last_seen_at'],$p['last_seen_at'],$roomId,$p['participant_key']]);
}
$pdo->prepare("DELETE FROM room_presence WHERE room_id=? AND last_seen_at<DATE_SUB(NOW(),INTERVAL 20 SECOND)")->execute([$roomId]);

if($_SERVER['REQUEST_METHOD']==='POST'){
  $mic=!empty($input['mic'])?1:0;$cam=!empty($input['cam'])?1:0;$screen=!empty($input['screen'])?1:0;

  $existing=$pdo->prepare('SELECT 1 FROM room_presence WHERE room_id=? AND participant_key=? LIMIT 1');
  $existing->execute([$roomId,$key]);
  if(!$existing->fetchColumn()){
      $att=$pdo->prepare('INSERT INTO room_attendance(room_id,participant_key,display_name,joined_at) VALUES(?,?,?,NOW())');
      $att->execute([$roomId,$key,$name]);
  }

  $q=$pdo->prepare("INSERT INTO room_presence(room_id,participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at)
    VALUES(?,?,?,?,?,?,NOW(),NOW())
    ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),mic_enabled=VALUES(mic_enabled),cam_enabled=VALUES(cam_enabled),screen_sharing=VALUES(screen_sharing),last_seen_at=NOW()");
  $q->execute([$roomId,$key,$name,$mic,$cam,$screen]);
}

$q=$pdo->prepare("SELECT participant_key,display_name,mic_enabled,cam_enabled,screen_sharing,joined_at,last_seen_at
FROM room_presence WHERE room_id=? AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND) ORDER BY joined_at");
$q->execute([$roomId]);

echo json_encode([
  'ok'=>true,
  'room_status'=>$me['room_status'],
  'self'=>$key,
  'participants'=>$q->fetchAll()
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
