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

$presence=$pdo->prepare("SELECT i.display_name,i.email,i.status,i.requested_at,i.approved_at,
                         MIN(p.joined_at) first_seen,MAX(p.last_seen_at) last_seen
                         FROM room_invites i
                         LEFT JOIN room_presence p ON p.room_id=i.room_id AND p.participant_key=i.participant_key
                         WHERE i.room_id=?
                         GROUP BY i.id,i.display_name,i.email,i.status,i.requested_at,i.approved_at
                         ORDER BY i.created_at");
$presence->execute([$id]);$participants=$presence->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Histórico - <?=e($room['name'])?></title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.panel{background:#fff;border:1px solid #ddd;border-radius:10px;padding:16px;margin:14px 0}.msg{padding:9px 0;border-bottom:1px solid #eee}.meta{font-size:12px;color:#6b7280}.text{white-space:pre-wrap;word-break:break-word;margin-top:3px}table{border-collapse:collapse;width:100%}th,td{text-align:left;padding:8px;border-bottom:1px solid #eee}
</style></head><body>
<h1>Histórico: <?=e($room['name'])?></h1>
<p>Responsável: <?=e($room['owner_name'])?> · Status: <?=e($room['status'])?></p>

<div class="panel"><h2>Participantes</h2>
<table><thead><tr><th>Nome</th><th>E-mail</th><th>Status</th><th>Solicitou</th><th>Aprovado</th></tr></thead><tbody>
<?php foreach($participants as $p):?><tr>
<td><?=e((string)($p['display_name']?:'-'))?></td><td><?=e($p['email'])?></td><td><?=e($p['status'])?></td><td><?=e((string)($p['requested_at']?:'-'))?></td><td><?=e((string)($p['approved_at']?:'-'))?></td>
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
