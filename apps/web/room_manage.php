<?php
require __DIR__ . '/lib/bootstrap.php';
$user = require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($user['role'] === 'admin') {
    $st = $pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
    $st->execute([$id]);
} else {
    $st = $pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
    $st->execute([$id,$user['id']]);
}
$room = $st->fetch();
if (!$room) { http_response_code(404); exit('Sala não encontrada.'); }

// Compatibilidade com salas criadas antes da identidade automática do anfitrião.
$hst = $pdo->prepare("SELECT * FROM room_invites WHERE room_id=? AND email=? AND status='approved' ORDER BY id LIMIT 1");
$hst->execute([$id,strtolower($room['owner_email'])]);
$hostInvite = $hst->fetch();
if (!$hostInvite) {
    $hostToken = bin2hex(random_bytes(32));
    $hostKey = bin2hex(random_bytes(32));
    $ins = $pdo->prepare("INSERT INTO room_invites(room_id,email,token,status,display_name,participant_key,requested_at,approved_at)
                          VALUES(?,?,?,'approved',?,?,NOW(),NOW())");
    $ins->execute([$id,strtolower($room['owner_email']),$hostToken,$room['owner_name'],$hostKey]);
    $hst->execute([$id,strtolower($room['owner_email'])]);
    $hostInvite = $hst->fetch();
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $inviteId=(int)($_POST['invite_id']??0);
    $action=$_POST['action']??'';

    if (in_array($action,['approve','reject'],true)) {
        $status=$action==='approve'?'approved':'rejected';
        if ($status === 'approved') {
            $pk = bin2hex(random_bytes(32));
            $up=$pdo->prepare("UPDATE room_invites SET status='approved',participant_key=COALESCE(participant_key,?),approved_at=NOW() WHERE id=? AND room_id=?");
            $up->execute([$pk,$inviteId,$id]);
        } else {
            $up=$pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?");
            $up->execute([$inviteId,$id]);
        }
    } elseif ($action==='open') {
        $pdo->prepare("UPDATE rooms SET status='open' WHERE id=?")->execute([$id]);
    } elseif ($action==='close') {
        $pdo->prepare("UPDATE rooms SET status='closed',ends_at=NOW() WHERE id=?")->execute([$id]);
        $pdo->prepare('DELETE FROM room_presence WHERE room_id=?')->execute([$id]);
    } elseif ($action==='remove') {
        $target=$pdo->prepare("SELECT participant_key FROM room_invites WHERE id=? AND room_id=?");
        $target->execute([$inviteId,$id]);$t=$target->fetch();
        if($t && $t['participant_key'] && (int)$inviteId !== (int)$hostInvite['id']){
            $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?")->execute([$inviteId,$id]);
            $pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')->execute([$id,$t['participant_key']]);
            $sig=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload) VALUES(?, ?, ?, 'leave', JSON_OBJECT('removed',true))");
            $sig->execute([$id,$hostInvite['participant_key'],$t['participant_key']]);
        }
    }
    header('Location: room_manage.php?id='.$id); exit;
}

$inv=$pdo->prepare("SELECT i.*,p.last_seen_at,p.mic_enabled,p.cam_enabled,p.screen_sharing,
                    (p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online
                    FROM room_invites i
                    LEFT JOIN room_presence p ON p.room_id=i.room_id AND p.participant_key=i.participant_key
                    WHERE i.room_id=? ORDER BY i.created_at");
$inv->execute([$id]); $invites=$inv->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Gerenciar sala</title>
<meta http-equiv="refresh" content="5">
<style>
body{font-family:Arial,sans-serif;margin:24px;max-width:1100px}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.person{padding:10px;border:1px solid #ddd;border-radius:8px;margin:8px 0}.online{font-weight:bold}.waiting{background:#fff8dc}.badges span{margin-right:8px}
</style></head><body>
<h1><?=e($room['name'])?></h1>
<p>Responsável: <?=e($room['owner_name'])?> | Status: <strong><?=e($room['status'])?></strong></p>
<div class="toolbar">
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<?php if($room['status']!=='open'):?><button name="action" value="open">Abrir sala</button><?php else:?><button name="action" value="close">Encerrar sala</button><?php endif;?>
</form>
<?php if($room['status']==='open'):?><a href="room.php?token=<?=urlencode($hostInvite['token'])?>">Entrar como anfitrião</a><?php endif;?>
</div>

<h2>Participantes e sala de espera</h2>
<?php foreach($invites as $i): ?>
<div class="person <?=$i['status']==='waiting'?'waiting':''?>">
<strong><?=e($i['display_name'] ?: $i['email'])?></strong> — <?=e($i['status'])?>
<?php if($i['online']):?>
 <span class="online">● online</span>
 <span class="badges">🎙 <?=$i['mic_enabled']?'on':'off'?> · 📹 <?=$i['cam_enabled']?'on':'off'?> · 🖥 <?=$i['screen_sharing']?'compartilhando':'não'?></span>
<?php endif;?>
<?php if($i['status']==='waiting'): ?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="invite_id" value="<?=(int)$i['id']?>">
<button name="action" value="approve">Autorizar</button>
<button name="action" value="reject">Recusar</button>
</form>
<?php elseif($i['status']==='approved' && (int)$i['id']!==(int)$hostInvite['id']):?>
<form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<input type="hidden" name="invite_id" value="<?=(int)$i['id']?>">
<button name="action" value="remove">Remover</button>
</form>
<?php endif;?>
</div>
<?php endforeach; ?>
<p><a href="index.php">Voltar</a></p>
</body></html>
