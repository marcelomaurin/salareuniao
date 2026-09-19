<?php
require __DIR__.'/lib/bootstrap.php';$user=require_admin();
$users=$pdo->query('SELECT COUNT(*) total FROM users')->fetch();
$rooms=$pdo->query('SELECT r.*,u.name owner_name,(SELECT COUNT(*) FROM room_invites i WHERE i.room_id=r.id) invited FROM rooms r JOIN users u ON u.id=r.owner_user_id ORDER BY r.created_at DESC LIMIT 100')->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Administração</title></head><body>
<h1>Administração</h1>
<p><a href="admin_users.php">Usuários (<?=(int)$users['total']?>)</a> | <a href="index.php">Painel principal</a></p>
<h2>Salas recentes</h2>
<?php foreach($rooms as $r):?><div>#<?=(int)$r['id']?> <strong><?=e($r['name'])?></strong> — responsável <?=e($r['owner_name'])?> — <?=e($r['status'])?> — <?=(int)$r['invited']?> convidados — <a href="room_manage.php?id=<?=(int)$r['id']?>">abrir</a></div><?php endforeach;?>
</body></html>
