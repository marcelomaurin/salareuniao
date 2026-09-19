<?php
require __DIR__ . '/lib/bootstrap.php';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$st = $pdo->prepare('SELECT i.*,r.name room_name,r.status room_status FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? LIMIT 1');
$st->execute([$token]);
$invite = $st->fetch();
if (!$invite) { http_response_code(404); exit('Convite inválido.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite['status'] === 'invited') {
    verify_csrf();
    $name = trim($_POST['display_name'] ?? '');
    if ($name !== '') {
        $participantKey = bin2hex(random_bytes(32));
        $up = $pdo->prepare("UPDATE room_invites SET display_name=?,participant_key=?,status='waiting',requested_at=NOW() WHERE id=?");
        $up->execute([$name,$participantKey,$invite['id']]);
        header('Location: join.php?token='.urlencode($token));
        exit;
    }
}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title><?=e($invite['room_name'])?></title>
<?php if ($invite['status']==='waiting'): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
</head><body>
<h1><?=e($invite['room_name'])?></h1>
<?php if ($invite['status']==='invited'): ?>
<p>Você recebeu um convite. Informe seu nome para solicitar entrada.</p>
<form method="post">
<input type="hidden" name="token" value="<?=e($token)?>">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input name="display_name" required placeholder="Seu nome">
<button>Solicitar entrada</button>
</form>
<?php elseif ($invite['status']==='waiting'): ?>
<p>Aguardando o administrador autorizar sua entrada...</p>
<?php elseif ($invite['status']==='approved'): ?>
<p>Entrada autorizada.</p>
<a href="room.php?token=<?=urlencode($token)?>">Entrar na videoconferência</a>
<?php elseif ($invite['status']==='rejected'): ?>
<p>O administrador não autorizou a entrada.</p>
<?php else: ?>
<p>Este convite não está disponível.</p>
<?php endif; ?>
</body></html>
