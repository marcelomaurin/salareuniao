<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$st = $pdo->prepare("
SELECT r.*,
 (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited,
 (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online
FROM rooms r
WHERE r.owner_user_id=?
ORDER BY COALESCE(r.starts_at,r.created_at) DESC
");
$st->execute([$user['id']]);
$rooms = $st->fetchAll();

$stats=$pdo->prepare("
SELECT
 COUNT(*) total_rooms,
 SUM(status='open') open_rooms,
 SUM(status='scheduled') scheduled_rooms
FROM rooms WHERE owner_user_id=?
");
$stats->execute([$user['id']]);$my=$stats->fetch();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sala Reunião</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f6f7f9;color:#1f2937}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.nav a{margin-right:12px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:18px 0}.card,.room{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:15px}.card strong{display:block;font-size:26px;margin-top:5px}.rooms{display:grid;gap:10px}.room{display:flex;justify-content:space-between;align-items:center;gap:12px}.muted{color:#6b7280;font-size:13px}.open{color:#15803d;font-weight:bold}
</style></head><body>
<div class="top"><div><h1>Sala Reunião</h1><div class="muted">Olá, <?=e($user['name'])?> — perfil <?=e($user['role'])?></div></div>
<div class="nav"><a href="room_create.php">Criar sala</a><?php if($user['role']==='admin'):?> <a href="admin.php">Administração global</a><?php endif;?> <a href="logout.php">Sair</a></div></div>

<div class="cards">
<div class="card">Minhas salas<strong><?=(int)($my['total_rooms']??0)?></strong></div>
<div class="card">Abertas agora<strong><?=(int)($my['open_rooms']??0)?></strong></div>
<div class="card">Agendadas<strong><?=(int)($my['scheduled_rooms']??0)?></strong></div>
</div>

<h2>Minhas salas</h2>
<div class="rooms">
<?php if(!$rooms):?><div class="room">Você ainda não criou nenhuma sala.</div><?php endif;?>
<?php foreach ($rooms as $r): ?>
<div class="room"><div>
<strong><?=e($r['name'])?></strong>
<div class="muted"><?= $r['starts_at'] ? e($r['starts_at']).' · ' : '' ?><span class="<?=$r['status']==='open'?'open':''?>"><?=e($r['status'])?></span> · <?=(int)$r['invited']?> convidados · <?=(int)$r['online']?> online</div>
</div><a href="room_manage.php?id=<?=(int)$r['id']?>">Gerenciar</a></div>
<?php endforeach; ?>
</div>
</body></html>
