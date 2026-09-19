<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM rooms WHERE id=? AND owner_user_id=? LIMIT 1');
$st->execute([$id,$user['id']]);
$room = $st->fetch();
if (!$room) { http_response_code(404); exit('Sala não encontrada.'); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $inviteId=(int)($_POST['invite_id']??0);
    $action=$_POST['action']??'';
    if (in_array($action,['approve','reject'],true)) {
        $status=$action==='approve'?'approved':'rejected';
        $up=$pdo->prepare("UPDATE room_invites SET status=?,approved_at=IF(?='approved',NOW(),approved_at) WHERE id=? AND room_id=?");
        $up->execute([$status,$status,$inviteId,$id]);
    }
    if ($action==='open') {
        $pdo->prepare("UPDATE rooms SET status='open' WHERE id=?")->execute([$id]);
    }
    if ($action==='close') {
        $pdo->prepare("UPDATE rooms SET status='closed' WHERE id=?")->execute([$id]);
    }
    header('Location: room_manage.php?id='.$id); exit;
}
$inv=$pdo->prepare('SELECT * FROM room_invites WHERE room_id=? ORDER BY created_at');
$inv->execute([$id]); $invites=$inv->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Gerenciar sala</title>
<meta http-equiv="refresh" content="5"></head><body>
<h1><?=e($room['name'])?></h1>
<p>Status: <?=e($room['status'])?></p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<button name="action" value="open">Abrir sala</button><button name="action" value="close">Encerrar</button>
</form>
<h2>Convidados / sala de espera</h2>
<?php foreach($invites as $i): ?>
<div>
<?=e($i['display_name'] ?: $i['email'])?> — <?=e($i['status'])?>
<?php if($i['status']==='waiting'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="invite_id" value="<?=(int)$i['id']?>">
<button name="action" value="approve">Autorizar</button>
<button name="action" value="reject">Recusar</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
<p><a href="index.php">Voltar</a></p>
</body></html>
