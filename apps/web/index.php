<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$st = $pdo->prepare('SELECT r.* FROM rooms r WHERE r.owner_user_id=? ORDER BY COALESCE(r.starts_at,r.created_at) DESC');
$st->execute([$user['id']]);
$rooms = $st->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Sala Reunião</title></head>
<body>
<header>
<h1>Sala Reunião</h1>
<p>Olá, <?=e($user['name'])?> | <a href="room_create.php">Criar sala</a>
<?php if ($user['role']==='admin'): ?> | <a href="admin.php">Administração</a><?php endif; ?>
 | <a href="logout.php">Sair</a></p>
</header>
<h2>Minhas salas</h2>
<?php foreach ($rooms as $r): ?>
<article>
<strong><?=e($r['name'])?></strong>
<?= $r['starts_at'] ? ' - '.e($r['starts_at']) : '' ?>
[<?=e($r['status'])?>]
<a href="room_manage.php?id=<?=(int)$r['id']?>">Gerenciar</a>
</article>
<?php endforeach; ?>
</body></html>
