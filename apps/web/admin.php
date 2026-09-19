<?php
require __DIR__ . '/lib/bootstrap.php';
$user=require_admin();
$users=$pdo->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY name')->fetchAll();
$rooms=$pdo->query('SELECT r.*,u.name owner_name,(SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited FROM rooms r JOIN users u ON u.id=r.owner_user_id ORDER BY r.created_at DESC LIMIT 100')->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Administração</title></head><body>
<h1>Administração</h1>
<h2>Usuários</h2>
<?php foreach($users as $u): ?><div>#<?=(int)$u['id']?> <?=e($u['name'])?> — <?=e($u['email'])?> — <?=e($u['role'])?> — <?=$u['active']?'ativo':'inativo'?></div><?php endforeach; ?>
<h2>Salas</h2>
<?php foreach($rooms as $r): ?><div>#<?=(int)$r['id']?> <?=e($r['name'])?> — responsável <?=e($r['owner_name'])?> — <?=e($r['status'])?> — <?=(int)$r['invited']?> convidados</div><?php endforeach; ?>
<p><a href="index.php">Voltar</a></p>
</body></html>
