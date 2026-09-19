<?php
require __DIR__.'/lib/bootstrap.php';
$user=require_login();
$id=(int)($_GET['id']??0);

if($user['role']==='admin'){
  $st=$pdo->prepare('SELECT r.*,u.name owner_name FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
  $st->execute([$id]);
}else{
  $st=$pdo->prepare('SELECT r.*,u.name owner_name FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
  $st->execute([$id,$user['id']]);
}
$room=$st->fetch();
if(!$room){http_response_code(404);exit('Sala não encontrada.');}

$msg=$pdo->prepare('SELECT id,display_name,message,created_at FROM room_messages WHERE room_id=? ORDER BY id ASC');
$msg->execute([$id]);$messages=$msg->fetchAll();

$participants=$pdo->prepare("
SELECT i.id,i.display_name,i.email,i.status,i.requested_at,i.approved_at,
       COUNT(a.id) sessions,
       MIN(a.joined_at) first_join,
       MAX(COALESCE(a.left_at,NOW())) last_leave,
       COALESCE(SUM(COALESCE(a.duration_seconds,TIMESTAMPDIFF(SECOND,a.joined_at,NOW()))),0) total_seconds
FROM room_invites i
LEFT JOIN room_attendance a ON a.room_id=i.room_id AND a.participant_key=i.participant_key
WHERE i.room_id=?
GROUP BY i.id,i.display_name,i.email,i.status,i.requested_at,i.approved_at
ORDER BY i.created_at");
$participants->execute([$id]);$participants=$participants->fetchAll();

$sessions=$pdo->prepare("SELECT display_name,joined_at,left_at,COALESCE(duration_seconds,TIMESTAMPDIFF(SECOND,joined_at,NOW())) duration_seconds FROM room_attendance WHERE room_id=? ORDER BY joined_at");
$sessions->execute([$id]);$sessions=$sessions->fetchAll();

function duration_text(int $seconds): string {
    $h=intdiv($seconds,3600);$m=intdiv($seconds%3600,60);$s=$seconds%60;
    return ($h>0?$h.'h ':'').$m.'m '.$s.'s';
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Histórico - <?=e($room['name'])?></title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.panel{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:14px 0;overflow:auto}.msg{padding:9px 0;border-bottom:1px solid #eee}.meta{font-size:12px;color:#6b7280}.text{white-space:pre-wrap;word-break:break-word;margin-top:3px}table{border-collapse:collapse;width:100%;min-width:760px}th,td{text-align:left;padding:8px;border-bottom:1px solid #eee}th{background:#f9fafb}
</style></head><body>
<h1>Histórico: <?=e($room['name'])?></h1>
<p>Responsável: <?=e($room['owner_name'])?> · Status: <?=e($room['status'])?> · Início: <?=e((string)($room['starts_at']?:'-'))?> · Término: <?=e((string)($room['ends_at']?:'-'))?></p>

<div class="panel"><h2>Participação consolidada</h2>
<table><thead><tr><th>Nome</th><th>E-mail</th><th>Status</th><th>Entradas</th><th>Primeira entrada</th><th>Última saída</th><th>Tempo total</th></tr></thead><tbody>
<?php foreach($participants as $p):?><tr>
<td><?=e((string)($p['display_name']?:'-'))?></td><td><?=e($p['email'])?></td><td><?=e($p['status'])?></td>
<td><?=(int)$p['sessions']?></td><td><?=e((string)($p['first_join']?:'-'))?></td><td><?=e((string)($p['last_leave']?:'-'))?></td>
<td><?=e(duration_text((int)$p['total_seconds']))?></td>
</tr><?php endforeach;?>
</tbody></table></div>

<div class="panel"><h2>Sessões de conexão</h2>
<table><thead><tr><th>Participante</th><th>Entrou</th><th>Saiu</th><th>Duração</th></tr></thead><tbody>
<?php if(!$sessions):?><tr><td colspan="4">Nenhuma sessão registrada.</td></tr><?php endif;?>
<?php foreach($sessions as $s):?><tr>
<td><?=e($s['display_name'])?></td><td><?=e($s['joined_at'])?></td><td><?=e((string)($s['left_at']?:'online/sem saída registrada'))?></td><td><?=e(duration_text((int)$s['duration_seconds']))?></td>
</tr><?php endforeach;?>
</tbody></table></div>

<div class="panel"><h2>Chat (<?=count($messages)?> mensagens)</h2>
<?php if(!$messages):?><p>Nenhuma mensagem registrada.</p><?php endif;?>
<?php foreach($messages as $m):?><div class="msg">
<div class="meta"><?=e($m['display_name'])?> · <?=e($m['created_at'])?></div>
<div class="text"><?=e($m['message'])?></div>
</div><?php endforeach;?>
</div>

<p><a href="room_manage.php?id=<?=$id?>">Voltar à sala</a></p>
</body></html>
