<?php
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/invitations.php';

$user=require_login();
$id=(int)($_GET['id']??$_POST['id']??0);
$msg='';$error='';

function load_room_for_manager(PDO $pdo,array $user,int $id): ?array {
    if($user['role']==='admin'){
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? LIMIT 1');
        $st->execute([$id]);
    }else{
        $st=$pdo->prepare('SELECT r.*,u.name owner_name,u.email owner_email FROM rooms r JOIN users u ON u.id=r.owner_user_id WHERE r.id=? AND r.owner_user_id=? LIMIT 1');
        $st->execute([$id,$user['id']]);
    }
    return $st->fetch()?:null;
}

$room=load_room_for_manager($pdo,$user,$id);
if(!$room){http_response_code(404);exit('Sala não encontrada.');}

$hst=$pdo->prepare("SELECT * FROM room_invites WHERE room_id=? AND email=? AND status='approved' ORDER BY id LIMIT 1");
$hst->execute([$id,strtolower($room['owner_email'])]);
$hostInvite=$hst->fetch();
if(!$hostInvite){
    $hostToken=bin2hex(random_bytes(32));$hostKey=bin2hex(random_bytes(32));
    $ins=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status,display_name,participant_key,requested_at,approved_at)
                        VALUES(?,?,?,'approved',?,?,NOW(),NOW())");
    $ins->execute([$id,strtolower($room['owner_email']),$hostToken,$room['owner_name'],$hostKey]);
    $hst->execute([$id,strtolower($room['owner_email'])]);$hostInvite=$hst->fetch();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $inviteId=(int)($_POST['invite_id']??0);
    $action=$_POST['action']??'';

    try{
        if($action==='edit'){
            $name=trim($_POST['name']??'');
            $description=trim($_POST['description']??'');
            $starts=trim($_POST['starts_at']??'')?:null;
            $ends=trim($_POST['ends_at']??'')?:null;
            if($name==='') throw new RuntimeException('Informe o nome da reunião.');
            $q=$pdo->prepare('UPDATE rooms SET name=?,description=?,starts_at=?,ends_at=? WHERE id=?');
            $q->execute([$name,$description,$starts,$ends,$id]);
            $msg='Dados da reunião atualizados.';
        }elseif($action==='add_invites'){
            if($room['status']==='cancelled') throw new RuntimeException('Não é possível convidar pessoas para uma reunião cancelada.');
            $emails=preg_split('/[\s,;]+/',trim($_POST['emails']??''),-1,PREG_SPLIT_NO_EMPTY);
            $added=0;
            foreach(array_unique($emails) as $email){
                $email=strtolower(trim($email));
                if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$email===strtolower($room['owner_email'])) continue;
                $exists=$pdo->prepare("SELECT id FROM room_invites WHERE room_id=? AND email=? AND status<>'rejected' LIMIT 1");
                $exists->execute([$id,$email]);
                if($exists->fetch()) continue;
                $token=bin2hex(random_bytes(32));
                $ins=$pdo->prepare("INSERT INTO room_invites(room_id,email,token,status) VALUES(?,?,?,'invited')");
                $ins->execute([$id,$email,$token]);
                send_room_invite_mail($config,$room,$email,$token);
                $added++;
            }
            $msg=$added.' novo(s) convite(s) enviado(s).';
        }elseif($action==='resend'){
            $q=$pdo->prepare('SELECT * FROM room_invites WHERE id=? AND room_id=? LIMIT 1');
            $q->execute([$inviteId,$id]);$invite=$q->fetch();
            if(!$invite) throw new RuntimeException('Convite não encontrado.');
            send_room_invite_mail($config,$room,$invite['email'],$invite['token'],'resend');
            $msg='Convite reenviado para '.$invite['email'].'.';
        }elseif(in_array($action,['approve','reject'],true)){
            if($action==='approve'){
                $pk=bin2hex(random_bytes(32));
                $up=$pdo->prepare("UPDATE room_invites SET status='approved',participant_key=COALESCE(participant_key,?),approved_at=NOW() WHERE id=? AND room_id=?");
                $up->execute([$pk,$inviteId,$id]);
                $msg='Participante autorizado.';
            }else{
                $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?")->execute([$inviteId,$id]);
                $msg='Participante recusado.';
            }
        }elseif($action==='open'){
            if($room['status']==='cancelled') throw new RuntimeException('Uma reunião cancelada não pode ser aberta.');
            $pdo->prepare("UPDATE rooms SET status='open',ends_at=NULL WHERE id=?")->execute([$id]);
            $msg='Sala aberta.';
        }elseif(in_array($action,['close','cancel'],true)){
            $newStatus=$action==='cancel'?'cancelled':'closed';
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE room_attendance a JOIN room_presence p ON p.room_id=a.room_id AND p.participant_key=a.participant_key SET a.left_at=p.last_seen_at,a.duration_seconds=TIMESTAMPDIFF(SECOND,a.joined_at,p.last_seen_at) WHERE a.room_id=? AND a.left_at IS NULL')->execute([$id]);
            $pdo->prepare("UPDATE rooms SET status=?,ends_at=NOW() WHERE id=?")->execute([$newStatus,$id]);
            $pdo->prepare('DELETE FROM room_presence WHERE room_id=?')->execute([$id]);
            $sig=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload)
                                SELECT ?,?,participant_key,'leave',JSON_OBJECT('roomEnded',true,'cancelled',?) FROM room_invites WHERE room_id=? AND participant_key IS NOT NULL AND participant_key<>?");
            $sig->execute([$id,$hostInvite['participant_key'],$action==='cancel'?1:0,$id,$hostInvite['participant_key']]);
            $pdo->commit();
            $msg=$action==='cancel'?'Reunião cancelada.':'Reunião encerrada.';
        }elseif($action==='remove'){
            $target=$pdo->prepare('SELECT participant_key FROM room_invites WHERE id=? AND room_id=?');
            $target->execute([$inviteId,$id]);$t=$target->fetch();
            if($t&&$t['participant_key']&&(int)$inviteId!==(int)$hostInvite['id']){
                $left=date('Y-m-d H:i:s');
                $pdo->prepare("UPDATE room_attendance SET left_at=?,duration_seconds=TIMESTAMPDIFF(SECOND,joined_at,?) WHERE room_id=? AND participant_key=? AND left_at IS NULL ORDER BY id DESC LIMIT 1")
                    ->execute([$left,$left,$id,$t['participant_key']]);
                $pdo->prepare("UPDATE room_invites SET status='rejected' WHERE id=? AND room_id=?")->execute([$inviteId,$id]);
                $pdo->prepare('DELETE FROM room_presence WHERE room_id=? AND participant_key=?')->execute([$id,$t['participant_key']]);
                $sig=$pdo->prepare("INSERT INTO signaling_messages(room_id,sender_key,recipient_key,message_type,payload) VALUES(?,?,?,'leave',JSON_OBJECT('removed',true))");
                $sig->execute([$id,$hostInvite['participant_key'],$t['participant_key']]);
                $msg='Participante removido.';
            }
        }
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $error=$e->getMessage();
    }
    $room=load_room_for_manager($pdo,$user,$id);
}

$inv=$pdo->prepare("SELECT i.*,p.last_seen_at,p.mic_enabled,p.cam_enabled,p.screen_sharing,
                    (p.last_seen_at>=DATE_SUB(NOW(),INTERVAL 20 SECOND)) online
                    FROM room_invites i
                    LEFT JOIN room_presence p ON p.room_id=i.room_id AND p.participant_key=i.participant_key
                    WHERE i.room_id=? ORDER BY i.created_at");
$inv->execute([$id]);$invites=$inv->fetchAll();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gerenciar sala</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;max-width:1100px;background:#f6f7f9;color:#1f2937}.panel{background:white;border:1px solid #ddd;border-radius:10px;padding:16px;margin:14px 0}.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.person{padding:10px;border:1px solid #ddd;border-radius:8px;margin:8px 0}.online{font-weight:bold;color:#15803d}.waiting{background:#fff8dc}.badges{font-size:13px;color:#6b7280}.ok{background:#dcfce7;padding:8px;border-radius:7px}.err{background:#fee2e2;padding:8px;border-radius:7px}input,textarea{padding:8px;border:1px solid #ccc;border-radius:7px;width:100%}.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}button{padding:8px 11px}.danger{background:#dc2626;color:white;border:0;border-radius:6px}@media(max-width:700px){.grid{grid-template-columns:1fr}}
</style></head><body>
<h1><?=e($room['name'])?></h1>
<p>Responsável: <?=e($room['owner_name'])?> | Status: <strong><?=e($room['status'])?></strong></p>
<?php if($msg):?><p class="ok"><?=e($msg)?></p><?php endif;?><?php if($error):?><p class="err"><?=e($error)?></p><?php endif;?>

<div class="panel"><h2>Ações da reunião</h2><div class="toolbar">
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>">
<?php if(!in_array($room['status'],['open','cancelled'],true)):?><button name="action" value="open">Abrir sala</button><?php endif;?>
<?php if($room['status']==='open'):?><button name="action" value="close">Encerrar sala</button><?php endif;?>
<?php if($room['status']!=='cancelled'):?><button class="danger" name="action" value="cancel" onclick="return confirm('Cancelar esta reunião?')">Cancelar reunião</button><?php endif;?>
</form>
<?php if($room['status']==='open'):?><a href="room.php?token=<?=urlencode($hostInvite['token'])?>">Entrar como anfitrião</a><?php endif;?>
<a href="room_history.php?id=<?=$id?>">Histórico</a>
</div></div>

<div class="panel"><h2>Editar reunião</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="edit">
<div class="grid"><label>Nome<input name="name" required value="<?=e($room['name'])?>"></label>
<label>Início<input type="datetime-local" name="starts_at" value="<?=e($room['starts_at']?date('Y-m-d\TH:i',strtotime($room['starts_at'])):'')?>"></label></div>
<label>Descrição<textarea name="description" rows="3"><?=e((string)$room['description'])?></textarea></label>
<label>Término previsto/real<input type="datetime-local" name="ends_at" value="<?=e($room['ends_at']?date('Y-m-d\TH:i',strtotime($room['ends_at'])):'')?>"></label>
<p><button>Salvar alterações</button></p></form></div>

<div class="panel"><h2>Adicionar convidados</h2>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="add_invites">
<textarea name="emails" rows="3" placeholder="email1@exemplo.com; email2@exemplo.com"></textarea>
<p><button <?=$room['status']==='cancelled'?'disabled':''?>>Adicionar e enviar convites</button></p></form></div>

<div class="panel"><h2>Participantes e sala de espera</h2>
<?php foreach($invites as $i):?>
<div class="person <?=$i['status']==='waiting'?'waiting':''?>">
<strong><?=e($i['display_name']?:$i['email'])?></strong> — <?=e($i['status'])?>
<?php if($i['online']):?><span class="online"> ● online</span><span class="badges"> · 🎙 <?=$i['mic_enabled']?'on':'off'?> · 📹 <?=$i['cam_enabled']?'on':'off'?> · 🖥 <?=$i['screen_sharing']?'sim':'não'?></span><?php endif;?>
<div class="toolbar" style="margin-top:6px">
<?php if($i['status']==='waiting'):?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="invite_id" value="<?=(int)$i['id']?>">
<button name="action" value="approve">Autorizar</button><button name="action" value="reject">Recusar</button></form>
<?php endif;?>
<?php if((int)$i['id']!==(int)$hostInvite['id']):?>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="invite_id" value="<?=(int)$i['id']?>">
<button name="action" value="resend">Reenviar convite</button>
<?php if($i['status']==='approved'):?><button name="action" value="remove">Remover</button><?php endif;?>
</form>
<?php endif;?>
</div></div>
<?php endforeach;?>
</div>

<p><a href="index.php">Voltar</a></p>
</body></html>
