<?php
require __DIR__ . '/lib/bootstrap.php';
$token = $_GET['token'] ?? $_POST['token'] ?? '';
$st = $pdo->prepare('SELECT i.*,r.name room_name,r.status room_status,r.starts_at FROM room_invites i JOIN rooms r ON r.id=i.room_id WHERE i.token=? LIMIT 1');
$st->execute([$token]);
$invite = $st->fetch();
if (!$invite) { http_response_code(404); exit('Convite inválido.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $invite['status'] === 'invited' && $invite['room_status'] !== 'cancelled') {
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
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($invite['room_name'])?></title>
<?php if ($invite['status']==='waiting' && $invite['room_status']!=='cancelled'): ?><meta http-equiv="refresh" content="3"><?php endif; ?>
<style>body{font-family:Arial,sans-serif;max-width:620px;margin:50px auto;padding:20px}.box{border:1px solid #ddd;border-radius:12px;padding:20px}input{padding:9px;width:100%;box-sizing:border-box;margin:8px 0}button{padding:9px 14px}</style>
</head><body><div class="box">
<h1><?=e($invite['room_name'])?></h1>
<?php if($invite['starts_at']):?><p>Data/hora: <?=e($invite['starts_at'])?></p><?php endif;?>
<?php if($invite['room_status']==='cancelled'):?>
<p><strong>Esta reunião foi cancelada.</strong></p>
<?php elseif($invite['room_status']==='closed'):?>
<p>Esta reunião já foi encerrada.</p>
<?php elseif($invite['status']==='invited'):?>
<p>Você recebeu um convite. Informe seu nome para solicitar entrada.</p>
<form method="post"><input type="hidden" name="token" value="<?=e($token)?>"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
<input name="display_name" required placeholder="Seu nome"><button>Solicitar entrada</button></form>
<?php elseif($invite['status']==='waiting'):?>
<p>Aguardando o responsável pela reunião autorizar sua entrada...</p>
<?php elseif($invite['status']==='approved'):?>
<?php if($invite['room_status']==='open'):?><p>Entrada autorizada.</p><a href="room.php?token=<?=urlencode($token)?>">Entrar na videoconferência</a>
<?php else:?><p>Você está autorizado. Aguarde o responsável abrir a sala.</p><?php endif;?>
<?php elseif($invite['status']==='rejected'):?><p>Sua entrada não está autorizada.</p>
<?php else:?><p>Este convite não está disponível.</p><?php endif;?>
</div></body></html>
