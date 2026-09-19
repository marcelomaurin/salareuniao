<?php
require __DIR__.'/lib/bootstrap.php';
$user=require_admin();

$summary=$pdo->query("
SELECT
 (SELECT COUNT(*) FROM users WHERE active=1) active_users,
 (SELECT COUNT(*) FROM users WHERE role='admin' AND active=1) admins,
 (SELECT COUNT(*) FROM users WHERE role='user' AND active=1) regular_users,
 (SELECT COUNT(*) FROM rooms) total_rooms,
 (SELECT COUNT(*) FROM rooms WHERE status='open') open_rooms,
 (SELECT COUNT(*) FROM rooms WHERE status='scheduled') scheduled_rooms,
 (SELECT COUNT(*) FROM room_presence WHERE last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online_participants,
 (SELECT COUNT(*) FROM signaling_messages WHERE created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)) signaling_5m,
 (SELECT COUNT(*) FROM devices WHERE active=1) active_devices,
 (SELECT COUNT(*) FROM devices WHERE active=1 AND last_seen_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) online_devices
")->fetch();

$rooms=$pdo->query("
SELECT r.*,u.name owner_name,
 (SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited,
 (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online,
 (SELECT COUNT(*) FROM room_presence p WHERE p.room_id=r.id AND p.screen_sharing=1 AND p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) sharing,
 (SELECT COUNT(*) FROM signaling_messages s WHERE s.room_id=r.id AND s.created_at>=DATE_SUB(NOW(),INTERVAL 5 MINUTE)) signaling_5m
FROM rooms r
JOIN users u ON u.id=r.owner_user_id
ORDER BY (r.status='open') DESC, COALESCE(r.starts_at,r.created_at) DESC
LIMIT 200
")->fetchAll();

$usage=$pdo->query("
SELECT u.id,u.name,u.email,u.role,
 COUNT(DISTINCT r.id) rooms_created,
 SUM(CASE WHEN r.status='open' THEN 1 ELSE 0 END) open_rooms,
 COALESCE(SUM((SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id)),0) invitations
FROM users u
LEFT JOIN rooms r ON r.owner_user_id=u.id
GROUP BY u.id,u.name,u.email,u.role
ORDER BY rooms_created DESC,u.name
LIMIT 100
")->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Administração - Sala Reunião</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fa;color:#1f2937}.top{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:18px 0}.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px}.card strong{font-size:28px;display:block;margin-top:5px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:16px 0;overflow:auto}table{border-collapse:collapse;width:100%;min-width:780px}th,td{text-align:left;padding:9px;border-bottom:1px solid #eee}th{background:#f9fafb}.open{color:#15803d;font-weight:bold}.muted{color:#6b7280;font-size:13px}.nav a{margin-right:12px}
</style></head><body>
<div class="top"><div><h1>Administração do sistema</h1><div class="muted">Visão global de usuários, salas, presença e sinalização.</div></div><div class="nav"><a href="admin_users.php">Usuários</a><a href="admin_devices.php">Dispositivos</a><a href="admin_firmware.php">Firmware</a><a href="admin_system.php">Diagnóstico</a><a href="index.php">Área do usuário</a><a href="logout.php">Sair</a></div></div>

<div class="cards">
<div class="card">Usuários ativos<strong><?=(int)$summary['active_users']?></strong><span class="muted"><?=(int)$summary['admins']?> admin / <?=(int)$summary['regular_users']?> usuários</span></div>
<div class="card">Salas cadastradas<strong><?=(int)$summary['total_rooms']?></strong><span class="muted"><?=(int)$summary['scheduled_rooms']?> agendadas</span></div>
<div class="card">Salas abertas<strong><?=(int)$summary['open_rooms']?></strong><span class="muted">em uso agora</span></div>
<div class="card">Participantes online<strong><?=(int)$summary['online_participants']?></strong><span class="muted">heartbeat &lt; 20 s</span></div>
<div class="card">Sinalização / 5 min<strong><?=(int)$summary['signaling_5m']?></strong><span class="muted">offer/answer/ICE/eventos</span></div>
<div class="card">Dispositivos online<strong><?=(int)$summary['online_devices']?></strong><span class="muted">de <?=(int)$summary['active_devices']?> ativos</span></div>
</div>

<div class="panel"><h2>Salas e uso atual</h2>
<table><thead><tr><th>Sala</th><th>Dono</th><th>Status</th><th>Online</th><th>Convites</th><th>Tela</th><th>Sinalização 5 min</th><th></th></tr></thead><tbody>
<?php foreach($rooms as $r):?><tr>
<td><?=e($r['name'])?></td><td><?=e($r['owner_name'])?></td><td class="<?=$r['status']==='open'?'open':''?>"><?=e($r['status'])?></td>
<td><?=(int)$r['online']?></td><td><?=(int)$r['invited']?></td><td><?=(int)$r['sharing']?></td><td><?=(int)$r['signaling_5m']?></td>
<td><a href="room_manage.php?id=<?=(int)$r['id']?>">inspecionar</a></td>
</tr><?php endforeach;?>
</tbody></table></div>

<div class="panel"><h2>Uso por usuário</h2>
<table><thead><tr><th>Usuário</th><th>Perfil</th><th>Salas criadas</th><th>Salas abertas</th><th>Convites emitidos</th></tr></thead><tbody>
<?php foreach($usage as $u):?><tr>
<td><strong><?=e($u['name'])?></strong><div class="muted"><?=e($u['email'])?></div></td><td><?=e($u['role'])?></td>
<td><?=(int)$u['rooms_created']?></td><td><?=(int)$u['open_rooms']?></td><td><?=(int)$u['invitations']?></td>
</tr><?php endforeach;?>
</tbody></table></div>

<div class="panel">
<h2>Sobre recursos</h2>
<p>Esta tela mede recursos lógicos da aplicação: usuários, salas, participantes, compartilhamento de tela e volume de sinalização. Em WebRTC P2P, o áudio/vídeo normalmente trafega diretamente entre os navegadores, portanto o servidor PHP não conhece o consumo real de banda da mídia. Se TURN for utilizado, métricas de tráfego do servidor TURN devem ser incorporadas ao painel.</p>
</div>
</body></html>
